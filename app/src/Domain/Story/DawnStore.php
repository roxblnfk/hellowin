<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The shooters of the dawn in `hellowin:dawn` — `{round, players}` — changed under the lock `dawn`. The dawn comes once
 * a round, so a record of an earlier round reads as an empty board: neither the step nor a reset needs to clear it.
 */
final readonly class DawnStore
{
    private const KEY = 'hellowin:dawn';
    private const LOCK = 'dawn';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    public function get(int $round): DawnBoard
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);

        return DawnBoard::fromArray(\is_array($value) && ($value['round'] ?? null) === $round ? $value : null);
    }

    /**
     * @param \Closure(DawnBoard): DawnBoard $change
     * @return DawnBoard The board after the change.
     * @throws LockBusyException
     */
    public function update(int $round, \Closure $change): DawnBoard
    {
        return $this->locks->run([self::LOCK], function () use ($round, $change): DawnBoard {
            $after = $change($this->get($round));
            $this->storage->set(self::KEY, ['round' => $round] + $after->toArray());

            return $after;
        });
    }
}
