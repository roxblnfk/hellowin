<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The shooters of the dawn in `hellowin:dawn` — `{players}` — changed under the lock `dawn`. The board is the whole
 * evening's: neither a reset nor a new cycle clears it.
 */
final readonly class DawnStore
{
    private const KEY = 'hellowin:dawn';
    private const LOCK = 'dawn';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    public function get(): DawnBoard
    {
        return DawnBoard::fromArray($this->storage->get(self::KEY));
    }

    /**
     * @param \Closure(DawnBoard): DawnBoard $change
     * @return DawnBoard The board after the change.
     * @throws LockBusyException
     */
    public function update(\Closure $change): DawnBoard
    {
        return $this->locks->run([self::LOCK], function () use ($change): DawnBoard {
            $after = $change($this->get());
            $this->storage->set(self::KEY, $after->toArray());

            return $after;
        });
    }
}
