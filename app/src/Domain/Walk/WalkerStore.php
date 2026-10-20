<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Characters on the lawn in one key-value key, `hellowin:walkers` (player id → character), changed under the lock
 * `walkers`. One key keeps the snapshot to a single read, and a reset of the ritual leaves it alone.
 */
final readonly class WalkerStore
{
    private const KEY = 'hellowin:walkers';
    private const LOCK = 'walkers';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * Reads without the lock, so it may lag a concurrent change.
     *
     * @return array<non-empty-string, Walker>
     */
    public function all(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        if (!\is_array($value)) {
            return [];
        }

        $walkers = [];
        /** @var mixed $record */
        foreach ($value as $id => $record) {
            $walker = Walker::fromArray((string) $id, $record);
            $walker === null or $walkers[$walker->id] = $walker;
        }

        return $walkers;
    }

    /**
     * Rewrites the characters with what the change returns.
     *
     * @template T
     * @param \Closure(array<non-empty-string, Walker>): array{array<non-empty-string, Walker>, T} $change Gets the
     *        characters, returns the new ones and a result to pass on.
     * @return T
     * @throws LockBusyException
     */
    public function update(\Closure $change): mixed
    {
        return $this->locks->run([self::LOCK], function () use ($change): mixed {
            [$walkers, $result] = $change($this->all());
            $this->storage->set(self::KEY, \array_map(static fn(Walker $walker): array => $walker->toRecord(), $walkers));

            return $result;
        });
    }
}
