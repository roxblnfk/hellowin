<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Story\DiceRoller;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\ScriptedDice;

#[Test]
#[Covers(DiceRoller::class)]
final class DiceRollerTest
{
    public function throwIsTheNextThreeFacesOfTheDraw(): void
    {
        $roller = new DiceRoller(new Randomizer(new ScriptedDice([3, 6, 1, 6, 6, 6])));

        Assert::same($roller->roll(), [3, 6, 1]);
        Assert::same($roller->roll(), DiceRoller::SIX);
    }

    public function facesAreEvenlySpreadFromOneToSix(): void
    {
        $roller = new DiceRoller(new Randomizer(new Mt19937(17)));
        $counts = \array_fill(1, 6, 0);

        for ($i = 0; $i < 6000; ++$i) {
            foreach ($roller->roll() as $face) {
                ++$counts[$face];
            }
        }

        Assert::same(\array_keys($counts), [1, 2, 3, 4, 5, 6]);
        // 18 000 faces, 3000 expected each; chi-square of five degrees of freedom stays under 20.5 with p = 0.999
        $chi = 0.0;
        foreach ($counts as $count) {
            $chi += ($count - 3000) ** 2 / 3000;
        }
        Assert::float($chi)->lessThan(20.5);
    }

    public function owlNeverRollsSixSixSixBeforeItsThirdThrow(): void
    {
        $roller = new DiceRoller(new Randomizer(new ScriptedDice([6, 6, 6, 6, 6, 6, 2, 6, 6])));

        Assert::same($roller->owl(1), [2, 6, 6]);
        Assert::same($roller->owl(3), DiceRoller::SIX);
    }

    public function owlsFirstTwoThrowsAreNeverSixSixSixOnARealDraw(): void
    {
        $roller = new DiceRoller(new Randomizer(new Mt19937(5)));

        foreach (\range(1, 2000) as $_) {
            Assert::notSame($roller->owl(1), DiceRoller::SIX);
            Assert::notSame($roller->owl(2), DiceRoller::SIX);
        }
    }
}
