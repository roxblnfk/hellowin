<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Flyer\FlyerService;
use App\Application\Story\DawnService;
use App\Domain\Story\DawnBoard;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The top of the dawn (docs/spec/scenario.md, «Топ-10 рассвета»): hits on finale step 7 ranked live. The harness has
 * scene 1 as the final one and a board of three rows.
 */
#[Test]
#[Covers(DawnService::class)]
#[Covers(DawnBoard::class)]
#[Covers(FlyerService::class)]
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
        Assert::count($ritual->dawnStore->get(1)->players, 4);
    }

    public function onlyHitsAtDawnByPlayersCount(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::hit($ritual, self::JACK);
        Assert::null($ritual->snapshot->create()['dawn']);
        foreach (\range(1, 7) as $step) {
            $ritual->story->finaleStep($step, force: true);
        }
        Assert::same($ritual->snapshot->create()['dawn'], ['board' => []]);

        self::hit($ritual, null);

        Assert::blank($ritual->broadcast->ofType('dawn'));
        Assert::same($ritual->snapshot->create()['dawn'], ['board' => []]);
    }

    public function resetAndANewCycleClearTheBoard(): void
    {
        $ritual = self::dawn();
        self::hit($ritual, self::JACK);

        $ritual->story->nextScene(1, false);

        Assert::null($ritual->snapshot->create()['dawn']);
        Assert::same($ritual->dawnStore->get(2)->board(10), []);
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
