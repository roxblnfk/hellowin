<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Mugs and strikes a pause of the progress let through without counting them toward the scene's task, for the finale
 * reveal: the bar's, the cursed world's and the boss's own counters stood still, the evening's numbers did not.
 * `hellowin:uncounted` — `{round, drinks, damage}`, changed under the lock `uncounted`. Counts of an earlier round read
 * as zero, so a reset needs not touch it.
 */
final readonly class UncountedTally
{
    public const DRINKS = 'drinks';
    public const DAMAGE = 'damage';
    private const KEY = 'hellowin:uncounted';
    private const LOCK = 'uncounted';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @param self::DRINKS|self::DAMAGE $what
     * @throws LockBusyException
     */
    public function add(int $round, string $what): void
    {
        $this->locks->run([self::LOCK], function () use ($round, $what): void {
            $counts = [self::DRINKS => $this->count($round, self::DRINKS), self::DAMAGE => $this->count($round, self::DAMAGE)];
            ++$counts[$what];
            $this->storage->set(self::KEY, ['round' => $round] + $counts);
        });
    }

    /**
     * @param self::DRINKS|self::DAMAGE $what
     * @return int<0, max>
     */
    public function count(int $round, string $what): int
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $count */
        $count = \is_array($value) && ($value['round'] ?? null) === $round ? $value[$what] ?? null : null;

        return \is_int($count) && $count > 0 ? $count : 0;
    }
}
