<?php

declare(strict_types=1);

namespace App\Domain\Game;

use Psr\SimpleCache\CacheInterface;
use RoadRunner\Lock\LockInterface;

/**
 * Shared click counter and stage, kept in a key-value store.
 * The stage switches from 0 to 1 once, on the threshold click, and stays there.
 *
 * Every worker increments the same counter, so the read-modify-write runs under an exclusive lock.
 */
final readonly class GameState
{
    private const KEY_CLICKS = 'clicks';
    private const KEY_STAGE = 'stage';
    private const LOCK_RESOURCE = 'hellowin:state';

    /** @var positive-int */
    private int $threshold;

    /**
     * @param int $threshold Clicks that switch the stage.
     * @param float $lockTtl Seconds the lock outlives a crashed worker.
     * @param float $lockWait Seconds to wait for a busy lock before giving up.
     */
    public function __construct(
        private CacheInterface $storage,
        private LockInterface $lock,
        int $threshold,
        private float $lockTtl = 5.0,
        private float $lockWait = 5.0,
    ) {
        $threshold > 0 or throw new \InvalidArgumentException('Threshold must be positive.');
        $this->threshold = $threshold;
    }

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
            $stage = $clicks >= $this->threshold ? 1 : 0;

            $this->storage->set(self::KEY_CLICKS, $clicks);
            $stage === $previousStage or $this->storage->set(self::KEY_STAGE, $stage);

            return new ClickResult(
                clicks: $clicks,
                stage: $stage,
                stageChanged: $stage !== $previousStage,
                threshold: $this->threshold,
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
            threshold: $this->threshold,
        );
    }

    /**
     * @return int<0, max>
     */
    private static function toCount(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * @return int<0, max>
     */
    private function readInt(string $key): int
    {
        return self::toCount($this->storage->get($key, 0));
    }
}
