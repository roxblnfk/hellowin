<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\Job\TransitionScene;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\TransitionHandler;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Player\LeaderboardService;
use App\Application\Player\PlayerService;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\Point;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\RitualStore;
use Psr\Log\NullLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spiral\Cache\Storage\ArrayStorage;

/**
 * The ritual services wired the way HellowinBootloader wires them, over in-memory fakes. Queued
 * jobs stay in {@see $queue} until {@see runJobs()} delivers them.
 */
final class RitualHarness
{
    public readonly SpyBroadcast $broadcast;
    public readonly SpyQueue $queue;
    public readonly SpyMetrics $metrics;
    public readonly RitualStore $store;
    public readonly ClickHandler $clicks;
    public readonly TransitionHandler $transitions;
    public readonly ResetService $reset;
    public readonly SnapshotFactory $snapshot;
    public readonly PlayerStore $playerStore;
    public readonly PlayerService $players;
    public readonly ArrayStorage $storage;
    public readonly ChannelPublisher $publisher;
    public readonly PlayMetrics $playMetrics;
    public readonly LeaderboardService $leaderboard;
    public readonly FrozenClock $clock;
    public readonly FlyerStore $flyerStore;
    public readonly FlyerService $flyers;

    /**
     * @param positive-int $threshold
     * @param non-empty-list<positive-int> $stageMax
     * @param list<non-empty-string> $pumpkins
     */
    public function __construct(
        int $threshold = 2,
        array $stageMax = [1, 2],
        array $pumpkins = ['pumpkin-1', 'pumpkin-2'],
        public readonly FakeLock $lock = new FakeLock(),
    ) {
        $this->broadcast = new SpyBroadcast();
        $this->queue = new SpyQueue();
        $this->metrics = new SpyMetrics();

        $elements = new ElementRegistry(\array_map(
            static fn(string $id): Element => new Element($id, 'pumpkin', 10, 20, ['🎃']),
            $pumpkins,
        ));
        $this->storage = new ArrayStorage();
        $this->store = new RitualStore($this->storage, $this->lock, $pumpkins);
        $this->playerStore = new PlayerStore($this->storage, new Locks($this->lock));
        $this->players = new PlayerService($this->playerStore, new NullLogger());
        $policy = new LockstepPolicy($threshold, $stageMax);
        $metrics = new RitualMetrics($this->metrics, new NullLogger());
        $publisher = new ChannelPublisher($this->broadcast, 'hellowin');
        $queue = new TransitionQueue($this->queue, $metrics);

        $this->clicks = new ClickHandler($elements, $this->store, $policy, $queue, $metrics, $publisher, $this->players, $threshold);
        $this->transitions = new TransitionHandler($this->store, $policy, $queue, $publisher, $metrics, $this->players);
        $this->reset = new ResetService($this->store, $publisher, $metrics);
        $this->snapshot = new SnapshotFactory($elements, $this->store, $policy, $threshold);
        $this->publisher = $publisher;
        $this->playMetrics = new PlayMetrics($this->metrics, new NullLogger());
        $this->leaderboard = new LeaderboardService($this->playerStore, $this->store, $this->storage, $publisher, $this->playMetrics, 3);
        $this->clock = new FrozenClock();
        $this->flyerStore = new FlyerStore($this->storage, new Locks($this->lock));
        $this->flyers = new FlyerService(
            new FlyerFactory(
                [new FlyerDepth(9000, 3), new FlyerDepth(6500, 2), new FlyerDepth(4000, 1)],
                [new Bush('bush-1', new Point(4, 86))],
                0.5,
                0.5,
                new Randomizer(new Mt19937(42)),
            ),
            $this->flyerStore,
            $this->store,
            $publisher,
            $this->playMetrics,
            $this->players,
            $this->clock,
        );
    }

    public function click(string $pumpkin, int $times = 1, ?string $player = null): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->clicks->click($pumpkin, $player);
        }
    }

    /**
     * Delivers every queued job, duplicates included, and the jobs they queue, in push order.
     * There is no idempotency storage here: duplicates reach the handler and meet the stage check.
     */
    public function runJobs(): void
    {
        while ($job = \array_shift($this->queue->pushed)) {
            $payload = $job['payload'];
            match ($job['name']) {
                TransitionPumpkin::class => $this->transitions->pumpkin($payload['round'], $payload['pumpkin'], $payload['toStage'], $payload['player'] ?? null),
                TransitionScene::class => $this->transitions->scene($payload['round'], $payload['toScene']),
            };
        }
    }

    /**
     * Metric values summed per name and labels, e.g. `hellowin_transitions_total{pumpkin}`.
     *
     * @return array<string, float>
     */
    public function counters(): array
    {
        $totals = [];
        foreach ($this->metrics->calls as [$operation, $name, $value, $labels]) {
            $key = $name . '{' . \implode(',', $labels) . '}';
            $totals[$key] = $operation === 'set' ? $value : ($totals[$key] ?? 0) + $value;
        }

        return $totals;
    }
}
