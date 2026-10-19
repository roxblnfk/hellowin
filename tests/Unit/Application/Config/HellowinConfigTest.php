<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Config;

use App\Application\Config\HellowinConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HellowinConfig::class)]
final class HellowinConfigTest
{
    public function parsesThresholdsFromAnEnvString(): void
    {
        $config = new HellowinConfig(['thresholds' => ' 10, 20 ,30']);

        Assert::same($config->getThresholds()->values, [10, 20, 30]);
    }

    public function acceptsASingleThreshold(): void
    {
        $config = new HellowinConfig(['thresholds' => 42]);

        Assert::same($config->getThresholds()->values, [42]);
    }
}
