<?php

declare(strict_types=1);

namespace App\Domain\Element;

/**
 * A clickable thing on the page.
 */
final readonly class Element
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $type
     * @param float $x Horizontal position, percent of the viewport width.
     * @param float $y Vertical position, percent of the viewport height.
     * @param non-empty-list<non-empty-string> $emojis Pool to pick from on each click.
     */
    public function __construct(
        public string $id,
        public string $type,
        public float $x,
        public float $y,
        public array $emojis,
    ) {}

    /**
     * Shape exposed to the page; emojis stay on the server.
     *
     * @return array{id: non-empty-string, type: non-empty-string, x: float, y: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'x' => $this->x,
            'y' => $this->y,
        ];
    }
}
