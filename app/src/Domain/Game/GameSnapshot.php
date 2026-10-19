<?php

declare(strict_types=1);

namespace App\Domain\Game;

final readonly class GameSnapshot
{
    /**
     * @param int<0, max> $clicks
     * @param int<0, max> $stage
     * @param positive-int $threshold Clicks that switch the stage.
     */
    public function __construct(
        public int $clicks,
        public int $stage,
        public int $threshold,
    ) {}

    /**
     * @return array{stage: int<0, max>, clicks: int<0, max>, threshold: positive-int}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'clicks' => $this->clicks,
            'threshold' => $this->threshold,
        ];
    }
}
