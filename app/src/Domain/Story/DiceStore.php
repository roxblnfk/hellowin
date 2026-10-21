<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The dice of a round in `hellowin:dice` — `{round, players, winner, owl, rolls}` — changed under the lock `dice`:
 * every throw of the room takes this one lock, so of two 6-6-6 the one that got it first wins. A record of an earlier
 * round reads as fresh dice, so a reset needs not touch it.
 */
final readonly class DiceStore
{
    private const KEY = 'hellowin:dice';
    private const LOCK = 'dice';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    public function get(int $round): Dice
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);

        return Dice::fromArray(\is_array($value) && ($value['round'] ?? null) === $round ? $value : null);
    }

    /**
     * @template T
     * @param \Closure(Dice): array{Dice, T} $change The dice after the change and what to return.
     * @return T
     * @throws LockBusyException
     */
    public function update(int $round, \Closure $change): mixed
    {
        return $this->locks->run([self::LOCK], function () use ($round, $change): mixed {
            $before = $this->get($round);
            [$after, $result] = $change($before);
            $after == $before or $this->storage->set(self::KEY, ['round' => $round] + $after->toArray());

            return $result;
        });
    }
}
