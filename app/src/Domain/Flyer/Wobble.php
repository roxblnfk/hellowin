<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * A swing across the base trajectory: at the share `t` of the flight (0…1) the flyer is shifted across its path by
 * `amp · sin(2π · waves · t)`. Large `amp` with many `waves` reads as loops.
 */
final readonly class Wobble
{
    /**
     * @param float $amp Percents of the screen.
     * @param float $waves Full swings over the flight.
     */
    public function __construct(
        public float $amp,
        public float $waves,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        return \is_array($value)
            && isset($value['amp'], $value['waves'])
            && (\is_int($value['amp']) || \is_float($value['amp']))
            && (\is_int($value['waves']) || \is_float($value['waves']))
            ? new self((float) $value['amp'], (float) $value['waves'])
            : null;
    }

    /**
     * @return array{amp: float, waves: float}
     */
    public function toArray(): array
    {
        return ['amp' => $this->amp, 'waves' => $this->waves];
    }
}
