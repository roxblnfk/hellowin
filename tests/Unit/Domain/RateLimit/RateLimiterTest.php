<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\RateLimit;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use App\Domain\RateLimit\RateLimiter;
use App\Domain\RateLimit\TokenBucket;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\FrozenClock;

#[Test]
#[Covers(RateLimiter::class)]
final class RateLimiterTest
{
    public function callerRunsOutOfItsBurst(): void
    {
        $limiter = $this->limiter(['click' => new TokenBucket(1.0, 2)]);

        Assert::true($limiter->allow('click', 'alice'));
        Assert::true($limiter->allow('click', 'alice'));
        Assert::false($limiter->allow('click', 'alice'));
    }

    public function callersAndGroupsHaveTheirOwnBuckets(): void
    {
        $limiter = $this->limiter(['click' => new TokenBucket(1.0, 1), 'move' => new TokenBucket(1.0, 1)]);

        Assert::true($limiter->allow('click', 'alice'));
        Assert::true($limiter->allow('click', 'bob'));
        Assert::true($limiter->allow('move', 'alice'));
        Assert::false($limiter->allow('click', 'alice'));
    }

    public function groupWithoutBucketIsNotLimited(): void
    {
        $limiter = $this->limiter([]);

        for ($i = 0; $i < 100; ++$i) {
            Assert::true($limiter->allow('click', 'alice'));
        }
    }

    public function bucketRefillsWithTime(): void
    {
        $clock = new FrozenClock(1_000);
        $limiter = $this->limiter(['click' => new TokenBucket(2.0, 1)], $clock);

        Assert::true($limiter->allow('click', 'alice'));
        Assert::false($limiter->allow('click', 'alice'));
        $clock->now += 500;

        Assert::true($limiter->allow('click', 'alice'));
    }

    public function restartedWorkerFindsTheBucketInTheProcessStorage(): void
    {
        $storage = new ArrayStorage();
        $clock = new FrozenClock();
        $buckets = ['click' => new TokenBucket(1.0, 1)];

        Assert::true((new RateLimiter($storage, new Locks(new FakeLock()), $clock, $buckets))->allow('click', 'alice'));

        Assert::false((new RateLimiter($storage, new Locks(new FakeLock()), $clock, $buckets))->allow('click', 'alice'));
    }

    public function bucketIsTakenUnderItsLock(): void
    {
        $lock = new FakeLock();
        $limiter = new RateLimiter(new ArrayStorage(), new Locks($lock), new FrozenClock(), ['click' => new TokenBucket(1.0, 1)]);

        $limiter->allow('click', 'alice');

        Assert::same([$lock->acquired, $lock->released], [1, 1]);
    }

    public function busyLockIsReported(): never
    {
        $limiter = new RateLimiter(
            new ArrayStorage(),
            new Locks(new FakeLock(available: false)),
            new FrozenClock(),
            ['click' => new TokenBucket(1.0, 1)],
        );

        Expect::exception(LockBusyException::class);

        $limiter->allow('click', 'alice');
    }

    /**
     * @param array<non-empty-string, TokenBucket> $buckets
     */
    private function limiter(array $buckets, ?FrozenClock $clock = null): RateLimiter
    {
        return new RateLimiter(new ArrayStorage(), new Locks(new FakeLock()), $clock ?? new FrozenClock(), $buckets);
    }
}
