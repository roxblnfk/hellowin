<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\RateLimit;

use App\Domain\RateLimit\Cooldown;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\FrozenClock;

#[Test]
#[Covers(Cooldown::class)]
final class CooldownTest
{
    public function passesOncePerWindow(): void
    {
        $clock = new FrozenClock();
        $lock = new FakeLock(clock: $clock);
        $cooldown = new Cooldown($lock, 'meep', 1500);

        Assert::true($cooldown->take());
        $clock->now += 1499;
        Assert::false($cooldown->take());
        $clock->now += 1;
        Assert::true($cooldown->take());
        Assert::same($lock->released, 0, 'the window ends by the lock expiring');
    }

    public function zeroWindowPassesEveryCall(): void
    {
        $cooldown = new Cooldown(new FakeLock(available: false), 'meep', 0);

        Assert::true($cooldown->take());
        Assert::true($cooldown->take());
    }
}
