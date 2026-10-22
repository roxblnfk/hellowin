<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Flyer\FlyerService;
use App\Application\Story\TopService;
use App\Domain\Story\TopBoard;
use App\Domain\Story\TopStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The shooters' top of the evening (docs/spec/protocol.md, «Топ стрелков»): points for flyers shot down over the whole
 * evening, in the snapshot of every scene. The harness has a board of three rows.
 */
#[Test]
#[Covers(TopService::class)]
#[Covers(TopBoard::class)]
#[Covers(TopStore::class)]
#[Covers(FlyerService::class)]
final class TopBoardTest
{
    private const ANN = '8a01b2c3d4e5f607';
    private const JACK = '3f9c0a7d12b4e856';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const DIGITS = '1234567890123456';

    public function hitInAnySceneTakesThePlayersPointsAndSendsNothingByItself(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        $ritual->broadcast->published = [];

        self::hit($ritual, self::JACK);
        self::hit($ritual, self::JACK);

        $points = $ritual->players->find(self::JACK)?->points;
        Assert::int($points)->greaterThan(0);
        Assert::same($ritual->snapshot->create()['top'], ['board' => [['player' => ['id' => self::JACK, 'name' => 'Jack'], 'points' => $points]]]);
        Assert::blank($ritual->broadcast->ofType('top'));
    }

    public function snapshotCarriesTheBoardInEveryScene(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);

        Assert::same($ritual->snapshot->create()['top'], ['board' => []]);
        Assert::false(\array_key_exists('dawn', $ritual->snapshot->create()));
    }

    public function morePointsRankHigherAndOfEqualOnesTheEarlier(): void
    {
        $board = (new TopBoard())
            ->reached(self::JACK, 'Jack', 2, 10)
            ->reached(self::ANN, 'Ann', 5, 20)
            ->reached(self::JILL, 'Jill', 5, 15);

        Assert::same(\array_map(static fn(array $row): array => [$row['player']['id'], $row['points']], $board->board(10)), [
            [self::JILL, 5],
            [self::ANN, 5],
            [self::JACK, 2],
        ]);
    }

    public function boardIsCappedButCountsEveryone(): void
    {
        $ritual = new RitualHarness();
        foreach ([self::JACK, self::ANN, self::JILL] as $player) {
            self::hit($ritual, $player);
        }

        for ($i = 0; $i < 4; ++$i) {
            self::hit($ritual, self::DIGITS);
        }

        $board = $ritual->snapshot->create()['top']['board'];
        Assert::count($board, 3);
        // An id of digits only stays a string id
        Assert::same($board[0]['player'], ['id' => self::DIGITS, 'name' => 'Jack']);
        Assert::count($ritual->topStore->get()->players, 4);
    }

    public function anonymousHitsDoNotCount(): void
    {
        $ritual = new RitualHarness();

        self::hit($ritual, null);

        Assert::same($ritual->snapshot->create()['top'], ['board' => []]);
    }

    public function resetAndANewCycleKeepTheBoard(): void
    {
        $ritual = new RitualHarness();
        self::hit($ritual, self::JACK);
        $board = $ritual->snapshot->create()['top'];

        $ritual->reset->reset(1);
        $ritual->reset->reset(2, again: true);

        Assert::same($ritual->snapshot->create()['top'], $board);
    }

    public function boardTakesTheRecordsScoreSoARepeatNeverCountsTwice(): void
    {
        $board = (new TopBoard())
            ->reached(self::JACK, 'Jack', 3, 10)
            ->reached(self::ANN, 'Ann', 3, 15)
            ->reached(self::JACK, 'Jacky', 3, 20)
            ->reached(self::JACK, null, 2, 30)
            ->reached(self::JILL, 'Jill', 0, 40);

        Assert::same($board->players, [
            self::JACK => ['points' => 3, 'at' => 10, 'name' => null],
            self::ANN => ['points' => 3, 'at' => 15, 'name' => 'Ann'],
        ]);
        Assert::same($board->reached(self::ANN, 'Ann', 4, 50)->board(1), [['player' => ['id' => self::ANN, 'name' => 'Ann'], 'points' => 4]]);
    }

    public function boardGoesOutOnlyWhenItDiffersFromTheOneTold(): void
    {
        $ritual = new RitualHarness();
        self::hit($ritual, self::JACK);
        $ritual->broadcast->published = [];

        $told = $ritual->top->tell(null);
        $again = $ritual->top->tell($told);

        Assert::same($again, $told);
        Assert::same($ritual->broadcast->ofType('top'), [['type' => 'top', 'round' => 1, 'board' => $told]]);
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
