<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Clock;

use App\Domain\Clock\StartTime;
use App\Domain\Lock\Locks;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\FrozenClock;

#[Test]
#[Covers(StartTime::class)]
final class StartTimeTest
{
    public function firstAskNotesTheStartAndLaterOnesKeepIt(): void
    {
        $storage = new ArrayStorage();
        $clock = new FrozenClock(1_000);

        Assert::same((new StartTime($storage, new Locks(new FakeLock()), $clock))->at(), 1_000);
        $clock->now = 5_000;

        // A restarted worker builds its own service over the same RoadRunner storage
        Assert::same((new StartTime($storage, new Locks(new FakeLock()), $clock))->at(), 1_000);
    }

    public function newProcessStartsOver(): void
    {
        $clock = new FrozenClock(1_000);
        (new StartTime(new ArrayStorage(), new Locks(new FakeLock()), $clock))->at();
        $clock->now = 9_000;

        Assert::same((new StartTime(new ArrayStorage(), new Locks(new FakeLock()), $clock))->at(), 9_000);
    }
}
