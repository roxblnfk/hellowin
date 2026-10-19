<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ticker;

use App\Application\Ticker\Ticker;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(Ticker::class)]
final class TickerTest
{
    public function firstTickBuildsTheLeaderboard(): void
    {
        $ritual = new RitualHarness();
        $ticker = new Ticker($ritual->leaderboard, 5000, new NullLogger());

        $ticker->tick(1_000);

        Assert::count($ritual->broadcast->ofType('leaderboard'), 1);
    }

    public function leaderboardWaitsForItsInterval(): void
    {
        $ritual = new RitualHarness();
        $ticker = new Ticker($ritual->leaderboard, 5000, new NullLogger());

        $ticker->tick(1_000);
        $ticker->tick(3_000);
        $ticker->tick(5_999);
        $ticker->tick(6_000);

        Assert::count($ritual->broadcast->ofType('leaderboard'), 2);
    }
}
