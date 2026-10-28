<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Flyer\Point;

/**
 * Where characters walk: a rectangle minus its obstacles, in percents of the screen. On the lawn the one obstacle is
 * the ellipse of the pumpkin patch; in the bar — the tables and the counter.
 */
final readonly class WalkZone
{
    /** How far, in percents, a waypoint may stick out of the rectangle and be pulled back into it. */
    private const SLACK = 2.0;

    private const EPSILON = 1e-6;

    /** @var list<Obstacle> */
    public array $obstacles;

    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        Obstacle ...$obstacles,
    ) {
        $x1 < $x2 && $y1 < $y2 or throw new \InvalidArgumentException('The walk area needs x1 < x2 and y1 < y2.');
        $this->obstacles = \array_values($obstacles);
    }

    /**
     * The lawn: the rectangle minus the ellipse of the pumpkin patch.
     */
    public static function lawn(float $x1, float $y1, float $x2, float $y2, float $cx, float $cy, float $rx, float $ry): self
    {
        return new self($x1, $y1, $x2, $y2, new Ellipse($cx, $cy, $rx, $ry));
    }

    /**
     * The pumpkin patch: the first elliptic obstacle. Zombies crawl out of it, characters flee it and pumpkins on its
     * edge are reachable from the lawn.
     */
    public function patch(): Ellipse
    {
        foreach ($this->obstacles as $obstacle) {
            if ($obstacle instanceof Ellipse) {
                return $obstacle;
            }
        }

        throw new \LogicException('The walk zone has no pumpkin patch.');
    }

    public function contains(Point $point): bool
    {
        if (!$this->inRect($point, 0.0)) {
            return false;
        }
        foreach ($this->obstacles as $obstacle) {
            if ($obstacle->contains($point)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The nearest point of the zone: outside the rectangle — on its edge; inside an obstacle — where it leaves the
     * obstacle, or where the obstacle's edge meets the rectangle when the obstacle sticks out of it there.
     */
    public function clamp(Point $point): Point
    {
        $point = new Point(\min(\max($point->x, $this->x1), $this->x2), \min(\max($point->y, $this->y1), $this->y2));
        if ($this->contains($point)) {
            return $point;
        }

        $candidates = [];
        foreach ($this->obstacles as $obstacle) {
            $obstacle->contains($point) and \array_push($candidates, ...$obstacle->exits($point));
            \array_push($candidates, ...$obstacle->crossings([$this->x1, $this->x2], [$this->y1, $this->y2]));
        }

        $nearest = $this->nearestOf($point, $candidates);
        if ($nearest === null) {
            // Obstacles overlap here: look along every edge
            $edges = [];
            foreach ($this->obstacles as $obstacle) {
                \array_push($edges, ...$obstacle->edge());
            }
            $nearest = $this->nearestOf($point, $edges);
        }

        return $nearest ?? $point;
    }

    /**
     * How far the point is from the zone: 0 in it.
     */
    public function distance(Point $point): float
    {
        $nearest = $this->clamp($point);

        return \hypot($nearest->x - $point->x, $nearest->y - $point->y);
    }

    /**
     * A point of the zone `$gap` to the side of `$target`, on the side `$from` comes from; the other side when the
     * zone squeezes that one into the target.
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
     * Where a point runs to when it flees the pumpkin patch: along the ray from the patch centre through the point to
     * the edge of the rectangle. The centre itself runs down.
     */
    public function outward(Point $point): Point
    {
        $patch = $this->patch();
        $dx = $point->x - $patch->cx;
        $dy = $point->y - $patch->cy;
        \abs($dx) < self::EPSILON && \abs($dy) < self::EPSILON and $dy = 1.0;

        // The ray is c + t·d: the exit is the nearest side of the rectangle ahead
        $exit = \INF;
        $dx > self::EPSILON and $exit = \min($exit, ($this->x2 - $patch->cx) / $dx);
        $dx < -self::EPSILON and $exit = \min($exit, ($this->x1 - $patch->cx) / $dx);
        $dy > self::EPSILON and $exit = \min($exit, ($this->y2 - $patch->cy) / $dy);
        $dy < -self::EPSILON and $exit = \min($exit, ($this->y1 - $patch->cy) / $dy);

        return $this->clamp(new Point($patch->cx + $dx * $exit, $patch->cy + $dy * $exit));
    }

    /**
     * A polyline from one point of the zone to another. A straight line that crosses an obstacle is replaced by the
     * shortest walk over the waypoints around the obstacles — a polygon a little outside each edge, kept in the
     * rectangle — where every leg keeps off the inside of every obstacle.
     *
     * @return non-empty-list<Point>
     */
    public function route(Point $from, Point $to): array
    {
        if ($this->clear($from, $to)) {
            return [$from, $to];
        }

        $nodes = [$from, $to];
        foreach ($this->obstacles as $obstacle) {
            foreach ($obstacle->waypoints() as $point) {
                if (!$this->inRect($point, self::SLACK)) {
                    continue;
                }
                $point = new Point(
                    \round(\min(\max($point->x, $this->x1), $this->x2), 2),
                    \round(\min(\max($point->y, $this->y1), $this->y2), 2),
                );
                $this->contains($point) and $nodes[] = $point;
            }
        }

        return $this->shortest($nodes) ?? [$from, $to];
    }

    /**
     * Dijkstra over the legs between the nodes that keep off every obstacle, from the first node to the second.
     *
     * @param non-empty-list<Point> $nodes
     * @return non-empty-list<Point>|null Null when no walk gets there.
     */
    private function shortest(array $nodes): ?array
    {
        $count = \count($nodes);
        $distance = \array_fill(0, $count, \INF);
        $previous = \array_fill(0, $count, null);
        $done = \array_fill(0, $count, false);
        $distance[0] = 0.0;
        $legs = [];

        while (true) {
            $current = null;
            foreach ($distance as $i => $d) {
                !$done[$i] && $d < \INF && ($current === null || $d < $distance[$current]) and $current = $i;
            }
            if ($current === null || $current === 1) {
                break;
            }
            $done[$current] = true;

            for ($next = 0; $next < $count; ++$next) {
                if ($done[$next]) {
                    continue;
                }
                $key = \min($current, $next) . ':' . \max($current, $next);
                $legs[$key] ??= $this->clear($nodes[$current], $nodes[$next]);
                if (!$legs[$key]) {
                    continue;
                }
                $length = $distance[$current] + \hypot($nodes[$next]->x - $nodes[$current]->x, $nodes[$next]->y - $nodes[$current]->y);
                $length < $distance[$next] and [$distance[$next], $previous[$next]] = [$length, $current];
            }
        }

        if ($distance[1] === \INF) {
            return null;
        }

        $path = [];
        for ($i = 1; $i !== null; $i = $previous[$i]) {
            \array_unshift($path, $nodes[$i]);
        }

        return $path === [] ? null : $path;
    }

    /**
     * Whether the segment keeps off the inside of every obstacle.
     */
    private function clear(Point $from, Point $to): bool
    {
        foreach ($this->obstacles as $obstacle) {
            if ($obstacle->crosses($from, $to)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Point> $candidates
     */
    private function nearestOf(Point $point, array $candidates): ?Point
    {
        $nearest = null;
        $best = \INF;
        foreach ($candidates as $candidate) {
            if (!$this->inRect($candidate, self::EPSILON) || !$this->contains($this->inside($candidate))) {
                continue;
            }
            $distance = \hypot($candidate->x - $point->x, $candidate->y - $point->y);
            $distance < $best and [$nearest, $best] = [$candidate, $distance];
        }

        return $nearest === null ? null : $this->inside($nearest);
    }

    /**
     * The point pulled into the rectangle: a candidate a rounding error outside it still counts.
     */
    private function inside(Point $point): Point
    {
        return new Point(\min(\max($point->x, $this->x1), $this->x2), \min(\max($point->y, $this->y1), $this->y2));
    }

    private function inRect(Point $point, float $slack): bool
    {
        return $point->x >= $this->x1 - $slack && $point->x <= $this->x2 + $slack
            && $point->y >= $this->y1 - $slack && $point->y <= $this->y2 + $slack;
    }
}
