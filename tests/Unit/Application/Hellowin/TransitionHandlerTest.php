<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Hellowin\Job\TransitionScene;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\TransitionHandler;
use App\Domain\Ritual\Pumpkin;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(TransitionHandler::class)]
final class TransitionHandlerTest
{
    public function pumpkinMovesAndItsClicksStartOver(): void
    {
        $ritual = new RitualHarness(threshold: 2);
        $ritual->click('pumpkin-1', 3);

        Assert::true($ritual->transitions->pumpkin(1, 'pumpkin-1', 1));

        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1', 1, 0, 3));
        Assert::same($ritual->broadcast->ofType('pumpkin'), [
            ['type' => 'pumpkin', 'round' => 1, 'element' => 'pumpkin-1', 'stage' => 1],
        ]);
        Assert::same($ritual->counters()[RitualMetrics::TRANSITIONS . '{pumpkin}'], 1.0);
        Assert::same($ritual->counters()[RitualMetrics::PUMPKIN_STAGE . '{pumpkin-1}'], 1.0);
    }

    public function thePlayerOfTheJobThatRunsFinishedThePumpkin(): void
    {
        $ritual = new RitualHarness(threshold: 2);
        $ritual->click('pumpkin-1', 1, 'aaaaaaaaaaaaaaaa');
        $ritual->click('pumpkin-1', 1, 'bbbbbbbbbbbbbbbb');
        $ritual->click('pumpkin-1', 1, 'aaaaaaaaaaaaaaaa');

        $ritual->runJobs();

        Assert::same(\array_column($ritual->broadcast->ofType('pumpkin'), 'player'), [
            ['id' => 'bbbbbbbbbbbbbbbb', 'name' => null],
        ]);
        Assert::same($ritual->playerStore->find('bbbbbbbbbbbbbbbb')?->finished, 1);
        Assert::same($ritual->playerStore->find('aaaaaaaaaaaaaaaa')?->finished, 0);
        Assert::same($ritual->playerStore->find('aaaaaaaaaaaaaaaa')?->clicks, 2);
    }

    public function anonymousTransitionCarriesNoPlayer(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');

        $ritual->runJobs();

        Assert::false(\array_key_exists('player', $ritual->broadcast->ofType('pumpkin')[0]));
    }

    public function duplicateTransitionChangesNothing(): void
    {
        $ritual = new RitualHarness(threshold: 2);
        $ritual->click('pumpkin-1', 2);
        $ritual->transitions->pumpkin(1, 'pumpkin-1', 1);
        $ritual->click('pumpkin-1');

        Assert::false($ritual->transitions->pumpkin(1, 'pumpkin-1', 1));

        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1', 1, 1, 3));
        Assert::count($ritual->broadcast->ofType('pumpkin'), 1);
        Assert::same($ritual->counters()[RitualMetrics::TRANSITIONS . '{pumpkin}'], 1.0);
    }

    public function duplicatesQueuedByClicksMoveThePumpkinOnce(): void
    {
        $ritual = new RitualHarness(threshold: 2);
        $ritual->click('pumpkin-1', 5);

        $ritual->runJobs();

        $counters = $ritual->counters();
        Assert::same($counters[RitualMetrics::JOBS_PUSHED . '{pumpkin}'], 4.0);
        Assert::same($counters[RitualMetrics::TRANSITIONS . '{pumpkin}'], 1.0);
        Assert::count($ritual->broadcast->ofType('pumpkin'), 1);
    }

