<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Http\Middleware\ErrorHandlerMiddleware;
use Spiral\Monolog\Bootloader\MonologBootloader;

/**
 * Application specific log channels. The default channel is set in app/config/monolog.php.
 *
 * @link https://spiral.dev/docs/basics-logging
 */
final class LoggingBootloader extends Bootloader
{
    public function init(MonologBootloader $monolog): void
    {
        // A channel without handlers drops its records; RoadRunner forwards worker stderr to its log
        $monolog->addHandler(
            channel: ErrorHandlerMiddleware::class,
            handler: new StreamHandler('php://stderr', Level::Error),
        );
    }
}
