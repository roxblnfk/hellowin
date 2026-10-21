<?php

declare(strict_types=1);

namespace App\Domain\Bar;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The counter in `hellowin:bar` and the mugs drunk in a round in `hellowin:drinks` — `{round, count}` — both changed
 * under the lock `bar`. The KV has no atomic update, and the lock is what makes the last mug go to one player: whoever
 * reaches the counter first takes it, the next one finds the counter empty.
 */
final readonly class BarStore
{
    private const KEY = 'hellowin:bar';
    private const KEY_DRINKS = 'hellowin:drinks';
    private const LOCK = 'bar';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * Reads without the lock, so it may lag a concurrent change.
     */
    public function get(): Bar
    {
        return Bar::fromArray($this->storage->get(self::KEY));
    }

    /**
     * Rewrites the counter with what the change returns, holding the lock.
     *
     * @template T
     * @param \Closure(Bar): array{Bar, T} $change
     * @return T
     * @throws LockBusyException
     */
    public function update(\Closure $change): mixed
    {
        return $this->locks->run([self::LOCK], function () use ($change): mixed {
            $before = $this->get();
            [$after, $result] = $change($before);
            $after == $before or $this->storage->set(self::KEY, $after->toArray());

            return $result;
        });
    }

    /**
     * Puts a new counter in place, holding the lock: the bar opens full or closes empty.
     *
     * @throws LockBusyException
     */
    public function replace(Bar $bar): void
    {
        $this->locks->run([self::LOCK], function () use ($bar): void {
            $this->write($bar);
        });
    }

    /**
     * Holds the lock of the counter around an operation that changes it through {@see write()}: a mug taken by
     * a character changes the counter and the lawn at once.
     *
     * @template T
     * @param \Closure(): T $operation
     * @return T
     * @throws LockBusyException
     */
    public function locked(\Closure $operation): mixed
    {
        return $this->locks->run([self::LOCK], $operation);
    }

    /**
     * Writes the counter; the caller holds the lock ({@see locked()}).
     */
    public function write(Bar $bar): void
    {
        $this->storage->set(self::KEY, $bar->toArray());
    }

    /**
     * One more mug drunk in the round.
     *
     * @return int<0, max> Mugs drunk in the round so far.
     * @throws LockBusyException
     */
    public function drank(int $round): int
    {
        return $this->locks->run([self::LOCK], function () use ($round): int {
            $count = $this->drinks($round) + 1;
            $this->storage->set(self::KEY_DRINKS, ['round' => $round, 'count' => $count]);

            return $count;
        });
    }

    /**
     * A count of an earlier round reads as zero, so a reset needs not touch it.
     *
     * @return int<0, max>
     */
    public function drinks(int $round): int
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY_DRINKS);
        /** @var mixed $count */
        $count = \is_array($value) && ($value['round'] ?? null) === $round ? $value['count'] ?? null : null;

        return \is_int($count) && $count > 0 ? $count : 0;
    }
}
