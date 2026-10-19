<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * A fixed spot on the ground flyers take off from.
 */
final readonly class Bush
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        public string $id,
        public Point $at,
    ) {}

    /**
     * @return array{id: non-empty-string, x: float, y: float}
     */
    public function toArray(): array
    {
        return ['id' => $this->id] + $this->at->toArray();
    }
}
