<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\TransitionHandler;
use App\Application\Hellowin\TransitionQueue;
use App\Domain\Ritual\Pumpkin;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The bonfire in the middle of the patch: the pumpkins of a scene first, then the whole room lights the bonfire.
 * The harness takes pumpkins to stage 1 in scene 0 and to 2 in scene 1; the bonfire stays out of scene 0 and goes
 * to stage 2 in scene 1, three clicks a stage.
 */
#[Test]
#[Covers(ClickHandler::class)]
#[Covers(TransitionHandler::class)]
final class BonfireTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';

    public function sceneWithoutTheBonfireIsReadyWithThePumpkins(): void
    {
        $ritual = self::ritual();

        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2', 1, self::JACK);
        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 0]]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['last-pumpkin', 'scene-ready']);
    }

    public function bonfireTakesNoClicksBeforeThePumpkinsAreDone(): void
    {
        $ritual = self::inSceneOne();
        $ritual->click('pumpkin-1');
        $ritual->runJobs();
        $ritual->broadcast->published = [];

        Expect::exception(ElementLockedException::class);

        try {
            $ritual->click(RitualHarness::BONFIRE, 1, self::JACK);
        } finally {
            Assert::blank($ritual->broadcast->published);
            Assert::false(\array_key_exists(RitualMetrics::CLICKS . '{bonfire,bonfire}', $ritual->counters()));
            Assert::equals($ritual->store->snapshot()->bonfire, new Pumpkin(RitualHarness::BONFIRE));
            Assert::null($ritual->playerStore->find(self::JACK));
        }
    }

    public function lastPumpkinOpensTheBonfireInsteadOfTheReadyScene(): void
    {
        $ritual = self::inSceneOne();

        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2', 1, self::JACK);
        $ritual->runJobs();

        Assert::blank($ritual->broadcast->ofType('ready'));
        Assert::false($ritual->store->snapshot()->ready());
        Assert::same($ritual->broadcast->ofType('owl'), [
            ['type' => 'owl', 'round' => 1, 'line' => 'last-pumpkin', 'params' => ['player' => ['id' => self::JACK, 'name' => null]]],
            ['type' => 'owl', 'round' => 1, 'line' => 'bonfire-open'],
        ]);
    }

    public function bonfireOpensOnce(): void
    {
        $ritual = self::inSceneOne();
        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2');
        $ritual->runJobs();

        // A duplicate that slipped past the idempotency storage finds the stage moved
        $ritual->transitions->pumpkin(1, 'pumpkin-2', 2);

        Assert::count(\array_filter(
            $ritual->broadcast->ofType('owl'),
            static fn(array $owl): bool => $owl['line'] === 'bonfire-open',
        ), 1);
    }

    public function bonfireMovesByItsOwnThresholdThroughTheTransitionQueue(): void
    {
        $ritual = self::withBonfireOpen();

        $ritual->click(RitualHarness::BONFIRE, 4, self::JACK);

        $clicks = $ritual->broadcast->ofType('click');
        Assert::same(\array_column($clicks, 'threshold'), [3, 3, 3, 3]);
        Assert::same(\array_column($clicks, 'element'), \array_fill(0, 4, RitualHarness::BONFIRE));
        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), [
            TransitionQueue::pumpkinKey(1, 'bonfire', 1),
            TransitionQueue::pumpkinKey(1, 'bonfire', 1),
        ]);
        Assert::same(TransitionQueue::pumpkinKey(1, 'bonfire', 1), '1:bonfire:1');
        Assert::same($ritual->counters()[RitualMetrics::JOBS_PUSHED . '{bonfire}'], 2.0);
        Assert::same($ritual->counters()[RitualMetrics::CLICKS . '{bonfire,bonfire}'], 4.0);

        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('pumpkin'), [
            ['type' => 'pumpkin', 'round' => 1, 'element' => 'bonfire', 'stage' => 1, 'player' => ['id' => self::JACK, 'name' => null]],
        ]);
        Assert::equals($ritual->store->snapshot()->bonfire, new Pumpkin(RitualHarness::BONFIRE, 1, 0, 4, 3));
        Assert::same($ritual->counters()[RitualMetrics::TRANSITIONS . '{bonfire}'], 1.0);
        Assert::same($ritual->counters()[RitualMetrics::BONFIRE_STAGE . '{}'], 1.0);
    }

    public function finishedBonfireMakesTheSceneReady(): void
    {
        $ritual = self::withBonfireOpen();

        $ritual->click(RitualHarness::BONFIRE, 3, self::JILL);
        $ritual->runJobs();
        Assert::blank($ritual->broadcast->ofType('ready'));
        $ritual->click(RitualHarness::BONFIRE, 3, self::JACK);
        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 1]]);
        Assert::same($ritual->broadcast->ofType('owl'), [
            ['type' => 'owl', 'round' => 1, 'line' => 'bonfire-done', 'params' => ['player' => ['id' => self::JACK, 'name' => null]]],
            ['type' => 'owl', 'round' => 1, 'line' => 'scene-ready'],
        ]);
        Assert::true($ritual->store->snapshot()->ready());
        Assert::same($ritual->store->snapshot()->story->lastPumpkin, self::JACK);
    }

    public function finishedBonfireTakesNoMoreClicks(): void
    {
        $ritual = self::withBonfireOpen();
        $ritual->click(RitualHarness::BONFIRE, 3);
        $ritual->runJobs();
        $ritual->click(RitualHarness::BONFIRE, 3);
        $ritual->runJobs();

        Expect::exception(ElementLockedException::class);

        $ritual->click(RitualHarness::BONFIRE);
    }

    public function clicksWhileTheTransitionIsInFlightBurnIntoTheReveal(): void
    {
        $ritual = self::withBonfireOpen();
        $ritual->click(RitualHarness::BONFIRE, 5);
        $ritual->runJobs();

        $reveal = $ritual->reveal->reveal($ritual->store->snapshot());

        // 4 pumpkin clicks over scenes 0 and 1, 5 on the bonfire: 3 moved it, 2 burned; 4 + 1 + 1 jobs
        Assert::same($reveal->clicks, 9);
        Assert::same($reveal->burned, 2);
        Assert::same($reveal->jobs, 6);
    }

    public function snapshotGivesTheBonfireItsThresholdAndStageMax(): void
    {
        $ritual = self::ritual();

        $before = $ritual->snapshot->create()['elements'];
        $ritual = self::inSceneOne();
        $after = $ritual->snapshot->create()['elements'];

        Assert::same(\end($before), ['id' => 'bonfire', 'type' => 'bonfire', 'x' => 50.0, 'y' => 63.0, 'stage' => 0, 'clicks' => 0, 'threshold' => 3, 'stageMax' => 0]);
        Assert::same(\end($after)['stageMax'] ?? null, 2);
        Assert::false(\array_key_exists('threshold', $after[0]));
    }

    public function resetPutsTheBonfireOut(): void
    {
        $ritual = self::withBonfireOpen();
        $ritual->click(RitualHarness::BONFIRE, 3);
        $ritual->runJobs();

        $ritual->reset->reset(1);

        $snapshot = $ritual->store->snapshot();
        Assert::equals($snapshot->bonfire, new Pumpkin(RitualHarness::BONFIRE));
        Assert::null($snapshot->story->bonfire);
        Assert::same($ritual->counters()[RitualMetrics::BONFIRE_STAGE . '{}'], 0.0);
    }

    private static function ritual(): RitualHarness
    {
        return new RitualHarness(threshold: 1, stageMax: [1, 2], bonfire: [0, 2], bonfireThreshold: 3);
    }

    /**
     * Scene 0 done and moved on; the events so far are dropped.
     */
    private static function inSceneOne(): RitualHarness
    {
        $ritual = self::ritual();
        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2');
        $ritual->runJobs();
        $ritual->story->nextScene(0, false);
        $ritual->runJobs();
        $ritual->broadcast->published = [];

        return $ritual;
    }

    private static function withBonfireOpen(): RitualHarness
    {
        $ritual = self::inSceneOne();
        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2');
        $ritual->runJobs();
        $ritual->broadcast->published = [];

        return $ritual;
    }
}
