<?php

declare(strict_types=1);

namespace App\Domain\Story;

use Random\Randomizer;

/**
 * Three d6 of a real random draw (docs/spec/scenario.md, «Кости 6-6-6»): nobody, the server included, can tell which
 * attempt rolls 6-6-6. A throw is drawn once; a repeated request is answered from what was kept for it, never drawn again.
 */
final readonly class DiceRoller
{
    public const SIX = [6, 6, 6];

    /**
     * @param Randomizer $random The default engine is the system's CSPRNG, as `random_int()`.
     */
    public function __construct(
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * @return array{int<1, 6>, int<1, 6>, int<1, 6>}
     */
    public function roll(): array
    {
        return [$this->face(), $this->face(), $this->face()];
    }

    /**
     * The owl's throw: the first two are anything but 6-6-6 — a 6-6-6 is drawn again — and the third is 6-6-6 for sure.
     *
     * @param positive-int $attempt
     * @return array{int<1, 6>, int<1, 6>, int<1, 6>}
     */
    public function owl(int $attempt): array
    {
        if ($attempt >= 3) {
            return self::SIX;
        }

        do {
            $dice = $this->roll();
        } while ($dice === self::SIX);

        return $dice;
    }

    /**
     * @return int<1, 6>
     */
    private function face(): int
    {
        /** @var int<1, 6> */
        return $this->random->getInt(1, 6);
    }
}
