<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\Point;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Flyer::class)]
final class FlyerTest
{
    public function eventCarriesLaunchAndExpiryTime(): void
    {
        $event = self::flyer()->toEvent();

        Assert::same($event['launchedAt'], 10_000);
        Assert::same($event['expiresAt'], 14_000);
    }

    public function storedFormRestoresTheSameFlyer(): void
    {
        $flyer = self::flyer();

        Assert::equals(Flyer::fromArray($flyer->toArray()), $flyer);
    }

    private static function flyer(): Flyer
    {
        return new Flyer('f-00000001', 1, new Point(-8, 20), new Point(108, 10), null, null, 4_000, 2, 10_000);
    }
}
