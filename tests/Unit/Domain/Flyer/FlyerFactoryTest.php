<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

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

    public function fliesDartAboutTheBarAndSometimesLandOnTheCounter(): void
    {
        $bar = RitualHarness::barLayout();
        $depths = \array_map(static fn(array $d): FlyerDepth => new FlyerDepth(...$d), self::DEPTHS);
        $factory = new FlyerFactory($depths, [new Bush('bush-left', new Point(4, 86), 2)], 1.0, 1.0, new Randomizer(new Mt19937(7)), [['fly'], ['duck']], bar: $bar, flyDuration: 2500, flyPoints: 3);

        $flies = \array_map(static fn(int $_): ?Flyer => $factory->launch(0), \range(1, 200));
        foreach ($flies as $fly) {
            Assert::notNull($fly);
            Assert::same([$fly->kind, $fly->depth, $fly->duration, $fly->points, $fly->bush], ['fly', 1, 2500, 3, null]);
            // Over the floor of the bar, tables or not, never at the screen edge
            foreach ([$fly->from, $fly->to] as $end) {
                Assert::numeric($end->x)->between(6, 91);
                Assert::numeric($end->y)->between(53, 94);
            }
            Assert::numeric(\hypot($fly->to->x - $fly->from->x, $fly->to->y - $fly->from->y))->greaterThanOrEqual(15);
            Assert::numeric($fly->wobble?->amp)->between(3, 6);
            Assert::numeric($fly->wobble?->waves)->between(6, 10);
            if ($fly->perch) {
                Assert::numeric($fly->via?->x)->between(66, 92);
                Assert::numeric($fly->via?->y)->between(52, 58);
            } else {
                Assert::null($fly->via);
            }
            Assert::equals(Flyer::fromArray($fly->toArray()), $fly);
        }
        $perched = \count(\array_filter($flies, static fn(?Flyer $f): bool => $f?->perch === true));
        Assert::numeric($perched)->between(30, 90);
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
