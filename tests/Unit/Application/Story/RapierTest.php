<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Bar\BarRefusedException;
use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\Job\TransitionFinale;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Story\CursedService;
use App\Application\Story\RapierService;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Application\Walk\ClinkRefusedException;
use App\Application\Walk\WalkService;
use App\Application\Walk\ZombifiedException;
use App\Domain\Walk\Clink;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The end of the night (docs/spec/scenario.md, «Рапира»): the goal of the cursed world, the word, zombie players and
 * their cure, FrankenPHP as a boss and the dawn. The harness has scene 1 as the final one, two cursed mugs and zombies
 * per player, and FrankenPHP of 40 health plus 5 per player.
 */
#[Test]
#[Covers(CursedService::class)]
#[Covers(RapierService::class)]
#[Covers(StoryService::class)]
#[Covers(WalkService::class)]
#[Covers(BarService::class)]
#[Covers(FlyerService::class)]
final class RapierTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function cursedWorldHandsBeerOutAndTellsItsGoals(): void
    {
        $ritual = self::finale();
        self::place($ritual, self::JACK, 20, 90);
        self::place($ritual, self::JILL, 60, 90);
        self::step($ritual, 4);
        $ritual->broadcast->published = [];

        $ritual->story->finaleStep(5, force: true);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['finale', 'cursed', 'zombies', 'flyer', 'flyer', 'owl']);
        Assert::same($ritual->broadcast->ofType('finale')[0]['item'], 'beer');
        Assert::same($ritual->broadcast->ofType('cursed')[0], ['type' => 'cursed', 'round' => 1, 'drunk' => 0, 'slain' => 0, 'drinksTarget' => 4, 'zombiesTarget' => 4]);
        Assert::same($ritual->broadcast->ofType('zombies')[0]['on'], true);
        $snapshot = $ritual->snapshot->create();
        Assert::same(\array_column($snapshot['walkers'], 'item'), ['beer', 'beer']);
        Assert::same($snapshot['cursed'], ['drunk' => 0, 'slain' => 0, 'drinksTarget' => 4, 'zombiesTarget' => 4]);
        // The closed circle made the scene ready long ago: now only the goal does
        Assert::false($snapshot['ready']);
        Assert::null($snapshot['boss']);
    }

    public function pumpkinHandsABeerOnArrival(): void
    {
        $ritual = self::cursed();
        self::drinkUp($ritual, self::JILL);
        $ritual->broadcast->published = [];

        $walk = $ritual->walks->toPumpkin(self::JILL, 'pumpkin-2');

        Assert::equals($walk->path[\count($walk->path) - 1]->toArray(), ['x' => 10.0, 'y' => 44.0]);
        Assert::same($ritual->broadcast->ofType('move')[0]['walk']['arrivesAt'], $walk->arrivesAt);
        $ritual->clock->now = $walk->arrivesAt - 1;
        $ritual->walks->beers();
        Assert::blank($ritual->broadcast->ofType('take'));

        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->beers();
        $ritual->walks->beers();

        $until = $walk->arrivesAt + 900;
        Assert::same($ritual->broadcast->ofType('take'), [['type' => 'take', 'round' => 1, 'player' => ['id' => self::JILL], 'pumpkin' => ['id' => 'pumpkin-2'], 'ok' => true, 'until' => $until]]);
        Assert::same(\array_column($ritual->snapshot->create()['walkers'], 'item', 'id')[self::JILL], 'beer');
        // The pumpkin is still handing the mug over
        $ritual->clock->now = $until - 1;
        Assert::same(self::refusal(static fn() => $ritual->walks->toPumpkin(self::JILL, 'pumpkin-2')), 'busy');
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JILL)), 'busy');
        $ritual->clock->now = $until;
        Assert::same(self::refusal(static fn() => $ritual->walks->toPumpkin(self::JILL, 'pumpkin-2')), 'has beer');
    }

    public function beerIsRefusedInOrder(): void
    {
        $ritual = self::cursed();
        Assert::same(self::refusal(static fn() => $ritual->walks->toPumpkin(self::JILL, 'pumpkin-1')), 'has beer');
        self::drinkUp($ritual, self::JILL);

        Assert::same(self::refusal(static fn() => $ritual->walks->toPumpkin(self::JILL, 'pumpkin-1')), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->walks->toPumpkin(self::JILL, RitualHarness::CIRCLE)), 'no target');
        $ritual->story->finaleStep(6, force: true);
        Assert::same(self::refusal(static fn() => $ritual->walks->toPumpkin(self::JILL, 'pumpkin-2')), 'not cursed');
    }

    public function newWalkCallsTheBeerOff(): void
    {
        $ritual = self::cursed();
        self::drinkUp($ritual, self::JILL);
        $walk = $ritual->walks->toPumpkin(self::JILL, 'pumpkin-2');
        $ritual->walks->move(self::JILL, 30, 90);

        $ritual->clock->now = $walk->arrivesAt + 10_000;
        $ritual->walks->beers();

        Assert::blank($ritual->broadcast->ofType('take'));
    }

    public function drinkingCountsTowardsTheGoalAndTheGoalMakesTheWorldReady(): void
    {
        $ritual = self::cursed(alone: true);
        $ritual->broadcast->published = [];

        // Alone on the lawn, the mug needs no clink; one player wants two mugs and two zombies
        $ritual->bar->drink(self::JACK);
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['drink', 'cursed']);
        Assert::same($ritual->broadcast->ofType('cursed')[0]['drunk'], 1);
        Assert::same($ritual->playerStore->find(self::JACK)?->drinks, 1);

        // The sip plays: no second mug, however fast the clicks
        self::refill($ritual, self::JACK);
        $ritual->clock->now += 1199;
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'busy');
        $ritual->clock->now += 1;
        $ritual->bar->drink(self::JACK);
        self::slay($ritual, 2);

        Assert::same(\array_column($ritual->broadcast->ofType('ready'), 'scene'), [1]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['cursed-ready']);
        Assert::true($ritual->snapshot->create()['ready']);
        Assert::same($ritual->snapshot->create()['cursed'], ['drunk' => 2, 'slain' => 2, 'drinksTarget' => 2, 'zombiesTarget' => 2]);

        // Once a round
        self::slay($ritual, 1);
        Assert::count($ritual->broadcast->ofType('ready'), 1);
    }

    public function mugInTheCursedWorldNeedsAClink(): void
    {
        $ritual = self::cursed(reachable: ['pumpkin-2']);

        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'not clinked');

        $walk = $ritual->walks->clink(self::JACK, Clink::PUMPKIN, 'pumpkin-2');
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'busy');
        $ritual->clock->now += 3200;
        $ritual->bar->drink(self::JACK);

        Assert::same($ritual->broadcast->ofType('drink')[0]['player'], ['id' => self::JACK]);
    }

    public function autoClinkCountsInTheCursedWorldToo(): void
    {
        $ritual = self::cursed();
        self::move($ritual, self::JACK, 20, 90);
        self::move($ritual, self::JILL, 22, 90);

        $ritual->walks->clinkAuto(self::JACK, self::JILL);
        $ritual->clock->now += 3000;
        // Clinking the same mug again changes nothing: it is drunk when the first clink has played
        $ritual->walks->clinkAuto(self::JACK, self::JILL);
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'busy');
        $ritual->clock->now += 200;
        $ritual->bar->drink(self::JACK);

        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JILL)), 'not clinked');
    }

    public function goalsFollowThePlayersAndTheModerator(): void
    {
        $ritual = self::cursed();
        $ritual->broadcast->published = [];

        Assert::same($ritual->cursed->moveTarget('drinks', 5), 9);
        Assert::same($ritual->cursed->moveTarget('zombies', -50), 1);
        Assert::same($ritual->cursed->moveTarget('zombies', -5), 1);
        Assert::same(\array_column($ritual->broadcast->ofType('cursed'), 'drinksTarget'), [9, 9]);
        Assert::same(\array_column($ritual->broadcast->ofType('cursed'), 'zombiesTarget'), [4, 1]);

        // A third player comes: the goals grow, and only then the clients hear of it
        $ritual->cursed->follow();
        Assert::count($ritual->broadcast->ofType('cursed'), 2);
        self::place($ritual, 'cccccccccccccccc', 40, 90);
        $ritual->cursed->follow();
        Assert::same(\array_slice($ritual->broadcast->ofType('cursed'), -1)[0]['drinksTarget'], 11);
    }

    public function goalRefusals(): void
    {
        $ritual = self::cursed();

        Assert::same(self::refusal(static fn() => $ritual->cursed->moveTarget('beer', 5)), 'invalid goal');
        Assert::same(self::refusal(static fn() => $ritual->cursed->moveTarget('drinks', '5')), 'invalid delta');
        $ritual->story->finaleStep(6, force: true);
        Assert::same(self::refusal(static fn() => $ritual->cursed->moveTarget('drinks', 5)), 'not cursed');
    }

    public function rapierStepWaitsForTheGoal(): void
    {
        $ritual = self::cursed(alone: true);

        Assert::same(self::refusal(static fn() => $ritual->story->finaleStep(6)), 'not ready');
        $ritual->cursed->moveTarget('drinks', -50);
        $ritual->cursed->moveTarget('zombies', -50);
        self::slay($ritual, 1);
        $ritual->bar->drink(self::JACK);
        $ritual->broadcast->published = [];

        Assert::same($ritual->story->finaleStep(6), 6);
        Assert::same($ritual->broadcast->ofType('finale'), [['type' => 'finale', 'round' => 1, 'step' => 6, 'reveal' => $ritual->broadcast->ofType('finale')[0]['reveal']]]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['finale-6']);
    }

    public function rightWordArmsThePlayerAndWakesTheBoss(): void
    {
        $ritual = self::rapier();
        $ritual->broadcast->published = [];

        Assert::true($ritual->rapier->word(self::JACK, '  Рапиру! '));

        Assert::same($ritual->broadcast->ofType('word'), [['type' => 'word', 'round' => 1, 'player' => ['id' => self::JACK, 'name' => 'Jack'], 'ok' => true]]);
        // 40 + 5 for each of the two players
        Assert::same($ritual->broadcast->ofType('boss'), [['type' => 'boss', 'round' => 1, 'hp' => 50, 'max' => 50]]);
        $snapshot = $ritual->snapshot->create();
        Assert::same(\array_column($snapshot['walkers'], 'item', 'id')[self::JACK], 'rapier');
        Assert::same($snapshot['boss'], ['hp' => 50, 'max' => 50]);
        Assert::same(self::refusal(static fn() => $ritual->rapier->word(self::JACK, 'рапира')), 'has rapier');

        // The second rapier does not wake it again
        $ritual->rapier->word(self::JILL, 'rapira');
        Assert::count($ritual->broadcast->ofType('boss'), 1);
    }

    public function bossHealthFollowsTheMultiplier(): void
    {
        $ritual = self::rapier();
        $ritual->story->thresholds(1.5);

        $ritual->rapier->word(self::JACK, 'rapira');

        Assert::same($ritual->snapshot->create()['boss'], ['hp' => 75, 'max' => 75]);
    }

    public function wrongWordMakesAZombieTheServerWalks(): void
    {
        $ritual = self::rapier();
        $ritual->broadcast->published = [];

        Assert::false($ritual->rapier->word(self::JILL, 'пиво'));

        Assert::same($ritual->broadcast->ofType('word')[0]['ok'], false);
        Assert::blank($ritual->broadcast->ofType('boss'));
        $walkers = \array_column($ritual->snapshot->create()['walkers'], null, 'id');
        Assert::true($walkers[self::JILL]['zombie']);
        Assert::false(isset($walkers[self::JACK]['zombie']));

        foreach ([
            static fn() => $ritual->walks->move(self::JILL, 30, 90),
            static fn() => $ritual->walks->clink(self::JILL, Clink::WALKER, self::JACK),
            static fn() => $ritual->walks->clinkAuto(self::JILL, self::JACK),
            static fn() => $ritual->bar->drink(self::JILL),
            static fn() => $ritual->rapier->word(self::JILL, 'rapira'),
            static fn() => $ritual->rapier->strike(self::JILL),
            static fn() => $ritual->rapier->cure(self::JILL, self::JACK),
        ] as $call) {
            Assert::same(self::refusal($call), 'zombified');
        }
        // A zombie is no one to clink with
        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::JACK, Clink::WALKER, self::JILL)), 'no target');

        // The ticker walks it every few seconds, as an ordinary move
        $ritual->broadcast->published = [];
        $ritual->rapier->shamble();
        Assert::blank($ritual->broadcast->ofType('move'));
        $ritual->clock->now += 4_000;
        $ritual->rapier->shamble();
        $moves = $ritual->broadcast->ofType('move');
        Assert::same(\array_column(\array_column($moves, 'player'), 'id'), [self::JILL]);
        Assert::true(RitualHarness::walkZone()->contains(\App\Domain\Flyer\Point::fromArray(\end($moves[0]['walk']['path']))));
    }

    public function firstCureWins(): void
    {
        $ritual = self::rapier();
        self::place($ritual, 'cccccccccccccccc', 40, 90);
        $ritual->rapier->word(self::JILL, 'пиво');
        $ritual->broadcast->published = [];

        Assert::true($ritual->rapier->cure(self::JACK, self::JILL));
        Assert::false($ritual->rapier->cure('cccccccccccccccc', self::JILL));

        Assert::same($ritual->broadcast->ofType('cure'), [['type' => 'cure', 'round' => 1, 'player' => ['id' => self::JILL, 'name' => 'Jack'], 'by' => ['id' => self::JACK, 'name' => 'Jack']]]);
        Assert::false(isset(\array_column($ritual->snapshot->create()['walkers'], null, 'id')[self::JILL]['zombie']));
        Assert::same(self::refusal(static fn() => $ritual->rapier->cure(self::JACK, self::JACK)), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->rapier->cure(self::JACK, 'dddddddddddddddd')), 'no target');
        // Cured, the player may say the word again
        Assert::true($ritual->rapier->word(self::JILL, 'rapira'));
    }

    public function zombieWhoLeftComesBackAZombie(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JILL, 'пиво');
        $ritual->presence->online = [self::JACK];
        $ritual->clock->now += 10_000;
        $ritual->walks->sync();
        Assert::false(isset($ritual->walkerStore->all()[self::JILL]));

        $ritual->presence->online = [self::JACK, self::JILL];
        $ritual->walks->sync();

        Assert::true($ritual->walkerStore->all()[self::JILL]->zombie());
        Assert::true(\array_slice($ritual->broadcast->ofType('walker'), -1)[0]['walker']['zombie']);
    }

    public function wordAndCureBelongToTheRapierStep(): void
    {
        $ritual = self::cursed();

        Assert::same(self::refusal(static fn() => $ritual->rapier->word(self::JACK, 'rapira')), 'not now');
        Assert::same(self::refusal(static fn() => $ritual->rapier->cure(self::JACK, self::JILL)), 'not now');
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JACK)), 'not now');
        $ritual->story->finaleStep(6, force: true);
        Assert::same(self::refusal(static fn() => $ritual->rapier->word(self::JACK, '   ')), 'invalid text');
        Assert::same(self::refusal(static fn() => $ritual->rapier->word(self::JACK, \str_repeat('я', 41))), 'invalid text');
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JACK)), 'not now');
    }

    public function roomStrikesTheBossDownAndTheDawnComes(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JACK, 'rapira');
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JILL)), 'no rapier');
        $ritual->rapier->tune(-49);
        $ritual->rapier->word(self::JILL, 'пиво');
        $ritual->broadcast->published = [];

        Assert::same($ritual->rapier->strike(self::JACK), 0);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['strike', 'owl']);
        Assert::same($ritual->broadcast->ofType('strike')[0], ['type' => 'strike', 'round' => 1, 'player' => ['id' => self::JACK, 'name' => 'Jack'], 'hp' => 0]);
        Assert::same($ritual->broadcast->ofType('owl')[0], ['type' => 'owl', 'round' => 1, 'line' => 'boss-down', 'params' => ['player' => ['id' => self::JACK, 'name' => 'Jack']]]);
        Assert::same(\array_column($ritual->queue->payloads(TransitionFinale::class), 'key'), ['1:finale:7']);
        Assert::same($ritual->playerStore->find(self::JACK)?->damage, 1);
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JACK)), 'not now');

        $ritual->runJobs();
        $ritual->story->finaleOf(1, 7);

        $finale = $ritual->broadcast->ofType('finale');
        Assert::count($finale, 1);
        Assert::same([$finale[0]['step'], $finale[0]['item'], $finale[0]['reveal']['damage']], [7, 'rapier', 1]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['boss-down', 'finale-7']);
        Assert::same($ritual->counters()[RitualMetrics::TRANSITIONS . '{finale}'], 1.0);
        $snapshot = $ritual->snapshot->create();
        Assert::same(\array_column($snapshot['walkers'], 'item'), ['rapier', 'rapier']);
        Assert::blank(\array_filter(\array_column($snapshot['walkers'], 'zombie')));
        Assert::same($snapshot['boss'], ['hp' => 0, 'max' => 1]);
    }

    public function dawnWaitsForTheBossUnlessForced(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JACK, 'rapira');
        $ritual->broadcast->published = [];

        Assert::same(self::refusal(static fn() => $ritual->story->finaleStep(7)), 'not ready');
        Assert::same($ritual->story->finaleStep(7, force: true), 7);

        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['finale-7']);
        Assert::same($ritual->snapshot->create()['boss'], ['hp' => 0, 'max' => 50]);
        Assert::same(self::refusal(static fn() => $ritual->rapier->tune(10)), 'not now');
    }

    public function moderatorTunesTheBossHealth(): void
    {
        $ritual = self::rapier();
        Assert::same(self::refusal(static fn() => $ritual->rapier->tune(10)), 'not now');
        $ritual->rapier->word(self::JACK, 'rapira');
        $ritual->broadcast->published = [];

        Assert::same($ritual->rapier->tune(10), ['hp' => 60, 'max' => 60]);
        Assert::same($ritual->rapier->tune(-100), ['hp' => 1, 'max' => 1]);
        Assert::same(self::refusal(static fn() => $ritual->rapier->tune('10')), 'invalid delta');
        Assert::same(\array_column($ritual->broadcast->ofType('boss'), 'hp'), [60, 1]);
    }

    public function dawnFliesItsOwnBirdsAndNoZombies(): void
    {
        $ritual = self::rapier();
        $ritual->story->finaleStep(7, force: true);

        Assert::true($ritual->flyers->atDawn());
        Assert::same($ritual->flyers->launch(0)?->kind, 'duck');
        Assert::blank($ritual->flyers->wander(0, true));
    }

    public function resetForgetsTheNight(): void
    {
        $ritual = self::rapier();
        $ritual->cursed->drank(1);
        $ritual->rapier->word(self::JACK, 'rapira');
        $ritual->rapier->word(self::JILL, 'пиво');

        $ritual->reset->reset();

        $snapshot = $ritual->snapshot->create();
        Assert::same([$snapshot['cursed'], $snapshot['boss']], [null, null]);
        Assert::blank(\array_filter(\array_column($snapshot['walkers'], 'zombie')));
        Assert::same(\array_column($snapshot['walkers'], 'item'), [null, null]);
        Assert::equals($ritual->cursedStore->get(2), new \App\Domain\Story\Cursed());
    }

    public function revealCountsTheWholeEvening(): void
    {
        $ritual = self::cursed(alone: true);
        $ritual->bar->drink(self::JACK);
        self::slay($ritual, 3);
        $ritual->story->finaleStep(6, force: true);
        $ritual->rapier->word(self::JACK, 'rapira');
        $ritual->rapier->strike(self::JACK);
        $ritual->rapier->strike(self::JACK);

        $reveal = $ritual->snapshot->create()['finale']['reveal'] ?? [];

        Assert::same([$reveal['drinks'] ?? null, $reveal['slain'] ?? null, $reveal['damage'] ?? null], [1, 3, 2]);
    }

    /**
     * The final scene at its step 0, the circle not drawn.
     */
    private static function finale(): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1, reachable: ['pumpkin-2']);
        $ritual->transitions->scene(1, 1);

        return $ritual;
    }

    /**
     * The cursed world with Jack, and Jill unless `$alone`, on the lawn with their beer.
     *
     * @param list<non-empty-string> $reachable
     */
    private static function cursed(bool $alone = false, array $reachable = ['pumpkin-2']): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1, reachable: $reachable);
        $ritual->transitions->scene(1, 1);
        self::place($ritual, self::JACK, 20, 90);
        $alone or self::place($ritual, self::JILL, 60, 90);
        self::step($ritual, 5);

        return $ritual;
    }

    private static function rapier(): RitualHarness
    {
        $ritual = self::cursed();
        $ritual->story->finaleStep(6, force: true);

        return $ritual;
    }

    private static function step(RitualHarness $ritual, int $last): void
    {
        $from = ($ritual->store->story()->finale ?? 0) + 1;
        for ($step = $from; $step <= $last; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
    }

    /**
     * @param non-empty-string $id
     */
    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        self::move($ritual, $id, $x, $y);
    }

    /**
     * @param non-empty-string $id
     */
    private static function move(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * @param non-empty-string $id
     */
    private static function drinkUp(RitualHarness $ritual, string $id): void
    {
        $ritual->walkerStore->update(static fn(array $walkers): array => [[$id => $walkers[$id]->emptyHanded()] + $walkers, null]);
    }

    /**
     * @param non-empty-string $id
     */
    private static function refill(RitualHarness $ritual, string $id): void
    {
        $ritual->walkerStore->update(static fn(array $walkers): array => [[$id => $walkers[$id]->withBeer()] + $walkers, null]);
    }

    private static function slay(RitualHarness $ritual, int $zombies): void
    {
        for ($i = 0; $i < $zombies; ++$i) {
            $zombie = $ritual->flyerStore->wanderers()[0] ?? null;
            Assert::notNull($ritual->flyers->shoot($zombie?->id ?? '', self::JACK));
            $ritual->flyers->wander($ritual->clock->now, false);
        }
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (StoryRefusedException|ClinkRefusedException|BarRefusedException|ZombifiedException $e) {
            return $e->error;
        }

        return null;
    }
}
