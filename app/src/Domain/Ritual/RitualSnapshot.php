<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

final readonly class RitualSnapshot
{
    /**
     * @param positive-int $round
     * @param int<0, max> $scene
     * @param list<Pumpkin> $pumpkins
     */
    public function __construct(
        public int $round,
        public int $scene,
        public array $pumpkins,
    ) {}

    public function pumpkin(string $id): ?Pumpkin
    {
        foreach ($this->pumpkins as $pumpkin) {
            if ($pumpkin->id === $id) {
                return $pumpkin;
            }
        }

        return null;
    }
}
