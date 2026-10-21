<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * How many wandering zombies the cursed world wants (docs/spec/scenario.md, «Зомби-воркеров больше»): never fewer than
 * `$min` alive, and one more on each beat of the zombie interval while fewer than `$perPlayer` per character on the
 * floor are alive. Players who leave take no zombies with them.
 */
final readonly class ZombieCrowd
{
    /**
     * @param positive-int $min
     * @param positive-int $perPlayer
     */
    public function __construct(
        public int $min = 5,
        public int $perPlayer = 5,
    ) {}

    /**
     * @param int<0, max> $alive Wandering zombies on the lawn.
     * @param int<0, max> $players Characters on the floor.
     * @param bool $due The zombie interval has come round.
     * @return int<0, max> Zombies to stand up now.
     */
    public function wanted(int $alive, int $players, bool $due): int
    {
        return \max(
            $this->min - $alive,
            $due && $alive < \max($this->min, $this->perPlayer * $players) ? 1 : 0,
        );
    }
}
