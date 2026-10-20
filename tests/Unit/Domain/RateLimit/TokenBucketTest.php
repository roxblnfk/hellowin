<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\RateLimit;

use App\Domain\RateLimit\TokenBucket;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TokenBucket::class)]
final class TokenBucketTest
{
    public function fullBucketLetsTheBurstThroughAtOnce(): void
    {
        $bucket = new TokenBucket(rate: 2.0, burst: 3);

        $state = null;
        for ($i = 0; $i < 3; ++$i) {
            $state = $bucket->take($state, 1_000);
            Assert::notNull($state);
        }

        Assert::null($bucket->take($state, 1_000));
    }

    public function emptyBucketRefillsAtTheRate(): void
    {
        $bucket = new TokenBucket(rate: 2.0, burst: 3);
        $state = ['tokens' => 0.0, 'at' => 1_000];

        Assert::null($bucket->take($state, 1_499));
        Assert::equals($bucket->take($state, 1_500), ['tokens' => 0.0, 'at' => 1_500]);
    }

    public function refillStopsAtTheBurst(): void
    {
        $bucket = new TokenBucket(rate: 2.0, burst: 3);

        Assert::equals($bucket->take(['tokens' => 0.0, 'at' => 1_000], 3_600_000), ['tokens' => 2.0, 'at' => 3_600_000]);
    }

    public function fastHandNeverRunsTheDefaultClickBucketDry(): void
    {
        $bucket = new TokenBucket(rate: 20.0, burst: 40);
        $state = null;

        // 15 clicks a second for a minute
        for ($now = 0; $now < 60_000; $now += 67) {
            $state = $bucket->take($state, $now);
            Assert::notNull($state);
        }
    }

    public function floodRunsDryAfterTheBurst(): void
    {
        $bucket = new TokenBucket(rate: 20.0, burst: 40);
        $state = null;
        $passed = 0;

        // 200 calls within 100 ms: the burst and the two tokens refilled meanwhile
        for ($i = 0; $i < 200; ++$i) {
            $next = $bucket->take($state, \intdiv($i, 2));
            $next === null or [$state, $passed] = [$next, $passed + 1];
        }

        Assert::same($passed, 41);
    }

    public function refillSecondsCoverTheWholeBurst(): void
    {
        Assert::same((new TokenBucket(rate: 20.0, burst: 40))->refillSeconds(), 2);
        Assert::same((new TokenBucket(rate: 0.5, burst: 3))->refillSeconds(), 6);
        Assert::same((new TokenBucket(rate: 100.0, burst: 1))->refillSeconds(), 1);
    }

    public function rateMustBeAboveZero(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('rate');

        new TokenBucket(rate: 0.0, burst: 1);
    }
}
