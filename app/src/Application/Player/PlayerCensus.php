<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Hellowin\PlayMetrics;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;

/**
 * Counts the known players for the gauges: the KV store keeps no count, so the ticker reads them all by the index now
 * and then.
 */
final readonly class PlayerCensus
{
    /** Milliseconds between two counts of the ticker. */
    public const INTERVAL = 5000;

    public function __construct(
        private PlayerStore $players,
        private PlayMetrics $metrics,
    ) {}

    public function count(): void
    {
        $players = $this->players->all();
        $this->metrics->players(
            \count($players),
            \count(\array_filter($players, static fn(Player $player): bool => $player->name !== null)),
        );
    }
}
