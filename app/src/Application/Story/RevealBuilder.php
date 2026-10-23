<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Domain\Bar\BarStore;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Story\BossStore;
use App\Domain\Story\CursedStore;
use App\Domain\Story\DiceStore;
use App\Domain\Story\EveningStore;
use App\Domain\Story\Reveal;
use App\Domain\Story\ShotTally;
use App\Domain\Story\UncountedTally;

/**
 * The finale as clients see it: the step and, from the reveal step on, the numbers of the evening. A return to the bar
 * starts a new round but not a new evening: the finished round is carried ({@see EveningStore}), and only the
 * moderator's reset zeroes the numbers.
 */
final readonly class RevealBuilder
{
    public function __construct(
        private ShotTally $shots,
        private PlayerStore $players,
        private RitualPolicy $policy,
        private ?BarStore $bar = null,
        private ?CursedStore $cursed = null,
        private ?BossStore $boss = null,
        private ?DiceStore $dice = null,
        private ?UncountedTally $uncounted = null,
        private ?EveningTally $tally = null,
        private ?EveningStore $evening = null,
    ) {}

    /**
     * @return array{step: int, reveal?: array}|null Null outside the final scene.
     */
    public function finale(RitualSnapshot $ritual): ?array
    {
        $step = $ritual->story->finale;
        if ($step === null) {
            return null;
        }

        return $ritual->story->revealed()
            ? ['step' => $step, 'reveal' => $this->reveal($ritual)->toArray()]
            : ['step' => $step];
    }

    /**
     * The numbers of the evening: the round's, the tally's and those the earlier cycles carried into the round.
     */
    public function reveal(RitualSnapshot $ritual): Reveal
    {
        $numbers = $this->numbers(
            $ritual,
            $this->tally?->count(EveningTally::CALLS) ?? 0,
            $this->tally?->count(EveningTally::DROPPED) ?? 0,
        );
        $carried = $this->evening?->carried($ritual->round);

        return $carried === null ? $numbers : $numbers->plus($carried);
    }

    /**
     * The numbers of the round alone, to carry into the next cycle: the tally goes on by itself.
     */
    public function round(RitualSnapshot $ritual): Reveal
    {
        return $this->numbers($ritual, 0, 0);
    }

    /**
     * Carries a finished round into the evening; a round carried already changes nothing.
     *
     * @throws LockBusyException
     */
    public function carry(int $round, Reveal $numbers): void
    {
        $this->evening?->fold($round, $numbers);
    }

    /**
     * The evening starts over, the moderator's reset: the carried cycles go; the tally is the reset's to clear.
     */
    public function forget(): void
    {
        $this->evening?->forget();
    }

    /**
     * @param int<0, max> $calls
     * @param int<0, max> $dropped
     */
    private function numbers(RitualSnapshot $ritual, int $calls, int $dropped): Reveal
    {
        $id = $ritual->story->lastPumpkin;
        $lastPumpkin = $id === null ? null : ($this->players->find($id)?->ref() ?? ['id' => $id, 'name' => null]);
        $cursed = $this->cursed?->get($ritual->round);

        return Reveal::of(
            $ritual,
            $this->policy,
            $this->shots->count($ritual->round),
            $lastPumpkin,
            ($this->bar?->drinks($ritual->round) ?? 0) + ($cursed?->drunk ?? 0) + ($this->uncounted?->count($ritual->round, UncountedTally::DRINKS) ?? 0),
            ($cursed?->slain ?? 0) + ($this->uncounted?->count($ritual->round, UncountedTally::SLAIN) ?? 0),
            ($this->boss?->get($ritual->round)?->damage ?? 0) + ($this->uncounted?->count($ritual->round, UncountedTally::DAMAGE) ?? 0),
            $this->dice?->get($ritual->round)->rolls ?? 0,
            $calls,
            $dropped,
        );
    }
}
