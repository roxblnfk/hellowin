<?php

declare(strict_types=1);

namespace App\Domain\Bar;

/**
 * A surface of the bar's furniture a fly lands on besides the table tops and the counter: a band from `x1` to `x2` at
 * the height `y` (a point when `x1 == x2`), `y` the top of the surface. Percents of the screen.
 */
final readonly class Perch
{
    /**
     * @param non-empty-string $name The surface, like `beam` or `shield-1`: two landings in a row are on different ones.
     */
    public function __construct(
        public float $x1,
        public float $x2,
        public float $y,
        public string $name,
    ) {
        $x1 <= $x2 or throw new \InvalidArgumentException(\sprintf('The perch "%s" needs x1 <= x2.', $name));
    }

    /**
     * @return array{x1: float, x2: float, y: float, name: non-empty-string}
     */
    public function toArray(): array
    {
        return ['x1' => $this->x1, 'x2' => $this->x2, 'y' => $this->y, 'name' => $this->name];
    }
}
