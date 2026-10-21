<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use App\Domain\Walk\WalkZone;
use Random\Randomizer;

/**
 * Zombies of the cursed world: ground targets that stand up anywhere on the lawn or the patch and wander there until
 * they are shot. Where a zombie goes is up to the browsers, alike from its id and the server time. To the rest of the
 * engine it is a flyer — the same key, index, shot and points.
 */
final readonly class ZombieFactory
{
    /**
     * @param positive-int $points
     * @param int<0, max> $depth
     */
    public function __construct(
        private WalkZone $zone,
        private int $points,
        private int $depth = 1,
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function wander(int $now): Flyer
    {
        return Flyer::wanderer(
            'f-' . \bin2hex($this->random->getBytes(4)),
            $this->depth,
            new Point(
                \round($this->random->getFloat($this->zone->x1, $this->zone->x2), 2),
                \round($this->random->getFloat($this->zone->y1, $this->zone->y2), 2),
            ),
            $this->points,
            $now,
        );
    }
}
