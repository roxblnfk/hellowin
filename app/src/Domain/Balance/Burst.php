<?php

declare(strict_types=1);

namespace App\Domain\Balance;

/**
 * The moderator's burst of flyers (docs/spec/protocol.md, `flyers.burst`): `base` flyers for one figure on the floor,
 * `perPlayer` more for every other one.
 */
final readonly class Burst
{
    /**
     * @param float $base Above zero.
     * @param float $perPlayer Not negative.
     */
    public function __construct(
        public float $base,
        public float $perPlayer,
    ) {
        $base > 0.0 or throw new \InvalidArgumentException('The base of a burst must be above zero.');
        $perPlayer >= 0.0 or throw new \InvalidArgumentException('The per-player part of a burst must not be negative.');
    }

    /**
     * `ceil(base + perPlayer × (n − 1))`.
     *
     * @param int $players Figures on the floor; none counts as one.
     * @return positive-int
     */
    public function count(int $players): int
    {
        $raw = $this->base + $this->perPlayer * (float) (\max(1, $players) - 1);

        return \max(1, (int) \ceil($raw - 1e-9));
    }
}
