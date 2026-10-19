<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * A point on the screen in percents of the viewport; outside 0–100 it is behind the screen edge.
 */
final readonly class Point
{
    public function __construct(
        public float $x,
        public float $y,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        return \is_array($value)
            && isset($value['x'], $value['y'])
            && (\is_int($value['x']) || \is_float($value['x']))
            && (\is_int($value['y']) || \is_float($value['y']))
            ? new self((float) $value['x'], (float) $value['y'])
            : null;
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
