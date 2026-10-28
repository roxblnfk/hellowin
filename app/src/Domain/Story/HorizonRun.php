<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * RoadRunner's run across the horizon that the moderator started by hand. Every client draws it from `at` by the
 * server clock, so the whole room sees the same run.
 */
final readonly class HorizonRun
{
    /**
     * How long a run lasts, ms. Not a game number: it is the length of the client's animation, `HORIZON.runMs` in
     * public/js/pixel/runner-horizon.js, and must follow it.
     */
    public const DURATION = 6500;

    /**
     * @param int $at Unix time in milliseconds when the run starts.
     * @param bool $ltr The run goes from left to right.
     */
    public function __construct(
        public int $at,
        public bool $ltr,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        /** @var mixed $at */
        $at = \is_array($value) ? $value['at'] ?? null : null;
        /** @var mixed $ltr */
        $ltr = \is_array($value) ? $value['ltr'] ?? null : null;

        return \is_int($at) && \is_bool($ltr) ? new self($at, $ltr) : null;
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function running(int $now): bool
    {
        return $now < $this->at + self::DURATION;
    }

    /**
     * @return array{at: int, ltr: bool}
     */
    public function toArray(): array
    {
        return ['at' => $this->at, 'ltr' => $this->ltr];
    }
}
