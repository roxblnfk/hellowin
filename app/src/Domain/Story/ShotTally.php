<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Flyers shot down in a round, for the finale reveal: `hellowin:shot` — `{round, count}`, changed under the lock
 * `shot`. A count of an earlier round reads as zero, so a reset needs not touch it.
 */
final readonly class ShotTally
{
    private const KEY = 'hellowin:shot';
    private const LOCK = 'shot';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @throws LockBusyException
     */
    public function add(int $round): void
    {
        $this->locks->run([self::LOCK], function () use ($round): void {
            $this->storage->set(self::KEY, ['round' => $round, 'count' => $this->count($round) + 1]);
        });
    }

    /**
     * @return int<0, max>
     */
    public function count(int $round): int
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $count */
        $count = \is_array($value) && ($value['round'] ?? null) === $round ? $value['count'] ?? null : null;

        return \is_int($count) && $count > 0 ? $count : 0;
    }
}
