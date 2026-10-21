<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\ZombieFactory;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(ZombieFactory::class)]
final class ZombieFactoryTest
{
    public function zombieWalksFromThePatchEdgeToTheEdgeOfTheLawn(): void
    {
        $zone = RitualHarness::walkZone();
        $factory = new ZombieFactory($zone, 14000, 2, 1, new Randomizer(new Mt19937(11)));

        for ($i = 0; $i < 200; ++$i) {
            $zombie = $factory->launch(1_000);

            $onPatch = \hypot(($zombie->from->x - $zone->patch()->cx) / $zone->patch()->rx, ($zombie->from->y - $zone->patch()->cy) / $zone->patch()->ry);
            Assert::true(\abs($onPatch - 1.0) < 0.01, 'starts on the patch edge');
            Assert::true($zombie->from->y >= $zone->y1, 'starts inside the lawn');
            Assert::true(
                \in_array($zombie->to->x, [$zone->x1, $zone->x2], true) || \in_array($zombie->to->y, [$zone->y1, $zone->y2], true),
                'ends on the lawn edge',
            );
            Assert::same([$zombie->kind, $zombie->depth, $zombie->points, $zombie->duration], [Flyer::KIND_ZOMBIE, 1, 2, 14000]);
            Assert::null($zombie->via);
            Assert::null($zombie->bush);
            Assert::true($zombie->shootable());
        }
    }
}
