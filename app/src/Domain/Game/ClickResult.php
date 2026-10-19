<?php

declare(strict_types=1);

namespace App\Domain\Game;

final readonly class ClickResult
{
    /**
     * @param int<1, max> $clicks Total clicks including this one.
     * @param int<0, max> $stage
     * @param bool $stageChanged This click moved the game to a new stage.
     * @param positive-int $threshold Next threshold, or the last one once all are passed.
     */
    public function __construct(
        public int $clicks,
        public int $stage,
        public bool $stageChanged,
        public int $threshold,
    ) {}
}
