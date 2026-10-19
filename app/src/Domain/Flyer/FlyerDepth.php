<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * How a flyer at one depth behaves: the farther, the slower and the more points.
 */
final readonly class FlyerDepth
{
    /**
     * @param positive-int $duration Milliseconds across the screen.
     * @param positive-int $points Points for shooting it down.
     */
    public function __construct(
        public int $duration,
        public int $points,
    ) {}
}
