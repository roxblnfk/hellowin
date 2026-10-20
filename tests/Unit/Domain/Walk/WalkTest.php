<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Walk;

use App\Domain\Flyer\Point;
use App\Domain\Walk\Walk;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Walk::class)]
final class WalkTest
{
    public function lastsTheLengthOverTheSpeed(): void
    {
        $walk = Walk::along([new Point(0, 0), new Point(30, 0), new Point(30, 40)], 1_000, 10.0);

        Assert::same($walk->startedAt, 1_000);
        Assert::same($walk->arrivesAt, 8_000);
    }

    public function movesAlongThePathAtAConstantSpeed(): void
    {
        $walk = Walk::along([new Point(0, 0), new Point(30, 0), new Point(30, 40)], 0, 10.0);

        Assert::equals($walk->positionAt(-500), new Point(0, 0));
        Assert::equals($walk->positionAt(1_500), new Point(15, 0));
        Assert::equals($walk->positionAt(3_000), new Point(30, 0));
        Assert::equals($walk->positionAt(5_000), new Point(30, 20));
        Assert::equals($walk->positionAt(60_000), new Point(30, 40));
    }

    public function walkToTheSamePointIsStanding(): void
    {
        $walk = Walk::along([new Point(5, 5), new Point(5, 5)], 1_000, 10.0);

        Assert::same($walk->toArray(), ['path' => [['x' => 5.0, 'y' => 5.0]], 'startedAt' => 1_000, 'arrivesAt' => 1_000]);
    }

    public function repeatedPointsAreDropped(): void
    {
        $walk = Walk::along([new Point(0, 0), new Point(0, 0), new Point(10, 0), new Point(10, 0)], 0, 10.0);

        Assert::count($walk->path, 2);
        Assert::same($walk->arrivesAt, 1_000);
    }

    public function survivesTheStore(): void
    {
        $walk = Walk::along([new Point(0, 0), new Point(10, 0)], 0, 10.0);

        Assert::equals(Walk::fromArray($walk->toArray()), $walk);
        Assert::null(Walk::fromArray(['path' => [], 'startedAt' => 0, 'arrivesAt' => 0]));
        Assert::null(Walk::fromArray(['path' => [['x' => 1, 'y' => 1]], 'startedAt' => 10, 'arrivesAt' => 5]));
    }
}
