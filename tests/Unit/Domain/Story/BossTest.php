<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Story\Boss;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Boss::class)]
final class BossTest
{
    public function healingNeverGoesAboveTheMaximum(): void
    {
        $boss = new Boss(48, 50, damage: 2);

        $healed = $boss->healed(3);

        Assert::same([$healed->hp, $healed->healed], [50, 2]);
        Assert::same($healed->healed(3), $healed);
    }

    public function fallenBossIsNotHealed(): void
    {
        $boss = (new Boss(1, 50, damage: 49))->struck();

        Assert::same($boss->healed(3), $boss);
    }

    public function newMaximumKeepsTheDamageAndTheHealing(): void
    {
        $boss = (new Boss(40, 50, damage: 10, awakeAt: 7_000))->healed(3);

        $rescaled = $boss->rescaled(60);

        Assert::same([$rescaled->hp, $rescaled->max, $rescaled->damage, $rescaled->healed, $rescaled->awakeAt], [53, 60, 10, 3, 7_000]);
    }

    public function recordKeepsTheWakingAndTheHealing(): void
    {
        $boss = (new Boss(40, 50, damage: 10, bonus: 5, awakeAt: 7_000))->healed(3);

        Assert::equals(Boss::fromArray($boss->toArray()), $boss);
        Assert::same($boss->toEvent(5000), ['hp' => 43, 'max' => 50, 'burnMs' => 5000, 'awakeAt' => 7_000]);
    }
}
