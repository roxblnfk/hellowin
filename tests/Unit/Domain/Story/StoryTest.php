<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Balance\Limit;
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

    public function cursedWorldIsTheGoalAndTheRapierAndTheDawnIsLast(): void
    {
        $story = new Story(ready: 1, finale: Story::REVEAL_STEP);

        Assert::false($story->cursed());
        Assert::true($story->revealed());
        $cursed = $story->stepTo(Story::CURSED_STEP);
        Assert::true($cursed->cursed());
        // The goal of the cursed world makes it ready, not the circle
        Assert::null($cursed->ready);
        $rapier = $cursed->stepTo(Story::RAPIER_STEP);
        Assert::true($rapier->cursed() && $rapier->rapier());
        $dawn = $rapier->stepTo(Story::DAWN_STEP);
        Assert::true($dawn->dawn());
        Assert::false($dawn->cursed());
        Assert::same($dawn->stepTo(Story::DAWN_STEP + 1)->finale, Story::LAST_STEP);
    }

    public function rapierWaitsForTheGoalAndTheDawnForTheBoss(): void
    {
        $cursed = new Story(finale: Story::CURSED_STEP);

        Assert::true($cursed->awaitsReady(Story::RAPIER_STEP));
        Assert::false($cursed->readyIn(1, null)->awaitsReady(Story::RAPIER_STEP));
        Assert::true((new Story(ready: 1, finale: Story::RAPIER_STEP))->awaitsReady(Story::DAWN_STEP));
        Assert::false(Story::fromArray(['finale' => 8])->finale !== null);
    }

    public function storyRoundTripsThroughItsRecord(): void
    {
        $story = new Story(2, null, true, 'aaaaaaaaaaaaaaaa', 2, 0.5, -3, true, 7);

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

    public function nextRoundKeepsTheMultiplierAndTheRoomButNotTheBonusOrThePause(): void
    {
        $story = new Story(1, 0, true, 'aaaaaaaaaaaaaaaa', 1, 2.5, 4, false, 6);

        Assert::equals($story->restarted(), new Story(k: 2.5, players: 6));
        Assert::same($story->nextScene(final: false)->k, 2.5);
    }

    public function burnedClicksAreTheOnesNoTransitionTook(): void
    {
        $ritual = new RitualSnapshot(1, 1, [new Pumpkin('a', 2, 7, 20, 10), new Pumpkin('b', 1, 1, 4, 5)]);

        $reveal = Reveal::of($ritual, new LockstepPolicy(new Limit(5), [4, 8]), 3, null);

        // 24 accepted: 15 went into 3 transitions, 5 + 1 wait for the next ones
        Assert::same($reveal->toArray(), ['clicks' => 24, 'jobs' => 4, 'burned' => 3, 'shot' => 3, 'drinks' => 0, 'slain' => 0, 'damage' => 0, 'rolls' => 0, 'lastPumpkin' => null]);
    }

    public function burnedClicksFollowTheThresholdOfEachTransition(): void
    {
        // Pumpkin a reached its stages at k = 1 (5 clicks each); now k = 2 and the next stage takes 10
        $ritual = new RitualSnapshot(1, 1, [new Pumpkin('a', 2, 12, 24, 10)], new Story(k: 2.0));

        $reveal = Reveal::of($ritual, new LockstepPolicy(new Limit(5), [4, 8]), 0, null);

        Assert::same($reveal->burned, 4);
    }
}
