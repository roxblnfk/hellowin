<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerId;
use Psr\SimpleCache\CacheInterface;

/**
 * Characters on the lawn in one key-value key, `hellowin:walkers` (player id → character), changed under the lock
 * `walkers`. One key keeps the snapshot to a single read, and a reset of the ritual leaves it alone.
 */
final readonly class WalkerStore
{
    private const KEY = 'hellowin:walkers';
    private const KEY_BENCH = 'hellowin:zombies';
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
     * Zombies whose players left the lawn in the round: a zombie that comes back is a zombie still. The caller holds
     * the lock ({@see update()}). A list of an earlier round reads as empty.
     *
     * @param list<non-empty-string> $ids
     */
    public function bench(int $round, array $ids): void
    {
        $this->storage->set(self::KEY_BENCH, ['round' => $round, 'ids' => \array_values(\array_unique([...$this->benched($round), ...$ids]))]);
    }

    /**
     * Takes the player off the bench of the round; the caller holds the lock ({@see update()}).
     *
     * @return bool Whether the player's character left as a zombie.
     */
    public function unbench(int $round, string $id): bool
    {
        $ids = $this->benched($round);
        if (!\in_array($id, $ids, true)) {
            return false;
        }
        $this->storage->set(self::KEY_BENCH, ['round' => $round, 'ids' => \array_values(\array_diff($ids, [$id]))]);

        return true;
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

    /**
     * @return list<non-empty-string>
     */
    private function benched(int $round): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY_BENCH);
        /** @var mixed $ids */
        $ids = \is_array($value) && ($value['round'] ?? null) === $round ? $value['ids'] ?? null : null;
        $valid = [];
        /** @var mixed $id */
        foreach (\is_array($ids) ? $ids : [] as $id) {
            PlayerId::isValid($id) and $valid[] = $id;
        }

        return $valid;
    }
}
