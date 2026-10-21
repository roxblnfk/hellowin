<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Flyer\Point;

/**
 * Something characters walk around: the pumpkin patch on the lawn, a table or the counter in the bar. Coordinates are
 * percents of the screen. Touching the edge is fine, only the inside is off limits.
 */
interface Obstacle
{
    /**
     * Whether the point is inside, its edge excluded.
     */
    public function contains(Point $point): bool;

    /**
     * Whether the segment passes through the inside; touching or running along the edge is fine.
     */
    public function crosses(Point $from, Point $to): bool;

    /**
     * Points of the edge nearest to a point inside: where it leaves the obstacle.
     *
     * @return list<Point>
     */
    public function exits(Point $point): array;

    /**
     * Where the edge meets the lines `x = $x` and `y = $y` of each given value: the sides of the walk area.
     *
     * @param list<float> $xs
     * @param list<float> $ys
     * @return list<Point>
     */
    public function crossings(array $xs, array $ys): array;

    /**
     * Waypoints of a path around: a polygon a little outside the edge, whose sides keep off the inside.
     *
     * @return list<Point>
     */
    public function waypoints(): array;

    /**
     * The edge sampled densely, for searches that cannot solve it exactly.
     *
     * @return list<Point>
     */
    public function edge(): array;

    /**
     * @return array<non-empty-string, float> The shape as the config writes it.
     */
    public function toArray(): array;
}
