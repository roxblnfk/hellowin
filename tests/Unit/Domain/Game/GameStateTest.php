<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Game;

use App\Domain\Game\ClickResult;
use App\Domain\Game\GameBusyException;
use App\Domain\Game\GameState;
use App\Domain\Game\Thresholds;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(GameState::class)]
final class GameStateTest
{
    public function countsClicks(): void
    {
        $state = self::state([10]);

        $state->registerClick();
        $result = $state->registerClick();

        Assert::same($result->clicks, 2);
        Assert::same($result->stage, 0);
        Assert::false($result->stageChanged);
        Assert::same($result->threshold, 10);
    }

    public function switchesStageOnTheThresholdClick(): void
    {
        $state = self::state([3]);

        $results = self::click($state, 4);

        Assert::same(\array_map(static fn(ClickResult $r): bool => $r->stageChanged, $results), [false, false, true, false]);
        Assert::same($results[2]->stage, 1);
        Assert::same($results[3]->stage, 1);
        Assert::same($results[3]->threshold, 3);
    }

    public function walksThroughSeveralThresholds(): void
    {
        $state = self::state([2, 4, 5]);

        $results = self::click($state, 6);

        Assert::same(\array_map(static fn(ClickResult $r): int => $r->stage, $results), [0, 1, 1, 2, 3, 3]);
        Assert::same(\array_map(static fn(ClickResult $r): int => $r->threshold, $results), [2, 4, 4, 5, 5, 5]);
        Assert::same(
            \array_keys(\array_filter(\array_map(static fn(ClickResult $r): bool => $r->stageChanged, $results))),
            [1, 3, 4],
        );
    }

    public function snapshotReflectsStoredState(): void
    {
        $state = self::state([2, 4]);
        self::click($state, 3);

        $snapshot = $state->snapshot();

        Assert::same($snapshot->toArray(), ['stage' => 1, 'clicks' => 3, 'threshold' => 4]);
    }

    public function snapshotOfAFreshGame(): void
    {
        Assert::same(self::state([7])->snapshot()->toArray(), ['stage' => 0, 'clicks' => 0, 'threshold' => 7]);
    }

    public function releasesTheLockAfterEachClick(): void
    {
        $lock = new FakeLock();
        $state = self::state([10], $lock);

        self::click($state, 3);

        Assert::same($lock->acquired, 3);
        Assert::same($lock->released, 3);
        Assert::blank($lock->held);
    }

    public function failsWhenTheLockIsBusy(): never
    {
        $state = self::state([10], new FakeLock(available: false));

        Expect::exception(GameBusyException::class);

        $state->registerClick();
    }

    /**
     * @param list<int> $thresholds
     */
    private static function state(array $thresholds, FakeLock $lock = new FakeLock()): GameState
    {
        return new GameState(new ArrayStorage(), $lock, new Thresholds($thresholds));
    }

    /**
     * @return list<ClickResult>
     */
    private static function click(GameState $state, int $times): array
    {
        $results = [];
        for ($i = 0; $i < $times; ++$i) {
            $results[] = $state->registerClick();
        }

        return $results;
    }
}
