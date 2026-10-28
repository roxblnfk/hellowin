<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Walk;

use App\Domain\Element\Element;
use App\Domain\Flyer\Point;
use App\Domain\Walk\PumpkinStand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(PumpkinStand::class)]
final class PumpkinStandTest
{
    /**
     * The reachable pumpkins of app/config/hellowin.php, as the frontend draws them after the jump.
     *
     * @param 's'|'m'|'l' $size
     */
    #[DataSet(['pumpkin-3', 66.2, 48.3, 's', new Point(77.12, 45.65)], 'right, by the horizon')]
    #[DataSet(['pumpkin-5', 71.2, 58.4, 'm', new Point(84.02, 57.57)], 'right')]
    #[DataSet(['pumpkin-7', 71, 72.8, 'l', new Point(84.78, 74.56)], 'right, large')]
    #[DataSet(['pumpkin-9', 60.7, 80.8, 'm', new Point(71.63, 84)], 'right, below')]
    #[DataSet(['pumpkin-10', 50.4, 82.7, 'l', new Point(60.59, 86.5)], 'right of the root, below, onto the edge of the patch')]
    #[DataSet(['pumpkin-11', 40, 83.7, 'm', new Point(29.2, 87.43)], 'left, below')]
    #[DataSet(['pumpkin-13', 29.3, 78.5, 'l', new Point(15.57, 81.29)], 'left, large')]
    #[DataSet(['pumpkin-15', 27.7, 60.2, 'm', new Point(14.69, 59.7)], 'left')]
    #[DataSet(['pumpkin-17', 35.8, 51.5, 's', new Point(24.36, 48.95)], 'left, into the patch and back onto its edge')]
    public function standsOnTheSideOfTheMugPastTheJumpedPumpkin(string $id, float $x, float $y, string $size, Point $stand): void
    {
        $pumpkin = new Element($id, Element::TYPE_PUMPKIN, $x, $y, ['🎃'], $size, true);

        $at = RitualHarness::pumpkinStand()->at($pumpkin, RitualHarness::walkZone());

        Assert::equals(new Point(\round($at->x, 2), \round($at->y, 2)), $stand);
    }

    public function pumpkinRightAboveTheRootKeepsItsMugByItsId(): void
    {
        $left = new Element('pumpkin-11', Element::TYPE_PUMPKIN, 50, 85, ['🎃'], 'm', true);
        $right = new Element('pumpkin-4', Element::TYPE_PUMPKIN, 50, 85, ['🎃'], 'm', true);

        $leftAt = RitualHarness::pumpkinStand()->at($left, RitualHarness::walkZone());
        $rightAt = RitualHarness::pumpkinStand()->at($right, RitualHarness::walkZone());

        Assert::same([\crc32('pumpkin-11') & 1, \crc32('pumpkin-4') & 1], [1, 0]);
        Assert::same([\round($leftAt->x, 2), \round($leftAt->y, 2)], [41.0, 88.96]);
        Assert::same([\round($rightAt->x, 2), \round($rightAt->y, 2)], [59.0, 88.96]);
    }
}
