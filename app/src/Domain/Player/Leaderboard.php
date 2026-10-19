<?php

declare(strict_types=1);

namespace App\Domain\Player;

final class Leaderboard
{
    /**
     * Ranks by points for flyers, then by finished pumpkins, then by clicks; players who have done nothing
     * yet are left out.
     *
     * @param iterable<Player> $players
     * @param positive-int $size
     * @return list<Player>
     */
    public static function top(iterable $players, int $size): array
    {
        $ranked = [];
        foreach ($players as $player) {
            $player->clicks + $player->finished + $player->flyers > 0 and $ranked[] = $player;
        }

        \usort(
            $ranked,
            static fn(Player $a, Player $b): int
                => [$b->points, $b->finished, $b->clicks, $a->id] <=> [$a->points, $a->finished, $a->clicks, $b->id],
        );

        return \array_slice($ranked, 0, $size);
    }
}
