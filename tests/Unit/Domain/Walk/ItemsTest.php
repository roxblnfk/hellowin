<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Walk;

use App\Domain\Walk\Items;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Items::class)]
final class ItemsTest
{
    public function goBySceneOutsideTheFinale(): void
    {
        $items = new Items(['pistol', 'candle'], [0 => 'beer']);

        Assert::same([$items->held(0), $items->held(1), $items->held(2)], ['pistol', 'candle', null]);
    }

    public function finaleStepsBeforeTheFirstEntryKeepTheSceneItem(): void
    {
        $items = new Items(['pistol', 'book'], [5 => 'beer']);

        Assert::same([$items->held(1, 0), $items->held(1, 4)], ['book', 'book']);
    }

    public function anEntryHoldsFromItsStepUntilTheNextOne(): void
    {
        $items = new Items(['pistol', 'book'], [4 => null, 2 => 'broom']);

        Assert::same(
            [$items->held(1, 1), $items->held(1, 2), $items->held(1, 3), $items->held(1, 4), $items->held(1, 9)],
            ['book', 'broom', 'broom', null, null],
        );
    }

    public function cursedWorldHandsOutBeer(): void
    {
        $items = new Items(['pistol', 'book'], [5 => 'beer']);

        Assert::same([$items->held(1, 5), $items->held(1, 6)], ['beer', 'beer']);
    }
}
