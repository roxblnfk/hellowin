<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\Point;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

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
        $factory = new FlyerFactory([new FlyerDepth(1000, 1)], [], 0.0, 0.0, new Randomizer(new Mt19937(7)), ['duck', 'chicken']);
        $kinds = \array_unique(\array_map(static fn(Flyer $f): string => $f->kind, self::flyers($factory, 50)));
        \sort($kinds);

        Assert::same($kinds, ['chicken', 'duck']);
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
            [new Bush('bush-left', new Point(4, 86)), new Bush('bush-right', new Point(96, 66))],
            $bushChance,
            $arcChance,
            new Randomizer(new Mt19937(42)),
        );
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
