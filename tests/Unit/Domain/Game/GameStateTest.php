<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Game;

use App\Domain\Game\ClickResult;
use App\Domain\Game\GameBusyException;
use App\Domain\Game\GameState;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(GameState::class)]
final class GameStateTest
{
    public function countsClicks(): void
    {
        $state = self::state(10);

        $state->registerClick();
        $result = $state->registerClick();

        Assert::same($result->clicks, 2);
        Assert::same($result->stage, 0);
        Assert::false($result->stageChanged);
        Assert::same($result->threshold, 10);
    }

    public function switchesStageOnTheThresholdClick(): void
    {
        $state = self::state(3);

        $results = self::click($state, 4);

        Assert::same(\array_map(static fn(ClickResult $r): bool => $r->stageChanged, $results), [false, false, true, false]);
        Assert::same($results[2]->stage, 1);
        Assert::same($results[3]->stage, 1);
        Assert::same($results[3]->threshold, 3);
    }

    public function stageSwitchesOnlyOnce(): void
    {
        $state = self::state(2);

        $results = self::click($state, 6);

        Assert::same(\array_map(static fn(ClickResult $r): int => $r->stage, $results), [0, 1, 1, 1, 1, 1]);
        Assert::same(\array_map(static fn(ClickResult $r): int => $r->threshold, $results), [2, 2, 2, 2, 2, 2]);
        Assert::same(
            \array_keys(\array_filter(\array_map(static fn(ClickResult $r): bool => $r->stageChanged, $results))),
            [1],
        );
    }

    public function snapshotReflectsStoredState(): void
    {
        $state = self::state(2);
        self::click($state, 3);

        $snapshot = $state->snapshot();

        Assert::same($snapshot->toArray(), ['stage' => 1, 'clicks' => 3, 'threshold' => 2]);
    }

    #[DataSet([0])]
    #[DataSet([-3])]
    public function rejectsNonPositiveThreshold(int $threshold): never
    {
        Expect::exception(\InvalidArgumentException::class);

        self::state($threshold);
    }

    public function snapshotOfAFreshGame(): void
    {
        Assert::same(self::state(7)->snapshot()->toArray(), ['stage' => 0, 'clicks' => 0, 'threshold' => 7]);
    }

    public function releasesTheLockAfterEachClick(): void
    {
        $lock = new FakeLock();
        $state = self::state(10, lock: $lock);

        self::click($state, 3);

        Assert::same($lock->acquired, 3);
        Assert::same($lock->released, 3);
        Assert::blank($lock->held);
    }

    public function failsWhenTheLockIsBusy(): never
    {
        $state = self::state(10, lock: new FakeLock(available: false));

        Expect::exception(GameBusyException::class);

        $state->registerClick();
    }

    private static function state(int $threshold, FakeLock $lock = new FakeLock()): GameState
    {
        return new GameState(new ArrayStorage(), $lock, $threshold);
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
