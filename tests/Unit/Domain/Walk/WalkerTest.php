<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Walk;

use App\Domain\Flyer\Point;
use App\Domain\Player\Look;
use App\Domain\Walk\Clink;
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

    public function everyNewWalkTurnsThePumpkinBack(): void
    {
        $pumpkin = self::walker()->disguised(7);
        $walk = Walk::standing(new Point(30, 80), 100);
        $clink = new Clink(Clink::WALKER, 'bbbbbbbbbbbbbbbb', 900);
        $going = self::walker()->walking(Walk::along([new Point(20, 90), new Point(60, 90)], 0, 10.0));

        $walks = [
            'move' => $pumpkin->walking($walk),
            'clink' => $pumpkin->clinking($walk, $clink),
            'counter' => $pumpkin->taking($walk, 900),
            'beer' => $pumpkin->taking($walk, 900, 'pumpkin-1'),
            'shamble' => $pumpkin->zombified(0, 50)->shambling($walk, 2000),
            'stop' => $going->disguised(7)->stoppedAt(1000),
        ];

        foreach ($walks as $kind => $walker) {
            Assert::null($walker?->pumpkin, $kind);
        }
        Assert::same($pumpkin->toArray()['pumpkin'] ?? null, 7);
        Assert::false(\array_key_exists('pumpkin', $pumpkin->walking($walk)->toArray()));
    }

    public function pumpkinStaysWhereNoWalkBegins(): void
    {
        $pumpkin = self::walker()->disguised(7);
        $look = $pumpkin->look;

        $kept = [
            'seen' => $pumpkin->seen(500),
            'dressed' => $pumpkin->dressed('Jack', $look, null),
            'zombified' => $pumpkin->zombified(0, 50),
            'placed' => $pumpkin->placed(Walk::standing(new Point(30, 80), 100)),
            'drank' => $pumpkin->tookMug(true)->drank(1200),
            'standing' => $pumpkin->stoppedAt(1000) ?? $pumpkin,
        ];

        foreach ($kept as $kind => $walker) {
            Assert::same($walker->pumpkin, 7, $kind);
        }
        Assert::null($pumpkin->unmasked()->pumpkin);
    }

    public function pumpkinSurvivesTheRecord(): void
    {
        $read = Walker::fromArray(self::ID, self::walker()->disguised(0)->toRecord());

        Assert::same($read?->pumpkin, 0);
        Assert::null(Walker::fromArray(self::ID, ['pumpkin' => -1] + self::walker()->toRecord())?->pumpkin);
    }

    private static function walker(): Walker
    {
        $look = Look::fromArray(['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']);

        return new Walker(self::ID, null, $look ?? Assert::fail('The look is valid.'), Walk::standing(new Point(20, 90), 0), 0);
    }
}
