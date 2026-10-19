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

        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1', 1, 0));
        Assert::same($ritual->broadcast->ofType('pumpkin'), [
            ['type' => 'pumpkin', 'round' => 1, 'element' => 'pumpkin-1', 'stage' => 1],
        ]);
        Assert::same($ritual->counters()[RitualMetrics::TRANSITIONS . '{pumpkin}'], 1.0);
        Assert::same($ritual->counters()[RitualMetrics::PUMPKIN_STAGE . '{pumpkin-1}'], 1.0);
    }

    public function duplicateTransitionChangesNothing(): void
    {
        $ritual = new RitualHarness(threshold: 2);
        $ritual->click('pumpkin-1', 2);
        $ritual->transitions->pumpkin(1, 'pumpkin-1', 1);
        $ritual->click('pumpkin-1');

        Assert::false($ritual->transitions->pumpkin(1, 'pumpkin-1', 1));

        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1', 1, 1));
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

    public function lastPumpkinQueuesTheSceneTransition(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->runJobs();
        Assert::blank($ritual->broadcast->ofType('scene'));

        $ritual->click('pumpkin-2');
        $ritual->transitions->pumpkin(1, 'pumpkin-2', 1);

        Assert::same($ritual->queue->payloads(TransitionScene::class), [
            ['key' => '1:scene:1', 'round' => 1, 'toScene' => 1],
        ]);
    }

    public function sceneMovesOnceAndIsPublished(): void
    {
        $ritual = new RitualHarness(threshold: 1);

        Assert::true($ritual->transitions->scene(1, 1));
        Assert::false($ritual->transitions->scene(1, 1));

        Assert::same($ritual->store->snapshot()->scene, 1);
        Assert::same($ritual->broadcast->ofType('scene'), [['type' => 'scene', 'round' => 1, 'scene' => 1, 'stageMax' => 2]]);
        $counters = $ritual->counters();
        Assert::same($counters[RitualMetrics::TRANSITIONS . '{scene}'], 1.0);
        Assert::same($counters[RitualMetrics::SCENE . '{}'], 1.0);
    }

    public function wholeRitualReachesTheFinalScene(): void
    {
        $ritual = new RitualHarness(threshold: 2, stageMax: [1, 2]);

        for ($scene = 0; $scene < 3; ++$scene) {
            $ritual->click('pumpkin-1', 3);
            $ritual->click('pumpkin-2', 3);
            $ritual->runJobs();
        }

        $snapshot = $ritual->store->snapshot();
        Assert::same($snapshot->scene, 2);
        Assert::same(\array_map(static fn(Pumpkin $p): int => $p->stage, $snapshot->pumpkins), [2, 2]);
        Assert::same(\array_column($ritual->broadcast->ofType('scene'), 'scene'), [1, 2]);
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
        Assert::same($ritual->broadcast->ofType('scene'), [['type' => 'scene', 'round' => 1, 'scene' => 1, 'stageMax' => 3]]);
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
