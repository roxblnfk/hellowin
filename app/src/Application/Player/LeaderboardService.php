<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Domain\Player\Leaderboard;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\WalkerStore;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds the leaderboard from every player: the KV store cannot sort, so the ticker reads the players by the index
 * and ranks them here. The result is kept under `hellowin:leaderboard` for RPC `me` and published to the channel.
 *
 * @psalm-type Top = list<array{id: non-empty-string, name: non-empty-string|null, look: array<non-empty-string, non-empty-string>|null, clicks: int<0, max>, finished: int<0, max>, flyers: int<0, max>, points: int<0, max>}>
 */
final readonly class LeaderboardService
{
    private const KEY = 'hellowin:leaderboard';

    /**
     * @param positive-int $size
     * @param WalkerStore|null $walkers Characters on the lawn: the event carries how many players are online.
     */
    public function __construct(
        private PlayerStore $players,
        private RitualStore $ritual,
        private CacheInterface $storage,
        private ChannelPublisher $publisher,
        private PlayMetrics $metrics,
        private int $size,
        private ?WalkerStore $walkers = null,
    ) {}

    /**
     * @return Top
     */
    public function build(): array
    {
        $players = $this->players->all();
        $top = \array_map(static fn(Player $player): array => $player->toArray(), Leaderboard::top($players, $this->size));

        $this->storage->set(self::KEY, $top);
        $this->publisher->leaderboard($this->ritual->round(), $top, \count($this->walkers?->all() ?? []));
        $this->metrics->players(
            \count($players),
            \count(\array_filter($players, static fn(Player $player): bool => $player->name !== null)),
        );

        return $top;
    }

    /**
     * The last built leaderboard.
     *
     * @return list<array>
     */
    public function last(): array
    {
        /** @var mixed $top */
        $top = $this->storage->get(self::KEY);
        if (!\is_array($top)) {
            return [];
        }

        $rows = [];
        /** @var mixed $row */
        foreach ($top as $row) {
            \is_array($row) and $rows[] = $row;
        }

        return $rows;
    }
}
