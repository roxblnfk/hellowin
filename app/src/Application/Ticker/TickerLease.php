<?php

declare(strict_types=1);

namespace App\Application\Ticker;

use RoadRunner\Lock\LockInterface;

/**
 * The right to tick: the RoadRunner lock `ticker`, renewed while the ticker runs. RoadRunner runs one ticker
 * process, but while a restarted one overlaps the old one, or someone starts `hellowin:ticker` by hand, the lease
 * keeps the second one idle until the first stops renewing it.
 */
final class TickerLease
{
    private const RESOURCE = 'ticker';

    /** @var non-empty-string|null */
    private ?string $id = null;
    private int $renewedAt = 0;

    /**
     * @param float $ttl Seconds the lease outlives a ticker that stopped renewing it.
     * @param int $renewEvery Milliseconds between renewals; ticks in between trust the lease, which outlives
     *        them by far, and spare the lock plugin a call per tick.
     */
    public function __construct(
        private readonly LockInterface $lock,
        private readonly float $ttl = 5.0,
        private readonly int $renewEvery = 1000,
    ) {}

    /**
     * Takes or renews the lease without waiting.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function hold(int $now): bool
    {
        if ($this->id !== null && $now - $this->renewedAt < $this->renewEvery) {
            return true;
        }

        if ($this->id === null || !$this->lock->updateTTL(self::RESOURCE, $this->id, $this->ttl)) {
            $id = $this->lock->lock(self::RESOURCE, ttl: $this->ttl);
            $this->id = $id === false ? null : $id;
        }

        $this->renewedAt = $now;

        return $this->id !== null;
    }

    public function release(): void
    {
        $this->id === null or $this->lock->release(self::RESOURCE, $this->id);
        $this->id = null;
    }
}
