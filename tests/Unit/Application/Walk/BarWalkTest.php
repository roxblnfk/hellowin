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

    public function newcomerWalksInThroughTheDoor(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2], door: true);

        $walker = $ritual->walks->enter($ritual->players->register(self::A, 'Jack', self::LOOK));

        // 6 percents at the harness speed of 10 a second
        Assert::equals($walker?->walk->path, [new Point(12, 50), new Point(12, 56)]);
        Assert::same([$walker->walk->startedAt, $walker->walk->arrivesAt], [1_000_000, 1_000_600]);
        Assert::same($ritual->broadcast->ofType('walker')[0]['walker']['walk']['path'], [['x' => 12, 'y' => 50], ['x' => 12, 'y' => 56]]);
    }

    public function newcomerOfThePresenceWalksInThroughTheDoorToo(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2], door: true);
        $ritual->players->register(self::A, 'Jack', self::LOOK);
        $ritual->presence->online = [self::A];

        $ritual->walks->sync();

        Assert::same($ritual->broadcast->ofType('walker')[0]['walker']['walk']['path'][0], ['x' => 12, 'y' => 50]);
    }

    public function redressedCharacterStaysWhereItStands(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2], door: true);
        self::place($ritual, self::A, 40, 92);
        $walk = $ritual->walkerStore->all()[self::A]->walk;

        $walker = $ritual->walks->enter($ritual->players->register(self::A, 'Jill', self::LOOK));

        Assert::equals($walker?->walk, $walk);
        Assert::same($walker->name, 'Jill');
    }

    public function outsideTheBarNewcomersAppearInTheSpawnBandEvenWithADoor(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2], door: true);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        $walker = $ritual->walks->enter($ritual->players->register(self::A, 'Jack', self::LOOK));

        Assert::count($walker?->walk->path, 1);
        Assert::numeric($walker->walk->path[0]->y)->between(86, 90);
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
        $at = Point::fromArray($reset['walkers'][0]['walk']['path'][0]);
        Assert::true(RitualHarness::barLayout()->zone->contains($at));
        // At once in the spawn band, as far along as it stood, never through the door
        Assert::numeric($at?->y)->between(90, 94);
    }

    public function resetWithADoorStillPutsEveryoneInTheSpawnBand(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2], door: true);
        self::place($ritual, self::A, 30, 80);

        $ritual->reset->reset();

        $path = $ritual->broadcast->ofType('reset')[0]['walkers'][0]['walk']['path'];
        Assert::count($path, 1);
        Assert::numeric($path[0]['y'])->between(90, 94);
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
        Assert::same($layout->toArray()['counter'], ['x1' => 66.0, 'x2' => 92.0, 'y' => 62.0]);
        Assert::count($layout->toArray()['obstacles'], 4);
    }

    public function counterPlaceKeepsAGapFromTheOthers(): void
    {
        $layout = RitualHarness::barLayout();

        Assert::equals($layout->atCounter(new Point(10, 90), [66.0], 4.0), new Point(70, 62));
        Assert::equals($layout->atCounter(new Point(80, 90), [80.0, 77.0], 4.0), new Point(84, 62));
        // A full counter: the nearest point as before
        Assert::equals($layout->atCounter(new Point(10, 90), \range(66.0, 92.0, 2.0), 4.0), new Point(66, 62));
        Assert::same($layout->counterSpot(new Point(70, 62)), 70.0);
        Assert::null($layout->counterSpot(new Point(70, 80)));
    }

    public function twoFromOneSideStandApartAtTheCounter(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 10, 92);
        self::place($ritual, self::B, 12, 92);

        $first = $ritual->walks->toCounter(self::A);
        $second = $ritual->walks->toCounter(self::B);

        Assert::equals($first->path[\count($first->path) - 1], new Point(66, 62));
        Assert::equals($second->path[\count($second->path) - 1], new Point(70, 62));
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
