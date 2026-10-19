<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

final readonly class Pumpkin
{
    /**
     * @param non-empty-string $id Element id.
     * @param int<0, max> $stage
     * @param int<0, max> $clicks Clicks since the pumpkin reached its current stage.
     */
    public function __construct(
        public string $id,
        public int $stage = 0,
        public int $clicks = 0,
    ) {}

    /**
     * @return array{id: non-empty-string, stage: int<0, max>, clicks: int<0, max>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'stage' => $this->stage, 'clicks' => $this->clicks];
    }
}
