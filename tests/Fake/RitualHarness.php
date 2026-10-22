<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarService;
use App\Application\Chat\ChatService;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\Job\TransitionFinale;
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
use App\Application\Story\CursedService;
use App\Application\Story\DawnService;
use App\Application\Story\DiceService;
use App\Application\Story\RapierService;
use App\Application\Story\RevealBuilder;
use App\Application\Story\StoryService;
use App\Application\Walk\ActionStop;
use App\Application\Walk\WalkService;
use App\Domain\Balance\Burst;
use App\Domain\Balance\Limit;
use App\Domain\Balance\LimitsStore;
use App\Domain\Bar\BarLayout;
use App\Domain\Bar\BarStore;
use App\Domain\Chat\ChatStore;
use App\Domain\Clock\StartTime;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\Point;
use App\Domain\Flyer\ZombieCrowd;
use App\Domain\Flyer\ZombieFactory;
use App\Domain\Lock\Locks;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\BossStore;
use App\Domain\Story\CursedStore;
use App\Domain\Story\DawnStore;
use App\Domain\Story\DiceRoller;
use App\Domain\Story\DiceStore;
use App\Domain\Story\ShotTally;
use App\Domain\Story\Story;
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
    public const DICE_SECRET = 'test-dice-secret';
    public const CHAT_MAX = 12;
    public const CHAT_HISTORY = 3;
    public const BURST_COOLDOWN = 3000;

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
    public readonly ActionStop $stops;
    public readonly FakePresence $presence;
    public readonly ShotTally $shots;
    public readonly RevealBuilder $reveal;
    public readonly StoryService $story;
    public readonly StartTime $startTime;
    public readonly BarStore $barStore;
    public readonly BarService $bar;
    public readonly CursedStore $cursedStore;
    public readonly CursedService $cursed;
    public readonly BossStore $bossStore;
    public readonly RapierService $rapier;
    public readonly DiceStore $diceStore;
    public readonly DiceService $dice;
    public readonly DawnStore $dawnStore;
    public readonly DawnService $dawn;
    public readonly ChatStore $chatStore;
    public readonly ChatService $chat;
    public readonly LimitsStore $limitsStore;
    public readonly LimitsService $limits;

    /**
     * @param positive-int $threshold
     * @param non-empty-list<int<0, max>> $stageMax A leading 0 makes scene 0 the bar, with flies for its flyers.
     * @param list<non-empty-string> $pumpkins
     * @param list<int>|null $bonfire Last bonfire stage by scene; null leaves the patch without a bonfire.
     * @param positive-int $bonfireThreshold
     * @param positive-int|null $circle Last circle stage; null leaves the patch without a circle.
     * @param positive-int $circleThreshold
     * @param list<non-empty-string> $reachable Pumpkins a character can clink with.
     * @param array<non-empty-string, array{float, float}> $positions Pumpkin positions by id; the rest stand at (10, 20).
     * @param positive-int $zombieMin Wandering zombies the cursed world keeps alive.
     * @param positive-int $zombiesPerPlayer Wandering zombies per character the zombie interval tops the crowd up to.
     * @param Limit|null $barDrinks Mugs the bar wants; 4 by default, whoever is on the floor.
     * @param non-empty-list<list<non-empty-string>>|null $flyerKinds Flyer kinds by scene in place of the usual sets.
     * @param Limit|null $pumpkin Clicks a pumpkin stage takes, in place of the flat `$threshold`.
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
        bool $door = false,
        int $zombieMin = 2,
        int $zombiesPerPlayer = 3,
        ?Limit $barDrinks = null,
        ?array $flyerKinds = null,
        ?Limit $pumpkin = null,
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
        $policy = new LockstepPolicy($pumpkin ?? new Limit($threshold), $stageMax, new Limit($bonfireThreshold), $bonfire ?? [], new Limit($circleThreshold), $circle ?? 1);
        $metrics = new RitualMetrics($this->metrics, new NullLogger());
        $publisher = new ChannelPublisher($this->broadcast, 'hellowin', new Items([null, 'torch', 'pitchfork'], [5 => 'beer', 7 => 'rapier']));
        $queue = new TransitionQueue($this->queue, $metrics);

        $this->clock = new FrozenClock();
        $bushes = [new Bush('bush-1', new Point(4, 86), 2)];
        $this->walkerStore = new WalkerStore($this->storage, new Locks($this->lock));
        $this->stops = new ActionStop($this->walkerStore, $this->store, $publisher, $this->clock);
        $this->clicks = new ClickHandler($elements, $this->store, $policy, $queue, $metrics, $publisher, $this->players, stops: $this->stops);
        $this->shots = new ShotTally($this->storage, new Locks($this->lock));
        $this->barStore = new BarStore($this->storage, new Locks($this->lock));
        $this->cursedStore = new CursedStore($this->storage, new Locks($this->lock));
        $this->cursed = new CursedService($this->cursedStore, $this->store, $policy, $publisher, new Limit(2, 2), new Limit(2, 2));
        $this->bossStore = new BossStore($this->storage, new Locks($this->lock));
        $this->rapier = new RapierService($this->walkerStore, $this->store, $this->bossStore, $this->players, $publisher, $queue, $this->clock, self::walkZone(), 10.0, new Limit(45, 5), new Randomizer(new Mt19937(5)), $this->stops);
        $this->bar = new BarService($this->barStore, $this->walkerStore, $this->store, $policy, $publisher, $this->players, $this->clock, 3, 2500, $barDrinks ?? new Limit(4), 0.5, new Randomizer(new Mt19937(11)), $this->cursed);
        $this->diceStore = new DiceStore($this->storage, new Locks($this->lock));
        $this->dice = new DiceService($this->diceStore, DiceRoller::withSecret(self::DICE_SECRET), $this->store, $policy, $this->walkerStore, $this->players, $publisher, $this->clock, 1800, $this->stops);
        $this->dawnStore = new DawnStore($this->storage, new Locks($this->lock));
        $this->dawn = new DawnService($this->dawnStore, $publisher, $this->clock, 3);
        $this->chatStore = new ChatStore($this->storage, new Locks($this->lock));
        $this->chat = new ChatService($this->chatStore, $this->walkerStore, $this->players, $this->store, $publisher, $this->clock, self::CHAT_MAX, self::CHAT_HISTORY);
        $this->reveal = new RevealBuilder($this->shots, $this->playerStore, $policy, $this->barStore, $this->cursedStore, $this->bossStore, $this->diceStore);
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
                $flyerKinds ?? [...(($stageMax[0] ?? null) === 0 ? [[Flyer::KIND_FLY]] : []), [Flyer::KIND_DUCK], ['bat'], [Flyer::KIND_WORM]],
                lawn: self::walkZone(),
                cursed: [Flyer::KIND_FIREFLY],
                fireflyPoints: 1,
                bar: self::barLayout(),
                dawn: [Flyer::KIND_DUCK, 'chicken'],
            ),
            $this->flyerStore,
            $this->store,
            $publisher,
            $this->playMetrics,
            $this->players,
            $this->clock,
            $this->shots,
            new NullLogger(),
            new ZombieFactory(self::walkZone(), 2, 1, new Randomizer(new Mt19937(3))),
            $this->cursed,
            $this->dawn,
            new ZombieCrowd($zombieMin, $zombiesPerPlayer),
            $this->walkerStore,
            $this->stops,
            new Burst(2, 0.5, 4, self::BURST_COOLDOWN),
        );
        $this->limitsStore = new LimitsStore($this->storage);
        $this->limits = new LimitsService($this->store, $policy, $this->limitsStore, $this->walkerStore, $publisher, $queue, $this->clock, $this->flyers, $this->bar, $this->cursed, $this->rapier);
        $this->snapshot = new SnapshotFactory($elements, $this->store, $policy, $bushes, $this->clock, $this->walkerStore, new Items([null, 'torch', 'pitchfork'], [5 => 'beer', 7 => 'rapier']), $this->reveal, $this->startTime = new StartTime(new ArrayStorage(), new Locks($this->lock), $this->clock), $this->bar, $this->cursed, $this->rapier, $this->dice, $this->dawn, $this->flyers, $this->limits);
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
            new Items([null, 'torch', 'pitchfork'], [5 => 'beer', 7 => 'rapier']),
            $elements,
            4.0,
            3200,
            10.0,
            new Randomizer(new Mt19937(7)),
            bar: ($stageMax[0] ?? null) === 0 ? self::barLayout($door) : null,
            policy: $policy,
        );
        $this->transitions = new TransitionHandler($this->store, $policy, $publisher, $metrics, $this->players, $this->walks, bar: $this->bar, limits: $this->limits);
        $this->reset = new ResetService($this->store, $publisher, $metrics, $this->walks, $this->bar, $this->flyers, $this->limits);
        $this->story = new StoryService($this->store, $policy, $queue, $publisher, $this->reveal, $this->walks, $this->reset, $this->cursed, $this->rapier, $metrics, $this->dice, $this->clock, $this->flyers, $this->dawn, $this->limits);
    }

    /**
     * The lawn of app/config/hellowin.php.
     */
    public static function walkZone(): WalkZone
    {
        return WalkZone::lawn(3, 44, 97, 97, 50, 63, 31, 25);
    }

    /**
     * The bar of app/config/hellowin.php: the floor, the counter at the back on the right, three tables; with the door
     * at the back on the left when asked.
     */
    public static function barLayout(bool $door = false): BarLayout
    {
        return new BarLayout(
            new WalkZone(
                3,
                50,
                94,
                97,
                new Box(62.5, 46, 95, 60),
                new Ellipse(22, 64, 13, 7),
                new Ellipse(44, 78, 13, 7),
                new Ellipse(76, 80, 13, 7),
            ),
            [66.0, 92.0, 62.0],
            [90.0, 94.0],
            10.0,
            door: $door ? new Point(12, 50) : null,
            doorIn: 6.0,
            roadrunner: new Point(74, 46),
        );
    }

    /**
     * The limits count the characters on the lawn now, as the ticker does once a count has held: tests that are not
     * about the wait skip it.
     */
    public function countPlayers(): void
    {
        $players = \count($this->walkerStore->all());
        $this->store->updateStory(static fn(Story $story): Story => $story->withPlayers($players));
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
                TransitionFinale::class => $this->story->finaleOf($payload['round'], $payload['toStep']),
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