    public function lastPumpkinMakesTheSceneReadyWithoutMovingIt(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->runJobs();
        Assert::blank($ritual->broadcast->ofType('ready'));

        $ritual->click('pumpkin-2', 1, 'aaaaaaaaaaaaaaaa');
        $ritual->runJobs();

        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 0]]);
        Assert::same($ritual->broadcast->ofType('owl'), [
            ['type' => 'owl', 'round' => 1, 'line' => 'last-pumpkin', 'params' => ['player' => ['id' => 'aaaaaaaaaaaaaaaa', 'name' => null]]],
            ['type' => 'owl', 'round' => 1, 'line' => 'scene-ready'],
        ]);
        Assert::blank($ritual->queue->payloads(TransitionScene::class));
        Assert::same($ritual->store->snapshot()->scene, 0);
        Assert::true($ritual->store->snapshot()->ready());
    }

    public function readySceneIsAnnouncedOnce(): void
    {
        $ritual = new RitualHarness(threshold: 1, pumpkins: ['pumpkin-1']);
        $ritual->click('pumpkin-1');
        $ritual->runJobs();

        // A duplicate that slipped past the idempotency storage finds the stage moved
        $ritual->transitions->pumpkin(1, 'pumpkin-1', 1);

        Assert::count($ritual->broadcast->ofType('ready'), 1);
    }

    public function sceneMovesOnceAndIsPublished(): void
    {
        $ritual = new RitualHarness(threshold: 1);

        Assert::true($ritual->transitions->scene(1, 1));
        Assert::false($ritual->transitions->scene(1, 1));

        Assert::same($ritual->store->snapshot()->scene, 1);
        Assert::same($ritual->broadcast->ofType('scene'), [['type' => 'scene', 'round' => 1, 'scene' => 1, 'stageMax' => 2, 'bonfireStageMax' => 0, 'circleStageMax' => 0, 'item' => 'torch']]);
        Assert::same($ritual->broadcast->ofType('owl'), [['type' => 'owl', 'round' => 1, 'line' => 'scene-1']]);
        Assert::blank($ritual->broadcast->ofType('finale'));
        $counters = $ritual->counters();
        Assert::same($counters[RitualMetrics::TRANSITIONS . '{scene}'], 1.0);
        Assert::same($counters[RitualMetrics::SCENE . '{}'], 1.0);
    }

    public function finalSceneStartsTheFinale(): void
    {
        $ritual = new RitualHarness(threshold: 1, stageMax: [1]);

        $ritual->transitions->scene(1, 1);

        Assert::same($ritual->broadcast->ofType('finale'), [['type' => 'finale', 'round' => 1, 'step' => 0]]);
        Assert::same($ritual->store->snapshot()->story->finale, 0);
    }

    public function wholeRitualReachesTheFinalSceneByTheModerator(): void
    {
        $ritual = new RitualHarness(threshold: 2, stageMax: [1, 2]);

        for ($scene = 0; $scene < 2; ++$scene) {
            $ritual->click('pumpkin-1', 3);
            $ritual->click('pumpkin-2', 3);
            $ritual->runJobs();
            Assert::same($ritual->store->snapshot()->scene, $scene);

            $ritual->story->nextScene($scene, false);
            $ritual->runJobs();
        }

        $snapshot = $ritual->store->snapshot();
        Assert::same($snapshot->scene, 2);
        Assert::same(\array_map(static fn(Pumpkin $p): int => $p->stage, $snapshot->pumpkins), [2, 2]);
        Assert::same(\array_column($ritual->broadcast->ofType('scene'), 'scene'), [1, 2]);
        Assert::same(\array_column($ritual->broadcast->ofType('ready'), 'scene'), [0, 1]);
        Assert::count($ritual->broadcast->ofType('pumpkin'), 4);
    }

    public function pumpkinGoesThroughEveryStageOfTheScene(): void
    {
        $ritual = new RitualHarness(threshold: 1, stageMax: [3], pumpkins: ['pumpkin-1']);

        for ($i = 0; $i < 4; ++$i) {
            $ritual->click('pumpkin-1');
            $ritual->runJobs();
        }

        Assert::same(\array_column($ritual->broadcast->ofType('pumpkin'), 'stage'), [1, 2, 3]);
        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 0]]);
        Assert::same($ritual->store->snapshot()->pumpkin('pumpkin-1')?->stage, 3);
    }

    public function jobOfAResetRoundIsDropped(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->reset->reset();

        $ritual->runJobs();

        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1'));
        Assert::blank($ritual->broadcast->ofType('pumpkin'));
    }
}
