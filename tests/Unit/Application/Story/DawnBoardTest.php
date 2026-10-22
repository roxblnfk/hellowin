<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Flyer\FlyerService;
use App\Application\Story\DawnService;
use App\Application\Story\StoryService;
use App\Domain\Story\DawnBoard;
use App\Domain\Story\DawnStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The top of the dawn (docs/spec/scenario.md, «Топ-10 рассвета»): flyers shot down over the whole evening, shown live
 * at dawn. The harness has scene 1 as the final one and a board of three rows.
 */
#[Test]
#[Covers(DawnService::class)]
#[Covers(DawnBoard::class)]
#[Covers(DawnStore::class)]
#[Covers(FlyerService::class)]
#[Covers(StoryService::class)]
final class DawnBoardTest
{
    private const ANN = '8a01b2c3d4e5f607';
    private const JACK = '3f9c0a7d12b4e856';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const DIGITS = '1234567890123456';

    public function everyHitAtDawnSendsTheWholeBoardRightAfterTheShot(): void
    {
        $ritual = self::dawn();
        $ritual->broadcast->published = [];

        self::hit($ritual, self::JACK);

        $types = \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
        Assert::same(\array_slice($types, (int) \array_search('shot', $types, true), 2), ['shot', 'dawn']);
        Assert::same($ritual->broadcast->ofType('dawn'), [[
            'type' => 'dawn',
            'round' => 1,
            'board' => [['player' => ['id' => self::JACK, 'name' => 'Jack'], 'shots' => 1]],
        ]]);
    }

    public function moreHitsRankHigherAndOfEqualOnesTheEarlier(): void
    {
        $ritual = self::dawn();

        self::hit($ritual, self::JACK);
        self::hit($ritual, self::ANN);
        self::hit($ritual, self::JILL);
        self::hit($ritual, self::JILL);
        self::hit($ritual, self::ANN);

        $board = $ritual->snapshot->create()['dawn']['board'] ?? [];
        Assert::same(\array_map(static fn(array $row): array => [$row['player']['id'], $row['shots']], $board), [
            [self::JILL, 2],
            [self::ANN, 2],
            [self::JACK, 1],
        ]);
        $events = $ritual->broadcast->ofType('dawn');
        Assert::same(\end($events)['board'] ?? null, $board);
    }

    public function boardIsCappedButCountsEveryone(): void
    {
        $ritual = self::dawn();
        foreach ([self::JACK, self::ANN, self::JILL] as $player) {
            self::hit($ritual, $player);
        }

        self::hit($ritual, self::DIGITS);
        self::hit($ritual, self::DIGITS);

        $board = $ritual->snapshot->create()['dawn']['board'] ?? [];
        Assert::count($board, 3);
        // An id of digits only stays a string id
        Assert::same($board[0]['player'], ['id' => self::DIGITS, 'name' => 'Jack']);
        Assert::count($ritual->dawnStore->get()->players, 4);
    }

    public function hitsBeforeTheDawnCountQuietlyAndTheDawnOpensWithThem(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::hit($ritual, self::JACK);
        self::hit($ritual, self::JACK);
        self::hit($ritual, self::ANN);
        Assert::null($ritual->snapshot->create()['dawn']);
        Assert::blank($ritual->broadcast->ofType('dawn'));

        foreach (\range(1, 7) as $step) {
            $ritual->story->finaleStep($step, force: true);
        }

        $board = [
            ['player' => ['id' => self::JACK, 'name' => 'Jack'], 'shots' => 2],
            ['player' => ['id' => self::ANN, 'name' => 'Jack'], 'shots' => 1],
        ];
        Assert::same($ritual->broadcast->ofType('dawn'), [['type' => 'dawn', 'round' => 1, 'board' => $board]]);
        Assert::same($ritual->snapshot->create()['dawn'], ['board' => $board]);
    }

    public function anonymousHitsDoNotCount(): void
    {
        $ritual = self::dawn();
        $ritual->broadcast->published = [];

        self::hit($ritual, null);

        Assert::blank($ritual->broadcast->ofType('dawn'));
        Assert::same($ritual->snapshot->create()['dawn'], ['board' => []]);
    }

    public function resetAndANewCycleKeepTheBoard(): void
    {
        $ritual = self::dawn();
        self::hit($ritual, self::JACK);

        $ritual->story->nextScene(1, false);
        self::hit($ritual, self::JACK);

        Assert::null($ritual->snapshot->create()['dawn']);
        Assert::same($ritual->dawnStore->get()->board(10), [['player' => ['id' => self::JACK, 'name' => 'Jack'], 'shots' => 2]]);
    }

    public function boardTakesTheRecordsCountSoARepeatNeverCountsTwice(): void
    {
        $board = (new DawnBoard())
            ->reached(self::JACK, 'Jack', 3, 10)
            ->reached(self::ANN, 'Ann', 3, 15)
            ->reached(self::JACK, 'Jacky', 3, 20)
            ->reached(self::JACK, null, 2, 30)
            ->reached(self::JILL, 'Jill', 0, 40);

        Assert::same($board->players, [
            self::JACK => ['shots' => 3, 'at' => 10, 'name' => null],
            self::ANN => ['shots' => 3, 'at' => 15, 'name' => 'Ann'],
        ]);
        Assert::same($board->reached(self::ANN, 'Ann', 4, 50)->board(1), [['player' => ['id' => self::ANN, 'name' => 'Ann'], 'shots' => 4]]);
    }

    public function aRecordOfTheRoundBoardReadsAsTheEveningsOne(): void
    {
        $ritual = new RitualHarness();
        $ritual->storage->set('hellowin:dawn', ['round' => 3, 'players' => [self::JACK => ['shots' => 2, 'at' => 5, 'name' => 'Jack']]]);

        $board = $ritual->dawnStore->update(static fn(DawnBoard $board): DawnBoard => $board->reached(self::ANN, 'Ann', 1, 6));

        Assert::same($board->board(10), [
            ['player' => ['id' => self::JACK, 'name' => 'Jack'], 'shots' => 2],
            ['player' => ['id' => self::ANN, 'name' => 'Ann'], 'shots' => 1],
        ]);
        Assert::same($ritual->storage->get('hellowin:dawn'), $board->toArray());
    }

    private static function dawn(): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        foreach (\range(1, 7) as $step) {
            $ritual->story->finaleStep($step, force: true);
        }

        return $ritual;
    }

    /**
     * @param non-empty-string|null $player
     */
    private static function hit(RitualHarness $ritual, ?string $player): void
    {
        $player === null or $ritual->players->register($player, 'Jack');
        ++$ritual->clock->now;
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now);
        Assert::notNull($ritual->flyers->shoot($flyer?->id ?? '', $player));
    }
}
