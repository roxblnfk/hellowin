<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Bar;

use App\Domain\Bar\Bar;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The counter pours in batches by the free places on it (docs/spec/protocol.md, «Бар», «Розлив»).
 */
#[Test]
#[Covers(Bar::class)]
final class BarTest
{
    #[DataSet([1, 1], 'one free place')]
    #[DataSet([10, 1], 'ten free places')]
    #[DataSet([11, 2], 'eleven free places')]
    #[DataSet([20, 2], 'twenty free places')]
    #[DataSet([21, 3], 'twenty-one free places')]
    #[DataSet([60, 6], 'sixty free places')]
    public function batchGrowsByOneMugPerTenFreePlaces(int $free, int $batch): void
    {
        Assert::same(Bar::batch($free, 10), $batch);
    }

    public function batchNeverExceedsTheFreePlaces(): void
    {
        Assert::same(Bar::batch(3, 1), 3);
    }

    public function pourCarriesItsBatch(): void
    {
        $bar = (new Bar(2))->pour(1_000, 2500, 7, 3);

        Assert::same($bar->toArray(), ['mugs' => 2, 'pouring' => ['since' => 1_000, 'until' => 3_500, 'batch' => 3, 'rumor' => 7]]);
        Assert::equals(Bar::fromArray($bar->toArray()), $bar);
    }

    public function pourStoredBeforeBatchesIsABatchOfOne(): void
    {
        $bar = Bar::fromArray(['mugs' => 4, 'pouring' => ['since' => 1_000, 'until' => 3_500]]);

        Assert::same($bar->batch, 1);
        Assert::same($bar->pouringToArray(), ['since' => 1_000, 'until' => 3_500, 'batch' => 1]);
        Assert::same($bar->placed(60)->mugs, 5);
    }

    public function batchLandsNoHigherThanTheRoom(): void
    {
        $bar = (new Bar(5))->pour(0, 2500, batch: 3);

        Assert::same($bar->placed(10)->mugs, 8);
        Assert::same($bar->placed(6)->mugs, 6);
        Assert::same((new Bar(7))->pour(0, 2500, batch: 2)->placed(6)->mugs, 7, 'a mug already standing stays');
        Assert::same($bar->taken()->placed(10)->mugs, 7, 'a mug taken mid-pour does not change the batch');
    }
}
