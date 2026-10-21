<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Cursed;
use App\Domain\Story\CursedStore;
use App\Domain\Story\Story;
use App\Domain\Walk\WalkerStore;

/**
 * The goal of the cursed world (docs/spec/scenario.md, «Шаг 5 — цель проклятого мира»): mugs drunk and zombies slain,
 * `$perPlayer` of each for every player on the floor plus the moderator's addition. Both reached — the final scene is
 * ready once more, and the moderator may take the rapier step.
 *
 * The goals follow the players live: the ticker compares the players on the floor with those the clients were last
 * told the goals for ({@see follow()}).
 */
final readonly class CursedService
{
    /**
     * @param positive-int $perPlayer Mugs and zombies per player on the floor.
     */
    public function __construct(
        private CursedStore $store,
        private RitualStore $ritual,
        private RitualPolicy $policy,
        private WalkerStore $walkers,
        private ChannelPublisher $publisher,
        private int $perPlayer,
    ) {}

    /**
     * The cursed world has begun: the clients get the goals for the players on the floor now.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function begin(int $round): void
    {
        $this->settle($round, static fn(Cursed $cursed): Cursed => $cursed, true);
    }

    /**
     * A mug drunk in the cursed world, from the goal step on.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function drank(int $round): void
    {
        $this->ritual->story()->cursed() and $this->settle($round, static fn(Cursed $cursed): Cursed => $cursed->drank(), true);
    }

    /**
     * A zombie slain in the cursed world, from the goal step on.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function slew(int $round): void
    {
        $this->ritual->story()->cursed() and $this->settle($round, static fn(Cursed $cursed): Cursed => $cursed->slew(), true);
    }

    /**
     * The moderator's «+5» / «−5» at a goal: an addition for this pass of the cursed world, gone with the round.
     *
     * @return positive-int The goal in force.
     * @throws StoryRefusedException `invalid goal`, `invalid delta` or `not cursed`.
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function moveTarget(mixed $goal, mixed $delta): int
    {
        Cursed::isGoal($goal) or throw new StoryRefusedException('invalid goal');
        \is_int($delta) or throw new StoryRefusedException('invalid delta');
        /** @var Cursed::DRINKS|Cursed::ZOMBIES $goal */
        $ritual = $this->ritual->snapshot();
        $ritual->story->finale === Story::CURSED_STEP or throw new StoryRefusedException('not cursed');

        $players = $this->players();
        $after = $this->settle(
            $ritual->round,
            fn(Cursed $cursed): Cursed => $cursed->moved($goal, $delta, $this->perPlayer, $players),
            false,
            $players,
        );

        return $after->target($goal, $this->perPlayer, $players);
    }

    /**
     * The ticker's work: when players came or went since the clients were told the goals, they get the new ones — and
     * a goal that came down to the counters makes the world ready.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function follow(): void
    {
        $ritual = $this->ritual->snapshot();
        if (!$ritual->story->cursed()) {
            return;
        }

        $players = $this->players();
        $this->store->get($ritual->round)->players === $players
            or $this->settle($ritual->round, static fn(Cursed $cursed): Cursed => $cursed, false, $players);
    }

    /**
     * The counters and goals as the snapshot carries them: from the goal step on, null before it.
     *
     * @param int<0, max> $players Characters on the floor.
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int}|null
     */
    public function state(int $round, Story $story, int $players): ?array
    {
        return $story->finale !== null && $story->finale >= Story::CURSED_STEP
            ? $this->store->get($round)->toEvent($this->perPlayer, $players)
            : null;
    }

    /**
     * Changes the goal under its lock, tells the clients when anything they see changed (or `$always`), and marks the
     * final scene ready on the goal step once both goals are reached.
     *
     * @param \Closure(Cursed): Cursed $change
     * @param int<0, max>|null $players Players on the floor, when the caller has counted them.
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    private function settle(int $round, \Closure $change, bool $always, ?int $players = null): Cursed
    {
        $players ??= $this->players();
        [$before, $after] = $this->store->update(
            $round,
            static fn(Cursed $cursed): Cursed => $change($cursed)->toldFor($players),
        );
        $event = $after->toEvent($this->perPlayer, $players);
        if ($always || $event !== $before->toEvent($this->perPlayer, $before->players)) {
            $this->publisher->cursed($round, $event);
        }

        if ($after->done($this->perPlayer, $players)
            && $this->ritual->markReady($round, $this->policy->finalScene(), null, Story::CURSED_STEP)) {
            $this->publisher->ready($round, $this->policy->finalScene());
            $this->publisher->owl($round, OwlLine::CURSED_READY);
        }

        return $after;
    }

    /**
     * @return int<0, max>
     */
    private function players(): int
    {
        return \count($this->walkers->all());
    }
}
