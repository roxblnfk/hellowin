<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Domain\Bar\BarStore;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Story\BossStore;
use App\Domain\Story\CursedStore;
use App\Domain\Story\Reveal;
use App\Domain\Story\ShotTally;

/**
 * The finale as clients see it: the step and, from the reveal step on, the numbers of the round.
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

    public function reveal(RitualSnapshot $ritual): Reveal
    {
        $id = $ritual->story->lastPumpkin;
        $lastPumpkin = $id === null ? null : ($this->players->find($id)?->ref() ?? ['id' => $id, 'name' => null]);
        $cursed = $this->cursed?->get($ritual->round);

        return Reveal::of(
            $ritual,
            $this->policy,
            $this->shots->count($ritual->round),
            $lastPumpkin,
            ($this->bar?->drinks($ritual->round) ?? 0) + ($cursed?->drunk ?? 0),
            $cursed?->slain ?? 0,
            $this->boss?->get($ritual->round)?->damage ?? 0,
        );
    }
}
