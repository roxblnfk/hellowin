<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Bar\BarLayout;
use App\Domain\Bar\Perch;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\Point;
use App\Domain\Walk\WalkZone;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(FlyerFactory::class)]
#[Covers(Flyer::class)]
final class FlyerFactoryTest
{
    private const DEPTHS = [[9000, 3], [6500, 2], [4000, 1]];

    public function depthDecidesDurationAndPoints(): void
    {
        foreach (self::flyers(self::factory(0.5, 0.5), 300) as $flyer) {
            Assert::same([$flyer->duration, $flyer->points], self::DEPTHS[$flyer->depth]);
        }
    }

    public function everyDepthShowsUp(): void
    {
        $depths = \array_map(static fn(Flyer $f): int => $f->depth, self::flyers(self::factory(0.5, 0.5), 300));

        Assert::same(\count(\array_unique($depths)), 3);
    }

    public function edgeFlightsStartAndEndBehindTheScreen(): void
    {
        foreach (self::flyers(self::factory(0.0, 0.5), 300) as $flyer) {
            Assert::null($flyer->bush);
            Assert::true(self::offScreen($flyer->from), \json_encode($flyer->toEvent()));
            Assert::true(self::offScreen($flyer->to), \json_encode($flyer->toEvent()));
            Assert::true($flyer->from->x < 50 !== $flyer->to->x < 50, 'crosses the screen');
            $flyer->via === null or Assert::true(self::inBounds($flyer->via));
        }
    }

    public function bushFlightsStartAtABushAndLeaveTheScreen(): void
    {
        foreach (self::flyers(self::factory(1.0, 0.5), 300) as $flyer) {
            Assert::notNull($flyer->bush);
            $bush = $flyer->bush === 'bush-left' ? new Point(4, 86) : new Point(96, 66);
            Assert::equals($flyer->from, $bush);
            Assert::true(self::offScreen($flyer->to));
            Assert::true($flyer->to->y < $flyer->from->y, 'flies up');
            $flyer->via === null or Assert::true($flyer->via->y < $flyer->from->y, 'rises from the bush');
        }
    }

    public function bushFlyerFliesAtTheBushDepth(): void
    {
        $flyers = self::flyers(self::factory(1.0, 0.5), 300);

        foreach ($flyers as $flyer) {
            Assert::same($flyer->depth, $flyer->bush === 'bush-left' ? 2 : 1);
            Assert::same([$flyer->duration, $flyer->points], self::DEPTHS[$flyer->depth]);
        }
        Assert::count(\array_unique(\array_map(static fn(Flyer $f): ?string => $f->bush, $flyers)), 2);
    }

    public function bushBeyondTheDepthsIsRejected(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Bush "bush-far" has depth 3');

        new FlyerFactory([new FlyerDepth(9000, 3), new FlyerDepth(4000, 1), new FlyerDepth(1000, 1)], [new Bush('bush-far', new Point(4, 86), 3)], 1.0, 0.0);
    }

    public function arcChanceDecidesTheCurve(): void
    {
        Assert::blank(\array_filter(self::flyers(self::factory(0.5, 0.0), 100), static fn(Flyer $f): bool => $f->via !== null));
        Assert::blank(\array_filter(self::flyers(self::factory(0.5, 1.0), 100), static fn(Flyer $f): bool => $f->via === null));
    }

    public function noBushesMeansFromTheEdge(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 1.0, 0.0, new Randomizer(new Mt19937(7)));

