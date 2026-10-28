<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\Clock\Clock;
use RoadRunner\Lock\LockInterface;

/**
 * Single-process stand-in for the RoadRunner lock plugin. With a clock, a lock taken with a TTL expires as the
 * plugin's does; without one, it is held until released.
 */
final class FakeLock implements LockInterface
{
    /** @var array<string, string> resource => lock id */
    public array $held = [];

    public int $acquired = 0;
    public int $released = 0;

    /** @var array<string, int> resource => milliseconds of the clock the lock expires at */
    private array $expires = [];

    public function __construct(
        private readonly bool $available = true,
        private readonly ?Clock $clock = null,
    ) {}

    #[\Override]
    public function lock(
        string $resource,
        ?string $id = null,
        int|float|\DateInterval $ttl = 0,
        int|float|\DateInterval $waitTTL = 0,
    ): false|string {
        $this->expire($resource);
        if (!$this->available || isset($this->held[$resource])) {
            return false;
        }

        ++$this->acquired;
        if ($this->clock !== null && \is_numeric($ttl) && $ttl > 0) {
            $this->expires[$resource] = $this->clock->now() + (int) \round($ttl * 1000);
        }

        return $this->held[$resource] = $id ?? 'lock-' . $this->acquired;
    }

    #[\Override]
    public function lockRead(
        string $resource,
        ?string $id = null,
        int|float|\DateInterval $ttl = 0,
        int|float|\DateInterval $waitTTL = 0,
    ): false|string {
        return $this->lock($resource, $id, $ttl, $waitTTL);
    }

    #[\Override]
    public function release(string $resource, string $id): bool
    {
        $this->expire($resource);
        if (($this->held[$resource] ?? null) !== $id) {
            return false;
        }

        unset($this->held[$resource], $this->expires[$resource]);
        ++$this->released;
        return true;
    }

    #[\Override]
    public function forceRelease(string $resource): bool
    {
        unset($this->held[$resource], $this->expires[$resource]);
        return true;
    }

    #[\Override]
    public function exists(string $resource, ?string $id = null): bool
    {
        $this->expire($resource);

        return isset($this->held[$resource]) && ($id === null || $this->held[$resource] === $id);
    }

    #[\Override]
    public function updateTTL(string $resource, string $id, int|float|\DateInterval $ttl): bool
    {
        return $this->exists($resource, $id);
    }

    private function expire(string $resource): void
    {
        $at = $this->expires[$resource] ?? null;
        if ($at !== null && $this->clock !== null && $this->clock->now() >= $at) {
            unset($this->held[$resource], $this->expires[$resource]);
        }
    }
}
