<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Domain\Balance\Limit;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Cursed;
use App\Domain\Story\CursedStore;
use App\Domain\Story\Story;
use App\Domain\Story\UncountedTally;

/**
 * The goal of the cursed world (docs/spec/scenario.md, «Шаг 5 — цель проклятого мира»): mugs drunk and zombies slain,
 * each its limit for the figures on the floor plus the moderator's addition. Both reached — the final scene is ready
 * once more, and the moderator may take the rapier step.
 *
 * The goals follow the room live: the ticker compares them with those the clients were last told ({@see follow()}).
 */
final readonly class CursedService
{
    /**
     * @param Limit $drinks Mugs the cursed world wants.
     * @param Limit $zombies Zombies the cursed world wants slain.
     */
    public function __construct(
        private CursedStore $store,
        private RitualStore $ritual,
        private RitualPolicy $policy,
        private ChannelPublisher $publisher,
        private Limit $drinks,
        private Limit $zombies,
        private ?UncountedTally $uncounted = null,
    ) {}

    /**
     * The cursed world has begun: the clients get the goals for the room there is now.
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
     * A zombie slain in the cursed world, from the goal step on. On a pause the goal does not grow: the hit goes to the
     * player and to the reveal alone.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function slew(int $round): void
    {
        $story = $this->ritual->story();
        if (!$story->cursed()) {
            return;
        }

        $story->paused
            ? $this->uncounted?->add($round, UncountedTally::SLAIN)
            : $this->settle($round, static fn(Cursed $cursed): Cursed => $cursed->slew(), true);
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

        $story = $ritual->story;
        $limit = $goal === Cursed::DRINKS ? $this->drinks : $this->zombies;
        $event = $this->settle(
            $ritual->round,
            static fn(Cursed $cursed): Cursed => $cursed->moved($goal, $delta, $limit, $story->players, $story->k),
            false,
        );

        return $goal === Cursed::DRINKS ? $event['drinksTarget'] : $event['zombiesTarget'];
    }

    /**
     * The goals in force now, for the limits of the console.
     *
     * @return array{drinks: positive-int, zombies: positive-int}
     */
    public function targets(int $round, Story $story): array
    {
        $event = $this->store->get($round)->toEvent($this->drinks, $this->zombies, $story->players, $story->k);

        return ['drinks' => $event['drinksTarget'], 'zombies' => $event['zombiesTarget']];
    }

    /**
     * The ticker's work, and the limits' after they change: when the goals moved since the clients were told them, they
     * get the new ones — and a goal that came down to the counters makes the world ready.
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

        $story = $ritual->story;
        $cursed = $this->store->get($ritual->round);
        $cursed->toEvent($this->drinks, $this->zombies, $story->players, $story->k) === $cursed->toldEvent()
            or $this->settle($ritual->round, static fn(Cursed $cursed): Cursed => $cursed, false);
    }

    /**
     * The pause is lifted: goals reached while it held — a lower goal, the ticker's follow — make the world ready now.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function resume(): void
    {
        $ritual = $this->ritual->snapshot();
        $ritual->story->cursed() and $this->settle($ritual->round, static fn(Cursed $cursed): Cursed => $cursed, false);
    }

    /**
     * The counters and goals as the snapshot carries them: from the goal step on, null before it.
     *
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int}|null
     */
    public function state(int $round, Story $story): ?array
    {
        return $story->finale !== null && $story->finale >= Story::CURSED_STEP
            ? $this->store->get($round)->toEvent($this->drinks, $this->zombies, $story->players, $story->k)
            : null;
    }

    /**
     * Changes the goal under its lock, tells the clients when anything they see changed (or `$always`), and marks the
     * final scene ready on the goal step once both goals are reached.
     *
     * @param \Closure(Cursed): Cursed $change
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int}
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    private function settle(int $round, \Closure $change, bool $always): array
    {
        $story = $this->ritual->story();
        [$before, $after] = $this->store->update(
            $round,
            fn(Cursed $cursed): Cursed => ($changed = $change($cursed))->told(
                $changed->toEvent($this->drinks, $this->zombies, $story->players, $story->k),
            ),
        );
        $event = $after->toEvent($this->drinks, $this->zombies, $story->players, $story->k);
        if ($always || $event !== $before->toldEvent()) {
            $this->publisher->cursed($round, $event);
        }

        if ($after->done($this->drinks, $this->zombies, $story->players, $story->k)
            && $this->ritual->markReady($round, $this->policy->finalScene(), null, Story::CURSED_STEP)) {
            $this->publisher->ready($round, $this->policy->finalScene());
            $this->publisher->owl($round, OwlLine::CURSED_READY);
        }

        return $event;
    }
}
