<?php

declare(strict_types=1);

namespace Tests\Fake;

use RoadRunner\Lock\LockInterface;

/**
 * Single-process stand-in for the RoadRunner lock plugin.
 */
final class FakeLock implements LockInterface
{
    /** @var array<string, string> resource => lock id */
    public array $held = [];

    public int $acquired = 0;
    public int $released = 0;

    public function __construct(
        private readonly bool $available = true,
    ) {}

    #[\Override]
    public function lock(
        string $resource,
        ?string $id = null,
        int|float|\DateInterval $ttl = 0,
        int|float|\DateInterval $waitTTL = 0,
    ): false|string {
        if (!$this->available || isset($this->held[$resource])) {
            return false;
        }

        ++$this->acquired;
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
        if (($this->held[$resource] ?? null) !== $id) {
            return false;
        }

        unset($this->held[$resource]);
        ++$this->released;
        return true;
    }

    #[\Override]
    public function forceRelease(string $resource): bool
    {
        unset($this->held[$resource]);
        return true;
    }

    #[\Override]
    public function exists(string $resource, ?string $id = null): bool
    {
        return isset($this->held[$resource]) && ($id === null || $this->held[$resource] === $id);
    }

    #[\Override]
    public function updateTTL(string $resource, string $id, int|float|\DateInterval $ttl): bool
    {
        return $this->exists($resource, $id);
    }
}
