<?php

declare(strict_types=1);

namespace Tests;

use Spiral\Core\Container;
use Spiral\Testing\Attribute\Config;
use Spiral\Testing\TestableKernelInterface;
use Spiral\Testing\TestCase as BaseTestCase;
use Spiral\Translator\TranslatorInterface;
use Testo\Lifecycle\BeforeTest;
use Tests\App\TestKernel;

#[Config('session.handler', null)]
abstract class TestCase extends BaseTestCase
{
    public const ENV = [
        'APP_ENV' => 'testing',
        'TOKENIZER_CACHE_TARGETS' => true,
        'TELEMETRY_DRIVER' => 'null',
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

    #[BeforeTest]
    protected function setDefaultLocale(): void
    {
        $container = $this->getContainer();

        if ($container->has(TranslatorInterface::class)) {
            $container->get(TranslatorInterface::class)->setLocale('en');
        }
    }
}
