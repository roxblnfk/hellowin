<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Bar\BarRefusedException;
use App\Application\Bar\BarService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\ResetService;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Autopilot;
use App\Domain\Story\AutoScene;
use App\Domain\Story\AutoStore;
use App\Domain\Story\OwlClock;
use Psr\Log\LoggerInterface;

/**
 * The evening runs by itself (docs/spec/scenario.md, «Автопилот»): the moderator switches it on, the ticker makes its
 * moves through the same services the console's RPC calls go to, with the same events and lines. A move is not an RPC:
 * the evening's `calls` and `hellowin_rpc_calls_total` leave it out.
 *
 * @psalm-import-type Next from Autopilot
 */
final class AutopilotService
{
    /** The `action` label of the empty room's reset in `hellowin_autopilot_moves_total`. */
    public const RESET = 'reset';

    /** The gauge as this process set it last; null — not yet. */
    private ?bool $told = null;

    public function __construct(
        private readonly AutoStore $store,
        private readonly Autopilot $autopilot,
        private readonly OwlClock $owl,
        private readonly RitualStore $ritual,
        private readonly RitualPolicy $policy,
        private readonly StoryService $story,
        private readonly ChannelPublisher $publisher,
        private readonly Clock $clock,
        private readonly PlayMetrics $metrics,
        private readonly LoggerInterface $logger,
        private readonly ?BarService $bar = null,
        private readonly ?DiceService $dice = null,
        private readonly ?ResetService $reset = null,
    ) {}

    /**
     * @return array{on: bool, next: Next|null} As the snapshot carries it.
     */
    public function state(): array
    {
        $auto = $this->store->read();

        return ['on' => $auto['on'], 'next' => $auto['next']];
    }

    /**
     * Switching on starts the record over, so every wait counts from now; the clients learn the first move at once
     * instead of on the ticker's next tick. A switch to what is already set changes and publishes nothing.
     *
     * @throws LockBusyException
     */
    public function switch(bool $on): void
    {
        $this->store->locked(function () use ($on): void {
            if ($this->store->read()['on'] === $on) {
                return;
            }

            $scene = $this->scene();
            $next = null;
            if ($on) {
                [$record, $next] = $this->autopilot->tick($scene, null, $this->owl->until(), $this->clock->now());
                $this->store->write(true, $record, $next);
            } else {
                $this->store->write(false);
            }
            // An empty spell counted before the autopilot was off does not carry into its next run
            $this->store->writeEmptyRoom(null);
            $this->publisher->auto($scene->round, $on, $next);
        });
        $this->gauge($on);
    }

    /**
     * One tick of the ticker: the record follows the story, a changed next move is told, a due one is made. A move the
     * story refuses (`not ready`, `busy`) is logged; the record keeps it, and it is tried again after
     * {@see Autopilot::RETRY_MS}.
     *
     * @param int $now Unix time in milliseconds.
     * @throws LockBusyException
     */
    public function tick(int $now): void
    {
        // Off is the usual state: one read without the lock per tick
        if (!$this->store->read()['on']) {
            $this->gauge(false);
            return;
        }

        $this->store->locked(function () use ($now): void {
            $auto = $this->store->read();
            if (!$auto['on']) {
                return;
            }

            $scene = $this->scene();
            [$record, $next, $due] = $this->autopilot->tick($scene, $auto['record'], $this->owl->until(), $now);
            $this->store->write(true, $record, $next);
            $next === $auto['next'] or $this->publisher->auto($scene->round, true, $next);
            $due and $this->move($scene, $next);
        });
        $this->gauge(true);
    }

    /**
     * One count of the presence: a room left without a connection for long enough starts the story over through the
     * moderator's reset ({@see Autopilot::empty()}). The ticker calls it only with a count it has read: an unknown
     * presence never resets. A reset the ritual refuses as busy is tried again on the next count.
     *
     * @param int<0, max> $connections
     * @param int $now Unix time in milliseconds.
     * @throws LockBusyException
     */
    public function room(int $connections, int $now): void
    {
        // A room with people in it is the usual state: reads without the lock, a write only when the state changes
        if (!$this->store->read()['on']) {
            return;
        }
        $empty = $this->store->emptyRoom();
        if ($this->autopilot->empty($empty, $connections, $now)[0] === $empty) {
            return;
        }

        $this->store->locked(function () use ($connections, $now): void {
            if (!$this->store->read()['on']) {
                return;
            }

            [$empty, $reset] = $this->autopilot->empty($this->store->emptyRoom(), $connections, $now);
            if ($reset && !$this->restart($empty['since'] ?? $now, $now)) {
                return;
            }
            $this->store->writeEmptyRoom($empty);
        });
    }

    /**
     * @return bool Whether the story has started over, by this reset or another one of the same round.
     */
    private function restart(int $since, int $now): bool
    {
        if ($this->reset === null) {
            return true;
        }

        $round = $this->ritual->snapshot()->round;
        try {
            $this->reset->reset($round);
        } catch (RitualBusyException) {
            $this->logger->info('Autopilot reset of the empty room refused: {error}', ['error' => 'busy']);
            return false;
        }
        $this->metrics->autopilotMove(self::RESET);
        $this->logger->info('Autopilot reset round {round}: nobody connected for {ms} ms', ['round' => $round, 'ms' => $now - $since]);

        return true;
    }

    /**
     * @param Next $next
     */
    private function move(AutoScene $scene, array $next): void
    {
        $action = $next['action'];
        $force = ($next['force'] ?? false) === true;
        $this->metrics->autopilotMove($action);

        try {
            match ($action) {
                Autopilot::ACTION_BAR => ($this->bar ?? throw new BarRefusedException('not bar'))->wave(),
                Autopilot::ACTION_SCENE, Autopilot::ACTION_AGAIN => $this->story->nextScene($scene->scene, $force),
                Autopilot::ACTION_STEP => $this->story->finaleStep($next['to'] ?? 0, $force),
                Autopilot::ACTION_DICE => ($this->dice ?? throw new StoryRefusedException('not now'))->owl(),
            };
        } catch (StoryRefusedException|BarRefusedException $e) {
            $this->logger->info('Autopilot move {action} refused: {error}', ['action' => $action, 'error' => $e->error]);
        } catch (RitualBusyException|LockBusyException) {
            $this->logger->info('Autopilot move {action} refused: {error}', ['action' => $action, 'error' => 'busy']);
        }
    }

    private function scene(): AutoScene
    {
        $ritual = $this->ritual->snapshot();
        $final = $this->policy->finalScene();
        $story = $ritual->story;
        $step = $ritual->scene >= $final ? $story->finale ?? 0 : null;

        return new AutoScene(
            $ritual->round,
            $ritual->scene,
            $final,
            $this->policy->bar($ritual->scene),
            $ritual->ready(),
            $step,
            $story->paused,
            ($this->dice?->state($ritual->round, $story)['on'] ?? false) === true,
        );
    }

    private function gauge(bool $on): void
    {
        $this->told === $on or $this->metrics->autopilot($on);
        $this->told = $on;
    }
}
