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
        $story = new Story(2, null, true, true, 'aaaaaaaaaaaaaaaa', 2);

        Assert::equals(Story::fromArray($story->toArray()), $story);
        Assert::equals(Story::fromArray('garbage'), new Story());
    }

    public function burnedClicksAreTheOnesNoTransitionTook(): void
    {
        $ritual = new RitualSnapshot(1, 1, [new Pumpkin('a', 2, 7, 20), new Pumpkin('b', 1, 1, 4)]);

        $reveal = Reveal::of($ritual, new LockstepPolicy(5, [4, 8]), 3, null);

        // 24 accepted: 15 went into 3 transitions, 5 + 1 wait for the next ones
        Assert::same($reveal->toArray(), ['clicks' => 24, 'jobs' => 4, 'burned' => 3, 'shot' => 3, 'lastPumpkin' => null]);
    }
}
