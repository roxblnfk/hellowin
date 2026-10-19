<?php

declare(strict_types=1);

namespace App\Domain\Game;

use Psr\SimpleCache\CacheInterface;
use RoadRunner\Lock\LockInterface;

/**
 * Shared click counter and stage, kept in a key-value store.
 *
 * Every worker increments the same counter, so the read-modify-write runs under an exclusive lock.
 */
final readonly class GameState
{
    private const KEY_CLICKS = 'clicks';
    private const KEY_STAGE = 'stage';
    private const LOCK_RESOURCE = 'hellowin:state';

    /**
     * @param float $lockTtl Seconds the lock outlives a crashed worker.
     * @param float $lockWait Seconds to wait for a busy lock before giving up.
     */
    public function __construct(
        private CacheInterface $storage,
        private LockInterface $lock,
        private Thresholds $thresholds,
        private float $lockTtl = 5.0,
        private float $lockWait = 5.0,
    ) {}

    /**
     * @throws GameBusyException
     */
    public function registerClick(): ClickResult
    {
        $lockId = $this->lock->lock(self::LOCK_RESOURCE, ttl: $this->lockTtl, waitTTL: $this->lockWait);
        $lockId === false and throw new GameBusyException('Game state is locked by another worker.');

        try {
            $clicks = $this->readInt(self::KEY_CLICKS) + 1;
            $previousStage = $this->readInt(self::KEY_STAGE);
            $stage = $this->thresholds->stageFor($clicks);

            $this->storage->set(self::KEY_CLICKS, $clicks);
            $stage === $previousStage or $this->storage->set(self::KEY_STAGE, $stage);

            return new ClickResult(
                clicks: $clicks,
                stage: $stage,
                stageChanged: $stage !== $previousStage,
                threshold: $this->thresholds->nextFor($stage),
            );
        } finally {
            $this->lock->release(self::LOCK_RESOURCE, $lockId);
        }
    }

    /**
     * Current state without taking the lock: a page render may see a value one click behind.
     */
    public function snapshot(): GameSnapshot
    {
        $stage = $this->readInt(self::KEY_STAGE);

        return new GameSnapshot(
            clicks: $this->readInt(self::KEY_CLICKS),
            stage: $stage,
            threshold: $this->thresholds->nextFor($stage),
        );
    }

    /**
     * @return int<0, max>
     */
    private function readInt(string $key): int
    {
        $value = $this->storage->get($key, 0);

        return \is_int($value) && $value > 0 ? $value : 0;
    }
}
