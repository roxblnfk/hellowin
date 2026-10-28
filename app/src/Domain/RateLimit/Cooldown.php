<?php

declare(strict_types=1);

namespace App\Domain\RateLimit;

use RoadRunner\Lock\LockInterface;

/**
 * One pass for everyone per window: the pass is a RoadRunner lock taken with the window as its TTL and never
 * released, so it is the plugin's expiry that opens the next window. Taking the lock is one atomic call — of any
 * number of workers inside one window exactly one gets it, with no read-then-write to race on.
 */
final readonly class Cooldown
{
    /**
     * @param non-empty-string $resource
     * @param int<0, max> $ms The window, in milliseconds; 0 — every call passes.
     */
    public function __construct(
        private LockInterface $lock,
        private string $resource,
        private int $ms,
    ) {}

    /**
     * @return bool False within the window of the last pass.
     */
    public function take(): bool
    {
        // No wait: a call inside the window is refused at once, not put off to the next one
        return $this->ms === 0 || $this->lock->lock($this->resource, ttl: $this->ms / 1000, waitTTL: 0) !== false;
    }
}
