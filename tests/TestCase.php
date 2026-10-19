<?php

declare(strict_types=1);

namespace Tests;

use Spiral\Core\Container;
use Spiral\Testing\TestableKernelInterface;
use Spiral\Testing\TestCase as BaseTestCase;
use Tests\App\TestKernel;

abstract class TestCase extends BaseTestCase
{
    public const ENV = [
        'APP_ENV' => 'testing',
        'TOKENIZER_CACHE_TARGETS' => true,
        'TELEMETRY_DRIVER' => 'null',
        'BROADCAST_CONNECTION' => 'null',
    ];

    #[\Override]
    public function createAppInstance(Container $container = new Container()): TestableKernelInterface
    {
        return TestKernel::create(
            directories: $this->defineDirectories(
                $this->rootDirectory(),
            ),
            container: $container,
        );
    }

    #[\Override]
    public function rootDirectory(): string
    {
        return __DIR__ . '/..';
    }

    #[\Override]
    public function defineDirectories(string $root): array
    {
        return [
            'root' => $root,
        ];
    }
}
