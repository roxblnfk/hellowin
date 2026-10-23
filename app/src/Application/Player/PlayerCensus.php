<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Hellowin\PlayMetrics;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;

/**
 * Counts the known players and the beer they have drunk for the gauges: the KV store keeps no count, so the ticker
 * reads them all by the index now and then.
 */
final readonly class PlayerCensus
{
    /** Milliseconds between two counts of the ticker. */
    public const INTERVAL = 5000;

    /**
     * @param float $mugLitres Litres in one mug.
     */
    public function __construct(
        private PlayerStore $players,
        private PlayMetrics $metrics,
        private float $mugLitres = 0.5,
    ) {}

    public function count(): void
    {
        $players = $this->players->all();
        $this->metrics->players(
            \count($players),
            \count(\array_filter($players, static fn(Player $player): bool => $player->name !== null)),
        );
        $mugs = 0;
        foreach ($players as $player) {
            $mugs += $player->drinks;
        }
        $this->metrics->beer($mugs, $this->mugLitres);
    }
}
