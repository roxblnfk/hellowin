<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Walk;

use App\Domain\Flyer\Point;
use App\Domain\Player\Look;
use App\Domain\Walk\Walk;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Walker::class)]
final class WalkerTest
{
    private const ID = 'aaaaaaaaaaaaaaaa';

    public function drunkMugStaysInHandEmpty(): void
    {
        $walker = self::walker()->tookMug(true)->clinkedMug(100);

        $drunk = $walker->drank(1200);

        Assert::same([$drunk->item, $drunk->clinked, $drunk->clinkedUntil, $drunk->busyUntil], [Walker::MUG, false, null, 1200]);
        Assert::true($drunk->hasMug());
        Assert::false($drunk->hasBeer());
    }

    public function emptyMugIsNeitherClinkedNorKeptOverAFreshOne(): void
    {
        $mug = self::walker()->drank(0);

        Assert::same($mug->clinkedMug(500)->clinked, false);
        Assert::same($mug->tookMug(true)->item, Walker::BEER);
        Assert::same($mug->tookMug(false)->item, Walker::MUG);
    }

    public function thrownMugLeavesTheHandsEmpty(): void
    {
        $thrown = self::walker()->drank(0)->threwMug();

        Assert::null($thrown->item);
        Assert::false($thrown->hasMug());
    }

    public function emptyMugSurvivesTheRecord(): void
    {
        $mug = self::walker()->drank(1200);

        $read = Walker::fromArray(self::ID, $mug->toRecord());

        Assert::same($mug->toRecord()['item'] ?? null, 'mug');
        Assert::same($read?->item, Walker::MUG);
        Assert::same($read->toArray($read->item)['item'], 'mug');
    }

    private static function walker(): Walker
    {
        $look = Look::fromArray(['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']);

        return new Walker(self::ID, null, $look ?? Assert::fail('The look is valid.'), Walk::standing(new Point(20, 90), 0), 0);
    }
}
