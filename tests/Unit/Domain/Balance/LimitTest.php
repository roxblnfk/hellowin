<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Balance;

use App\Domain\Balance\Burst;
use App\Domain\Balance\Limit;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Limit::class)]
#[Covers(Burst::class)]
final class LimitTest
{
    #[DataSet([0, 1.0, 2], 'nobody counts as one')]
    #[DataSet([1, 1.0, 2], 'one figure is the base')]
    #[DataSet([40, 1.0, 197], 'a crowd')]
    #[DataSet([3, 0.5, 6], 'k scales the sum, rounded up')]
    #[DataSet([1, 0.1, 1], 'never below one')]
    public function formulaIsTheBasePlusEveryOtherFigureTimesK(int $players, float $k, int $expected): void
    {
        Assert::same((new Limit(2, 5))->of($players, $k), $expected);
    }

    public function fractionsRoundUpWithoutFloatingNoise(): void
    {
        Assert::same((new Limit(5, 1.5))->of(2), 7);
        Assert::same((new Limit(10))->of(1, 1.1), 11);
    }

    public function bonusGoesOnTopAndNeverTakesTheLimitBelowOne(): void
    {
        $limit = new Limit(5, 4);

        Assert::same($limit->with(5, 2), 14);
        Assert::same($limit->with(-50, 2), 1);
        Assert::same($limit->moved(0, -50, 2), -8);
        Assert::same($limit->moved(-8, 5, 2), -3);
    }

    public function baseMustBeAboveZero(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new Limit(0);
    }

    #[DataSet([1, 5], 'one figure')]
    #[DataSet([11, 7], 'a fifth more for each other one')]
    #[DataSet([100, 15], 'the most')]
    public function burstGrowsWithTheRoomUpToItsMost(int $players, int $expected): void
    {
        Assert::same((new Burst(5, 0.2, 15, 3000))->count($players), $expected);
    }
}
