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
     * @param bool $counted False on a pause of the progress: the click is accepted, but the element keeps its clicks.
     */
    public function __construct(
        public int $round,
        public int $scene,
        public Pumpkin $pumpkin,
        public float $k = 1.0,
        public int $players = 1,
        public bool $counted = true,
    ) {}
}
