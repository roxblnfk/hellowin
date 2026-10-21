<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\Pumpkin;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Story\Reveal;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Story::class)]
#[Covers(Reveal::class)]
final class StoryTest
{
    public function finaleMovesOnlyToTheNextStep(): void
    {
        $story = (new Story())->nextScene(final: true);

        Assert::same($story->stepTo(1)->finale, 1);
        Assert::same($story->stepTo(2)->finale, 0);
        Assert::same($story->stepTo(0)->finale, 0);
        Assert::null((new Story())->stepTo(1)->finale);
    }

    public function lastStepIsTheCursedWorld(): void
    {
        $story = new Story(finale: Story::LAST_STEP - 1);

        Assert::false($story->cursed());
        Assert::true($story->revealed());
        Assert::true($story->stepTo(Story::LAST_STEP)->cursed());
        Assert::same($story->stepTo(Story::LAST_STEP)->stepTo(Story::LAST_STEP + 1)->finale, Story::LAST_STEP);
    }

    public function storyRoundTripsThroughItsRecord(): void
    {
        $story = new Story(2, null, true, true, 'aaaaaaaaaaaaaaaa', 2, 0.5);

        Assert::equals(Story::fromArray($story->toArray()), $story);
        Assert::equals(Story::fromArray('garbage'), new Story());
    }

    public function multiplierOutOfRangeFallsBackToOne(): void
    {
        Assert::same(Story::fromArray(['k' => 0.05])->k, 1.0);
        Assert::same(Story::fromArray(['k' => 11])->k, 1.0);
        Assert::same(Story::fromArray(['k' => '2'])->k, 1.0);
        Assert::same(Story::fromArray(['k' => 10])->k, 10.0);
    }

    public function nextRoundKeepsThePauseAndTheMultiplier(): void
    {
        $story = new Story(1, 0, true, true, 'aaaaaaaaaaaaaaaa', 1, 2.5);

        Assert::equals($story->restarted(), new Story(paused: true, k: 2.5));
        Assert::same($story->nextScene(final: false)->k, 2.5);
    }

    public function burnedClicksAreTheOnesNoTransitionTook(): void
    {
        $ritual = new RitualSnapshot(1, 1, [new Pumpkin('a', 2, 7, 20, 10), new Pumpkin('b', 1, 1, 4, 5)]);

        $reveal = Reveal::of($ritual, new LockstepPolicy(5, [4, 8]), 3, null);

        // 24 accepted: 15 went into 3 transitions, 5 + 1 wait for the next ones
        Assert::same($reveal->toArray(), ['clicks' => 24, 'jobs' => 4, 'burned' => 3, 'shot' => 3, 'drinks' => 0, 'lastPumpkin' => null]);
    }

    public function burnedClicksFollowTheThresholdOfEachTransition(): void
    {
        // Pumpkin a reached its stages at k = 1 (5 clicks each); now k = 2 and the next stage takes 10
        $ritual = new RitualSnapshot(1, 1, [new Pumpkin('a', 2, 12, 24, 10)], new Story(k: 2.0));

        $reveal = Reveal::of($ritual, new LockstepPolicy(5, [4, 8]), 0, null);

        Assert::same($reveal->burned, 4);
    }
}
