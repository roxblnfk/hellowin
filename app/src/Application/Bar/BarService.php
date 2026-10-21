<?php

declare(strict_types=1);

namespace App\Application\Bar;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Player\PlayerService;
use App\Application\Story\OwlLine;
use App\Application\Walk\NoCharacterException;
use App\Domain\Bar\Bar;
use App\Domain\Bar\BarStore;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Story;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use Random\Randomizer;

/**
 * The bar of scene 0 (docs/spec/scenario.md, «Сцена 0 — «Бар»»): the owl pours one mug at a time, a character who
 * reaches the counter takes one if any is left, a clinked mug may be drunk, and the room is ready to leave once it
 * has drunk the bar's goal or the moderator waves.
 *
 * Two mechanics are here to be seen. The last mug goes to whoever reaches the counter first: arrivals are settled
 * under the lock `bar`, which checks and takes the mug in one step, like the first hit of a flyer. The pouring runs on
 * the ticker by server time, kept in the KV: a restarted worker or ticker carries on with the same mug.
 */
final readonly class BarService
{
    /** Milliseconds between two «the counter is empty» of the owl. */
    public const EMPTY_PAUSE = 20_000;

    /**
     * @param positive-int $mugs Full mugs the counter holds at most.
     * @param positive-int $pourMs Milliseconds the owl pours one mug.
     * @param positive-int $drinks Mugs the room drinks before it may leave, unless the moderator moved the goal.
     * @param float $rumorChance Probability that the owl mutters a rumour over a pour.
     */
    public function __construct(
        private BarStore $bar,
        private WalkerStore $walkers,
        private RitualStore $ritual,
        private RitualPolicy $policy,
        private ChannelPublisher $publisher,
        private PlayerService $players,
        private Clock $clock,
        private int $mugs,
        private int $pourMs,
        private int $drinks,
        private float $rumorChance = 0.0,
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * The ticker's work: a poured mug lands on the counter and the next one is begun, characters at the counter take
     * their mugs.
     *
     * @throws LockBusyException
     */
    public function tick(): void
    {
        $this->pour();
        $this->takes();
    }

    /**
     * Puts the poured mug on the counter when its time has come and begins the next one while there is room. Only in
     * the bar: the scene is checked again under the lock, so a mug never lands after the room has left.
     *
     * @throws LockBusyException
     */
    public function pour(): void
    {
        $now = $this->clock->now();
        $bar = $this->bar->get();
        if (!$this->policy->bar($this->ritual->scene()) || !($bar->poured($now) || !$bar->pouring() && $bar->mugs < $this->mugs)) {
            return;
        }

        [$placed, $pouring] = $this->bar->update(function (Bar $bar) use ($now): array {
            if (!$this->policy->bar($this->ritual->scene())) {
                return [$bar, [null, null]];
            }

            $placed = null;
            if ($bar->poured($now)) {
                $bar = $bar->placed();
                $placed = $bar->mugs;
            }
            [$bar, $pouring] = $this->pourNext($bar, $now);

            return [$bar, [$placed, $pouring]];
        });

        $round = $this->ritual->round();
        $placed === null or $this->publisher->mugs($round, $placed);
        $pouring === null or $this->publisher->pour($round, $pouring);
    }

    /**
     * Characters that have reached the counter: each takes a mug if one is left, in the order the lock settles them;
     * the rest go away empty-handed and the owl says so, at most once in {@see EMPTY_PAUSE}. Under the lawn lock and,
     * inside it, the counter's: the mug and the hand change together.
     *
     * @throws LockBusyException
     */
    public function takes(): void
    {
        $now = $this->clock->now();
        $due = \array_filter($this->walkers->all(), static fn(Walker $walker): bool => $walker->atCounter($now));
        if ($due === []) {
            return;
        }

        [$taken, $empty, $pouring] = $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            fn(array $walkers): array => $this->bar->locked(function () use ($walkers, $now): array {
                $inBar = $this->policy->bar($this->ritual->scene());
                $bar = $this->bar->get();
                $taken = [];
                $empty = false;
                foreach ($walkers as $id => $walker) {
                    if (!$walker->atCounter($now)) {
                        continue;
                    }
                    if (!$inBar || $walker->hasBeer()) {
                        $walkers[$id] = $walker->tookMug(false);
                        continue;
                    }

                    $ok = $bar->mugs > 0;
                    $ok and $bar = $bar->taken();
                    $walkers[$id] = $walker->tookMug($ok);
                    $taken[] = [$id, $ok ? $bar->mugs : null];
                    if (!$ok && ($bar->emptyAt === null || $now - $bar->emptyAt >= self::EMPTY_PAUSE)) {
                        $bar = $bar->saidEmpty($now);
                        $empty = true;
                    }
                }

                $pouring = null;
                $inBar and [$bar, $pouring] = $this->pourNext($bar, $now);
                $this->bar->write($bar);

                return [$walkers, [$taken, $empty, $pouring]];
            }),
        );

        $round = $this->ritual->round();
        foreach ($taken as [$id, $mugs]) {
            $this->publisher->take($round, $id, $mugs);
        }
        $empty and $this->publisher->owl($round, OwlLine::BAR_EMPTY);
        $pouring === null or $this->publisher->pour($round, $pouring);
    }

    /**
     * Drinks the clinked mug in hand: the hands are empty again, the player and the room count one more mug, and the
     * bar is ready once the room has drunk its goal. A player alone on the floor has no one to clink with and drinks
     * the mug as it is.
     *
     * @param non-empty-string $id
     * @throws NoCharacterException
     * @throws BarRefusedException `not bar`, `no beer` or `not clinked`.
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function drink(string $id): void
    {
        $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $this->policy->bar($this->ritual->scene()) or throw new BarRefusedException('not bar');
                $walker->hasBeer() or throw new BarRefusedException('no beer');
                $walker->clinked || count($walkers) === 1 or throw new BarRefusedException('not clinked');
                $walkers[$id] = $walker->emptyHanded();

                return [$walkers, null];
            },
        );

        $round = $this->ritual->round();
        $this->players->drank($id);
        $drunk = $this->bar->drank($round);
        $target = $this->target($this->ritual->story());
        $this->publisher->drink($round, $id);
        $this->publisher->drinks($round, $drunk, $target);
        $drunk >= $target and $this->ready($round);
    }

    /**
     * Moves the bar's goal by `$delta` mugs, never below one. A goal at or below what the room has drunk makes the bar
     * ready at once.
     *
     * @return positive-int The goal in force.
     * @throws BarRefusedException `invalid delta`.
     * @throws RitualBusyException
     */
    public function moveTarget(mixed $delta): int
    {
        \is_int($delta) or throw new BarRefusedException('invalid delta');

        // A goal moved back to the configured one is no longer the moderator's: a new env value takes effect again
        [$before, $after] = $this->ritual->updateStory(function (Story $story) use ($delta): Story {
            $target = \max(1, $this->target($story) + $delta);

            return $story->withBarTarget($target === $this->drinks ? null : $target);
        });
        $target = $this->target($after);
        if ($target === $this->target($before)) {
            return $target;
        }

        $round = $this->ritual->round();
        $drunk = $this->bar->drinks($round);
        $this->publisher->drinks($round, $drunk, $target);
        $drunk >= $target && $this->policy->bar($this->ritual->scene()) and $this->ready($round);

        return $target;
    }

    /**
     * The moderator's wave: the bar is ready without waiting for the goal.
     *
     * @throws BarRefusedException `not bar`.
     * @throws RitualBusyException
     */
    public function wave(): void
    {
        $this->policy->bar($this->ritual->scene()) or throw new BarRefusedException('not bar');

        $this->ready($this->ritual->round());
    }

    /**
     * The room has left the bar: the mugs on the counter are gone and the owl stops pouring.
     *
     * @throws LockBusyException
     */
    public function close(): void
    {
        $this->bar->replace(new Bar());
    }

    /**
     * A new round starts in the bar with a full counter.
     *
     * @return array{mugs: int<0, max>, pouring: array|null, drunk: int<0, max>, target: positive-int} The counter as
     *         the reset event carries it.
     * @throws LockBusyException
     */
    public function open(int $round): array
    {
        $this->bar->replace(new Bar($this->mugs));

        return $this->state($round, 0, $this->ritual->story());
    }

    /**
     * The counter as clients see it; outside the bar it is empty. `drunk` and `target` go everywhere: the reveal and
     * the console show them too.
     *
     * @return array{mugs: int<0, max>, pouring: array|null, drunk: int<0, max>, target: positive-int}
     */
    public function state(int $round, int $scene, Story $story): array
    {
        $bar = $this->policy->bar($scene) ? $this->bar->get() : new Bar();

        return [
            'mugs' => $bar->mugs,
            'pouring' => $bar->pouringToArray(),
            'drunk' => $this->bar->drinks($round),
            'target' => $this->target($story),
        ];
    }

    /**
     * @return positive-int The goal the moderator set, or the configured one.
     */
    public function target(Story $story): int
    {
        return $story->barTarget ?? $this->drinks;
    }

    /**
     * Once a round: the bar is ready, and the owl leads the room out.
     *
     * @throws RitualBusyException
     */
    private function ready(int $round): void
    {
        if ($this->ritual->markReady($round, 0, null)) {
            $this->publisher->ready($round, 0);
            $this->publisher->owl($round, OwlLine::BAR_INTRO);
        }
    }

    /**
     * Begins the next mug while there is room on the counter and the owl is not pouring.
     *
     * @return array{Bar, array{since: int, until: int, rumor?: int}|null} The counter and the new pour, if any.
     */
    private function pourNext(Bar $bar, int $now): array
    {
        if ($bar->pouring() || $bar->mugs >= $this->mugs) {
            return [$bar, null];
        }

        /** @var int<0, max>|null $rumor */
        $rumor = $this->rumorChance > 0.0 && $this->random->nextFloat() < $this->rumorChance
            ? $this->random->getInt(0, 0x7FFFFFFF)
            : null;
        $bar = $bar->pour($now, $this->pourMs, $rumor);

        return [$bar, $bar->pouringToArray()];
    }
}
