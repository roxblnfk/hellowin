<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Flyer\Point;

/**
 * An elliptic obstacle: the pumpkin patch on the lawn, a table in the bar.
 */
final readonly class Ellipse implements Obstacle
{
    /**
     * Waypoints lie on an ellipse this much larger: the polygon cuts the corners of the arc, and the margin keeps its
     * sides off the inside.
     */
    private const MARGIN = 1.03;

    /** Waypoints around the ellipse: one every 15°. */
    private const WAYPOINTS = 24;

    /** Degrees between two sampled points of the edge. */
    private const EDGE_STEP = 0.5;

    private const EPSILON = 1e-6;

    public function __construct(
        public float $cx,
        public float $cy,
        public float $rx,
        public float $ry,
    ) {
        $rx > 0.0 && $ry > 0.0 or throw new \InvalidArgumentException('An ellipse needs positive radii.');
    }

    #[\Override]
    public function contains(Point $point): bool
    {
        return $this->radius($point) < 1.0 - self::EPSILON;
    }

    #[\Override]
    public function crosses(Point $from, Point $to): bool
    {
        // In coordinates where the ellipse is the unit circle: the distance from the centre to the segment
        $ax = ($from->x - $this->cx) / $this->rx;
        $ay = ($from->y - $this->cy) / $this->ry;
        $dx = ($to->x - $this->cx) / $this->rx - $ax;
        $dy = ($to->y - $this->cy) / $this->ry - $ay;
        $length2 = $dx * $dx + $dy * $dy;
        $t = $length2 > 0.0 ? \min(1.0, \max(0.0, -($ax * $dx + $ay * $dy) / $length2)) : 0.0;

        return \hypot($ax + $dx * $t, $ay + $dy * $t) < 1.0 - self::EPSILON;
    }

    /**
     * Along the ray from the centre; the centre itself leaves downwards.
     */
    #[\Override]
    public function exits(Point $point): array
    {
        $radius = $this->radius($point);

        return [$radius < self::EPSILON
            ? new Point($this->cx, $this->cy + $this->ry)
            : new Point($this->cx + ($point->x - $this->cx) / $radius, $this->cy + ($point->y - $this->cy) / $radius)];
    }

    #[\Override]
    public function crossings(array $xs, array $ys): array
    {
        $points = [];
        foreach ($xs as $x) {
            $k = 1.0 - (($x - $this->cx) / $this->rx) ** 2.0;
            if ($k >= 0.0) {
                $points[] = new Point($x, $this->cy - $this->ry * \sqrt($k));
                $points[] = new Point($x, $this->cy + $this->ry * \sqrt($k));
            }
        }
        foreach ($ys as $y) {
            $k = 1.0 - (($y - $this->cy) / $this->ry) ** 2.0;
            if ($k >= 0.0) {
                $points[] = new Point($this->cx - $this->rx * \sqrt($k), $y);
                $points[] = new Point($this->cx + $this->rx * \sqrt($k), $y);
            }
        }

        return $points;
    }

    #[\Override]
    public function waypoints(): array
    {
        $points = [];
        for ($i = 0; $i < self::WAYPOINTS; ++$i) {
            $angle = 2.0 * \M_PI * (float) $i / (float) self::WAYPOINTS;
            $points[] = new Point(
                $this->cx + $this->rx * self::MARGIN * \cos($angle),
                $this->cy + $this->ry * self::MARGIN * \sin($angle),
            );
        }

        return $points;
    }

    #[\Override]
    public function edge(): array
    {
        $points = [];
        for ($degree = 0.0; $degree < 360.0; $degree += self::EDGE_STEP) {
            $angle = \deg2rad($degree);
            $points[] = new Point($this->cx + $this->rx * \cos($angle), $this->cy + $this->ry * \sin($angle));
        }

        return $points;
    }

    /**
     * @return array{cx: float, cy: float, rx: float, ry: float}
     */
    #[\Override]
    public function toArray(): array
    {
        return ['cx' => $this->cx, 'cy' => $this->cy, 'rx' => $this->rx, 'ry' => $this->ry];
    }

    /**
     * @return float 1 on the edge, less inside.
     */
    private function radius(Point $point): float
    {
        return \hypot(($point->x - $this->cx) / $this->rx, ($point->y - $this->cy) / $this->ry);
    }
}
