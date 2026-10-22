<?php

declare(strict_types=1);

namespace App\Domain\Balance;

/**
 * One limit of the game (docs/spec/protocol.md, «Лимиты и баланс»): clicks a stage of an element takes, mugs of the
 * bar, a goal of the cursed world or FrankenPHP's health. The first figure on the floor brings `base`, every other one
 * `perPlayer` more, and the moderator's multiplier scales the sum: a crowd plays longer, a lone player still finishes
 * fast.
 */
final readonly class Limit
{
    /**
     * Spares a product like 1.1 × 10 = 11.000000000000002 from rounding up to 12.
     */
    private const EPSILON = 1e-9;

    /**
     * @param float $base The limit for one figure on the floor at k = 1; above zero.
     * @param float $perPlayer What every other figure adds; not negative.
     */
    public function __construct(
        public float $base,
        public float $perPlayer = 0.0,
    ) {
        $base > 0.0 or throw new \InvalidArgumentException('The base of a limit must be above zero.');
        $perPlayer >= 0.0 or throw new \InvalidArgumentException('The per-player part of a limit must not be negative.');
    }

    /**
     * `max(1, ceil(k × (base + perPlayer × (max(1, n) − 1))))`; the moderator's bonus goes on top of it.
     *
     * @param int $players Figures on the floor; none counts as one.
     * @param float $k The moderator's multiplier.
     * @return positive-int
     */
    public function of(int $players, float $k = 1.0): int
    {
        $raw = $k * ($this->base + $this->perPlayer * (float) (\max(1, $players) - 1));

        return \max(1, (int) \ceil($raw - self::EPSILON));
    }

    /**
     * A limit with the moderator's bonus on top, never below one.
     *
     * @return positive-int
     */
    public function with(int $bonus, int $players, float $k = 1.0): int
    {
        return \max(1, $this->of($players, $k) + $bonus);
    }

    /**
     * Moves a bonus by `$delta`; a bonus that would take the limit below one stops at one for the figures there are
     * now, so a run of «−5» leaves no hidden debt to pay off with «+5».
     */
    public function moved(int $bonus, int $delta, int $players, float $k = 1.0): int
    {
        return \max(1 - $this->of($players, $k), $bonus + $delta);
    }
}
