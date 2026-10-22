<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Balance\LimitsService;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Walk\WalkService;
use App\Domain\Clock\Clock;
use App\Domain\Clock\SystemClock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Story;

/**
 * The moderator's console (docs/spec/protocol.md, «Модератор»). Every call is safe to repeat: it names the state the
 * moderator sees or wants, and a call that finds the story already there changes and publishes nothing.
 */
final readonly class StoryService
{
    /** Longest free line of the owl, in characters. */
    private const TEXT_MAX = 200;

    public function __construct(
        private RitualStore $store,
        private RitualPolicy $policy,
        private TransitionQueue $queue,
        private ChannelPublisher $publisher,
        private RevealBuilder $reveal,
        private ?WalkService $walks = null,
        private ?ResetService $reset = null,
        private ?CursedService $cursed = null,
        private ?RapierService $rapier = null,
        private ?RitualMetrics $metrics = null,
        private ?DiceService $dice = null,
        private Clock $clock = new SystemClock(),
        private ?FlyerService $flyers = null,
        private ?DawnService $dawn = null,
        private ?LimitsService $limits = null,
    ) {}

    /**
     * Queues the move from the scene the moderator sees to the next one. The job runs at most once per scene of
     * a round, so two presses — or two moderators — move it once.
     *
     * At dawn the next scene is the bar again: a new cycle of the evening is the reset of the round, which compares
     * the round under its locks — so a repeated press finds the round over and changes nothing.
     *
     * @param bool $force Move on even if not every pumpkin is done: the room got stuck.
     * @return array{scene: int<0, max>, round?: positive-int} The scene the moderator will see: the next one, or the
     *         current one when it already moved; with the round when the call named the final scene, which the dawn
     *         may have left for a new cycle.
     * @throws StoryRefusedException
     * @throws RitualBusyException
     */
    public function nextScene(int $scene, bool $force): array
    {
        $ritual = $this->store->snapshot();
        $final = $this->policy->finalScene();
        if ($scene !== $ritual->scene) {
            return $scene === $final ? ['scene' => $ritual->scene, 'round' => $ritual->round] : ['scene' => $ritual->scene];
        }
        if ($ritual->scene === $final && $ritual->story->dawn() && $this->reset !== null) {
            $this->reset->reset($ritual->round, again: true);
            $now = $this->store->snapshot();

            return ['scene' => $now->scene, 'round' => $now->round];
        }
        $ritual->scene < $final or throw new StoryRefusedException('final scene');
        $force || $ritual->ready() or throw new StoryRefusedException('not ready');

        $this->queue->scene($ritual->round, $ritual->scene + 1);

        return ['scene' => $ritual->scene + 1];
    }

    /**
     * The moderator's «Шаг финала».
     *
     * @param bool $force Take a step that waits for the room without it — the circle not closed, the goal of the cursed
     *        world not reached, the boss alive: the room got stuck, or the evening runs late.
     * @return int<0, 7> The current step: the requested one if it was next, the unchanged one otherwise.
     * @throws StoryRefusedException Outside the final scene, or a step that waits for the room.
     * @throws RitualBusyException
     */
    public function finaleStep(int $step, bool $force = false): int
    {
        [$before, $after] = $this->store->updateStory(
            static fn(Story $story): Story => !$force && $story->awaitsReady($step) ? $story : $story->stepTo($step),
        );
        $after->finale === null and throw new StoryRefusedException('not final');
        !$force && $before->awaitsReady($step) and throw new StoryRefusedException('not ready');

        $after->finale === $before->finale or $this->entered($before, $after, false);

        return $after->finale;
    }

    /**
     * A finale step the room took by itself, from the transition queue: the dawn after the boss's last strike. A job of
     * a round that is over, or one late for its step, changes nothing.
     *
     * @throws RitualBusyException
     */
    public function finaleOf(int $round, int $step): void
    {
        [$before, $after] = $this->store->updateStory(static fn(Story $story): Story => $story->stepTo($step), $round);
        if ($after->finale !== null && $after->finale !== $before->finale) {
            $this->metrics?->finaleMoved();
            $this->entered($before, $after, true);
        }
    }

    /**
     * @throws StoryRefusedException
     */
    public function say(mixed $line, mixed $params): void
    {
        OwlLine::isId($line) or throw new StoryRefusedException('invalid line');
        $params === null || \is_array($params) && ($params === [] || !\array_is_list($params))
            or throw new StoryRefusedException('invalid params');

        /** @var array<non-empty-string, mixed>|null $params */
        $this->publisher->owl($this->store->round(), $line, $params ?? []);
    }

    /**
     * @throws StoryRefusedException
     */
    public function sayText(mixed $text): void
    {
        $text = \is_string($text) ? \trim($text) : '';
        $text !== '' && \mb_check_encoding($text, 'UTF-8') && \mb_strlen($text) <= self::TEXT_MAX
            or throw new StoryRefusedException('invalid text');

        $this->publisher->owlText($this->store->round(), $text);
    }

    /**
     * RoadRunner says «Мип-мип!» where the moderator clicked him. Nothing is kept: a client that does not see that
     * runner skips it.
     *
     * @param mixed $flyer The runner on the horizon; anything but an id names the RoadRunner of the scene.
     */
    public function meep(mixed $flyer): void
    {
        $flyer = \is_string($flyer) && $flyer !== '' && \preg_match('/^[A-Za-z0-9_-]{1,64}$/', $flyer) === 1 ? $flyer : null;

        $this->publisher->meep($this->store->round(), $flyer, $this->clock->now());
    }

    /**
     * Holds the scene's progress (docs/spec/protocol.md, «Пауза прогресса»): the room's clicks, sips, strikes and
     * throws are refused and no goal makes the scene ready, while the sky goes on. Lifting it catches up with what the
     * room reached meanwhile — on every call, not only the first: a busy lock fails the call, and the moderator's
     * repeat must still catch up.
     *
     * @throws RitualBusyException
     * @throws LockBusyException
     */
    public function pause(bool $on): void
    {
        [$before] = $this->store->updateStory(static fn(Story $story): Story => $story->withPaused($on));
        $before->paused === $on or $this->publisher->pause($this->store->round(), $on);
        if (!$on) {
            $this->limits?->resume();
            $this->dice?->resume();
        }
    }

    /**
     * Scales every limit to the room. Progress that has reached a lower limit moves on by itself
     * ({@see LimitsService::settle()}).
     *
     * @return array{k: float, players: positive-int, limits: array<non-empty-string, positive-int>|\stdClass} The
     *         multiplier and the limits in force after the call, as the `limits` event carries them.
     * @throws StoryRefusedException
     * @throws RitualBusyException
     */
    public function thresholds(mixed $k): array
    {
        Story::isK($k) or throw new StoryRefusedException('invalid k');
        $k = (float) $k;

        $this->store->updateStory(static fn(Story $story): Story => $story->withK($k));
        $this->limits?->nudge();
        $state = LimitsService::toClient($this->limits?->state() ?? ['players' => 1, 'k' => $k, 'burst' => false, 'limits' => []]);

        return ['k' => $k, 'players' => $state['players'], 'limits' => $state['limits']];
    }

    /**
     * What a new finale step brings besides the step itself: the cursed world hands a beer to everyone and tells its
     * goals, zombies come out; the dawn fells FrankenPHP if the moderator took it, lifts the curse from the zombies and
     * shows the board of the evening's shooters.
     *
     * @param bool $byRoom The room took the step by itself: the boss is down already.
     * @throws RitualBusyException
     */
    private function entered(Story $before, Story $after, bool $byRoom): void
    {
        $ritual = $this->store->snapshot();
        $round = $ritual->round;
        $step = $after->finale ?? 0;

        try {
            $step === Story::CURSED_STEP and $this->walks?->handBeer();
            $step === Story::DAWN_STEP && !$byRoom and $this->rapier?->fell($round);
            $step === Story::DAWN_STEP and $this->walks?->cureAll();
        } catch (LockBusyException) {
        }

        $before->finale === Story::DICE_STEP and $this->dice?->end($round);
        $this->publisher->finale($round, $ritual->scene, $step, $this->reveal->finale($ritual)['reveal'] ?? null);
        $step === Story::DAWN_STEP and $this->dawn?->begin($round);
        if ($step === Story::CURSED_STEP) {
            try {
                $this->cursed?->begin($round);
            } catch (LockBusyException) {
            }
        }
        $this->limits?->nudge();
        $this->herd($step);
        $this->publisher->owl($round, OwlLine::finale($step));
        $step === Story::DICE_STEP and $this->dice?->begin($round);
        $step === Story::SCATTER_STEP and $this->scatter();
    }

    /**
     * The cursed world stands its least crowd of wandering zombies up at once, the dawn takes them off; the ticker
     * does both anyway on its next tick, so a busy lock only costs the moment.
     */
    private function herd(int $step): void
    {
        try {
            $step === Story::CURSED_STEP and $this->flyers?->wander($this->clock->now(), false);
            $step === Story::DAWN_STEP and $this->flyers?->retire();
        } catch (LockBusyException) {
        }
    }

    /**
     * The characters run from FrankenPHP. The step has already moved, so a busy lawn only costs the run, not the step.
     */
    private function scatter(): void
    {
        try {
            $this->walks?->scatter();
        } catch (LockBusyException) {
        }
    }
}
