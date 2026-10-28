<?php

declare(strict_types=1);

namespace Tests\Fake;

use RoadRunner\Lock\LockInterface;

/**
 * The RoadRunner lock as concurrent workers meet it, for fibers: a busy lock is waited for, and every acquired one
 * lets the other fibers run, so a second caller arrives while the first is inside.
 */
final class YieldingLock implements LockInterface
{
    /** @var list<string> Resources a caller found busy and waited for. */
    public array $waited = [];

    public function __construct(
        private readonly FakeLock $lock = new FakeLock(),
    ) {}

    #[\Override]
    public function lock(
        string $resource,
        ?string $id = null,
        int|float|\DateInterval $ttl = 0,
        int|float|\DateInterval $waitTTL = 0,
    ): false|string {
        while (($acquired = $this->lock->lock($resource, $id, $ttl, $waitTTL)) === false) {
            $this->waited[] = $resource;
            \Fiber::suspend();
        }
        \Fiber::suspend();

        return $acquired;
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
        return $this->lock->release($resource, $id);
    }

    #[\Override]
    public function forceRelease(string $resource): bool
    {
        return $this->lock->forceRelease($resource);
    }

    #[\Override]
    public function exists(string $resource, ?string $id = null): bool
    {
        return $this->lock->exists($resource, $id);
    }

    #[\Override]
    public function updateTTL(string $resource, string $id, int|float|\DateInterval $ttl): bool
    {
        return $this->lock->updateTTL($resource, $id, $ttl);
    }
}
