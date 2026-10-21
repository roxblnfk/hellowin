<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Walk;

use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\TransitionHandler;
use App\Application\Walk\WalkService;
use App\Domain\Bar\BarLayout;
use App\Domain\Flyer\Point;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * Characters in the bar walk its floor around the tables and the counter, and change location with the room
 * (docs/spec/scenario.md, «Сцена 0 — «Бар»»).
 */
#[Test]
#[Covers(WalkService::class)]
#[Covers(BarLayout::class)]
#[Covers(TransitionHandler::class)]
#[Covers(ResetService::class)]
final class BarWalkTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function characterEntersTheBarInItsSpawnBand(): void
    {
        $ritual = self::bar();

        $walker = $ritual->walks->enter($ritual->players->register(self::A, 'Jack', self::LOOK));

        $at = $walker?->walk->path[0];
        Assert::numeric($at?->y)->between(90, 94);
        Assert::true(RitualHarness::barLayout()->zone->contains($at));
    }

    public function moveInTheBarGoesAroundATableOnItsFloor(): void
    {
        $ritual = self::bar();
        $ritual->players->register(self::A, 'Jack', self::LOOK);
        $ritual->walks->move(self::A, 44, 90);
        $ritual->clock->now += 60_000;

        $walk = $ritual->walks->move(self::A, 44, 60);

        $zone = RitualHarness::barLayout()->zone;
        Assert::true(\count($walk->path) > 2);
        Assert::equals($walk->path[\count($walk->path) - 1], new Point(44, 60));
        foreach ($walk->path as $point) {
            Assert::true($zone->contains($point));
        }
    }

    public function targetInTheCounterIsBroughtOntoTheFloor(): void
    {
        $ritual = self::bar();
        $ritual->players->register(self::A, 'Jack', self::LOOK);

        $walk = $ritual->walks->move(self::A, 80, 55);

        Assert::equals($walk->path[\count($walk->path) - 1], new Point(80, 60));
    }

    public function leavingTheBarPutsEveryoneOnTheLawnWithoutWalking(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 80, 62);
        self::place($ritual, self::B, 20, 92);
        $ritual->broadcast->published = [];

        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        $scene = $ritual->broadcast->ofType('scene')[0];
        Assert::same($scene['item'], 'torch');
        $walkers = \array_column($scene['walkers'], null, 'id');
        Assert::same(\array_keys($walkers), [self::A, self::B]);
        $lawn = RitualHarness::walkZone();
        foreach ($walkers as $walker) {
            Assert::count($walker['walk']['path'], 1);
            Assert::same($walker['walk']['startedAt'], $walker['walk']['arrivesAt']);
            Assert::same($walker['item'], 'torch');
            Assert::numeric($lawn->distance(Point::fromArray($walker['walk']['path'][0])))->lessThan(0.01);
        }
        // (80, 62) is inside the patch: the nearest lawn point is on its edge
        Assert::equals($walkers[self::A]['walk']['path'][0], self::rounded($lawn->clamp(new Point(80, 62))));
        Assert::blank($ritual->broadcast->ofType('move'));
        Assert::equals($ritual->snapshot->create()['walkers'], $scene['walkers']);
    }

    public function laterScenesCarryNoWalkers(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        $ritual->story->nextScene(1, true);
        $ritual->runJobs();

        Assert::false(\array_key_exists('walkers', $ritual->broadcast->ofType('scene')[1]));
    }

    public function resetTakesEveryoneBackToTheBar(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        $ritual->walks->move(self::A, 50, 46);
        $ritual->clock->now += 60_000;

        $ritual->reset->reset();

        $reset = $ritual->broadcast->ofType('reset')[0];
        Assert::same($reset['item'], null);
        Assert::count($reset['walkers'], 1);
        Assert::count($reset['walkers'][0]['walk']['path'], 1);
        Assert::true(RitualHarness::barLayout()->zone->contains(Point::fromArray($reset['walkers'][0]['walk']['path'][0])));
    }

    public function withoutTheBarResetCarriesNoWalkers(): void
    {
        $ritual = new RitualHarness();
        self::place($ritual, self::A, 20, 92);

        $ritual->reset->reset();

        Assert::false(\array_key_exists('walkers', $ritual->broadcast->ofType('reset')[0]));
    }

    public function counterPlaceIsTheNearestPointOfItsLine(): void
    {
        $layout = RitualHarness::barLayout();

        Assert::equals($layout->atCounter(new Point(10, 90)), new Point(66, 62));
        Assert::equals($layout->atCounter(new Point(80, 90)), new Point(80, 62));
        Assert::same($layout->toArray()['counter'], ['x1' => 66.0, 'x2' => 95.0, 'y' => 62.0]);
        Assert::count($layout->toArray()['obstacles'], 4);
    }

    public function zoneOfTheBarIsNotTheLawn(): void
    {
        Assert::false(RitualHarness::barLayout()->zone->contains(new Point(22, 64)));
    }

    private static function bar(): RitualHarness
    {
        return new RitualHarness(stageMax: [0, 1, 2]);
    }

    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * @return array{x: float, y: float}
     */
    private static function rounded(Point $point): array
    {
        return ['x' => \round($point->x, 2), 'y' => \round($point->y, 2)];
    }
}
