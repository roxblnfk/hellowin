<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use App\Endpoint\Web\Middleware\LocaleMiddleware;
use App\Endpoint\Web\Middleware\PoweredByMiddleware;
use Spiral\Bootloader\Http\RoutesBootloader as BaseRoutesBootloader;
use Spiral\Debug\StateCollector\HttpCollector;
use Spiral\Http\Middleware\ErrorHandlerMiddleware;
use Spiral\Router\Bootloader\AnnotatedRoutesBootloader;
use Spiral\Router\Loader\Configurator\RoutingConfigurator;

/**
 * @link https://spiral.dev/docs/http-routing
 */
final class RoutesBootloader extends BaseRoutesBootloader
{
    protected const DEPENDENCIES = [AnnotatedRoutesBootloader::class];

    #[\Override]
    protected function globalMiddleware(): array
    {
        return [
            PoweredByMiddleware::class,
            // Outermost but one: error pages are rendered in the visitor's language too
            LocaleMiddleware::class,
            ErrorHandlerMiddleware::class,
            HttpCollector::class,
        ];
    }

    #[\Override]
    protected function middlewareGroups(): array
    {
        return [
            'web' => [],
        ];
    }

    #[\Override]
    protected function defineRoutes(RoutingConfigurator $routes): void {}
}
