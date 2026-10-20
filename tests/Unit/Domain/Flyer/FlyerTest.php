<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\Point;
use App\Domain\Flyer\Wobble;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Flyer::class)]
#[Covers(Wobble::class)]
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

    public function wobbleGoesIntoTheEventAndBack(): void
    {
        $flyer = new Flyer('f-00000002', 0, new Point(-8, 20), new Point(108, 10), null, null, 4_000, 3, 10_000, 'bat', new Wobble(5.5, 3.2));

        Assert::same($flyer->toEvent()['wobble'], ['amp' => 5.5, 'waves' => 3.2]);
        Assert::false(\array_key_exists('wobble', self::flyer()->toEvent()));
        Assert::equals(Flyer::fromArray($flyer->toArray()), $flyer);
    }

    private static function flyer(): Flyer
    {
        return new Flyer('f-00000001', 1, new Point(-8, 20), new Point(108, 10), null, null, 4_000, 2, 10_000);
    }
}
