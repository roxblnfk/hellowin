<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use App\Application\Config\FlyerConfig;
use App\Application\Config\HellowinConfig;
use App\Application\Config\PlayerConfig;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Player\LeaderboardService;
use App\Application\Story\RevealBuilder;
use App\Application\Ticker\Ticker;
use App\Application\Walk\CentrifugoPresence;
use App\Application\Walk\Presence;
use App\Application\Walk\WalkService;
use App\Domain\Clock\Clock;
use App\Domain\Clock\StartTime;
use App\Domain\Clock\SystemClock;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\ZombieFactory;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\ShotTally;
use App\Domain\Walk\WalkerStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RoadRunner\Centrifugo\CentrifugoApiInterface;
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
                $elements->pumpkinIds(),
                bonfire: $elements->centrepiece(Element::TYPE_BONFIRE)?->id,
                circle: $elements->centrepiece(Element::TYPE_CIRCLE)?->id,
            ),
            PlayerStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): PlayerStore => new PlayerStore($cache->storage($config->getStorage()), new Locks($lock)),
            RitualPolicy::class => static fn(HellowinConfig $config): RitualPolicy
                => new LockstepPolicy(
                    $config->getClicksPerStage(),
                    $config->getSceneStageMax(),
                    $config->getBonfireClicksPerStage(),
                    $config->getBonfireStageMax(),
                    $config->getCircleClicksPerStage(),
                    $config->getCircleStages(),
                ),
            SnapshotFactory::class => static fn(
                HellowinConfig $config,
                FlyerConfig $flyers,
                ElementRegistry $elements,
                RitualStore $store,
                RitualPolicy $policy,
                Clock $clock,
                WalkerStore $walkers,
                RevealBuilder $reveal,
                StartTime $startTime,
            ): SnapshotFactory => new SnapshotFactory(
                $elements,
                $store,
                $policy,
                $config->getBushes(\count($flyers->getDepths())),
                $clock,
                $walkers,
                $config->getItems(),
                $reveal,
                $startTime,
            ),
            StartTime::class => static fn(CacheStorageProviderInterface $cache, LockInterface $lock, Clock $clock): StartTime
                => new StartTime($cache->storage('process'), new Locks($lock), $clock),
            ShotTally::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): ShotTally => new ShotTally($cache->storage($config->getStorage()), new Locks($lock)),
            RevealBuilder::class => static fn(ShotTally $shots, PlayerStore $players, RitualPolicy $policy): RevealBuilder
                => new RevealBuilder($shots, $players, $policy),
            WalkerStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): WalkerStore => new WalkerStore($cache->storage($config->getStorage()), new Locks($lock)),
            Presence::class => static fn(HellowinConfig $config, CentrifugoApiInterface $api): Presence
                => new CentrifugoPresence($api, $config->getChannel()),
            WalkService::class => static fn(
                HellowinConfig $config,
                WalkerStore $walkers,
                PlayerStore $players,
                Presence $presence,
                ChannelPublisher $publisher,
                RitualStore $ritual,
                PlayMetrics $metrics,
                Clock $clock,
            ): WalkService => new WalkService(
                $walkers,
                $config->getWalkZone(),
                $players,
                $presence,
                $publisher,
                $ritual,
                $metrics,
                $clock,
                $config->getWalkSpeed(),
                $config->getWalkLeaveAfter(),
                $config->getWalkSpawn(),
                $config->getItems(),
            ),
            ChannelPublisher::class => static fn(
                HellowinConfig $config,
                BroadcastInterface $broadcast,
            ): ChannelPublisher => new ChannelPublisher($broadcast, $config->getChannel(), $config->getItems()),
            Clock::class => SystemClock::class,
            LeaderboardService::class => static fn(
                HellowinConfig $config,
                PlayerConfig $players,
                PlayerStore $store,
                RitualStore $ritual,
                CacheStorageProviderInterface $cache,
                ChannelPublisher $publisher,
                PlayMetrics $metrics,
                WalkerStore $walkers,
            ): LeaderboardService => new LeaderboardService(
                $store,
                $ritual,
                $cache->storage($config->getStorage()),
                $publisher,
                $metrics,
                $players->getLeaderboardSize(),
                $walkers,
            ),
            FlyerFactory::class => static fn(HellowinConfig $config, FlyerConfig $flyers): FlyerFactory => new FlyerFactory(
                $flyers->getDepths(),
                $config->getBushes(\count($flyers->getDepths())),
                $flyers->getBushChance(),
                $flyers->getArcChance(),
                kinds: $flyers->getKinds(),
                rare: $flyers->getRare(),
                rareChance: $flyers->getRareChance(),
                rarePoints: $flyers->getRarePoints(),
                horizon: $config->getHorizon(),
                lawn: $config->getWalkZone(),
                cursed: $flyers->getCursedKinds(),
                fireflyPoints: $flyers->getFireflyPoints(),
            ),
            ZombieFactory::class => static fn(HellowinConfig $config, FlyerConfig $flyers): ZombieFactory => new ZombieFactory(
                $config->getWalkZone(),
                $flyers->getZombieDuration(),
                $flyers->getZombiePoints(),
                \min(1, \count($flyers->getDepths()) - 1),
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
                WalkService $walks,
                LoggerInterface $logger,
            ): Ticker => new Ticker(
                $leaderboard,
                $players->getLeaderboardInterval(),
                $flyers,
                $flyerConfig->getInterval(),
                $walks,
                $logger,
                $flyerConfig->getZombieInterval(),
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
            // The first worker of the RoadRunner process notes when the server started
            $start = $container->get(StartTime::class);
            \assert($start instanceof StartTime);
            $start->at();
        } catch (\Throwable $e) {
            $logger->warning('Start time is unavailable on boot: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
        try {
            $metrics->state($store->snapshot());
        } catch (\Throwable $e) {
            $logger->warning('Ritual state is unavailable on boot: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
