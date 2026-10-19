<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Config;

use App\Application\Config\HellowinConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HellowinConfig::class)]
final class HellowinConfigTest
{
    public function clicksPerStageDefaultsToFive(): void
    {
        Assert::same((new HellowinConfig())->getClicksPerStage(), 5);
    }

    #[DataSet([' 12 ', 12], 'env string')]
    #[DataSet([42, 42], 'integer')]
    public function acceptsAPositiveInteger(mixed $value, int $expected): void
    {
        Assert::same((new HellowinConfig(['clicks_per_stage' => $value]))->getClicksPerStage(), $expected);
    }

    #[DataSet(['0'], 'zero')]
    #[DataSet([-5], 'negative')]
    #[DataSet(['2.5'], 'fraction')]
    #[DataSet(['five'], 'word')]
    #[DataSet(['5,10,15'], 'list')]
    #[DataSet([''], 'empty string')]
    #[DataSet([null], 'missing')]
    public function rejectsInvalidClicksPerStage(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('"hellowin.clicks_per_stage" (env HELLOWIN_CLICKS_PER_STAGE) must be a positive integer');

        (new HellowinConfig(['clicks_per_stage' => $value]))->getClicksPerStage();
    }
}
