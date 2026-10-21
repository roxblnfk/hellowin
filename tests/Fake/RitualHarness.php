<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Application\Bar\BarService;
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
use App\Application\Story\RevealBuilder;
use App\Application\Story\StoryService;
use App\Application\Walk\WalkService;
use App\Domain\Bar\BarLayout;
use App\Domain\Bar\BarStore;
use App\Domain\Clock\StartTime;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\Point;
use App\Domain\Flyer\ZombieFactory;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\ShotTally;
use App\Domain\Walk\Box;
use App\Domain\Walk\Ellipse;
use App\Domain\Walk\Items;
use App\Domain\Walk\WalkerStore;
use App\Domain\Walk\WalkZone;
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
    public const BONFIRE = 'bonfire';
    public const CIRCLE = 'circle';

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
    public readonly WalkerStore $walkerStore;
    public readonly WalkService $walks;
    public readonly FakePresence $presence;
    public readonly ShotTally $shots;
    public readonly RevealBuilder $reveal;
    public readonly StoryService $story;
    public readonly StartTime $startTime;
    public readonly BarStore $barStore;
    public readonly BarService $bar;

    /**
     * @param positive-int $threshold
     * @param non-empty-list<int<0, max>> $stageMax A leading 0 makes scene 0 the bar.
     * @param list<non-empty-string> $pumpkins
     * @param list<int>|null $bonfire Last bonfire stage by scene; null leaves the patch without a bonfire.
     * @param positive-int $bonfireThreshold
     * @param positive-int|null $circle Last circle stage; null leaves the patch without a circle.
     * @param positive-int $circleThreshold
     * @param list<non-empty-string> $reachable Pumpkins a character can clink with.
     * @param array<non-empty-string, array{float, float}> $positions Pumpkin positions by id; the rest stand at (10, 20).
     */
    public function __construct(
        int $threshold = 2,
        array $stageMax = [1, 2],
        array $pumpkins = ['pumpkin-1', 'pumpkin-2'],
        public readonly FakeLock $lock = new FakeLock(),
        ?array $bonfire = null,
        int $bonfireThreshold = 3,
        ?int $circle = null,
        int $circleThreshold = 3,
        array $reachable = [],
        array $positions = [],
    ) {
        $this->broadcast = new SpyBroadcast();
        $this->queue = new SpyQueue();
        $this->metrics = new SpyMetrics();

        $elements = new ElementRegistry([
            ...\array_map(
                static fn(string $id): Element => new Element(
                    $id,
                    'pumpkin',
                    $positions[$id][0] ?? 10,
                    $positions[$id][1] ?? 20,
                    ['🎃'],
                    reachable: \in_array($id, $reachable, true),
                ),
                $pumpkins,
            ),
            ...($bonfire === null ? [] : [new Element(self::BONFIRE, Element::TYPE_BONFIRE, 50, 63, ['🔥'], null)]),
            ...($circle === null ? [] : [new Element(self::CIRCLE, Element::TYPE_CIRCLE, 50, 63, ['🩸'], null)]),
        ]);
        $this->storage = new ArrayStorage();
        $this->store = new RitualStore($this->storage, $this->lock, $pumpkins, bonfire: $bonfire === null ? null : self::BONFIRE, circle: $circle === null ? null : self::CIRCLE);
        $this->playerStore = new PlayerStore($this->storage, new Locks($this->lock));
        $this->players = new PlayerService($this->playerStore, new NullLogger());
        $policy = new LockstepPolicy($threshold, $stageMax, $bonfireThreshold, $bonfire ?? [], $circleThreshold, $circle ?? 1);
        $metrics = new RitualMetrics($this->metrics, new NullLogger());
        $publisher = new ChannelPublisher($this->broadcast, 'hellowin', new Items([null, 'torch', 'pitchfork'], [5 => 'beer']));
        $queue = new TransitionQueue($this->queue, $metrics);

        $this->clicks = new ClickHandler($elements, $this->store, $policy, $queue, $metrics, $publisher, $this->players);
        $this->clock = new FrozenClock();
        $bushes = [new Bush('bush-1', new Point(4, 86), 2)];
        $this->walkerStore = new WalkerStore($this->storage, new Locks($this->lock));
        $this->shots = new ShotTally($this->storage, new Locks($this->lock));
        $this->barStore = new BarStore($this->storage, new Locks($this->lock));
        $this->bar = new BarService($this->barStore, $this->walkerStore, $this->store, $policy, $publisher, $this->players, $this->clock, 3, 2500, 4, 0.5, new Randomizer(new Mt19937(11)));
        $this->reveal = new RevealBuilder($this->shots, $this->playerStore, $policy, $this->barStore);
        $this->snapshot = new SnapshotFactory($elements, $this->store, $policy, $bushes, $this->clock, $this->walkerStore, new Items([null, 'torch', 'pitchfork'], [5 => 'beer']), $this->reveal, $this->startTime = new StartTime(new ArrayStorage(), new Locks($this->lock), $this->clock), $this->bar);
        $this->publisher = $publisher;
        $this->playMetrics = new PlayMetrics($this->metrics, new NullLogger());
        $this->leaderboard = new LeaderboardService($this->playerStore, $this->store, $this->storage, $publisher, $this->playMetrics, 3, $this->walkerStore);
        $this->flyerStore = new FlyerStore($this->storage, new Locks($this->lock));
        $this->flyers = new FlyerService(
            new FlyerFactory(
                [new FlyerDepth(9000, 3), new FlyerDepth(6500, 2), new FlyerDepth(4000, 1)],
                $bushes,
                0.5,
                0.5,
                new Randomizer(new Mt19937(42)),
                [[Flyer::KIND_DUCK], ['bat'], [Flyer::KIND_WORM]],
                lawn: self::walkZone(),
                cursed: [Flyer::KIND_FIREFLY],
                fireflyPoints: 1,
            ),
            $this->flyerStore,
            $this->store,
            $publisher,
            $this->playMetrics,
            $this->players,
            $this->clock,
            $this->shots,
            new NullLogger(),
            new ZombieFactory(self::walkZone(), 14000, 2, 1, new Randomizer(new Mt19937(3))),
        );
        $this->presence = new FakePresence();
        $this->walks = new WalkService(
            $this->walkerStore,
            self::walkZone(),
            $this->playerStore,
            $this->presence,
            $publisher,
            $this->store,
            $this->playMetrics,
            $this->clock,
            10.0,
            5000,
            [86.0, 90.0],
            new Items([null, 'torch', 'pitchfork'], [5 => 'beer']),
            $elements,
            4.0,
            3200,
            10.0,
            new Randomizer(new Mt19937(7)),
            bar: ($stageMax[0] ?? null) === 0 ? self::barLayout() : null,
            policy: $policy,
        );
        $this->transitions = new TransitionHandler($this->store, $policy, $publisher, $metrics, $this->players, $this->walks, bar: $this->bar);
        $this->reset = new ResetService($this->store, $publisher, $metrics, $this->walks, $this->bar);
        $this->story = new StoryService($this->store, $policy, $queue, $publisher, $this->reveal, $this->walks);
    }

    /**
     * The lawn of app/config/hellowin.php.
     */
    public static function walkZone(): WalkZone
    {
        return WalkZone::lawn(3, 44, 97, 97, 50, 63, 31, 25);
    }

    /**
     * The bar of app/config/hellowin.php: the floor, the counter at the back on the right, three tables.
     */
    public static function barLayout(): BarLayout
    {
        return new BarLayout(
            new WalkZone(
                3,
                50,
                97,
                97,
                new Box(64, 46, 100, 60),
                new Ellipse(22, 64, 10, 4.5),
                new Ellipse(44, 78, 10, 4.5),
                new Ellipse(76, 80, 10, 4.5),
            ),
            [66.0, 95.0, 62.0],
            [90.0, 94.0],
            10.0,
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
