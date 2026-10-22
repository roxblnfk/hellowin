<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The shooters' top in `hellowin:top` — `{players}` — changed under the lock `top`. The board is the whole evening's:
 * neither a reset nor a new cycle clears it.
 */
final readonly class TopStore
{
    private const KEY = 'hellowin:top';
    private const LOCK = 'top';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    public function get(): TopBoard
    {
        return TopBoard::fromArray($this->storage->get(self::KEY));
    }

    /**
     * @param \Closure(TopBoard): TopBoard $change
     * @return TopBoard The board after the change.
     * @throws LockBusyException
     */
    public function update(\Closure $change): TopBoard
    {
        return $this->locks->run([self::LOCK], function () use ($change): TopBoard {
            $before = $this->get();
            $after = $change($before);
            $after == $before or $this->storage->set(self::KEY, $after->toArray());

            return $after;
        });
    }
}
