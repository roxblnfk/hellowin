<?php

declare(strict_types=1);

namespace App\Domain\Clock;

final readonly class SystemClock implements Clock
{
    #[\Override]
    public function now(): int
    {
        return (int) \floor(\microtime(true) * 1000.0);
    }
}
