<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Player;

use App\Application\Hellowin\PlayMetrics;
use App\Application\Player\LeaderboardService;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(LeaderboardService::class)]
final class LeaderboardServiceTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccc';

    public function buildPublishesAndKeepsTheTop(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 1, self::A);
        $ritual->click('pumpkin-1', 3, self::B);
        $ritual->players->register(self::C, 'Idle');
        $ritual->players->shotFlyer(self::A, 3);

        $top = $ritual->leaderboard->build();

        Assert::same(\array_column($top, 'id'), [self::A, self::B]);
        Assert::same($top[0], ['id' => self::A, 'name' => null, 'look' => null, 'clicks' => 1, 'finished' => 0, 'flyers' => 1, 'points' => 3]);
        Assert::same($ritual->broadcast->ofType('leaderboard'), [['type' => 'leaderboard', 'round' => 1, 'top' => $top, 'online' => 0]]);
        Assert::same($ritual->leaderboard->last(), $top);
    }

    public function buildCountsKnownAndRegisteredPlayers(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 1, self::A);
        $ritual->players->register(self::B, 'Jack');

        $ritual->leaderboard->build();

        Assert::same($ritual->counters()[PlayMetrics::PLAYERS . '{}'], 2.0);
        Assert::same($ritual->counters()[PlayMetrics::PLAYERS_REGISTERED . '{}'], 1.0);
    }

    public function emptyBoardBeforeTheFirstBuild(): void
    {
        Assert::same((new RitualHarness())->leaderboard->last(), []);
    }
}
