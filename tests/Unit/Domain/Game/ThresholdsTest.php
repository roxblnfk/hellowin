<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Game;

use App\Domain\Game\Thresholds;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Thresholds::class)]
final class ThresholdsTest
{
    public function stageIsTheNumberOfPassedThresholds(): void
    {
        $thresholds = new Thresholds([3, 5, 8]);

        Assert::same($thresholds->stageFor(0), 0);
        Assert::same($thresholds->stageFor(2), 0);
        Assert::same($thresholds->stageFor(3), 1);
        Assert::same($thresholds->stageFor(7), 2);
        Assert::same($thresholds->stageFor(8), 3);
        Assert::same($thresholds->stageFor(100), 3);
    }

    public function nextThresholdFallsBackToTheLastOne(): void
    {
        $thresholds = new Thresholds([3, 5, 8]);

        Assert::same($thresholds->nextFor(0), 3);
        Assert::same($thresholds->nextFor(2), 8);
        Assert::same($thresholds->nextFor(3), 8);
    }

    public function rejectsUnsortedThresholds(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new Thresholds([5, 3]);
    }

    public function rejectsEmptyThresholds(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new Thresholds([]);
    }
}
