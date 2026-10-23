<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarService;
use App\Application\Chat\ChatService;
use App\Application\Config\FlyerConfig;
use App\Application\Config\HellowinConfig;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Player\PlayerCensus;
use App\Application\Player\PlayerService;
use App\Application\Story\CursedService;
use App\Application\Story\DiceService;
use App\Application\Story\EveningTally;
use App\Application\Story\RapierService;
use App\Application\Story\RevealBuilder;
use App\Application\Story\TopService;
use App\Application\Ticker\Ticker;
use App\Application\Walk\ActionStop;
use App\Application\Walk\CentrifugoPresence;
use App\Application\Walk\Presence;
use App\Application\Walk\WalkService;
use App\Domain\Balance\Burst;
use App\Domain\Balance\LimitsStore;
use App\Domain\Bar\BarStore;
use App\Domain\Chat\ChatStore;
use App\Domain\Clock\Clock;
use App\Domain\Clock\StartTime;
use App\Domain\Clock\SystemClock;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\ZombieCrowd;
use App\Domain\Flyer\ZombieFactory;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerStore;
use App\Domain\RateLimit\RateLimiter;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\BossStore;
use App\Domain\Story\CursedStore;
use App\Domain\Story\DiceRoller;
use App\Domain\Story\DiceStore;
use App\Domain\Story\ShotTally;
use App\Domain\Story\TopStore;
use App\Domain\Story\UncountedTally;
use App\Domain\Walk\WalkerStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RoadRunner\Centrifugo\CentrifugoApiInterface;
use RoadRunner\Lock\LockInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Broadcasting\BroadcastInterface;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\Idempotency\IdempotencyRegistry;
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
                    $config->getLimit(HellowinConfig::LIMIT_PUMPKIN),
                    $config->getSceneStageMax(),
                    $config->getLimit(HellowinConfig::LIMIT_BONFIRE),
                    $config->getBonfireStageMax(),
                    $config->getLimit(HellowinConfig::LIMIT_CIRCLE),
                    $config->getCircleStages(),
                ),
            Burst::class => static fn(FlyerConfig $flyers): Burst => $flyers->getBurst(),
            LimitsStore::class => static fn(HellowinConfig $config, CacheStorageProviderInterface $cache): LimitsStore
                => new LimitsStore($cache->storage($config->getStorage())),
            SnapshotFactory::class => static fn(
                HellowinConfig $config,
                FlyerConfig $flyers,
                ElementRegistry $elements,
                RitualStore $store,
                RitualPolicy $policy,
                Clock $clock,
                WalkerStore $walkers,
                RevealBuilder $reveal,
                BarService $bar,
                StartTime $startTime,
                CursedService $cursed,
                RapierService $rapier,
                DiceService $dice,
                TopService $top,
                FlyerService $flyerService,
                LimitsService $limits,
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
                $bar,
                $cursed,
                $rapier,
                $dice,
                $top,
                $flyerService,
                $limits,
            ),
            StartTime::class => static fn(CacheStorageProviderInterface $cache, LockInterface $lock, Clock $clock): StartTime
                => new StartTime($cache->storage('process'), new Locks($lock), $clock),
            // Wait under a second for a bucket's lock: it is held for one read and one write
            RateLimiter::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
                Clock $clock,
            ): RateLimiter => new RateLimiter(
                $cache->storage('process'),
                new Locks($lock, ttl: 1.0, wait: 0.5),
                $clock,
                $config->getRpcRates(),
            ),
            ShotTally::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): ShotTally => new ShotTally($cache->storage($config->getStorage()), new Locks($lock)),
            UncountedTally::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): UncountedTally => new UncountedTally($cache->storage($config->getStorage()), new Locks($lock)),
            RevealBuilder::class => static fn(
                ShotTally $shots,
                PlayerStore $players,
                RitualPolicy $policy,
                BarStore $bar,
                CursedStore $cursed,
                BossStore $boss,
                DiceStore $dice,
                UncountedTally $uncounted,
                EveningTally $tally,
            ): RevealBuilder => new RevealBuilder($shots, $players, $policy, $bar, $cursed, $boss, $dice, $uncounted, $tally),
            CursedStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): CursedStore => new CursedStore($cache->storage($config->getStorage()), new Locks($lock)),
            TopStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): TopStore => new TopStore($cache->storage($config->getStorage()), new Locks($lock)),
            TopService::class => static fn(
                HellowinConfig $config,
                TopStore $store,
                RitualStore $ritual,
                ChannelPublisher $publisher,
                Clock $clock,
            ): TopService => new TopService($store, $ritual, $publisher, $clock, $config->getTopBoard()),
            ChatStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): ChatStore => new ChatStore($cache->storage($config->getStorage()), new Locks($lock)),
            ChatService::class => static fn(
                HellowinConfig $config,
                ChatStore $store,
                WalkerStore $walkers,
                PlayerService $players,
                RitualStore $ritual,
                ChannelPublisher $publisher,
                Clock $clock,
            ): ChatService => new ChatService(
                $store,
                $walkers,
                $players,
                $ritual,
                $publisher,
                $clock,
                $config->getChatMax(),
                $config->getChatHistory(),
            ),
            DiceStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): DiceStore => new DiceStore($cache->storage($config->getStorage()), new Locks($lock)),
            DiceRoller::class => static fn(): DiceRoller => new DiceRoller(),
            DiceService::class => static fn(
                HellowinConfig $config,
                DiceStore $store,
                DiceRoller $roller,
                RitualStore $ritual,
                RitualPolicy $policy,
                WalkerStore $walkers,
                PlayerService $players,
                ChannelPublisher $publisher,
                Clock $clock,
                ActionStop $stops,
                IdempotencyRegistry $idempotency,
                EveningTally $tally,
            ): DiceService => new DiceService(
                $store,
                $roller,
                $ritual,
                $policy,
                $walkers,
                $players,
                $publisher,
                $clock,
                $config->getDiceCooldown(),
                $idempotency->get(DiceService::STORAGE),
                $config->getDiceIdempotencyTtl(),
                $stops,
                $tally,
            ),
            BossStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): BossStore => new BossStore($cache->storage($config->getStorage()), new Locks($lock)),
            CursedService::class => static fn(
                HellowinConfig $config,
                CursedStore $store,
                RitualStore $ritual,
                RitualPolicy $policy,
                ChannelPublisher $publisher,
                UncountedTally $uncounted,
            ): CursedService => new CursedService(
                $store,
                $ritual,
                $policy,
                $publisher,
                $config->getLimit(HellowinConfig::LIMIT_DRINKS),
                $config->getLimit(HellowinConfig::LIMIT_ZOMBIES),
                $uncounted,
            ),
            RapierService::class => static fn(
                HellowinConfig $config,
                WalkerStore $walkers,
                RitualStore $ritual,
                BossStore $boss,
                PlayerService $players,
                ChannelPublisher $publisher,
                TransitionQueue $queue,
                Clock $clock,
                ActionStop $stops,
                UncountedTally $uncounted,
            ): RapierService => new RapierService(
                $walkers,
                $ritual,
                $boss,
                $players,
                $publisher,
                $queue,
                $clock,
                $config->getWalkZone(),
                $config->getWalkSpeed(),
                $config->getLimit(HellowinConfig::LIMIT_BOSS),
                stops: $stops,
                uncounted: $uncounted,
            ),
            BarStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): BarStore => new BarStore($cache->storage($config->getStorage()), new Locks($lock)),
            BarService::class => static fn(
                HellowinConfig $config,
                BarStore $bar,
                WalkerStore $walkers,
                RitualStore $ritual,
                RitualPolicy $policy,
                ChannelPublisher $publisher,
                PlayerService $players,
                Clock $clock,
                CursedService $cursed,
                PlayMetrics $metrics,
                UncountedTally $uncounted,
            ): BarService => new BarService(
                $bar,
                $walkers,
                $ritual,
                $policy,
                $publisher,
                $players,
                $clock,
                $config->getBarMugs(),
                $config->getBarPourMs(),
                $config->getLimit(HellowinConfig::LIMIT_BAR),
                $config->getBarRumorChance(),
                cursed: $cursed,
                drinkMs: $config->getDrinkMs(),
                metrics: $metrics,
                uncounted: $uncounted,
            ),
            PlayerCensus::class => static fn(HellowinConfig $config, PlayerStore $players, PlayMetrics $metrics): PlayerCensus
                => new PlayerCensus($players, $metrics, $config->getMugLitres()),
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
                ElementRegistry $elements,
                RitualPolicy $policy,
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
                $elements,
                $config->getClinkGap(),
                $config->getClinkDuration(),
                $config->getWalkReach(),
                bar: $config->getBarLayout(),
                policy: $policy,
                handover: $config->getBeerHandoverMs(),
            ),
            ChannelPublisher::class => static fn(
                HellowinConfig $config,
                BroadcastInterface $broadcast,
            ): ChannelPublisher => new ChannelPublisher($broadcast, $config->getChannel(), $config->getItems()),
            Clock::class => SystemClock::class,
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
                bar: $config->getBarLayout(),
                flyStops: $flyers->getFlyStops(),
                flySit: $flyers->getFlySit(),
                flyLeg: $flyers->getFlyLeg(),
                flyPoints: $flyers->getFlyPoints(),
                dawn: $flyers->getDawnKinds(),
            ),
            ZombieFactory::class => static fn(HellowinConfig $config, FlyerConfig $flyers): ZombieFactory => new ZombieFactory(
                $config->getWalkZone(),
                $flyers->getZombiePoints(),
                \min(1, \count($flyers->getDepths()) - 1),
            ),
            ZombieCrowd::class => static fn(FlyerConfig $flyers): ZombieCrowd
                => new ZombieCrowd($flyers->getZombieMin(), $flyers->getZombiePerPlayer()),
            FlyerStore::class => static fn(
                HellowinConfig $config,
                CacheStorageProviderInterface $cache,
                LockInterface $lock,
            ): FlyerStore => new FlyerStore($cache->storage($config->getStorage()), new Locks($lock)),
            Ticker::class => static fn(
                HellowinConfig $config,
                FlyerConfig $flyerConfig,
                TopService $top,
                PlayerCensus $census,
                FlyerService $flyers,
                BarService $bar,
                WalkService $walks,
                LoggerInterface $logger,
                RapierService $rapier,
                CursedService $cursed,
                LimitsService $limits,
            ): Ticker => new Ticker(
                $top,
                $config->getTopInterval(),
                $flyers,
                $flyerConfig->getInterval(),
                $walks,
                $logger,
                $flyerConfig->getZombieInterval(),
                bar: $bar,
                dawnInterval: $flyerConfig->getDawnInterval(),
                rapier: $rapier,
                cursed: $cursed,
                limits: $limits,
                census: $census,
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
