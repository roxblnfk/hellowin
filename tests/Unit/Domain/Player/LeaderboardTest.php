<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Player;

use App\Domain\Player\Leaderboard;
use App\Domain\Player\Player;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Leaderboard::class)]
final class LeaderboardTest
{
    public function ranksByPointsThenFinishedThenClicks(): void
    {
        $top = Leaderboard::top([
            new Player('c', clicks: 50),
            new Player('a', clicks: 1, flyers: 1, points: 3),
            new Player('d', clicks: 10, finished: 2),
            new Player('b', clicks: 2, flyers: 1, points: 3),
            new Player('e', clicks: 10, finished: 2),
        ], 10);

        Assert::same(\array_map(static fn(Player $p): string => $p->id, $top), ['b', 'a', 'd', 'e', 'c']);
    }

    public function idlePlayersAreLeftOut(): void
    {
        Assert::same(Leaderboard::top([new Player('idle', 'Named but idle')], 10), []);
    }

    public function cutsToTheSize(): void
    {
        $players = \array_map(static fn(int $i): Player => new Player('p' . $i, clicks: $i), \range(1, 5));

        $top = Leaderboard::top($players, 2);

        Assert::same(\array_map(static fn(Player $p): string => $p->id, $top), ['p5', 'p4']);
    }
}
