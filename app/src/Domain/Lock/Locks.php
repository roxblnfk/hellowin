<?php

declare(strict_types=1);

namespace App\Domain\Lock;

use RoadRunner\Lock\LockInterface;

/**
 * Runs an operation holding RoadRunner locks: taken in the given order, released in reverse.
 */
final readonly class Locks
{
    /**
     * @param float $ttl Seconds a lock outlives a crashed worker.
     * @param float $wait Seconds to wait for a busy lock before giving up.
     */
    public function __construct(
        private LockInterface $lock,
        private float $ttl = 5.0,
        private float $wait = 5.0,
    ) {}

    /**
     * @template T
     * @param list<non-empty-string> $resources
     * @param \Closure(): T $operation
     * @return T
     * @throws LockBusyException
     */
    public function run(array $resources, \Closure $operation): mixed
    {
        /** @var array<non-empty-string, non-empty-string> $held */
        $held = [];

        try {
            foreach ($resources as $resource) {
                $id = $this->lock->lock($resource, ttl: $this->ttl, waitTTL: $this->wait);
                $id === false and throw new LockBusyException(\sprintf('"%s" is locked by another worker.', $resource));
                $held[$resource] = $id;
            }

            return $operation();
        } finally {
            foreach (\array_reverse($held, true) as $resource => $id) {
                $this->lock->release($resource, $id);
            }
        }
    }
}
