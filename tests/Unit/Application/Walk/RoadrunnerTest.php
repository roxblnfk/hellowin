<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Walk;

use App\Application\Bar\BarService;
use App\Application\Hellowin\ResetService;
use App\Application\Walk\ClinkRefusedException;
use App\Application\Walk\WalkService;
use App\Domain\Flyer\Point;
use App\Domain\Story\Story;
use App\Domain\Walk\Clink;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * RoadRunner behind the bar's counter after the dawn (docs/spec/scenario.md, «RoadRunner в баре»): he comes with a new
 * cycle of the evening, leaves with the moderator's reset, and a player with a mug may clink with him. The harness bar
 * has him at (74, 46), the counter place along y 62 from x 66 to 92.
 */
#[Test]
#[Covers(WalkService::class)]
#[Covers(ResetService::class)]
#[Covers(BarService::class)]
#[Covers(Clink::class)]
final class RoadrunnerTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function newCycleBringsRoadrunnerAndTheResetSendsHimAway(): void
    {
        $ritual = self::again();

        Assert::true($ritual->broadcast->ofType('reset')[0]['bar']['roadrunner']);
        Assert::true($ritual->snapshot->create()['bar']['roadrunner']);

        $ritual->reset->reset();

        Assert::false($ritual->broadcast->ofType('reset')[1]['bar']['roadrunner']);
        Assert::false($ritual->snapshot->create()['bar']['roadrunner']);
    }

    public function clinkWithRoadrunnerWalksToTheCounterAndCountsForTheMug(): void
    {
        $ritual = self::again();
        self::beer($ritual, self::JACK);
        $ritual->broadcast->published = [];

        $walk = $ritual->walks->clink(self::JACK, Clink::ROADRUNNER, Clink::ROADRUNNER);

        Assert::equals($walk->path[\count($walk->path) - 1], new Point(74, 62));
        Assert::same($ritual->broadcast->ofType('move')[0]['clink'], ['roadrunner' => true]);
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'not clinked');

        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();
        $ritual->walks->clinks();

        $clinks = $ritual->broadcast->ofType('clink');
        Assert::count($clinks, 1);
        Assert::same([$clinks[0]['player'], $clinks[0]['roadrunner'], $clinks[0]['at']], [['id' => self::JACK], true, $walk->arrivesAt]);
        Assert::int($clinks[0]['toast'])->greaterThanOrEqual(0);
        $ritual->bar->drink(self::JACK);
        Assert::count($ritual->broadcast->ofType('drink'), 1);
    }

    public function secondComerStandsAGapAside(): void
    {
        $ritual = self::again();
        self::beer($ritual, self::JACK);
        self::beer($ritual, self::JILL);

        $ritual->walks->clink(self::JACK, Clink::ROADRUNNER, Clink::ROADRUNNER);
        $walk = $ritual->walks->clink(self::JILL, Clink::ROADRUNNER, Clink::ROADRUNNER);

        Assert::same(\abs($walk->path[\count($walk->path) - 1]->x - 74.0), 4.0);
    }

    public function clinkWithRoadrunnerIsRefusedInOrder(): void
    {
        $ritual = self::again();

        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::JACK, Clink::ROADRUNNER, Clink::ROADRUNNER)), 'no beer');
        self::beer($ritual, self::JACK);
        $ritual->reset->reset();
        self::beer($ritual, self::JACK);
        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::JACK, Clink::ROADRUNNER, Clink::ROADRUNNER)), 'no target');
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::JACK, Clink::ROADRUNNER, Clink::ROADRUNNER)), 'not bar');
    }

    public function drinkingUpOnTheWayCallsTheClinkOff(): void
    {
        $ritual = self::again();
        self::beer($ritual, self::JACK);
        $walk = $ritual->walks->clink(self::JACK, Clink::ROADRUNNER, Clink::ROADRUNNER);
        $ritual->walkerStore->update(static fn(array $walkers): array => [[self::JACK => $walkers[self::JACK]->emptyHanded()] + $walkers, null]);

        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();

        Assert::blank($ritual->broadcast->ofType('clink'));
    }

    /**
     * Back in the bar from the dawn, Jack and Jill on its floor.
     */
    private static function again(): RitualHarness
    {
        $ritual = new RitualHarness(threshold: 1, stageMax: [0, 1, 2]);
        foreach ([self::JACK, self::JILL] as $id) {
            $ritual->players->register($id, 'Jack', self::LOOK);
            $walk = $ritual->walks->move($id, $id === self::JACK ? 20 : 40, 92);
            $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
        }
        foreach ([0, 1, 2] as $scene) {
            $ritual->story->nextScene($scene, true);
            $ritual->runJobs();
        }
        $ritual->store->updateStory(static fn(Story $story): Story => new Story(finale: Story::DAWN_STEP, k: $story->k));
        $ritual->story->nextScene(3, false);

        return $ritual;
    }

    /**
     * @param non-empty-string $id
     */
    private static function beer(RitualHarness $ritual, string $id): void
    {
        $ritual->walkerStore->update(static fn(array $walkers): array => [[$id => $walkers[$id]->withBeer()] + $walkers, null]);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (ClinkRefusedException|\App\Application\Bar\BarRefusedException $e) {
            return $e->error;
        }

        return null;
    }
}
