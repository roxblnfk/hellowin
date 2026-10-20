<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Walk;

use App\Domain\Flyer\Point;
use App\Domain\Walk\WalkZone;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(WalkZone::class)]
final class WalkZoneTest
{
    public function lawnPointStaysWhereItIs(): void
    {
        Assert::equals(RitualHarness::walkZone()->clamp(new Point(10, 90)), new Point(10, 90));
    }

    public function pointOutsideTheRectangleGoesToItsEdge(): void
    {
        Assert::equals(RitualHarness::walkZone()->clamp(new Point(-20, 120)), new Point(3, 97));
    }

    public function pointInThePatchGoesToItsEdge(): void
    {
        $zone = RitualHarness::walkZone();

        $point = $zone->clamp(new Point(50, 80));

        Assert::equals($point, new Point(50, 88));
        Assert::true($zone->contains($point));
    }

    public function pointWherePatchCrossesTheTopGoesToTheCrossing(): void
    {
        $point = RitualHarness::walkZone()->clamp(new Point(45, 30));

        Assert::same(\round($point->x, 2), 29.85);
        Assert::same($point->y, 44.0);
    }

    public function clearLineIsWalkedStraight(): void
    {
        $from = new Point(10, 90);
        $to = new Point(90, 95);

        Assert::equals(RitualHarness::walkZone()->route($from, $to), [$from, $to]);
    }

    #[DataSet([[10, 60], [90, 60]], 'left to right')]
    #[DataSet([[25, 46], [75, 46]], 'across the top')]
    #[DataSet([[50, 95], [40, 44.5]], 'bottom to top')]
    #[DataSet([[50, 88], [50, 38]], 'from the patch edge')]
    public function pathGoesAroundThePatchAndStaysOnTheLawn(array $from, array $to): void
    {
        $zone = RitualHarness::walkZone();
        $from = $zone->clamp(new Point(...$from));
        $to = $zone->clamp(new Point(...$to));

        $path = $zone->route($from, $to);

        Assert::equals($path[0], $from);
        Assert::equals($path[\count($path) - 1], $to);
        self::assertOnTheLawn($zone, $path);
    }

    public function pathTakesTheShorterSide(): void
    {
        $zone = new WalkZone(0, 0, 100, 100, 50, 50, 20, 20);

        $path = $zone->route(new Point(40, 20), new Point(45, 80));

        Assert::true(\count($path) > 2);
        foreach ($path as $point) {
            Assert::true($point->x <= 50);
        }
        self::assertOnTheLawn($zone, $path);
    }

    /**
     * Samples every segment: no point of the walk is inside the patch or outside the rectangle.
     *
     * @param list<Point> $path
     */
    private static function assertOnTheLawn(WalkZone $zone, array $path): void
    {
        for ($i = 1, $count = \count($path); $i < $count; ++$i) {
            for ($t = 0.0; $t <= 1.0; $t += 0.05) {
                $point = new Point(
                    $path[$i - 1]->x + ($path[$i]->x - $path[$i - 1]->x) * $t,
                    $path[$i - 1]->y + ($path[$i]->y - $path[$i - 1]->y) * $t,
                );
                $radius = \hypot(($point->x - $zone->cx) / $zone->rx, ($point->y - $zone->cy) / $zone->ry);
                Assert::true($radius >= 0.999, \sprintf('(%.2f, %.2f) is inside the patch', $point->x, $point->y));
                Assert::true(
                    $point->x >= $zone->x1 && $point->x <= $zone->x2 && $point->y >= $zone->y1 && $point->y <= $zone->y2,
                    \sprintf('(%.2f, %.2f) is off the lawn', $point->x, $point->y),
                );
            }
        }
    }
}
