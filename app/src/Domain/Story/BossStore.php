<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * FrankenPHP's health in `hellowin:boss` — `{round, hp, max, damage}` — changed under the lock `boss`: every strike
 * of the room takes this one lock, like every click on the bonfire. A record of an earlier round reads as no boss, so a
 * reset needs not touch it.
 */
final readonly class BossStore
{
    private const KEY = 'hellowin:boss';
    private const LOCK = 'boss';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    public function get(int $round): ?Boss
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);

        return \is_array($value) && ($value['round'] ?? null) === $round ? Boss::fromArray($value) : null;
    }

    /**
     * @param \Closure(?Boss): ?Boss $change
     * @return array{?Boss, ?Boss} The boss before and after the change.
     * @throws LockBusyException
     */
    public function update(int $round, \Closure $change): array
    {
        return $this->locks->run([self::LOCK], function () use ($round, $change): array {
            $before = $this->get($round);
            $after = $change($before);
            $after === null || $after == $before or $this->storage->set(self::KEY, ['round' => $round] + $after->toArray());

            return [$before, $after];
        });
    }
}
