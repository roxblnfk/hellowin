<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\TransitionHandler;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Domain\Ritual\Pumpkin;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The magic circle on the ashes: the whole room draws it at the start of the finale, and FrankenPHP rises out of it
 * once it is closed. The harness has scene 1 as the final one and a circle of two stages, three clicks a stage.
 */
#[Test]
#[Covers(ClickHandler::class)]
#[Covers(TransitionHandler::class)]
#[Covers(StoryService::class)]
final class CircleTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';

    public function circleTakesNoClicksBeforeTheFinalScene(): void
    {
        $ritual = self::ritual();

        Expect::exception(ElementLockedException::class);

        try {
            $ritual->click(RitualHarness::CIRCLE, 1, self::JACK);
        } finally {
            Assert::blank($ritual->broadcast->published);
            Assert::false(\array_key_exists(RitualMetrics::CLICKS . '{circle,circle}', $ritual->counters()));
            Assert::equals($ritual->store->snapshot()->circle, new Pumpkin(RitualHarness::CIRCLE));
            Assert::null($ritual->playerStore->find(self::JACK));
        }
    }

    public function circleIsDrawnByItsOwnThresholdThroughTheTransitionQueue(): void
    {
        $ritual = self::inTheFinale();

        $ritual->click(RitualHarness::CIRCLE, 4, self::JACK);

        Assert::same(\array_column($ritual->broadcast->ofType('click'), 'threshold'), [3, 3, 3, 3]);
        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), ['1:circle:1', '1:circle:1']);
        Assert::same($ritual->counters()[RitualMetrics::JOBS_PUSHED . '{circle}'], 2.0);
        Assert::same($ritual->counters()[RitualMetrics::CLICKS . '{circle,circle}'], 4.0);

        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('pumpkin'), [
            ['type' => 'pumpkin', 'round' => 1, 'element' => 'circle', 'stage' => 1, 'player' => ['id' => self::JACK, 'name' => null]],
        ]);
        Assert::equals($ritual->store->snapshot()->circle, new Pumpkin(RitualHarness::CIRCLE, 1, 0, 4, 3));
        Assert::same($ritual->counters()[RitualMetrics::TRANSITIONS . '{circle}'], 1.0);
        Assert::same($ritual->counters()[RitualMetrics::CIRCLE_STAGE . '{}'], 1.0);
    }

    public function closedCircleMakesTheFinalSceneReady(): void
    {
        $ritual = self::inTheFinale();

        $ritual->click(RitualHarness::CIRCLE, 3, self::JILL);
        $ritual->runJobs();
        Assert::blank($ritual->broadcast->ofType('ready'));
        $ritual->click(RitualHarness::CIRCLE, 3, self::JACK);
        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 1]]);
        Assert::same($ritual->broadcast->ofType('owl'), [
            ['type' => 'owl', 'round' => 1, 'line' => 'circle-done', 'params' => ['player' => ['id' => self::JACK, 'name' => null]]],
            ['type' => 'owl', 'round' => 1, 'line' => 'scene-ready'],
        ]);
        $snapshot = $ritual->snapshot->create();
        Assert::true($snapshot['ready']);
        Assert::same($snapshot['finale'], ['step' => 0]);
        Assert::same($ritual->store->snapshot()->story->lastPumpkin, self::JACK);
        Assert::same($ritual->playerStore->find(self::JACK)?->finished, 1);
    }

    public function anonymousCloserLeavesTheOwlNoNameToSay(): void
    {
        $ritual = self::inTheFinale();

        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();

        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['scene-ready']);
    }

    public function closedCircleTakesNoMoreClicks(): void
    {
        $ritual = self::inTheFinale();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();

        Expect::exception(ElementLockedException::class);

        $ritual->click(RitualHarness::CIRCLE);
    }

    public function circleTakesNoClicksAfterTheFirstFinaleStep(): void
    {
        $ritual = self::inTheFinale();
        $ritual->story->finaleStep(1, force: true);

        Expect::exception(ElementLockedException::class);

        $ritual->click(RitualHarness::CIRCLE);
    }

    public function firstFinaleStepWaitsForTheClosedCircle(): void
    {
        $ritual = self::inTheFinale();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();

        try {
            $ritual->story->finaleStep(1);
            Assert::fail('The first step must wait for the circle.');
        } catch (StoryRefusedException $e) {
            Assert::same($e->error, 'not ready');
        }
        Assert::blank($ritual->broadcast->ofType('finale'));

        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();

        Assert::same($ritual->story->finaleStep(1), 1);
        Assert::same($ritual->story->finaleStep(2, force: true), 2);
        Assert::same(\array_column($ritual->broadcast->ofType('finale'), 'step'), [1, 2]);
    }

    public function circleCountsInTheReveal(): void
    {
        $ritual = self::inTheFinale();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();
        $ritual->click(RitualHarness::CIRCLE, 5);
        $ritual->runJobs();

        $reveal = $ritual->reveal->reveal($ritual->store->snapshot());

        // 2 pumpkin clicks in scene 0 and 8 on the circle: 6 drew it, 2 burned; 2 + 2 circle jobs + 1 scene
        Assert::same([$reveal->clicks, $reveal->burned, $reveal->jobs], [10, 2, 5]);
    }

    public function snapshotGivesTheCircleItsThresholdAndStageMax(): void
    {
        $before = self::ritual()->snapshot->create()['elements'];
        $after = self::inTheFinale()->snapshot->create()['elements'];

        Assert::same(\end($before), ['id' => 'circle', 'type' => 'circle', 'x' => 50.0, 'y' => 63.0, 'stage' => 0, 'clicks' => 0, 'threshold' => 3, 'stageMax' => 0]);
        Assert::same(\end($after)['stageMax'] ?? null, 2);
    }

    public function finalSceneEventCarriesTheCircleStageMax(): void
    {
        $ritual = self::ritual();
        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2');
        $ritual->runJobs();
        $ritual->story->nextScene(0, false);
        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('scene')[0]['circleStageMax'] ?? null, 2);
    }

    public function resetWipesTheCircleOut(): void
    {
        $ritual = self::inTheFinale();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();

        $ritual->reset->reset(1);

        Assert::equals($ritual->store->snapshot()->circle, new Pumpkin(RitualHarness::CIRCLE));
        Assert::same($ritual->counters()[RitualMetrics::CIRCLE_STAGE . '{}'], 0.0);
    }

    private static function ritual(): RitualHarness
    {
        return new RitualHarness(threshold: 1, stageMax: [1], circle: 2, circleThreshold: 3);
    }

    /**
     * Scene 0 done and the final scene begun; the events so far are dropped.
     */
    private static function inTheFinale(): RitualHarness
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
}
