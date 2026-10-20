<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\RitualMetrics;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(ResetService::class)]
final class ResetServiceTest
{
    public function resetStartsTheNextRoundAndTellsEveryone(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->runJobs();

        $round = $ritual->reset->reset();

        Assert::same($round, 2);
        Assert::same($ritual->broadcast->ofType('reset'), [['type' => 'reset', 'round' => 2, 'item' => null]]);
        $counters = $ritual->counters();
        Assert::same($counters[RitualMetrics::ROUND . '{}'], 2.0);
        Assert::same($counters[RitualMetrics::PUMPKIN_STAGE . '{pumpkin-1}'], 0.0);
    }

    public function secondResetOfTheSameRoundDoesNothing(): void
    {
        $ritual = new RitualHarness();

        Assert::same($ritual->reset->reset(1), 2);
        Assert::null($ritual->reset->reset(1));

        Assert::count($ritual->broadcast->ofType('reset'), 1);
        Assert::same($ritual->store->snapshot()->round, 2);
    }

    public function transitionKeysOfTheNextRoundAreNew(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->runJobs();
        $ritual->reset->reset();

        $ritual->click('pumpkin-1');

        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), ['2:pumpkin-1:1']);
        $ritual->runJobs();
        Assert::same($ritual->store->snapshot()->pumpkin('pumpkin-1')?->stage, 1);
    }
}
