<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Flyer;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * A shot with the empty mug in hand throws it at the flyer, hit or miss (docs/spec/protocol.md, «Пустая кружка»).
 */
#[Test]
#[Covers(FlyerService::class)]
#[Covers(ChannelPublisher::class)]
final class MugThrowTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function hitWithTheMugThrowsItBetweenTheStopAndTheShot(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 90);
        self::mug($ritual, self::A);
        $ritual->walks->move(self::A, 80, 90);
        $ritual->clock->now += 500;
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now) ?? Assert::fail('The witch flies.');
        $ritual->broadcast->published = [];

        $points = $ritual->flyers->shoot($flyer->id, self::A);

        Assert::same($points, $flyer->points);
        Assert::same(\array_slice(self::types($ritual), 0, 3), ['move', 'throw', 'shot']);
        Assert::same($ritual->broadcast->ofType('throw'), [
            ['type' => 'throw', 'round' => 1, 'player' => ['id' => self::A], 'flyer' => $flyer->id, 'at' => $ritual->clock->now],
        ]);
        Assert::null($ritual->walkerStore->all()[self::A]->item);
        Assert::same(\array_column($ritual->snapshot->create()['walkers'], 'item', 'id'), [self::A => null]);
        Assert::same($ritual->playerStore->find(self::A)?->flyers, 1);
    }

    public function missedThrowIsToldWithoutAShot(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 90);
        self::mug($ritual, self::A);
        $ritual->broadcast->published = [];

        Assert::null($ritual->flyers->shoot('f-missing', self::A));

        Assert::same(self::types($ritual), ['throw']);
        Assert::same($ritual->broadcast->ofType('throw')[0]['flyer'], 'f-missing');
        Assert::null($ritual->walkerStore->all()[self::A]->item);
    }

    public function shotWithoutTheMugThrowsNothing(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 40, 90);
        self::mug($ritual, self::B);
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now) ?? Assert::fail('The witch flies.');
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot($flyer->id, self::A);
        $ritual->flyers->shoot($flyer->id, null);

        Assert::blank($ritual->broadcast->ofType('throw'));
        Assert::count($ritual->broadcast->ofType('shot'), 1);
        Assert::same($ritual->walkerStore->all()[self::B]->item, Walker::MUG);
    }

    public function mugIsKeptWhereTheSceneHoldsItsOwnItem(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 90);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        self::mug($ritual, self::A);
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot('f-missing', self::A);

        Assert::blank($ritual->broadcast->ofType('throw'));
        Assert::same($ritual->walkerStore->all()[self::A]->item, Walker::MUG);
    }

    private static function bar(): RitualHarness
    {
        return new RitualHarness(stageMax: [0, 1, 2]);
    }

    /**
     * @param non-empty-string $id
     */
    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * @param non-empty-string $id
     */
    private static function mug(RitualHarness $ritual, string $id): void
    {
        $ritual->walkerStore->update(static fn(array $walkers): array => [[$id => $walkers[$id]->drank(0)] + $walkers, null]);
    }

    /**
     * @return list<mixed>
     */
    private static function types(RitualHarness $ritual): array
    {
        return \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
    }
}
