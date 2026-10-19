<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use App\Application\Config\HellowinConfig;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ClickMetrics;
use App\Domain\Element\ElementRegistry;
use App\Domain\Game\GameState;
use RoadRunner\Lock\LockInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Broadcasting\BroadcastInterface;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\RoadRunnerBridge\RoadRunnerMode;

final class HellowinBootloader extends Bootloader
{
    #[\Override]
    public function defineSingletons(): array
    {
        return [
            ElementRegistry::class => static fn(HellowinConfig $config): ElementRegistry
                => new ElementRegistry($config->getElements()),
            GameState::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): GameState => new GameState(
                $cache->storage($config->getStorage()),
                $lock,
                $config->getClicksPerStage(),
            ),
            ChannelPublisher::class => static fn(
                HellowinConfig $config,
                BroadcastInterface $broadcast,
            ): ChannelPublisher => new ChannelPublisher($broadcast, $config->getChannel()),
        ];
    }

    /**
     * Outside RoadRunner (console, tests) there is no metrics plugin to declare collectors in.
     */
    public function boot(RoadRunnerMode $mode, ClickMetrics $metrics): void
    {
        $mode === RoadRunnerMode::Unknown or $metrics->declare();
    }
}
