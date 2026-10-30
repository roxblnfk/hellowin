<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Hellowin\Job\TransitionScene;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Story\AutopilotService;
use App\Application\Story\EveningTally;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The autopilot on the ritual services (docs/spec/scenario.md, «Автопилот»): scene 0 is the bar, scene 2 the final one;
 * a pause of {@see RitualHarness::AUTO_PAUSE} ms.
 */
#[Test]
#[Covers(AutopilotService::class)]
final class AutopilotServiceTest
{
    public function readyBarMovesToTheDayOnceTheOwlHasFinishedAndThePausePassed(): void
    {
        $ritual = self::ritual();
        $ritual->bar->wave();
        $ritual->autopilot->switch(true);
        $at = \max($ritual->owl->until(), $ritual->clock->now) + RitualHarness::AUTO_PAUSE;
        $ritual->clock->now = $at - 1;
        $ritual->autopilot->tick($ritual->clock->now);
        Assert::blank($ritual->queue->pushed);

        $ritual->clock->now = $at;
        $ritual->autopilot->tick($ritual->clock->now);

        Assert::same(\array_column($ritual->queue->pushed, 'name'), [TransitionScene::class]);
        $ritual->runJobs();
        Assert::same($ritual->store->snapshot()->scene, 1);
        Assert::same((int) ($ritual->counters()[PlayMetrics::AUTOPILOT_MOVES . '{scene.next}'] ?? -1), 1);
        Assert::same($ritual->tally->count(EveningTally::CALLS), 0);
        Assert::same($ritual->tally->count(EveningTally::DROPPED), 0);
    }

    public function switchingOnTellsTheFirstMoveAndTheSnapshotCarriesIt(): void
    {
        $ritual = self::ritual();

        $ritual->autopilot->switch(true);

        $auto = ['on' => true, 'next' => ['action' => 'bar.ready', 'wait' => 'room']];
        Assert::same($ritual->broadcast->ofType('auto'), [['type' => 'auto', 'round' => 1] + $auto]);
        Assert::same($ritual->snapshot->create()['auto'] ?? null, $auto);
        Assert::same((int) ($ritual->counters()[PlayMetrics::AUTOPILOT_ON . '{}'] ?? -1), 1);
    }

    public function nextMoveIsToldOnlyWhenItChanges(): void
    {
        $ritual = self::ritual();
        $ritual->autopilot->switch(true);

        $ritual->autopilot->tick($ritual->clock->now);
        $ritual->autopilot->tick($ritual->clock->now + 200);
        $ritual->bar->wave();
        $ritual->autopilot->tick($ritual->clock->now + 400);

        $told = \array_column($ritual->broadcast->ofType('auto'), 'next');
        Assert::count($told, 2);
        Assert::same($told[1]['action'] ?? null, 'scene.next');
    }

    public function offAutopilotDoesNothing(): void
    {
        $ritual = self::ritual();
        $ritual->bar->wave();

        $ritual->autopilot->tick($ritual->clock->now + 600000);

        Assert::blank($ritual->queue->pushed);
        Assert::blank($ritual->broadcast->ofType('auto'));
        Assert::same($ritual->autopilot->state(), ['on' => false, 'next' => null]);
    }

    public function switchingOffForgetsTheMove(): void
    {
        $ritual = self::ritual();
        $ritual->autopilot->switch(true);

        $ritual->autopilot->switch(false);

        Assert::same($ritual->autopilot->state(), ['on' => false, 'next' => null]);
        Assert::same((int) ($ritual->counters()[PlayMetrics::AUTOPILOT_ON . '{}'] ?? -1), 0);
    }

    public function autopilotLivesThroughTheResetAndANewCycle(): void
    {
        $ritual = self::ritual();
        $ritual->autopilot->switch(true);

        $ritual->reset->reset(1);
        $ritual->reset->reset(2, again: true);

        Assert::true($ritual->autopilot->state()['on']);
        Assert::true($ritual->snapshot->create()['auto']['on'] ?? false);
    }

    public function dawnGoesBackToTheBarAfterItsDeadline(): void
    {
        $ritual = self::ritual();
        self::toDawn($ritual);
        $ritual->autopilot->switch(true);
        $at = $ritual->autopilot->state()['next']['at'] ?? 0;

        $ritual->clock->now = $at;
        $ritual->autopilot->tick($at);

        Assert::same($ritual->store->snapshot()->round, 2);
        Assert::same($ritual->store->snapshot()->scene, 0);
        Assert::same(\array_column($ritual->broadcast->ofType('reset'), 'again'), [true]);
    }

    private static function ritual(): RitualHarness
    {
        return new RitualHarness(stageMax: [0, 1]);
    }

    /**
     * Through the bar and the day into the final scene, then the moderator's force up to the dawn.
     */
    private static function toDawn(RitualHarness $ritual): void
    {
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        $ritual->story->nextScene(1, true);
        $ritual->runJobs();
        for ($step = 1; $step <= Story::DAWN_STEP; ++$step) {
            $ritual->story->finaleStep($step, true);
        }
    }
}
