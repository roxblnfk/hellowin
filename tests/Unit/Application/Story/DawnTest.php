<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Hellowin\ResetService;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The dawn leads the room back to the bar: «Следующая сцена» on the last finale step is a new cycle of the evening
 * (docs/spec/scenario.md, «Раунд и сброс», «Снова в бар»). The harness has the bar and scene 3 as the final one.
 */
#[Test]
#[Covers(StoryService::class)]
#[Covers(ResetService::class)]
final class DawnTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function nextSceneAtDawnStartsANewCycleInTheBar(): void
    {
        $ritual = self::atDawn();
        $ritual->broadcast->published = [];

        $answer = $ritual->story->nextScene(3, false);

        Assert::same($answer, ['scene' => 0, 'round' => 2]);
        $snapshot = $ritual->store->snapshot();
        Assert::same([$snapshot->round, $snapshot->scene, $snapshot->story->finale], [2, 0, null]);
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['reset', 'owl']);
        $reset = $ritual->broadcast->ofType('reset')[0];
        Assert::same([$reset['round'], $reset['again'], $reset['item']], [2, true, null]);
        Assert::count($reset['walkers'], 1);
        Assert::numeric($reset['walkers'][0]['walk']['path'][0]['y'])->between(90, 94);
        Assert::same($reset['bar']['drunk'], 0);
        Assert::same($ritual->broadcast->ofType('owl')[0]['line'], 'bar-again');
    }

    public function repeatedPressAfterTheCycleAnswersTheCurrentRound(): void
    {
        $ritual = self::atDawn();
        $ritual->story->nextScene(3, false);
        $ritual->broadcast->published = [];

        Assert::same($ritual->story->nextScene(3, false), ['scene' => 0, 'round' => 2]);
        Assert::blank($ritual->broadcast->published);
        Assert::same($ritual->store->snapshot()->round, 2);
    }

    public function newCycleKeepsTheRoomsSettings(): void
    {
        $ritual = self::atDawn();
        $ritual->story->thresholds(2);
        $ritual->bar->moveTarget(5);

        $ritual->story->nextScene(3, false);

        Assert::same($ritual->store->snapshot()->story->k, 2.0);
        Assert::same($ritual->bar->target($ritual->store->story()), 9);
    }

    public function consoleResetIsNotACycle(): void
    {
        $ritual = self::atDawn();
        $ritual->broadcast->published = [];

        $ritual->reset->reset();

        Assert::false(\array_key_exists('again', $ritual->broadcast->ofType('reset')[0]));
        Assert::blank($ritual->broadcast->ofType('owl'));
    }

    public function beforeTheDawnTheFinalSceneHasNoNextOne(): void
    {
        $ritual = self::atDawn(Story::RAPIER_STEP);

        Expect::exception(StoryRefusedException::class)->withMessage('final scene');

        $ritual->story->nextScene(3, true);
    }

    /**
     * @param int<0, 7> $step
     */
    private static function atDawn(int $step = Story::DAWN_STEP): RitualHarness
    {
        $ritual = new RitualHarness(threshold: 1, stageMax: [0, 1, 2]);
        $ritual->players->register(self::JACK, 'Jack', self::LOOK);
        $ritual->walks->move(self::JACK, 40, 92);
        foreach ([0, 1, 2] as $scene) {
            $ritual->story->nextScene($scene, true);
            $ritual->runJobs();
        }
        $ritual->store->updateStory(static fn(Story $story): Story => new Story(finale: $step, k: $story->k, barTarget: $story->barTarget));

        return $ritual;
    }
}
