<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use App\Application\Config\FlyerConfig;
use App\Application\Config\HellowinConfig;
use App\Application\Config\PlayerConfig;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Player\LeaderboardService;
use App\Application\Player\PlayerService;
use App\Application\Ticker\Ticker;
use App\Domain\Clock\Clock;
use App\Domain\Clock\SystemClock;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
            RitualStore::class => static fn(
                HellowinConfig $config,
                ElementRegistry $elements,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): RitualStore => new RitualStore(
                $cache->storage($config->getStorage()),
                $lock,
                \array_map(static fn(Element $element): string => $element->id, $elements->all()),
            ),
            PlayerStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): PlayerStore => new PlayerStore($cache->storage($config->getStorage()), new Locks($lock)),
            RitualPolicy::class => static fn(HellowinConfig $config): RitualPolicy
                => new LockstepPolicy($config->getClicksPerStage(), $config->getSceneStageMax()),
            ClickHandler::class => static fn(
                HellowinConfig $config,
                ElementRegistry $elements,
                RitualStore $store,
                RitualPolicy $policy,
                TransitionQueue $transitions,
                RitualMetrics $metrics,
                ChannelPublisher $publisher,
                PlayerService $players,
            ): ClickHandler => new ClickHandler(
                $elements,
                $store,
                $policy,
                $transitions,
                $metrics,
                $publisher,
                $players,
                $config->getClicksPerStage(),
            ),
            SnapshotFactory::class => static fn(
                HellowinConfig $config,
                FlyerConfig $flyers,
                ElementRegistry $elements,
                RitualStore $store,
                RitualPolicy $policy,
                Clock $clock,
            ): SnapshotFactory => new SnapshotFactory(
                $elements,
                $store,
                $policy,
                $config->getClicksPerStage(),
                $config->getBushes(\count($flyers->getDepths())),
                $clock,
            ),
            ChannelPublisher::class => static fn(
                HellowinConfig $config,
                BroadcastInterface $broadcast,
            ): ChannelPublisher => new ChannelPublisher($broadcast, $config->getChannel()),
            Clock::class => SystemClock::class,
            LeaderboardService::class => static fn(
                HellowinConfig $config,
                PlayerConfig $players,
                PlayerStore $store,
                RitualStore $ritual,
                CacheStorageProviderInterface $cache,
                ChannelPublisher $publisher,
                PlayMetrics $metrics,
            ): LeaderboardService => new LeaderboardService(
                $store,
                $ritual,
                $cache->storage($config->getStorage()),
                $publisher,
                $metrics,
                $players->getLeaderboardSize(),
            ),
            FlyerFactory::class => static fn(HellowinConfig $config, FlyerConfig $flyers): FlyerFactory => new FlyerFactory(
                $flyers->getDepths(),
                $config->getBushes(\count($flyers->getDepths())),
                $flyers->getBushChance(),
                $flyers->getArcChance(),
                kinds: $flyers->getKinds(),
            ),
            FlyerStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): FlyerStore => new FlyerStore($cache->storage($config->getStorage()), new Locks($lock)),
            Ticker::class => static fn(
                PlayerConfig $players,
                FlyerConfig $flyerConfig,
                LeaderboardService $leaderboard,
                FlyerService $flyers,
                LoggerInterface $logger,
            ): Ticker => new Ticker(
                $leaderboard,
                $players->getLeaderboardInterval(),
                $flyers,
                $flyerConfig->getInterval(),
                $logger,
            ),
        ];
    }

    /**
     * Outside RoadRunner (console, tests) there is no metrics plugin to declare collectors in, so the services
     * are resolved only inside RoadRunner. The gauges start from the stored state: it outlives the RoadRunner process.
     */
    public function boot(RoadRunnerMode $mode, ContainerInterface $container): void
    {
        if ($mode === RoadRunnerMode::Unknown) {
            return;
        }

        $metrics = $container->get(RitualMetrics::class);
        $store = $container->get(RitualStore::class);
        $logger = $container->get(LoggerInterface::class);
        \assert($metrics instanceof RitualMetrics && $store instanceof RitualStore && $logger instanceof LoggerInterface);
        $metrics->declare();
        $play = $container->get(PlayMetrics::class);
        \assert($play instanceof PlayMetrics);
        $play->declare();
        try {
            $metrics->state($store->snapshot());
        } catch (\Throwable $e) {
            $logger->warning('Ritual state is unavailable on boot: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
