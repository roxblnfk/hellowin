<?php

declare(strict_types=1);

namespace App\Application;

use Spiral\Boot\Bootloader\CoreBootloader;
use Spiral\Bootloader as Framework;
use Spiral\Bootloader\Http\HttpBootloader;
use Spiral\Broadcasting\Bootloader\BroadcastingBootloader;
use Spiral\Cache\Bootloader\CacheBootloader;
use Spiral\DotEnv\Bootloader\DotenvBootloader;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Bootloader\QueueIdempotencyBootloader;
use Spiral\Monolog\Bootloader\MonologBootloader;
use Spiral\Nyholm\Bootloader\NyholmBootloader;
use Spiral\RoadRunnerBridge\Bootloader as RoadRunnerBridge;
use Spiral\Stempler\Bootloader\StemplerBootloader;
use Spiral\Tokenizer\Bootloader\TokenizerListenerBootloader;
use Spiral\Views\Bootloader\ViewsBootloader;
use Spiral\YiiErrorHandler\Bootloader\YiiErrorHandlerBootloader;

/**
 * @psalm-suppress ClassMustBeFinal
 */
class Kernel extends \Spiral\Framework\Kernel
{
    #[\Override]
    public function defineSystemBootloaders(): array
    {
        return [
            CoreBootloader::class,
            DotenvBootloader::class,
            TokenizerListenerBootloader::class,
        ];
    }

    #[\Override]
    public function defineBootloaders(): array
    {
        return [
            // Logging and exceptions handling
            MonologBootloader::class,
            YiiErrorHandlerBootloader::class,
            Bootloader\ExceptionHandlerBootloader::class,
            Bootloader\LoggingBootloader::class,
            Framework\SnapshotsBootloader::class,

            // RoadRunner
            RoadRunnerBridge\LoggerBootloader::class,
            RoadRunnerBridge\HttpBootloader::class,
            RoadRunnerBridge\MetricsBootloader::class,
            RoadRunnerBridge\LockBootloader::class,
            CacheBootloader::class,
            RoadRunnerBridge\CacheBootloader::class,
            BroadcastingBootloader::class,
            RoadRunnerBridge\CentrifugoBootloader::class,
            RoadRunnerBridge\QueueBootloader::class,

            // Idempotent queue jobs; the Valkey client backs the idempotency storage
            Bootloader\ValkeyBootloader::class,
            IdempotencyBootloader::class,
            QueueIdempotencyBootloader::class,

            // HTTP
            HttpBootloader::class,
            Framework\Http\RouterBootloader::class,
            NyholmBootloader::class,

            // Views
            ViewsBootloader::class,
            // Before Stempler: it mounts the `[[...]]` translation only when the translator is already there
            Bootloader\LocaleBootloader::class,
            StemplerBootloader::class,

            // Console commands
            Framework\CommandBootloader::class,
            RoadRunnerBridge\CommandBootloader::class,

            Bootloader\RoutesBootloader::class,
        ];
    }

    #[\Override]
    public function defineAppBootloaders(): array
    {
        return [
            Bootloader\HellowinBootloader::class,
        ];
    }
}
