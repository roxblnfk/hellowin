<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\RitualMetrics;
use App\Application\Hellowin\TransitionQueue;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(ClickHandler::class)]
#[Covers(TransitionQueue::class)]
final class ClickHandlerTest
{
    public function clickIsPublishedWithThePumpkinProgress(): void
    {
        $ritual = new RitualHarness(threshold: 3);

        $ritual->click('pumpkin-1');

        Assert::same($ritual->broadcast->published, [[
            'topic' => 'hellowin',
            'message' => [
                'type' => 'click',
                'round' => 1,
                'element' => 'pumpkin-1',
                'emoji' => '🎃',
                'clicks' => 1,
                'threshold' => 3,
                'stage' => 0,
            ],
        ]]);
    }

    public function noJobBelowTheThreshold(): void
    {
        $ritual = new RitualHarness(threshold: 3);

        $ritual->click('pumpkin-1', 2);

        Assert::blank($ritual->queue->pushed);
    }

    public function everyClickPastTheThresholdQueuesTheSameTransition(): void
    {
        $ritual = new RitualHarness(threshold: 3);

        $ritual->click('pumpkin-1', 5);

        $key = ['key' => '1:pumpkin-1:1', 'round' => 1, 'pumpkin' => 'pumpkin-1', 'toStage' => 1, 'player' => null];
        Assert::same($ritual->queue->payloads(TransitionPumpkin::class), [$key, $key, $key]);
        Assert::same($ritual->counters()[RitualMetrics::JOBS_PUSHED . '{pumpkin}'], 3.0);
    }

    public function pumpkinAheadOfTheSceneQueuesNothing(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->runJobs();

        $ritual->click('pumpkin-1', 3);

        Assert::blank($ritual->queue->pushed);
        Assert::same(\array_column($ritual->broadcast->ofType('click'), 'clicks'), [1, 1, 2, 3]);
    }

    public function clickIsCountedInMetrics(): void
    {
        $ritual = new RitualHarness();

        $ritual->click('pumpkin-2');

        Assert::same($ritual->metrics->calls, [['add', RitualMetrics::CLICKS, 1.0, ['pumpkin', 'pumpkin-2']]]);
    }
}
