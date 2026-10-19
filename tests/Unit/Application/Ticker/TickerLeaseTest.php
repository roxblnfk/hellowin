<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ticker;

use App\Application\Ticker\TickerLease;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(TickerLease::class)]
final class TickerLeaseTest
{
    public function onlyOneTickerHoldsTheLease(): void
    {
        $lock = new FakeLock();
        $first = new TickerLease($lock);
        $second = new TickerLease($lock);

        Assert::true($first->hold(0));
        Assert::false($second->hold(0));
        Assert::true($first->hold(5_000));
        Assert::false($second->hold(5_000));
        Assert::same($lock->acquired, 1);
    }

    public function releasedLeasePassesToTheNextTicker(): void
    {
        $lock = new FakeLock();
        $first = new TickerLease($lock);
        $second = new TickerLease($lock);
        $first->hold(0);

        $first->release();

        Assert::true($second->hold(0));
        Assert::false($first->hold(0));
    }

    public function expiredLeaseIsTakenAgainOnRenewal(): void
    {
        $lock = new FakeLock();
        $lease = new TickerLease($lock, renewEvery: 1000);
        $lease->hold(0);
        $lock->forceRelease('ticker');

        Assert::true($lease->hold(500));
        Assert::same($lock->acquired, 1);
        Assert::true($lease->hold(1_000));
        Assert::same($lock->acquired, 2);
    }
}
