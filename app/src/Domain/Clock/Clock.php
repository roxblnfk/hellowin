<?php

declare(strict_types=1);

namespace App\Domain\Clock;

interface Clock
{
    /**
     * @return int Unix time in milliseconds.
     */
    public function now(): int;
}
