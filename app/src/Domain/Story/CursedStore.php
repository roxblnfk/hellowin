<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The goal of the cursed world in `hellowin:cursed` — `{round, …}` — changed under the lock `cursed`. A record of an
 * earlier round reads as a fresh goal, so a reset needs not touch it and the moderator's additions do not outlive it.
 */
final readonly class CursedStore
{
    private const KEY = 'hellowin:cursed';
    private const LOCK = 'cursed';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    public function get(int $round): Cursed
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);

        return Cursed::fromArray(\is_array($value) && ($value['round'] ?? null) === $round ? $value : null);
    }

    /**
     * @param \Closure(Cursed): Cursed $change
     * @return array{Cursed, Cursed} The goal before and after the change.
     * @throws LockBusyException
     */
    public function update(int $round, \Closure $change): array
    {
        return $this->locks->run([self::LOCK], function () use ($round, $change): array {
            $before = $this->get($round);
            $after = $change($before);
            $after == $before or $this->storage->set(self::KEY, ['round' => $round] + $after->toArray());

            return [$before, $after];
        });
    }
}
