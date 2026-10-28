<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Player;

use App\Application\Player\StatsResetService;
use App\Application\Story\TopService;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The moderator's reset of the players' statistics (docs/spec/players.md, «Статистика»).
 */
#[Test]
#[Covers(StatsResetService::class)]
#[Covers(PlayerStore::class)]
#[Covers(Player::class)]
#[Covers(TopService::class)]
final class StatsResetTest
{
    private const JACK = '3f9c0a7d12b4e856';
    private const ANN = '8a01b2c3d4e5f607';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function everyCounterGoesToZeroAndTheNameAndLookStay(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->register(self::JACK, 'Jack', self::LOOK);
        $ritual->walks->enter($ritual->players->find(self::JACK) ?? throw new \LogicException());
        $ritual->playerStore->update(self::JACK, static fn(Player $player): Player => $player
            ->clicked()->finishedPumpkin()->shotFlyer(3)->drank()->struck()->rolled());
        $ritual->players->clicked(self::ANN);

        Assert::same($ritual->stats->reset(), 2);

        Assert::same($ritual->players->find(self::JACK)?->toArray(), [
            'id' => self::JACK,
            'name' => 'Jack',
            'look' => self::LOOK,
            'clicks' => 0,
            'finished' => 0,
            'flyers' => 0,
            'points' => 0,
            'drinks' => 0,
            'damage' => 0,
            'rolls' => 0,
        ]);
        Assert::same($ritual->players->find(self::ANN)?->clicks, 0);
        Assert::count($ritual->walkerStore->all(), 1);
    }

    public function topEmptiesAtOnceAndTheTickerDoesNotBringItBack(): void
    {
        $ritual = new RitualHarness();
        self::hit($ritual, self::JACK);
        $told = $ritual->top->tell(null);
        Assert::notBlank($told);
        $ritual->broadcast->published = [];

        $ritual->stats->reset();
        $told = $ritual->top->tell($told);
        $ritual->top->tell($told);

        Assert::same($ritual->snapshot->create()['top'], ['board' => []]);
        Assert::same($ritual->broadcast->ofType('top'), [
            ['type' => 'top', 'round' => 1, 'board' => []],
            ['type' => 'top', 'round' => 1, 'board' => []],
        ]);
    }

    public function nextHitAfterTheResetRanksFromZero(): void
    {
        $ritual = new RitualHarness();
        self::hit($ritual, self::JACK);
        self::hit($ritual, self::JACK);
        $ritual->stats->reset();

        self::hit($ritual, self::JACK);

        $points = $ritual->players->find(self::JACK)?->points;
        Assert::same($ritual->snapshot->create()['top']['board'], [['player' => ['id' => self::JACK, 'name' => 'Jack'], 'points' => $points]]);
        Assert::same($ritual->playerStore->find(self::JACK)?->flyers, 1);
    }

    public function repeatZeroesAgainAndCountsTheSame(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->clicked(self::JACK);

        Assert::same($ritual->stats->reset(), 1);
        Assert::same($ritual->stats->reset(), 1);
        Assert::same($ritual->players->find(self::JACK)?->clicks, 0);
    }

    public function busyPlayerIsSkippedAndNotCounted(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->clicked(self::JACK);
        $ritual->players->clicked(self::ANN);
        $ritual->lock->held['player:' . self::ANN] = 'someone-else';

        Assert::same($ritual->stats->reset(), 1);

        Assert::same($ritual->players->find(self::JACK)?->clicks, 0);
        Assert::same($ritual->players->find(self::ANN)?->clicks, 1);
    }

    public function indexedIdWithoutARecordIsNotCountedNorCreated(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->clicked(self::JACK);
        $ritual->storage->set('hellowin:players', [self::JACK, self::ANN]);

        Assert::same($ritual->stats->reset(), 1);
        Assert::null($ritual->players->find(self::ANN));
    }

    /**
     * @param non-empty-string $player
     */
    private static function hit(RitualHarness $ritual, string $player): void
    {
        $ritual->players->find($player) ?? $ritual->players->register($player, 'Jack');
        ++$ritual->clock->now;
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now);
        Assert::notNull($ritual->flyers->shoot($flyer?->id ?? '', $player));
    }
}
