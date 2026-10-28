<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ticker;

use App\Application\Hellowin\Job\TransitionScene;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Ticker\Ticker;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\Point;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(Ticker::class)]
final class TickerTest
{
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function firstTickTellsTheTopAndLaunchesNothing(): void
    {
        $ritual = new RitualHarness();

        self::ticker($ritual)->tick(1_000);

        Assert::same($ritual->broadcast->ofType('top'), [['type' => 'top', 'round' => 1, 'board' => []]]);
        Assert::blank($ritual->broadcast->ofType('flyer'));
    }

    public function everyTickDrivesTheAutopilot(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        $ritual->bar->wave();
        $ritual->autopilot->switch(true);
        $at = \max($ritual->owl->until(), $ritual->clock->now) + RitualHarness::AUTO_PAUSE;
        $ticker = new Ticker($ritual->top, 500, $ritual->flyers, [2000, 2000], $ritual->walks, new NullLogger(), autopilot: $ritual->autopilot);

        $ticker->tick($at);

        Assert::same(\array_column($ritual->queue->pushed, 'name'), [TransitionScene::class]);
    }

    public function everyTickLooksForArrivedClinks(): void
    {
        $ritual = new RitualHarness(stageMax: [1], reachable: ['pumpkin-1']);
        $ritual->transitions->scene(1, 1);
        // On the lawn before the cursed world, so with its beer
        $ritual->players->register('aaaaaaaaaaaaaaaa', 'Jack', ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']);
        $ritual->walks->move('aaaaaaaaaaaaaaaa', 20, 90);
        foreach ([1, 2, 3, 4, 5] as $step) {
            $ritual->story->finaleStep($step, force: true);
        }
        $walk = $ritual->walks->clink('aaaaaaaaaaaaaaaa', 'pumpkin', 'pumpkin-1');
        $ticker = self::ticker($ritual);

        $ticker->tick($ritual->clock->now = $walk->arrivesAt - 200);
        Assert::blank($ritual->broadcast->ofType('clink'));
        $ticker->tick($ritual->clock->now = $walk->arrivesAt);
        $ticker->tick($ritual->clock->now += 200);

        Assert::count($ritual->broadcast->ofType('clink'), 1);
    }

    public function topGoesOutOnAChangeAtMostOncePerInterval(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->register('aaaaaaaaaaaaaaaa', 'Jack');
        $ticker = self::ticker($ritual);
        $ticker->tick(1_000);

        $ticker->tick(1_500);
        self::shootAWitch($ritual, 'aaaaaaaaaaaaaaaa');
        self::shootAWitch($ritual, 'aaaaaaaaaaaaaaaa');
        $ticker->tick(1_999);
        Assert::count($ritual->broadcast->ofType('top'), 1, 'unchanged, then within the interval');
        $ticker->tick(2_000);
        $ticker->tick(2_500);

        $tops = $ritual->broadcast->ofType('top');
        Assert::count($tops, 2);
        Assert::same($tops[1]['board'], [['player' => ['id' => 'aaaaaaaaaaaaaaaa', 'name' => 'Jack'], 'points' => $ritual->players->find('aaaaaaaaaaaaaaaa')?->points]]);
        Assert::blank($ritual->broadcast->ofType('leaderboard'));
    }

    public function censusCountsThePlayersForTheGauges(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->register('aaaaaaaaaaaaaaaa', 'Jack');

        self::ticker($ritual)->tick(1_000);

        Assert::equals($ritual->counters()['hellowin_players{}'] ?? null, 1);
    }

    public function censusPoursTheLitresOfAllTheMugsEverDrunk(): void
    {
        $ritual = new RitualHarness();
        $ritual->players->register('aaaaaaaaaaaaaaaa', 'Jack');
        $ritual->players->drank('aaaaaaaaaaaaaaaa');
        $ritual->players->drank('aaaaaaaaaaaaaaaa');
        $ritual->players->drank('bbbbbbbbbbbbbbbb');

        self::ticker($ritual)->tick(1_000);

        Assert::equals($ritual->counters()['hellowin_beer_litres{}'] ?? null, 1.5, 'three half-litre mugs');
    }

    public function flyerIsLaunchedOncePerInterval(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);

        $ticker->tick(0);
        $ticker->tick(1_999);
        $ticker->tick(2_000);
        $ticker->tick(2_100);
        $ticker->tick(4_000);

        Assert::count($ritual->broadcast->ofType('flyer'), 2);
        Assert::count(
            \array_filter($ritual->metrics->calls, static fn(array $call): bool => $call[1] === PlayMetrics::FLYERS_LAUNCHED),
            2,
        );
    }

    public function pauseLeavesTheLaunchesAlone(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);
        $ritual->story->pause(true);

        $ticker->tick(0);
        $ticker->tick(2_000);
        $ticker->tick(4_000);

