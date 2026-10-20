<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

final readonly class Pumpkin
{
    /**
     * @param non-empty-string $id Element id.
     * @param int<0, max> $stage
     * @param int<0, max> $clicks Clicks since the pumpkin reached its current stage.
     * @param int<0, max> $accepted Clicks on the pumpkin in the round, burned ones included.
     */
    public function __construct(
        public string $id,
        public int $stage = 0,
        public int $clicks = 0,
        public int $accepted = 0,
    ) {}
}
