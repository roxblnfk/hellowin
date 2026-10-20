<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Flyer\Point;

/**
 * The lawn characters walk on: a rectangle minus the ellipse of the pumpkin patch, in percents of the screen.
 */
final readonly class WalkZone
{
    /**
     * Paths go around an ellipse this much larger than the patch: the polyline cuts the corners of its arc, and the
     * margin keeps those chords off the patch.
     */
    private const MARGIN = 1.03;

    /** The largest angle of the arc around the patch between two points of the path. */
    private const STEP = \M_PI / 12.0;

    /** How far, in percents, a vertex of the arc may stick out of the rectangle and be pulled back into it. */
    private const SLACK = 2.0;

    private const EPSILON = 1e-6;

    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        public float $cx,
        public float $cy,
        public float $rx,
        public float $ry,
    ) {
        $x1 < $x2 && $y1 < $y2 or throw new \InvalidArgumentException('The walk area needs x1 < x2 and y1 < y2.');
        $rx > 0.0 && $ry > 0.0 or throw new \InvalidArgumentException('The patch needs positive radii.');
    }

    public function contains(Point $point): bool
    {
        return $this->inRect($point, 0.0) && $this->radius($point, 1.0) >= 1.0 - self::EPSILON;
    }

    /**
     * The nearest point of the lawn: outside the rectangle — on its edge, inside the patch — on the patch edge,
     * or where the patch edge meets the rectangle when the patch sticks out of it there.
     */
    public function clamp(Point $point): Point
    {
        $point = new Point(\min(\max($point->x, $this->x1), $this->x2), \min(\max($point->y, $this->y1), $this->y2));
        $radius = $this->radius($point, 1.0);
        if ($radius >= 1.0) {
            return $point;
        }

        $candidates = $this->corners();
        $edge = $radius < self::EPSILON
            ? new Point($this->cx, $this->cy + $this->ry)
            : new Point($this->cx + ($point->x - $this->cx) / $radius, $this->cy + ($point->y - $this->cy) / $radius);
        $this->inRect($edge, self::EPSILON) and $candidates[] = $edge;

        $nearest = $edge;
        $best = \INF;
        foreach ($candidates as $candidate) {
            $distance = \hypot($candidate->x - $point->x, $candidate->y - $point->y);
            $distance < $best and [$nearest, $best] = [$candidate, $distance];
        }

        return $nearest;
    }

    /**
     * How far the point is from the lawn: 0 on it.
     */
    public function distance(Point $point): float
    {
        $nearest = $this->clamp($point);

        return \hypot($nearest->x - $point->x, $nearest->y - $point->y);
    }

    /**
     * A lawn point `$gap` to the side of `$target`, on the side `$from` comes from; the other side when the lawn
     * squeezes that one into the target.
     */
    public function beside(Point $target, Point $from, float $gap): Point
    {
        $side = $from->x >= $target->x ? 1.0 : -1.0;
        foreach ([$side, -$side] as $direction) {
            $point = $this->clamp(new Point($target->x + $direction * $gap, $target->y));
            if (\hypot($point->x - $target->x, $point->y - $target->y) >= $gap / 2.0) {
                return $point;
            }
        }

        return $this->clamp(new Point($target->x + $side * $gap, $target->y));
    }

    /**
     * Where a lawn point runs to when it flees the patch: along the ray from the patch centre through the point to the
     * edge of the rectangle. The centre itself runs down.
     */
    public function outward(Point $point): Point
    {
        $dx = $point->x - $this->cx;
        $dy = $point->y - $this->cy;
        \abs($dx) < self::EPSILON && \abs($dy) < self::EPSILON and $dy = 1.0;

        // The ray is c + t·d: the exit is the nearest side of the rectangle ahead
        $exit = \INF;
        $dx > self::EPSILON and $exit = \min($exit, ($this->x2 - $this->cx) / $dx);
        $dx < -self::EPSILON and $exit = \min($exit, ($this->x1 - $this->cx) / $dx);
        $dy > self::EPSILON and $exit = \min($exit, ($this->y2 - $this->cy) / $dy);
        $dy < -self::EPSILON and $exit = \min($exit, ($this->y1 - $this->cy) / $dy);

        return $this->clamp(new Point($this->cx + $dx * $exit, $this->cy + $dy * $exit));
    }

    /**
     * A polyline from one lawn point to another. A straight line that crosses the patch is replaced by a walk around
     * it: along the tangent to the patch edge, along the edge, along the tangent to the target — on the shorter side
     * of the two that fit into the rectangle.
     *
     * @return non-empty-list<Point>
     */
    public function route(Point $from, Point $to): array
    {
        if (!$this->crossesPatch($from, $to)) {
            return [$from, $to];
        }

        $best = null;
        $bestLength = \INF;
        foreach ([1, -1] as $direction) {
            $path = $this->around($from, $to, $direction);
            if ($path === null) {
                continue;
            }
            $length = self::length($path);
            $length < $bestLength and [$best, $bestLength] = [$path, $length];
        }

        return $best ?? [$from, $to];
    }

    /**
     * @param list<Point> $path
     */
    private static function length(array $path): float
    {
        $length = 0.0;
        for ($i = 1, $count = \count($path); $i < $count; ++$i) {
            $length += \hypot($path[$i]->x - $path[$i - 1]->x, $path[$i]->y - $path[$i - 1]->y);
        }

        return $length;
    }

    /**
     * Whether the segment passes through the inside of the patch; touching its edge is fine.
     */
    private function crossesPatch(Point $from, Point $to): bool
    {
        // In coordinates where the patch is the unit circle: the distance from the centre to the segment
        $ax = ($from->x - $this->cx) / $this->rx;
        $ay = ($from->y - $this->cy) / $this->ry;
        $dx = ($to->x - $this->cx) / $this->rx - $ax;
        $dy = ($to->y - $this->cy) / $this->ry - $ay;
        $length2 = $dx * $dx + $dy * $dy;
        $t = $length2 > 0.0 ? \min(1.0, \max(0.0, -($ax * $dx + $ay * $dy) / $length2)) : 0.0;

        return \hypot($ax + $dx * $t, $ay + $dy * $t) < 1.0 - self::EPSILON;
    }

    /**
     * Around the patch counterclockwise (`$direction` 1) or clockwise (-1), in coordinates where the enlarged patch
     * is the unit circle: there tangents and arcs are plain trigonometry, and scaling back keeps them tangents.
     *
     * @param 1|-1 $direction
     * @return non-empty-list<Point>|null Null when the walk leaves the rectangle.
     */
    private function around(Point $from, Point $to, int $direction): ?array
    {
        $rx = $this->rx * self::MARGIN;
        $ry = $this->ry * self::MARGIN;
        $start = $this->tangent($from, $rx, $ry, $direction);
        $end = $this->tangent($to, $rx, $ry, -$direction);

        $sweep = \fmod((float) $direction * ($end - $start), 2.0 * \M_PI);
        $sweep < 0.0 and $sweep += 2.0 * \M_PI;
        $steps = \max(1, (int) \ceil($sweep / (float) self::STEP));

        $path = [$from];
        for ($i = 0; $i <= $steps; ++$i) {
            $angle = $start + (float) $direction * $sweep * (float) $i / (float) $steps;
            $point = new Point($this->cx + $rx * \cos($angle), $this->cy + $ry * \sin($angle));
            if (!$this->inRect($point, self::SLACK)) {
                return null;
            }
            $path[] = new Point(
                \round(\min(\max($point->x, $this->x1), $this->x2), 2),
                \round(\min(\max($point->y, $this->y1), $this->y2), 2),
            );
        }
        $path[] = $to;

        return $path;
    }

    /**
     * The angle of the point where the tangent from `$point` touches the enlarged patch, on the side the walk turns
     * to; a point within the margin touches it right where it is.
     */
    private function tangent(Point $point, float $rx, float $ry, int $direction): float
    {
        $u = ($point->x - $this->cx) / $rx;
        $v = ($point->y - $this->cy) / $ry;
        $distance = \hypot($u, $v);

        return \atan2($v, $u) + (float) $direction * \acos(\min(1.0, 1.0 / \max($distance, self::EPSILON)));
    }

    /**
     * @return list<Point> Where the patch edge crosses the sides of the rectangle.
     */
    private function corners(): array
    {
        $corners = [];
        foreach ([$this->x1, $this->x2] as $x) {
            $k = 1.0 - (($x - $this->cx) / $this->rx) ** 2.0;
            if ($k >= 0.0) {
                $corners[] = new Point($x, $this->cy - $this->ry * \sqrt($k));
                $corners[] = new Point($x, $this->cy + $this->ry * \sqrt($k));
            }
        }
        foreach ([$this->y1, $this->y2] as $y) {
            $k = 1.0 - (($y - $this->cy) / $this->ry) ** 2.0;
            if ($k >= 0.0) {
                $corners[] = new Point($this->cx - $this->rx * \sqrt($k), $y);
                $corners[] = new Point($this->cx + $this->rx * \sqrt($k), $y);
            }
        }

        return \array_values(\array_filter($corners, fn(Point $point): bool => $this->inRect($point, self::EPSILON)));
    }

    /**
     * @return float 1 on the edge of the patch scaled by `$scale`, less inside it.
     */
    private function radius(Point $point, float $scale): float
    {
        return \hypot(($point->x - $this->cx) / ($this->rx * $scale), ($point->y - $this->cy) / ($this->ry * $scale));
    }

    private function inRect(Point $point, float $slack): bool
    {
        return $point->x >= $this->x1 - $slack && $point->x <= $this->x2 + $slack
            && $point->y >= $this->y1 - $slack && $point->y <= $this->y2 + $slack;
    }
}
