<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Bar;

use App\Application\Bar\BarRefusedException;
use App\Application\Bar\BarService;
use App\Application\Walk\NoCharacterException;
use App\Domain\Bar\Bar;
use App\Domain\Bar\BarStore;
use App\Domain\Flyer\Point;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The bar of scene 0: the owl pours one mug at a time, the first to reach the counter takes the last mug, a clinked mug
 * is drunk towards the bar's goal (docs/spec/scenario.md, «Сцена 0 — «Бар»»). The harness counter holds 3 mugs, pours
 * one in 2500 ms, and the goal is 4 mugs.
 */
#[Test]
#[Covers(BarService::class)]
#[Covers(BarStore::class)]
#[Covers(Bar::class)]
final class BarServiceTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function owlPoursOneMugAtATimeUntilTheCounterIsFull(): void
    {
        $ritual = self::bar();
        $start = $ritual->clock->now;

        $ritual->bar->pour();
        $ritual->bar->pour();

        Assert::count($ritual->broadcast->ofType('pour'), 1);
        $pour = $ritual->broadcast->ofType('pour')[0];
        Assert::same([$pour['since'], $pour['until']], [$start, $start + 2500]);
        Assert::same($ritual->snapshot->create()['bar']['pouring']['until'] ?? null, $start + 2500);

        for ($i = 1; $i <= 4; ++$i) {
            $ritual->clock->now = $start + 2500 * $i;
            $ritual->bar->pour();
        }

        Assert::same(\array_column($ritual->broadcast->ofType('mugs'), 'mugs'), [1, 2, 3]);
        Assert::count($ritual->broadcast->ofType('pour'), 3);
        Assert::same($ritual->snapshot->create()['bar'], ['mugs' => 3, 'pouring' => null, 'drunk' => 0, 'target' => 4]);
    }

    public function owlSometimesMuttersARumourOverThePour(): void
    {
        $ritual = self::bar();
        $start = $ritual->clock->now;
        for ($i = 0; $i < 20; ++$i) {
            $ritual->barStore->update(static fn(Bar $bar): array => [new Bar(), null]);
            $ritual->clock->now = $start + $i;
            $ritual->bar->pour();
        }

        $rumors = \array_column($ritual->broadcast->ofType('pour'), 'rumor');
        Assert::true(\count($rumors) > 0 && \count($rumors) < 20, 'about half of the pours carry a rumour');
        foreach ($rumors as $rumor) {
            Assert::int($rumor)->greaterThanOrEqual(0);
        }
    }

    public function owlDoesNotPourOutsideTheBar(): void
    {
        $ritual = self::bar();
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        $ritual->bar->pour();

        Assert::blank($ritual->broadcast->ofType('pour'));
        Assert::same($ritual->snapshot->create()['bar'], ['mugs' => 0, 'pouring' => null, 'drunk' => 0, 'target' => 4]);
    }

    public function characterWalksToTheCounterAndTakesAMugOnArrival(): void
    {
        $ritual = self::bar(mugs: 2);
        self::place($ritual, self::A, 20, 92);
        $ritual->broadcast->published = [];

        $walk = $ritual->walks->toCounter(self::A);

        Assert::equals($walk->path[\count($walk->path) - 1], new Point(66, 62));
        Assert::equals($ritual->broadcast->ofType('move')[0]['walk'], $walk->toArray());
        $ritual->clock->now = $walk->arrivesAt - 1;
        $ritual->bar->takes();
        Assert::blank($ritual->broadcast->ofType('take'));

        $ritual->clock->now = $walk->arrivesAt;
        $ritual->bar->tick();
        $ritual->bar->tick();

        Assert::same($ritual->broadcast->ofType('take'), [['type' => 'take', 'round' => 1, 'player' => ['id' => self::A], 'ok' => true, 'mugs' => 1]]);
        Assert::same($ritual->walkerStore->all()[self::A]->item, Walker::BEER);
        Assert::same(\array_column($ritual->snapshot->create()['walkers'], 'item', 'id'), [self::A => 'beer']);
        // The counter had room: the owl starts pouring right away
        Assert::count($ritual->broadcast->ofType('pour'), 1);
    }

    public function lastMugGoesToTheFirstToArriveAndTheOtherLeavesEmptyHanded(): void
    {
        $ritual = self::bar(mugs: 1);
        self::place($ritual, self::A, 80, 70);
        self::place($ritual, self::B, 20, 92);
        $near = $ritual->walks->toCounter(self::A);
        $far = $ritual->walks->toCounter(self::B);
        Assert::true($near->arrivesAt < $far->arrivesAt);

        $ritual->clock->now = $far->arrivesAt;
        $ritual->bar->takes();

        Assert::same(\array_map(
            static fn(array $take): array => [$take['player']['id'], $take['ok'], $take['mugs'] ?? null],
            $ritual->broadcast->ofType('take'),
        ), [[self::A, true, 0], [self::B, false, null]]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['bar-empty']);
        Assert::null($ritual->walkerStore->all()[self::B]->item);
        Assert::null($ritual->walkerStore->all()[self::B]->takeAt);
    }

    public function owlSaysTheCounterIsEmptyAtMostOnceInTwentySeconds(): void
    {
        $ritual = self::bar(mugs: 0);
        self::place($ritual, self::A, 70, 70);

        foreach ([0, 5_000, 21_000] as $later) {
            $walk = $ritual->walks->toCounter(self::A);
            $ritual->clock->now = $walk->arrivesAt + $later;
            $ritual->barStore->update(static fn(Bar $bar): array => [new Bar(0, $bar->since, $bar->until, null, $bar->emptyAt), null]);
            $ritual->bar->takes();
        }

        Assert::same(\array_column($ritual->broadcast->ofType('take'), 'ok'), [false, false, false]);
        Assert::count($ritual->broadcast->ofType('owl'), 2);
    }

    public function newWalkCallsTheMugOff(): void
    {
        $ritual = self::bar(mugs: 1);
        self::place($ritual, self::A, 20, 92);
        $walk = $ritual->walks->toCounter(self::A);
        $ritual->walks->move(self::A, 30, 92);

        $ritual->clock->now = $walk->arrivesAt + 60_000;
        $ritual->bar->takes();

        Assert::blank($ritual->broadcast->ofType('take'));
        Assert::null($ritual->walkerStore->all()[self::A]->item);
    }

    public function takeIsRefusedWithBeerInHandOrOutsideTheBar(): void
    {
        $ritual = self::bar(mugs: 1);
        Assert::same(self::refusal(static fn() => $ritual->walks->toCounter(self::A)), 'no character');
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A);

        Assert::same(self::refusal(static fn() => $ritual->walks->toCounter(self::A)), 'has beer');

        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        Assert::same(self::refusal(static fn() => $ritual->walks->toCounter(self::A)), 'not bar');
    }

    public function unclinkedMugIsNotDrunk(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::A)), 'no beer');
        self::give($ritual, self::A);

        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::A)), 'not clinked');
        Assert::blank($ritual->broadcast->ofType('drink'));
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::B)), 'no character');
    }

    public function clinkedMugIsDrunkAndCountsForThePlayerAndTheRoom(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A, clinked: true);

        $ritual->bar->drink(self::A);

        Assert::null($ritual->walkerStore->all()[self::A]->item);
        Assert::false($ritual->walkerStore->all()[self::A]->clinked);
        Assert::same($ritual->broadcast->ofType('drink'), [['type' => 'drink', 'round' => 1, 'player' => ['id' => self::A]]]);
        Assert::same($ritual->broadcast->ofType('drinks'), [['type' => 'drinks', 'round' => 1, 'drunk' => 1, 'target' => 4]]);
        Assert::same($ritual->playerStore->find(self::A)?->drinks, 1);
        Assert::same($ritual->snapshot->create()['bar']['drunk'], 1);
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::A)), 'no beer');
    }

    public function barIsReadyOnceTheRoomHasDrunkItsGoal(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);

        for ($i = 0; $i < 5; ++$i) {
            self::give($ritual, self::A, clinked: true);
            $ritual->bar->drink(self::A);
        }

        Assert::same(\array_column($ritual->broadcast->ofType('drinks'), 'drunk'), [1, 2, 3, 4, 5]);
        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 0]]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['bar-intro']);
        Assert::true($ritual->snapshot->create()['ready']);
    }

    public function moderatorMovesTheGoalAndALowGoalMakesTheBarReady(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A, clinked: true);
        $ritual->bar->drink(self::A);
        $ritual->broadcast->published = [];

        Assert::same($ritual->bar->moveTarget(5), 9);
        Assert::same($ritual->bar->moveTarget(-100), 1);
        Assert::same($ritual->bar->moveTarget(-5), 1);

        Assert::same($ritual->broadcast->ofType('drinks'), [
            ['type' => 'drinks', 'round' => 1, 'drunk' => 1, 'target' => 9],
            ['type' => 'drinks', 'round' => 1, 'drunk' => 1, 'target' => 1],
        ]);
        Assert::count($ritual->broadcast->ofType('ready'), 1);
        Assert::same(self::refusal(static fn() => $ritual->bar->moveTarget('5')), 'invalid delta');
    }

    public function goalMovedBackIsTheConfiguredOneAgain(): void
    {
        $ritual = self::bar();

        $ritual->bar->moveTarget(5);
        Assert::same($ritual->store->story()->barTarget, 9);
        $ritual->bar->moveTarget(-5);

        Assert::null($ritual->store->story()->barTarget);
    }

    public function goalOutlivesTheResetAndTheCountDoesNot(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A, clinked: true);
        $ritual->bar->drink(self::A);
        $ritual->bar->moveTarget(6);

        $ritual->reset->reset();

        Assert::same($ritual->broadcast->ofType('reset')[0]['bar'], ['mugs' => 3, 'pouring' => null, 'drunk' => 0, 'target' => 10]);
        Assert::same($ritual->snapshot->create()['bar'], ['mugs' => 3, 'pouring' => null, 'drunk' => 0, 'target' => 10]);
        Assert::false($ritual->snapshot->create()['ready']);
    }

    public function waveIsOnlyForTheBar(): void
    {
        $ritual = self::bar();
        $ritual->bar->wave();
        $ritual->bar->wave();
        Assert::count($ritual->broadcast->ofType('ready'), 1);

        $ritual->story->nextScene(0, false);
        $ritual->runJobs();
        Assert::same(self::refusal(static fn() => $ritual->bar->wave()), 'not bar');
    }

    public function leavingTheBarBurnsTheMugsOnTheCounterAndInHand(): void
    {
        $ritual = self::bar(mugs: 2);
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A, clinked: true);
        self::place($ritual, self::B, 30, 92);
        $walk = $ritual->walks->toCounter(self::B);

        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        $ritual->clock->now = $walk->arrivesAt + 1000;
        $ritual->bar->tick();

        Assert::same($ritual->barStore->get()->mugs, 0);
        Assert::null($ritual->walkerStore->all()[self::A]->item);
        Assert::null($ritual->walkerStore->all()[self::B]->takeAt);
        Assert::blank($ritual->broadcast->ofType('take'));
        Assert::same($ritual->broadcast->ofType('scene')[0]['walkers'][0]['item'], 'torch');
    }

    public function revealCountsTheMugsDrunkInTheRound(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A, clinked: true);
        $ritual->bar->drink(self::A);

        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->drinks, 1);
    }

    private static function bar(?int $mugs = null): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);
        $mugs === null or $ritual->barStore->update(static fn(Bar $bar): array => [new Bar($mugs), null]);

        return $ritual;
    }

    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * @param non-empty-string $id
     */
    private static function give(RitualHarness $ritual, string $id, bool $clinked = false): void
    {
        $ritual->walkerStore->update(static function (array $walkers) use ($id, $clinked): array {
            $walker = $walkers[$id]->tookMug(true);
            $walkers[$id] = $clinked ? $walker->clinkedMug() : $walker;

            return [$walkers, null];
        });
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (BarRefusedException $e) {
            return $e->error;
        } catch (NoCharacterException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
