<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ticker;

use App\Application\Hellowin\PlayMetrics;
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
    public function firstTickBuildsTheLeaderboardAndLaunchesNothing(): void
    {
        $ritual = new RitualHarness();

        self::ticker($ritual)->tick(1_000);

        Assert::count($ritual->broadcast->ofType('leaderboard'), 1);
        Assert::blank($ritual->broadcast->ofType('flyer'));
    }

    public function leaderboardWaitsForItsInterval(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);

        $ticker->tick(1_000);
        $ticker->tick(3_000);
        $ticker->tick(5_999);
        $ticker->tick(6_000);

        Assert::count($ritual->broadcast->ofType('leaderboard'), 2);
    }

    public function flyerIsLaunchedOncePerInterval(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);

        $ticker->tick(0);
        $ticker->tick(1_999);
        $ticker->tick(2_000);
        $ticker->tick(2_100);
        $ticker->tick(4_000);

        Assert::count($ritual->broadcast->ofType('flyer'), 2);
        Assert::count(
            \array_filter($ritual->metrics->calls, static fn(array $call): bool => $call[1] === PlayMetrics::FLYERS_LAUNCHED),
            2,
        );
    }

    public function pauseStopsTheLaunches(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);
        $ritual->story->pause(true);

        $ticker->tick(0);
        $ticker->tick(2_000);
        $ritual->story->pause(false);
        $ticker->tick(4_000);

        Assert::count($ritual->broadcast->ofType('flyer'), 1);
    }

    public function flyersFollowTheScene(): void
    {
        $ritual = new RitualHarness();
        $ritual->transitions->scene(1, 1);

        Assert::same($ritual->flyers->launch(0)->kind, 'bat');
    }

    public function escapedFlyerIsCountedOnceAfterItsFlight(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);
        $flyer = $ritual->broadcast->ofType('flyer')[0];
        $gone = 2_000 + $flyer['duration'] + 500;

        $ticker->tick($gone - 1);
        $before = self::escaped($ritual);
        $ticker->tick($gone);
        $ticker->tick($gone + 100);

        Assert::same($before, 0.0);
        Assert::same(self::escaped($ritual), 1.0);
    }

    public function shotFlyerNeverEscapes(): void
    {
        $ritual = new RitualHarness();
        $ticker = self::ticker($ritual);
        $ticker->tick(0);
        $ticker->tick(2_000);
        $ritual->clock->now = 2_000;
        $ritual->flyers->shoot($ritual->broadcast->ofType('flyer')[0]['flyer'], null);

        $ticker->tick(60_000);

        Assert::same(self::escaped($ritual), 0.0);
    }

    private static function ticker(RitualHarness $ritual): Ticker
    {
        return new Ticker($ritual->leaderboard, 5000, $ritual->flyers, [2000, 2000], $ritual->walks, new NullLogger());
    }

    private static function escaped(RitualHarness $ritual): float
    {
        $total = 0.0;
        foreach ($ritual->counters() as $key => $value) {
            \str_starts_with($key, PlayMetrics::FLYERS_ESCAPED . '{') and $total += $value;
        }

        return $total;
    }
}
