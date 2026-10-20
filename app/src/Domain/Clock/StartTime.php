<?php

declare(strict_types=1);

namespace App\Domain\Clock;

use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * When the server started: the reveal shows how long RoadRunner has been running without a pause.
 *
 * The first worker to ask writes the time into a storage that lives as long as the RoadRunner process — its in-memory
 * KV — so restarted PHP workers keep it, while a restarted RoadRunner or container starts it over. A lock makes the
 * write happen once among workers booting at the same moment.
 */
final readonly class StartTime
{
    private const KEY = 'hellowin:started-at';
    private const LOCK = 'started-at';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
        private Clock $clock,
    ) {}

    /**
     * @return int Unix time in milliseconds.
     * @throws \App\Domain\Lock\LockBusyException
     */
    public function at(): int
    {
        /** @var mixed $stored */
        $stored = $this->storage->get(self::KEY);
        if (\is_int($stored)) {
            return $stored;
        }

        return $this->locks->run([self::LOCK], function (): int {
            /** @var mixed $stored */
            $stored = $this->storage->get(self::KEY);
            if (\is_int($stored)) {
                return $stored;
            }

            $now = $this->clock->now();
            $this->storage->set(self::KEY, $now);

            return $now;
        });
    }
}
