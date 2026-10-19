<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\Clock\Clock;

final class FrozenClock implements Clock
{
    public function __construct(
        public int $now = 1_000_000,
    ) {}

    #[\Override]
    public function now(): int
    {
        return $this->now;
    }
}
