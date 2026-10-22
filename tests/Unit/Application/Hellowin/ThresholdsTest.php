<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Balance\LimitsService;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\TransitionHandler;
use App\Application\Story\StoryService;
use App\Domain\Ritual\Pumpkin;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The moderator's threshold multiplier `k` over a running ritual.
 */
#[Test]
#[Covers(StoryService::class)]
#[Covers(ClickHandler::class)]
#[Covers(TransitionHandler::class)]
#[Covers(SnapshotFactory::class)]
#[Covers(LimitsService::class)]
final class ThresholdsTest
{
    public function loweredThresholdMovesTheElementByItselfOnce(): void
    {
        $ritual = new RitualHarness(threshold: 4, stageMax: [3, 6]);
        $ritual->limits->settle();
        $ritual->click('pumpkin-1', 3);

        // The clicks already gathered are over the new threshold: the limits queue the transition without a click
        $ritual->story->thresholds(0.5);
        $ritual->limits->settle();
        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), ['1:pumpkin-1:1']);

        $ritual->click('pumpkin-1', 2);

        Assert::same(\array_column($ritual->broadcast->ofType('click'), 'threshold'), [4, 4, 4, 2, 2]);
        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), ['1:pumpkin-1:1', '1:pumpkin-1:1', '1:pumpkin-1:1']);

        $ritual->runJobs();

        Assert::same(\array_column($ritual->broadcast->ofType('pumpkin'), 'stage'), [1]);
        // The transition took the 2 clicks of the threshold in force, the other 3 burned
        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1', 1, 0, 5, 2));
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->burned, 3);
    }

    public function raisedThresholdWaitsForTheNewNumberOfClicks(): void
    {
        $ritual = new RitualHarness(threshold: 2, stageMax: [3, 6]);
        $ritual->story->thresholds(2);

        $ritual->click('pumpkin-1', 3);
        Assert::blank($ritual->queue->pushed);

        $ritual->click('pumpkin-1');
        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), ['1:pumpkin-1:1']);
    }

    public function jobTakesTheThresholdInForceWhenItRuns(): void
    {
        $ritual = new RitualHarness(threshold: 2, stageMax: [3, 6]);
        $ritual->click('pumpkin-1', 3);

        // Raised while the job was in flight: the job still runs, and every click it found is spent
        $ritual->story->thresholds(2);
        $ritual->runJobs();

        Assert::equals($ritual->store->snapshot()->pumpkin('pumpkin-1'), new Pumpkin('pumpkin-1', 1, 0, 3, 3));
    }

    public function snapshotCarriesTheMultiplierAndTheThresholdsInForce(): void
    {
        $ritual = new RitualHarness(threshold: 5, bonfire: [0, 2], bonfireThreshold: 3);

        $answer = $ritual->story->thresholds(0.5);
        $state = $ritual->snapshot->create();

        Assert::same($answer, ['k' => 0.5, 'players' => 1, 'limits' => ['pumpkin' => 3, 'bonfire' => 2]]);
        Assert::same($state['k'], 0.5);
        Assert::same($state['threshold'], 3);
        Assert::same(\end($state['elements'])['threshold'] ?? null, 2);
        Assert::same($state['limits'], ['players' => 1, 'k' => 0.5, 'burst' => true, 'limits' => ['pumpkin' => 3, 'bonfire' => 2]]);
    }

    public function multiplierIsPublishedOnChangeOnly(): void
    {
        $ritual = new RitualHarness(threshold: 4);
        $ritual->limits->settle();
        $ritual->broadcast->published = [];

        $ritual->story->thresholds(1);
        $ritual->story->thresholds(1.25);
        $ritual->story->thresholds(1.25);

        Assert::same($ritual->broadcast->ofType('limits'), [
            ['type' => 'limits', 'round' => 1, 'players' => 1, 'k' => 1.25, 'burst' => true, 'limits' => ['pumpkin' => 5, 'bonfire' => 4]],
        ]);
    }

    public function multiplierOutlivesTheReset(): void
    {
        $ritual = new RitualHarness();
        $ritual->story->thresholds(3);

        $ritual->reset->reset(1);

        Assert::same($ritual->snapshot->create()['k'], 3.0);
        Assert::same($ritual->snapshot->create()['threshold'], 6);
    }
}