        Assert::null($factory->launch(0)->bush);
    }

    public function everyKindIsLaunched(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['duck', 'chicken']]);
        $kinds = \array_unique(\array_map(static fn(Flyer $f): string => $f->kind, self::flyers($factory, 50)));
        \sort($kinds);

        Assert::same($kinds, ['chicken', 'duck']);
    }

    public function kindsFollowTheScene(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['duck'], ['bat']]);

        Assert::same($factory->launch(0, 0)->kind, 'duck');
        Assert::same($factory->launch(0, 1)->kind, 'bat');
        Assert::same($factory->launch(0, 5)->kind, 'bat');
    }

    public function batsWobbleAndOthersFlyTheirPath(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.5, 0.5, new Randomizer(new Mt19937(7)), [['duck'], ['bat']]);

        Assert::null($factory->launch(0, 0)?->wobble);
        foreach (\range(1, 30) as $_) {
            $wobble = $factory->launch(0, 1)?->wobble;
            Assert::notNull($wobble);
            Assert::numeric($wobble->amp)->between(3, 8);
            Assert::numeric($wobble->waves)->between(2, 5);
        }
    }

    public function wormsCrawlOverTheLawnAtTheMiddleDepth(): void
    {
        $lawn = WalkZone::lawn(3, 44, 97, 97, 50, 63, 31, 25);
        $depths = \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS);
        $factory = new FlyerFactory($depths, [new Bush('bush-left', new Point(4, 86), 2)], 1.0, 1.0, new Randomizer(new Mt19937(7)), [['duck'], ['worm']], lawn: $lawn);

        foreach (\range(1, 50) as $_) {
            $worm = $factory->launch(0, 1);
            Assert::notNull($worm);
            Assert::same([$worm->kind, $worm->depth, $worm->duration, $worm->points], ['worm', 1, 6500, 2]);
            Assert::null($worm->via);
            Assert::null($worm->bush);
            Assert::true($lawn->contains($worm->from) && $lawn->contains($worm->to));
            // Out of the earth and back into it: no edge of the screen, no crossing of the patch
            Assert::count($lawn->route($worm->from, $worm->to), 2);
            Assert::numeric($worm->wobble?->amp)->between(0.8, 2);
            Assert::numeric($worm->wobble?->waves)->between(3, 6);
        }
    }

    public function cursedWorldTakesItsOwnSetInsteadOfTheScenes(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['duck'], ['worm']], lawn: self::lawn(), cursed: ['bat', 'firefly']);

        Assert::same($factory->launch(0, 1)?->kind, 'worm');
        $kinds = \array_unique(\array_map(static fn(int $_): ?string => $factory->launch(0, 1, true)?->kind, \range(1, 50)));
        \sort($kinds);
        Assert::same($kinds, ['bat', 'firefly']);
    }

    public function dawnTakesItsOwnSetOverTheCursedOne(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['bat']], cursed: ['firefly'], dawn: ['duck', 'chicken']);

        $kinds = \array_unique(\array_map(static fn(int $_): ?string => $factory->launch(0, 1, true, true)?->kind, \range(1, 50)));
        \sort($kinds);
        Assert::same($kinds, ['chicken', 'duck']);
    }

    public function cursedWorldWithoutKindsLaunchesOnlyTheRareFlyerOfTheScene(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['duck']], [null, 'witch'], 1.0);

        Assert::null($factory->launch(0, 0, true));
        Assert::same($factory->launch(0, 1, true)?->kind, 'witch');
    }

    public function firefliesWobbleSluggishlyAtTheMiddleAndNearDepthsForTheirOwnPoints(): void
    {
        $depths = \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS);
        $factory = new FlyerFactory(
            $depths,
            [new Bush('bush-far', new Point(4, 86), 0), new Bush('bush-near', new Point(96, 66), 2)],
            0.5,
            0.5,
            new Randomizer(new Mt19937(7)),
            [['firefly']],
            fireflyPoints: 4,
        );

        $flyers = self::flyers($factory, 200);
        foreach ($flyers as $firefly) {
            Assert::same($firefly->kind, 'firefly');
            Assert::true(\in_array($firefly->depth, [1, 2], true), 'never at the far depth');
            Assert::same([$firefly->duration, $firefly->points], [self::DEPTHS[$firefly->depth][0], 4]);
            Assert::notSame($firefly->bush, 'bush-far');
            $firefly->bush === null and Assert::true(self::offScreen($firefly->from) && self::offScreen($firefly->to));
            Assert::numeric($firefly->wobble?->amp)->between(2, 5);
            Assert::numeric($firefly->wobble?->waves)->between(1.5, 3);
            Assert::equals(Flyer::fromArray($firefly->toArray()), $firefly);
        }
        Assert::count(\array_unique(\array_map(static fn(Flyer $f): int => $f->depth, $flyers)), 2);
        Assert::notBlank(\array_filter($flyers, static fn(Flyer $f): bool => $f->bush === 'bush-near'));
    }

    public function firefliesKeepToTheDepthsThereAre(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 3)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['firefly']]);

        Assert::same($factory->launch(0)?->depth, 0);
    }

    public function fliesLandOnTheTablesAndTheCounterBetweenTheirWayInAndOut(): void
    {
        $bar = RitualHarness::barLayout();
        $depths = \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS);
        $factory = new FlyerFactory(
            $depths,
            [new Bush('bush-left', new Point(4, 86), 2)],
            1.0,
            1.0,
            new Randomizer(new Mt19937(7)),
            [['fly'], ['duck']],
            bar: $bar,
            flyStops: [2, 3],
            flySit: [900, 1600],
            flyLeg: 900,
            flyPoints: 3,
        );
        // Table tops of the harness bar (x range, y) and the counter top
        $tables = [[[15.5, 28.5], 60.5], [[37.5, 50.5], 74.5], [[69.5, 82.5], 76.5]];

        $flies = \array_map(static fn(int $_): ?Flyer => $factory->launch(0), \range(1, 200));
        $landed = [];
        foreach ($flies as $fly) {
            Assert::notNull($fly);
            Assert::same([$fly->kind, $fly->depth, $fly->points, $fly->bush, $fly->via, $fly->wobble], ['fly', 1, 3, null, null, null]);
            Assert::numeric(\count($fly->stops))->between(2, 3);
            $sits = 0;
            $previous = null;
            foreach ($fly->stops as $stop) {
                Assert::numeric($stop->sit)->between(900, 1600);
                $sits += $stop->sit;
                $surface = $stop->at->y <= 53.0 ? 'counter' : null;
                foreach ($tables as $i => [[$x1, $x2], $top]) {
                    $stop->at->y === $top && $stop->at->x >= $x1 && $stop->at->x <= $x2 and $surface = $i;
                }
                $surface === 'counter' and Assert::true($stop->at->x >= 66 && $stop->at->x <= 92 && $stop->at->y >= 46, \json_encode($stop->toArray()));
                Assert::notNull($surface, \json_encode($stop->toArray()));
                Assert::notSame($surface, $previous, 'lands somewhere else each time');
                $previous = $surface;
                $landed[$surface] = true;
            }
            Assert::same($fly->duration, 900 * (\count($fly->stops) + 1) + $sits);
            // In and out over the floor, or behind its side walls
            foreach ([$fly->from, $fly->to] as $end) {
                Assert::true(\in_array($end->x, [0.0, 97.0], true) || ($end->x >= 6 && $end->x <= 91), \json_encode($end));
                Assert::numeric($end->y)->between(53, 94);
            }
            Assert::equals(Flyer::fromArray($fly->toArray()), $fly);
        }
        Assert::count($landed, 4);
        Assert::notBlank(\array_filter($flies, static fn(?Flyer $f): bool => $f?->from->x === 0.0 || $f?->from->x === 97.0));
    }

    public function fliesLandOnThePerchesTooNeverTwiceRunningOnOne(): void
    {
        $harness = RitualHarness::barLayout();
        $bar = new BarLayout($harness->zone, $harness->counter, $harness->spawnY, $harness->clinkReach, [
            new Perch(3, 97, 8, 'beam'),
            new Perch(25, 25, 33, 'shield-1'),
        ]);
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(5)), [['fly']], bar: $bar, flyStops: [3, 3]);

        $landed = [];
        for ($i = 0; $i < 200; ++$i) {
            $previous = null;
            foreach ($factory->launch(0)?->stops ?? [] as $stop) {
                $surface = match (true) {
                    $stop->at->y === 8.0 => 'beam',
                    $stop->at->y === 33.0 => 'shield-1',
                    default => 'furniture ' . $stop->at->y,
                };
                $surface === 'beam' and Assert::numeric($stop->at->x)->between(3, 97);
                $surface === 'shield-1' and Assert::same($stop->at->x, 25.0);
                $surface !== $previous || \str_starts_with($surface, 'furniture') or Assert::fail('lands twice running on ' . $surface);
                $previous = $surface;
                $landed[$surface] = true;
            }
        }
        Assert::true(isset($landed['beam'], $landed['shield-1']), 'lands on the perches');
    }

    public function fliesNeedTheBar(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('walk.bar');

        new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, kinds: [['fly'], ['duck']]);
    }

    public function cursedWormsNeedTheLawnToo(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('walk zone');

        new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, cursed: ['worm']);
    }

    public function wormsNeedTheLawn(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('walk zone');

        new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, kinds: [['bat'], ['worm']]);
    }

    public function sceneWithoutKindsLaunchesOnlyItsRareFlyer(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['duck'], [], []], [null, null, 'witch'], 1.0);

        Assert::null($factory->launch(0, 1));
        Assert::same($factory->launch(0, 2)?->kind, 'witch');
    }

    public function roadrunnerHeldBackLeavesTheSceneWithoutARareFlyer(): void
    {
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), [['duck'], []], [null, 'roadrunner', 'witch'], 1.0);

        Assert::null($factory->launch(0, 1, roadrunner: false));
        Assert::same($factory->launch(0, 1)?->kind, 'roadrunner');
        Assert::same($factory->launch(0, 2, roadrunner: false)?->kind, 'witch');
    }

    public function rareFlyerOfTheSceneShowsUpByChance(): void
    {
        $factory = new FlyerFactory(
            \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS),
            [],
            0.0,
            0.0,
            new Randomizer(new Mt19937(7)),
            [['duck']],
            [null, 'witch'],
            1.0,
            25,
        );

        Assert::same($factory->launch(0, 0)->kind, 'duck');
        $witch = $factory->launch(0, 1);
        Assert::same([$witch->kind, $witch->points, $witch->bush], ['witch', 25, null]);
        Assert::same($factory->launch(0, 3)->kind, 'witch');
    }

    public function roadrunnerRunsAlongTheHorizonForNothing(): void
    {
        $factory = new FlyerFactory(
            \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS),
            [],
            0.5,
            0.5,
            new Randomizer(new Mt19937(7)),
            horizon: 41.5,
        );

        for ($i = 0; $i < 20; ++$i) {
            $runner = $factory->special(Flyer::KIND_ROADRUNNER, 0);
            Assert::same([$runner->depth, $runner->points, $runner->duration], [0, 0, 4000]);
            Assert::same([$runner->from->y, $runner->to->y], [41.5, 41.5]);
            Assert::true(self::offScreen($runner->from) && self::offScreen($runner->to));
            Assert::null($runner->via);
            Assert::false($runner->shootable());
            Assert::equals(Flyer::fromArray($runner->toArray()), $runner);
        }
    }

    public function farFlightsStayAboveTheHorizonSwingIncludedNearerOnesMayDipBelowIt(): void
    {
        $factory = new FlyerFactory(
            \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS),
            [],
            0.0,
            0.7,
            new Randomizer(new Mt19937(3)),
            [['bat', 'duck']],
            ['witch'],
            0.3,
            horizon: 38.0,
        );
        $flyers = [...self::flyers($factory, 1500), ...\array_map(
            static fn(int $i): Flyer => $factory->special('witch', $i),
            \range(1, 300),
        )];

        $far = \array_filter($flyers, static fn(Flyer $f): bool => $f->depth === 0);
        $kinds = \array_unique(\array_map(static fn(Flyer $f): string => $f->kind, $far));
        Assert::array($kinds)->sameElementsAs(['bat', 'duck', 'witch']);
        Assert::true(\count(\array_filter($far, static fn(Flyer $f): bool => $f->via !== null)) > 50);
        foreach ($far as $flyer) {
            $lowest = \max($flyer->from->y, $flyer->to->y, $flyer->via->y ?? -INF) + ($flyer->wobble->amp ?? 0.0);
            Assert::true($lowest <= 36.0, \json_encode($flyer->toEvent()));
        }

        $nearDips = \array_filter(
            $flyers,
            static fn(Flyer $f): bool => $f->depth > 0 && \max($f->from->y, $f->via->y ?? -INF) > 38.0,
        );
        Assert::notBlank($nearDips);
    }

    public function flyerCarriesItsLaunchTimeAndAnId(): void
    {
        $flyer = self::factory(0.5, 0.5)->launch(123_456);

        Assert::same($flyer->launchedAt, 123_456);
        Assert::same($flyer->expiresAt(), 123_456 + $flyer->duration);
        Assert::true(Flyer::isId($flyer->id));
        Assert::equals(Flyer::fromArray($flyer->toArray()), $flyer);
    }

    private static function factory(float $bushChance, float $arcChance): FlyerFactory
    {
        return new FlyerFactory(
            \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS),
            [new Bush('bush-left', new Point(4, 86), 2), new Bush('bush-right', new Point(96, 66), 1)],
            $bushChance,
            $arcChance,
            new Randomizer(new Mt19937(42)),
        );
    }

    private static function lawn(): WalkZone
    {
        return WalkZone::lawn(3, 44, 97, 97, 50, 63, 31, 25);
    }

    /**
     * @return list<Flyer>
     */
    private static function flyers(FlyerFactory $factory, int $count): array
    {
        return \array_map(static fn(int $i): Flyer => $factory->launch($i), \range(1, $count));
    }

    private static function offScreen(Point $point): bool
    {
        return self::inBounds($point) && ($point->x < 0 || $point->x > 100 || $point->y < 0);
    }

    private static function inBounds(Point $point): bool
    {
        return $point->x >= -10 && $point->x <= 110 && $point->y >= -10 && $point->y <= 100;
    }
}
