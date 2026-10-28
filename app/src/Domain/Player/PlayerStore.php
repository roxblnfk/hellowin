<?php

declare(strict_types=1);

namespace App\Domain\Player;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Players in a key-value store: one key per player, changed under the lock `player:{id}`, and an index of every
 * player id under the lock `players`.
 *
 * The KV store has no atomic increment and cannot list keys, hence the locks and the index. The index is written
 * only when a player appears, so its lock is taken once per player, not once per click.
 */
final readonly class PlayerStore
{
    private const KEY_PLAYER = 'hellowin:player:';
    private const KEY_INDEX = 'hellowin:players';
    private const LOCK_PLAYER = 'player:';
    private const LOCK_INDEX = 'players';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @param non-empty-string $id
     */
    public function find(string $id): ?Player
    {
        $value = self::record($this->storage->get(self::KEY_PLAYER . $id));

        return $value === null ? null : Player::fromArray($id, $value);
    }

    /**
     * Applies the change to the player, creating one with empty statistics first if needed.
     *
     * @param non-empty-string $id
     * @param \Closure(Player): Player $change
     * @throws LockBusyException
     */
    public function update(string $id, \Closure $change): Player
    {
        return $this->locks->run([self::LOCK_PLAYER . $id], function () use ($id, $change): Player {
            $stored = self::record($this->storage->get(self::KEY_PLAYER . $id));
            $player = $change(Player::fromArray($id, $stored));
            $this->storage->set(self::KEY_PLAYER . $id, $player->toArray());
            $stored === null and $this->index($id);

            return $player;
        });
    }

    /**
     * Zeroes the player's counters; an id without a record stays without one.
     *
     * @param non-empty-string $id
     * @return Player|null The zeroed record; null when there is none.
     * @throws LockBusyException
     */
    public function zero(string $id): ?Player
    {
        return $this->locks->run([self::LOCK_PLAYER . $id], function () use ($id): ?Player {
            $stored = self::record($this->storage->get(self::KEY_PLAYER . $id));
            if ($stored === null) {
                return null;
            }

            $player = Player::fromArray($id, $stored)->zeroed();
            $this->storage->set(self::KEY_PLAYER . $id, $player->toArray());

            return $player;
        });
    }

    /**
     * Every known player; reads without locks, so it may lag a concurrent change.
     *
     * @return list<Player>
     */
    public function all(): array
    {
        $ids = $this->ids();
        if ($ids === []) {
            return [];
        }

        $keys = \array_map(static fn(string $id): string => self::KEY_PLAYER . $id, $ids);
        $values = [...$this->storage->getMultiple($keys)];

        $players = [];
        foreach ($ids as $id) {
            $value = self::record($values[self::KEY_PLAYER . $id] ?? null);
            $value === null or $players[] = Player::fromArray($id, $value);
        }

        return $players;
    }

    /**
     * @return list<non-empty-string>
     */
    public function ids(): array
    {
        $ids = $this->storage->get(self::KEY_INDEX);
        if (!\is_array($ids)) {
            return [];
        }

        $valid = [];
        /** @var mixed $id */
        foreach ($ids as $id) {
            PlayerId::isValid($id) and $valid[] = $id;
        }

        return $valid;
    }

    private static function record(mixed $value): ?array
    {
        return \is_array($value) ? $value : null;
    }

    /**
     * @param non-empty-string $id
     * @throws LockBusyException
     */
    private function index(string $id): void
    {
        $this->locks->run([self::LOCK_INDEX], function () use ($id): void {
            $ids = $this->ids();
            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
                $this->storage->set(self::KEY_INDEX, $ids);
            }
        });
    }
}
