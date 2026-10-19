<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * A fixed spot on the ground flyers take off from. Every flyer from the bush flies at its depth: a far bird rising
 * from a front bush would break the perspective.
 */
final readonly class Bush
{
    /**
     * @param non-empty-string $id
     * @param int<0, max> $depth
     */
    public function __construct(
        public string $id,
        public Point $at,
        public int $depth,
    ) {}

    /**
     * @return array{id: non-empty-string, x: float, y: float, depth: int<0, max>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id] + $this->at->toArray() + ['depth' => $this->depth];
    }
}
