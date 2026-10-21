<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Hellowin;

use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * Scene 0 is the bar when the first last-stage is 0 (docs/spec/scenario.md, «Сцена 0 — «Бар»»).
 */
#[Test]
#[Covers(ClickHandler::class)]
#[Covers(SnapshotFactory::class)]
#[Covers(StoryService::class)]
final class BarSceneTest
{
    public function pumpkinInTheBarIsLocked(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);

        $locked = false;
        try {
            $ritual->click('pumpkin-1');
        } catch (ElementLockedException) {
            $locked = true;
        }

        Assert::true($locked);
        Assert::blank($ritual->broadcast->published);
        Assert::same($ritual->store->snapshot()->pumpkin('pumpkin-1')?->accepted, 0);
    }

    public function pumpkinCountsOnceTheRoomLeftTheBar(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        $ritual->click('pumpkin-1');

        Assert::count($ritual->broadcast->ofType('click'), 1);
    }

    public function barWaitsForTheModeratorOrForce(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);

        Assert::false($ritual->snapshot->create()['ready']);
        $refused = null;
        try {
            $ritual->story->nextScene(0, false);
        } catch (StoryRefusedException $e) {
            $refused = $e->error;
        }
        Assert::same($refused, 'not ready');

        $ritual->bar->wave();
        Assert::true($ritual->snapshot->create()['ready']);
        Assert::same($ritual->story->nextScene(0, false), ['scene' => 1]);
        $ritual->runJobs();

        Assert::same($ritual->store->snapshot()->scene, 1);
        Assert::false($ritual->snapshot->create()['ready']);
        Assert::same($ritual->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 0]]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['bar-intro', 'scene-1']);
    }

    public function daySceneStillWaitsForItsPumpkins(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        Expect::exception(StoryRefusedException::class);

        $ritual->story->nextScene(1, false);
    }
}
