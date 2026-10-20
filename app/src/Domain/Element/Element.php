<?php

declare(strict_types=1);

namespace App\Domain\Element;

/**
 * A clickable thing on the page: a pumpkin or a centrepiece in the middle of the patch — the bonfire of scenes 1–2 or
 * the magic circle of the final scene.
 */
final readonly class Element
{
    public const TYPE_PUMPKIN = 'pumpkin';

    /** The centrepiece the whole room clicks once the pumpkins of the scene are done. */
    public const TYPE_BONFIRE = 'bonfire';

    /** The centrepiece the whole room draws on the ashes at the start of the final scene. */
    public const TYPE_CIRCLE = 'circle';

    public const CENTREPIECES = [self::TYPE_BONFIRE, self::TYPE_CIRCLE];

    /**
     * @param non-empty-string $id
     * @param self::TYPE_* $type
     * @param float $x Horizontal position, percent of the viewport width.
     * @param float $y Vertical position, percent of the viewport height.
     * @param non-empty-list<non-empty-string> $emojis Pool to pick from on each click.
     * @param 's'|'m'|'l'|null $size Null for a centrepiece: it has one size.
     * @param bool $reachable A pumpkin a character can clink with from the lawn; never a centrepiece.
     */
    public function __construct(
        public string $id,
        public string $type,
        public float $x,
        public float $y,
        public array $emojis,
        public ?string $size = 'm',
        public bool $reachable = false,
    ) {}

    /**
     * One of a kind, with its own lock, record, threshold and stages: the bonfire or the circle.
     *
     * @psalm-assert-if-true self::TYPE_BONFIRE|self::TYPE_CIRCLE $this->type
     */
    public function centrepiece(): bool
    {
        return $this->type !== self::TYPE_PUMPKIN;
    }

    /**
     * Shape exposed to the page; emojis stay on the server.
     *
     * @return array{id: non-empty-string, type: self::TYPE_*, x: float, y: float, size?: 's'|'m'|'l', reachable?: bool}
     */
    public function toArray(): array
    {
        $data = ['id' => $this->id, 'type' => $this->type, 'x' => $this->x, 'y' => $this->y];
        $this->size === null or $data['size'] = $this->size;
        $this->centrepiece() or $data['reachable'] = $this->reachable;

        return $data;
    }
}
