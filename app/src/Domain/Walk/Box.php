<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Flyer\Point;

/**
 * A rectangular obstacle: the counter of the bar.
 */
final readonly class Box implements Obstacle
{
    /** Waypoints stand this many percents off the corners, so a path around keeps clear of the sides. */
    private const MARGIN = 0.5;

    /** Percents between two sampled points of the edge. */
    private const EDGE_STEP = 0.25;

    private const EPSILON = 1e-6;

    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
    ) {
        $x1 < $x2 && $y1 < $y2 or throw new \InvalidArgumentException('A box needs x1 < x2 and y1 < y2.');
    }

    #[\Override]
    public function contains(Point $point): bool
    {
        return $point->x > $this->x1 + self::EPSILON && $point->x < $this->x2 - self::EPSILON
            && $point->y > $this->y1 + self::EPSILON && $point->y < $this->y2 - self::EPSILON;
    }

    /**
     * Clips the segment to the inside of the box (Liang–Barsky): it crosses when a piece of it is left.
     */
    #[\Override]
    public function crosses(Point $from, Point $to): bool
    {
        $dx = $to->x - $from->x;
        $dy = $to->y - $from->y;
        $t0 = 0.0;
        $t1 = 1.0;
        $sides = [
            [-$dx, $from->x - ($this->x1 + self::EPSILON)],
            [$dx, ($this->x2 - self::EPSILON) - $from->x],
            [-$dy, $from->y - ($this->y1 + self::EPSILON)],
            [$dy, ($this->y2 - self::EPSILON) - $from->y],
        ];
        foreach ($sides as [$p, $q]) {
            if (\abs($p) < 1e-12) {
                if ($q <= 0.0) {
                    return false;
                }
                continue;
            }
            $t = $q / $p;
            if ($p < 0.0) {
                $t0 = \max($t0, $t);
            } else {
                $t1 = \min($t1, $t);
            }
            if ($t0 >= $t1) {
                return false;
            }
        }

        return true;
    }

    #[\Override]
    public function exits(Point $point): array
    {
        return [
            new Point($this->x1, $point->y),
            new Point($this->x2, $point->y),
            new Point($point->x, $this->y1),
            new Point($point->x, $this->y2),
        ];
    }

    #[\Override]
    public function crossings(array $xs, array $ys): array
    {
        $points = [];
        foreach ($xs as $x) {
            $x >= $this->x1 && $x <= $this->x2 and \array_push($points, new Point($x, $this->y1), new Point($x, $this->y2));
        }
        foreach ($ys as $y) {
            $y >= $this->y1 && $y <= $this->y2 and \array_push($points, new Point($this->x1, $y), new Point($this->x2, $y));
        }

        return $points;
    }

    #[\Override]
    public function waypoints(): array
    {
        return [
            new Point($this->x1 - self::MARGIN, $this->y1 - self::MARGIN),
            new Point($this->x2 + self::MARGIN, $this->y1 - self::MARGIN),
            new Point($this->x2 + self::MARGIN, $this->y2 + self::MARGIN),
            new Point($this->x1 - self::MARGIN, $this->y2 + self::MARGIN),
        ];
    }

    #[\Override]
    public function edge(): array
    {
        $points = [];
        for ($x = $this->x1; $x <= $this->x2 + self::EPSILON; $x += self::EDGE_STEP) {
            \array_push($points, new Point($x, $this->y1), new Point($x, $this->y2));
        }
        for ($y = $this->y1; $y <= $this->y2 + self::EPSILON; $y += self::EDGE_STEP) {
            \array_push($points, new Point($this->x1, $y), new Point($this->x2, $y));
        }

        return $points;
    }

    /**
     * @return array{x1: float, y1: float, x2: float, y2: float}
     */
    #[\Override]
    public function toArray(): array
    {
        return ['x1' => $this->x1, 'y1' => $this->y1, 'x2' => $this->x2, 'y2' => $this->y2];
    }
}
