<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\Point;
use App\Domain\Flyer\ZombieCrowd;
use App\Domain\Flyer\ZombieFactory;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(ZombieFactory::class)]
#[Covers(ZombieCrowd::class)]
#[Covers(Flyer::class)]
final class ZombieFactoryTest
{
    public function zombieStandsUpOnTheLawnAndWanders(): void
    {
        $zone = RitualHarness::walkZone();
        $factory = new ZombieFactory($zone, 2, 1, new Randomizer(new Mt19937(11)));

        for ($i = 0; $i < 200; ++$i) {
            $zombie = $factory->wander(1_000);

            Assert::true($zombie->from->x >= $zone->x1 && $zombie->from->x <= $zone->x2, 'stands inside the lawn');
            Assert::true($zombie->from->y >= $zone->y1 && $zombie->from->y <= $zone->y2, 'stands inside the lawn');
            Assert::same([$zombie->kind, $zombie->depth, $zombie->points, $zombie->wander], [Flyer::KIND_ZOMBIE, 1, 2, true]);
            Assert::true($zombie->flyingAt(\PHP_INT_MAX), 'never leaves');
        }
    }

    public function wandererEventHasNoFlight(): void
    {
        $zombie = Flyer::wanderer('f-0000beef', 1, new Point(38.5, 81), 2, 1_000);

        Assert::same($zombie->toEvent(), [
            'flyer' => 'f-0000beef',
            'kind' => 'zombie',
            'depth' => 1,
            'from' => ['x' => 38.5, 'y' => 81.0],
            'points' => 2,
            'launchedAt' => 1_000,
            'wander' => true,
        ]);
        Assert::equals(Flyer::fromArray($zombie->toArray()), $zombie);
    }

    public function crowdKeepsItsMinimumAndGrowsWithThePlayersOnTheBeat(): void
    {
        $crowd = new ZombieCrowd(5, 5);

        Assert::same($crowd->wanted(0, 0, false), 5);
        Assert::same($crowd->wanted(3, 4, false), 2, 'the minimum does not wait for the beat');
        Assert::same($crowd->wanted(5, 4, false), 0);
        Assert::same($crowd->wanted(5, 4, true), 1);
        Assert::same($crowd->wanted(19, 4, true), 1);
        Assert::same($crowd->wanted(20, 4, true), 0);
        Assert::same($crowd->wanted(20, 1, true), 0, 'players who left take no zombies with them');
        Assert::same($crowd->wanted(5, 0, true), 0, 'an empty floor still keeps the minimum');
    }
}
