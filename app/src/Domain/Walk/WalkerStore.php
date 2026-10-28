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
     * Zombies whose players left the lawn in the round, each with the moment it turns human by itself: a zombie that
     * comes back before it is a zombie still. The caller holds the lock ({@see update()}). A list of an earlier round
     * reads as empty.
     *
     * @param array<non-empty-string, int|null> $zombies Unix time in milliseconds by id; null — only a cure.
     */
    public function bench(int $round, array $zombies): void
    {
        // Not array_merge(): an id of digits only is an int key, and it would be renumbered
        $all = $this->benched($round);
        foreach ($zombies as $id => $until) {
            $all[$id] = $until;
        }
        $this->storage->set(self::KEY_BENCH, self::record($round, $all));
    }

    /**
     * Takes the player off the bench of the round; the caller holds the lock ({@see update()}).
     *
     * @return array{bool, int|null} Whether the player's character left as a zombie, and when it turns human by itself.
     */
    public function unbench(int $round, string $id): array
    {
        $zombies = $this->benched($round);
        if (!\array_key_exists($id, $zombies)) {
            return [false, null];
        }
        $until = $zombies[$id];
        unset($zombies[$id]);
        $this->storage->set(self::KEY_BENCH, self::record($round, $zombies));

        return [true, $until];
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
     * `ids` lists every zombie, `until` only those with a moment. An id of digits only is an int key here, so `ids`
     * turns every key back into a string.
     *
     * @param array<non-empty-string, int|null> $zombies
     */
    private static function record(int $round, array $zombies): array
    {
        return [
            'round' => $round,
            'ids' => \array_map(\strval(...), \array_keys($zombies)),
            'until' => \array_filter($zombies, static fn(?int $until): bool => $until !== null),
        ];
    }

    /**
     * @return array<non-empty-string, int|null>
     */
    private function benched(int $round): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY_BENCH);
        $value = \is_array($value) && ($value['round'] ?? null) === $round ? $value : [];
        /** @var mixed $ids */
        $ids = $value['ids'] ?? null;
        /** @var mixed $until */
        $until = $value['until'] ?? null;
        \is_array($until) or $until = [];
        $valid = [];
        /** @var mixed $id */
        foreach (\is_array($ids) ? $ids : [] as $id) {
            /** @var mixed $at */
            $at = PlayerId::isValid($id) ? $until[$id] ?? null : null;
            PlayerId::isValid($id) and $valid[$id] = \is_int($at) ? $at : null;
        }

        return $valid;
    }
}
