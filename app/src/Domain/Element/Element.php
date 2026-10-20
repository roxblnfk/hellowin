<?php

declare(strict_types=1);

namespace App\Domain\Element;

/**
 * A clickable thing on the page: a pumpkin or the bonfire in the middle of the patch.
 */
final readonly class Element
{
    public const TYPE_PUMPKIN = 'pumpkin';

    /** The one element the whole room clicks once the pumpkins of the scene are done. */
    public const TYPE_BONFIRE = 'bonfire';

    /**
     * @param non-empty-string $id
     * @param self::TYPE_* $type
     * @param float $x Horizontal position, percent of the viewport width.
     * @param float $y Vertical position, percent of the viewport height.
     * @param non-empty-list<non-empty-string> $emojis Pool to pick from on each click.
     * @param 's'|'m'|'l'|null $size Null for the bonfire: it has one size.
     */
    public function __construct(
        public string $id,
        public string $type,
        public float $x,
        public float $y,
        public array $emojis,
        public ?string $size = 'm',
    ) {}

    public function bonfire(): bool
    {
        return $this->type === self::TYPE_BONFIRE;
    }

    /**
     * Shape exposed to the page; emojis stay on the server.
     *
     * @return array{id: non-empty-string, type: self::TYPE_*, x: float, y: float, size?: 's'|'m'|'l'}
     */
    public function toArray(): array
    {
        $data = ['id' => $this->id, 'type' => $this->type, 'x' => $this->x, 'y' => $this->y];
        $this->size === null or $data['size'] = $this->size;

        return $data;
    }
}