        Assert::count($ritual->broadcast->ofType('flyer'), 2);
    }

    public function zombiesWanderOnlyInTheCursedWorld(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);

        $ticker->tick(3_000);
        Assert::blank(self::zombies($ritual));

        self::cursed($ritual);
        // The step stands the least crowd up at once, wandering, without a flight
        Assert::count(self::zombies($ritual), 2);
        Assert::same(\array_keys(self::zombies($ritual)[0]), ['type', 'round', 'flyer', 'kind', 'depth', 'from', 'points', 'launchedAt', 'wander']);
        Assert::same(\array_column($ritual->snapshot->create()['flyers'], 'flyer'), \array_column(self::zombies($ritual), 'flyer'));

        // The sweep never takes them
        $ritual->flyers->shoot(self::zombies($ritual)[0]['flyer'], null);
        $ticker->tick(600_000);
        Assert::count(self::zombies($ritual), 3);
        Assert::count($ritual->snapshot->create()['flyers'], 2);
        Assert::count($ritual->flyerStore->wanderers(), 2);
    }

    public function crowdGrowsOnTheBeatUpToThePlayers(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        self::cursed($ritual);
        self::place($ritual, 'aaaaaaaaaaaaaaaa');
        self::place($ritual, 'bbbbbbbbbbbbbbbb');

        // Off the beat the least crowd is enough; two players want six, one each beat
        $ticker->tick(1_000);
        Assert::count(self::zombies($ritual), 2);
        foreach ([3_000, 6_000, 9_000, 12_000, 15_000, 18_000] as $now) {
            $ticker->tick($now);
        }
        Assert::count(self::zombies($ritual), 6);

        // A player leaves: nobody is taken off, nobody stands up
        $ritual->walkerStore->update(static fn(array $walkers): array => [\array_slice($walkers, 0, 1, true), null]);
        $ticker->tick(21_000);
        Assert::count($ritual->snapshot->create()['flyers'], 6);
        Assert::count(self::zombies($ritual), 6);
    }

    public function slainZombieIsReplacedBelowTheMinimum(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        self::cursed($ritual);
        $zombie = self::zombies($ritual)[0];

        $ritual->clock->now = 999_999;
        Assert::same($ritual->flyers->shoot($zombie['flyer'], 'aaaaaaaaaaaaaaaa'), 2);
        Assert::null($ritual->flyers->shoot($zombie['flyer'], 'bbbbbbbbbbbbbbbb'));
        Assert::same($ritual->snapshot->create()['cursed']['slain'] ?? null, 1);
        Assert::count($ritual->snapshot->create()['flyers'], 1);

        // Off the beat: the minimum does not wait for it
        $ticker->tick(1_000);
        Assert::count(self::zombies($ritual), 3);
        Assert::count($ritual->snapshot->create()['flyers'], 2);
    }

    public function pauseLeavesTheCrowdAlone(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ritual->story->pause(true);
        self::cursed($ritual);
        Assert::count(self::zombies($ritual), 2);

        $ritual->flyers->shoot(self::zombies($ritual)[0]['flyer'], null);
        $ticker->tick(3_000);
        Assert::count(self::zombies($ritual), 3);
    }

    public function dawnAndResetTakeTheCrowdOff(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        self::cursed($ritual);
        $ids = \array_column(self::zombies($ritual), 'flyer');
        $ritual->story->finaleStep(6, force: true);
        Assert::count($ritual->snapshot->create()['flyers'], 2);

        $ritual->story->finaleStep(7, force: true);
        $ticker->tick(3_000);

        Assert::same($ritual->broadcast->ofType('gone'), [['type' => 'gone', 'round' => 1, 'flyers' => $ids]]);
        Assert::same($ritual->snapshot->create()['flyers'], []);
        Assert::blank($ritual->flyerStore->wanderers());
        Assert::same(self::escaped($ritual), 0.0);

        // A new pass of the cursed world, then a reset
        $ritual->reset->reset();
        self::cursed($ritual, 2);
        $ritual->reset->reset();

        Assert::count($ritual->broadcast->ofType('gone'), 2);
        Assert::same(\array_slice($ritual->broadcast->ofType('gone'), -1)[0]['round'], 3);
        Assert::blank($ritual->flyerStore->wanderers());
    }

    public function staleZombieOutOfTheCursedWorldIsTakenOffByTheTicker(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->flyerStore->add(Flyer::wanderer('f-0000beef', 1, new Point(50, 80), 2, 0));

        self::ticker($ritual)->tick(0);

        Assert::same(\array_column($ritual->broadcast->ofType('gone'), 'flyers'), [['f-0000beef']]);
        Assert::blank($ritual->flyerStore->wanderers());
    }

    public function flyersFollowTheScene(): void
    {
        $ritual = new RitualHarness();
        $ritual->transitions->scene(1, 1);

        Assert::same($ritual->flyers->launch(0)->kind, 'bat');
    }

    public function wormsCrawlInTheFinalSceneWithoutTheCursedWorld(): void
    {
        $ritual = new RitualHarness();
        $ritual->transitions->scene(1, 1);
        $ritual->transitions->scene(1, 2);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);

        $worm = $ritual->broadcast->ofType('flyer')[0] ?? [];
        Assert::same([$worm['kind'] ?? null, $worm['depth'] ?? null], ['worm', 1]);
        $ritual->clock->now = 3_000;
        Assert::same($ritual->flyers->shoot($worm['flyer'], 'aaaaaaaaaaaaaaaa'), 2);
    }

    public function fliesBuzzAboutTheBar(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);
        $ticker->tick(4_000);

        $flies = $ritual->broadcast->ofType('flyer');
        Assert::count($flies, 2);
        Assert::same(\array_unique(\array_column($flies, 'kind')), ['fly']);
        Assert::same([$flies[0]['depth'] ?? null, \count($flies[0]['stops'] ?? []) >= 2, isset($flies[0]['wobble'])], [1, true, false]);
        $ritual->clock->now = 4_100;
        Assert::same($ritual->flyers->shoot($flies[1]['flyer'], 'aaaaaaaaaaaaaaaa'), 1);
        Assert::null($ritual->flyers->shoot($flies[1]['flyer'], 'bbbbbbbbbbbbbbbb'));
    }

    public function roadrunnerLeavesTheSkyOnceHeComesIntoTheFinale(): void
    {
        $ritual = new RitualHarness(stageMax: [1], flyerRare: [null, 'roadrunner'], flyerRareChance: 1.0);
        $ritual->transitions->scene(1, 1);
        $ritual->story->finaleStep(1, force: true);
        $ritual->story->finaleStep(2, force: true);

        Assert::same($ritual->flyers->launch(0)?->kind, 'roadrunner', 'before his entrance');

        $ritual->story->finaleStep(3, force: true);
        Assert::same($ritual->flyers->launch(0)?->kind, 'bat', 'his entrance');

        $ritual->story->finaleStep(4, force: true);
        Assert::same($ritual->flyers->launch(0)?->kind, 'bat', 'after it');
    }

    public function cursedWorldLaunchesItsOwnSet(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->transitions->scene(1, 1);
        for ($step = 1; $step <= 4; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
        Assert::same($ritual->flyers->launch(0)->kind, 'bat', 'the scene\'s set before the last step');

        $ritual->story->finaleStep(5, force: true);
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);

        $flyers = \array_values(\array_filter($ritual->broadcast->ofType('flyer'), static fn(array $flyer): bool => $flyer['kind'] !== 'zombie'));
        Assert::count($flyers, 2);
        Assert::same([$flyers[1]['kind'] ?? null, $flyers[1]['points'] ?? null], ['firefly', 1]);
    }

    public function escapedFlyerIsCountedOnceAfterItsFlight(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);
        $flyer = $ritual->broadcast->ofType('flyer')[0];
        $gone = 2_000 + $flyer['duration'] + 500;

        $ticker->tick($gone - 1);
        $before = self::escaped($ritual);
        $ticker->tick($gone);
        $ticker->tick($gone + 100);

        Assert::same($before, 0.0);
        Assert::same(self::escaped($ritual), 1.0);
    }

    public function shotFlyerNeverEscapes(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);
        $ritual->clock->now = 2_000;
        $ritual->flyers->shoot($ritual->broadcast->ofType('flyer')[0]['flyer'], null);

        $ticker->tick(60_000);

        Assert::same(self::escaped($ritual), 0.0);
    }

    /**
     * Takes the final scene of `$round` to the cursed world.
     */
    private static function cursed(RitualHarness $ritual, int $round = 1): void
    {
        $ritual->transitions->scene($round, 1);
        for ($step = 1; $step <= 5; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
    }

    /**
     * @param non-empty-string $id
     */
    private static function place(RitualHarness $ritual, string $id): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $ritual->walks->move($id, 20, 90);
    }

    private static function ticker(RitualHarness $ritual): Ticker
    {
        return new Ticker($ritual->top, 500, $ritual->flyers, [2000, 2000], $ritual->walks, new NullLogger(), [3000, 3000], census: $ritual->census);
    }

    /**
     * @param non-empty-string $player
     */
    private static function shootAWitch(RitualHarness $ritual, string $player): void
    {
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now);
        Assert::notNull($ritual->flyers->shoot($flyer?->id ?? '', $player));
    }

    /**
     * @return list<array>
     */
    private static function zombies(RitualHarness $ritual): array
    {
        return \array_values(\array_filter(
            $ritual->broadcast->ofType('flyer'),
            static fn(array $flyer): bool => $flyer['kind'] === 'zombie',
        ));
    }

    private static function escaped(RitualHarness $ritual): float
    {
        $total = 0.0;
        foreach ($ritual->counters() as $key => $value) {
            \str_starts_with($key, PlayMetrics::FLYERS_ESCAPED . '{') and $total += $value;
        }

        return $total;
    }
}
