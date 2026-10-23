<?php

declare(strict_types=1);

namespace App\Application\Bar;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Player\PlayerService;
use App\Application\Story\CursedService;
use App\Application\Story\OwlLine;
use App\Application\Story\StoryRefusedException;
use App\Application\Walk\ActionStop;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\ZombifiedException;
use App\Domain\Balance\Limit;
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
     * @param positive-int $mugs Full mugs the counter has room for, however many players are on the floor.
     * @param positive-int $pourMs Milliseconds the owl pours one mug.
     * @param Limit $drinks Mugs the room drinks before it may leave, before the moderator's bonus.
     * @param float $rumorChance Probability that the owl mutters a rumour over a pour.
     * @param positive-int $drinkMs Milliseconds a sip plays: no mug is taken or drunk meanwhile.
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
        private Limit $drinks,
        private float $rumorChance = 0.0,
        private Randomizer $random = new Randomizer(),
        private ?CursedService $cursed = null,
        private int $drinkMs = 1200,
        private ?PlayMetrics $metrics = null,
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
        if (!$this->policy->bar($this->ritual->scene()) || !($bar->poured($now) || !$bar->pouring() && $bar->mugs < $this->max())) {
            return;
        }

        [$placed, $pouring, $max] = $this->bar->update(function (Bar $bar) use ($now): array {
            if (!$this->policy->bar($this->ritual->scene())) {
                return [$bar, [null, null, 1]];
            }

            // Counted again under the counter's lock: the check above may be a tick old
            $max = $this->max();
            $placed = null;
            if ($bar->poured($now)) {
                $bar = $bar->placed();
                $placed = $bar->mugs;
            }
            [$bar, $pouring] = $this->pourNext($bar, $now, $max);

            return [$bar, [$placed, $pouring, $max]];
        });

        $round = $this->ritual->round();
        $placed === null or $this->publisher->mugs($round, $placed, $max);
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
                $inBar and [$bar, $pouring] = $this->pourNext($bar, $now, $this->max(\count($walkers)));
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
     * the mug as it is. The cursed world drinks the same way and counts the mug towards its own goal. A walking
     * character stops where it is to drink.
     *
     * @param non-empty-string $id
     * @throws NoCharacterException
     * @throws ZombifiedException
     * @throws BarRefusedException `not bar` — neither the bar nor the cursed world, `no beer`, `not clinked` or `busy`:
     *         the clink of the mug, the last sip or the hand-over of a pumpkin is still playing, so a click never skips
     *         an animation.
     * @throws StoryRefusedException `paused` — the moderator holds the progress: the mug stays in hand.
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function drink(string $id): void
    {
        $now = $this->clock->now();
        [$inBar, $stopped] = $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $now): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $walker->zombie() and throw new ZombifiedException();
                $inBar = $this->policy->bar($this->ritual->scene());
                $story = $this->ritual->story();
                $inBar || $story->cursed() or throw new BarRefusedException('not bar');
                $walker->hasBeer() or throw new BarRefusedException('no beer');
                $walker->busyAt($now) and throw new BarRefusedException('busy');
                $walker->clinked || count($walkers) === 1 or throw new BarRefusedException('not clinked');
                $walker->mugClinking($now) and throw new BarRefusedException('busy');
                $story->paused and throw new StoryRefusedException('paused');
                // Not through ActionStop: the walk is cut in the same write as the sip, under the lock held here
                $stopped = $walker->stoppedAt($now);
                $walkers[$id] = ($stopped ?? $walker)->drank($now + $this->drinkMs);

                return [$walkers, [$inBar, $stopped]];
            },
        );

        $round = $this->ritual->round();
        $stopped === null or $this->publisher->move($round, $stopped, ActionStop::DRINK);
        $this->players->drank($id);
        $this->metrics?->mug($inBar ? PlayMetrics::PLACE_BAR : PlayMetrics::PLACE_CURSED);
        if (!$inBar) {
            $this->publisher->drink($round, $id);
            $this->cursed?->drank($round);

            return;
        }

        $drunk = $this->bar->drank($round);
        $target = $this->target($this->ritual->story());
        $this->publisher->drink($round, $id);
        $this->publisher->drinks($round, $drunk, $target);
        $drunk >= $target and $this->ready($round);
    }

    /**
     * Moves the moderator's bonus on top of the bar's goal by `$delta` mugs, until the end of the round; the goal never
     * drops below one. The clients learn the new goal from the limits ({@see follow()}), like any other change of it.
     *
     * @return positive-int The goal in force.
     * @throws BarRefusedException `invalid delta`, `not bar`.
     * @throws RitualBusyException
     */
    public function moveTarget(mixed $delta): int
    {
        \is_int($delta) or throw new BarRefusedException('invalid delta');
        $this->policy->bar($this->ritual->scene()) or throw new BarRefusedException('not bar');

        [, $after] = $this->ritual->updateStory(fn(Story $story): Story => $story->withBarBonus(
            $this->drinks->moved($story->barBonus, $delta, $story->players, $story->k),
        ));

        return $this->target($after);
    }

    /**
     * The goal has changed — the room, the multiplier or the bonus: the clients get it, and a goal at or below what the
     * room has drunk makes the bar ready at once.
     *
     * @throws RitualBusyException
     */
    public function follow(int $round): void
    {
        $ritual = $this->ritual->snapshot();
        if ($ritual->round !== $round || !$this->policy->bar($ritual->scene)) {
            return;
        }

        $drunk = $this->bar->drinks($round);
        $target = $this->target($ritual->story);
        $this->publisher->drinks($round, $drunk, $target);
        $drunk >= $target and $this->ready($round);
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

        $this->ready($this->ritual->round(), evenPaused: true);
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
     * A new round starts in the bar with a counter full for the players on the floor.
     *
     * @return array{mugs: int<0, max>, max: positive-int, pouring: array|null, drunk: int<0, max>, target: positive-int, roadrunner: bool}
     *         The counter as the reset event carries it.
     * @throws LockBusyException
     */
    public function open(int $round): array
    {
        $this->bar->replace(new Bar($this->max()));

        return $this->state($round, 0, $this->ritual->story());
    }

    /**
     * The counter as clients see it; outside the bar it is empty. `drunk` and `target` go everywhere: the reveal and
     * the console show them too.
     *
     * @param int<0, max>|null $walkers Characters on the floor, when the caller has read them already.
     * @return array{mugs: int<0, max>, max: positive-int, pouring: array|null, drunk: int<0, max>, target: positive-int, roadrunner: bool}
     */
    public function state(int $round, int $scene, Story $story, ?int $walkers = null): array
    {
        $bar = $this->policy->bar($scene) ? $this->bar->get() : new Bar();

        return [
            'mugs' => $bar->mugs,
            'max' => $this->max($walkers),
            'pouring' => $bar->pouringToArray(),
            'drunk' => $this->bar->drinks($round),
            'target' => $this->target($story),
            'roadrunner' => $story->roadrunner,
        ];
    }

    /**
     * @return positive-int The bar's limit for the room plus the moderator's bonus.
     */
    public function target(Story $story): int
    {
        return $this->drinks->with($story->barBonus, $story->players, $story->k);
    }

    /**
     * Mugs the owl pours up to: one per character on the floor — the count that also lets a lone player drink — at
     * least one and no more than the counter has room for. Read without the lawn lock: a player who comes or goes
     * moves the next pour, never a mug already on the counter.
     *
     * @param int<0, max>|null $walkers Characters on the floor, when the caller has read them already.
     * @return positive-int
     */
    public function max(?int $walkers = null): int
    {
        return \max(1, \min($walkers ?? \count($this->walkers->all()), $this->mugs));
    }

    /**
     * Once a round: the bar is ready, and the owl leads the room out.
     *
     * @param bool $evenPaused The moderator's wave, which a pause does not hold.
     * @throws RitualBusyException
     */
    private function ready(int $round, bool $evenPaused = false): void
    {
        if ($this->ritual->markReady($round, 0, null, evenPaused: $evenPaused)) {
            $this->publisher->ready($round, 0);
            $this->publisher->owl($round, OwlLine::BAR_INTRO);
        }
    }

    /**
     * Begins the next mug while the counter holds fewer than `$max` and the owl is not pouring. Mugs above `$max`, left
     * by players who went away, stay until they are taken.
     *
     * @param positive-int $max
     * @return array{Bar, array{since: int, until: int, rumor?: int}|null} The counter and the new pour, if any.
     */
    private function pourNext(Bar $bar, int $now, int $max): array
    {
        if ($bar->pouring() || $bar->mugs >= $max) {
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
