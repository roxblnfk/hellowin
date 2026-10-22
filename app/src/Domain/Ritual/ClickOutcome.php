<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * State seen by a click, read under the pumpkin lock.
 */
final readonly class ClickOutcome
{
    /**
     * @param positive-int $round
     * @param int<0, max> $scene
     * @param float $k Multiplier in force.
     * @param int<0, max> $players Figures on the floor the limits are counted for.
     */
    public function __construct(
        public int $round,
        public int $scene,
        public Pumpkin $pumpkin,
        public float $k = 1.0,
        public int $players = 1,
    ) {}
}
