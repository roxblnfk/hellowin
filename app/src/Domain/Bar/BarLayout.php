<?php

declare(strict_types=1);

namespace App\Domain\Bar;

use App\Domain\Flyer\Point;
use App\Domain\Walk\WalkZone;

/**
 * Where characters walk in the bar (docs/spec/protocol.md, «Бар»): the floor minus the tables and the counter, the
 * place in front of the counter where a mug is taken, the band new characters appear in and how close two characters
 * stand to clink by themselves. Percents of the screen, like the lawn.
 */
final readonly class BarLayout
{
    /**
     * @param array{float, float, float} $counter The place at the counter: from x1 to x2 along the line y.
     * @param array{float, float} $spawnY Top and bottom of the band new characters appear in.
     * @param float $clinkReach Percents of the screen: two characters at most this far apart clink by themselves.
     */
    public function __construct(
        public WalkZone $zone,
        public array $counter,
        public array $spawnY,
        public float $clinkReach,
    ) {
        $counter[0] <= $counter[1] or throw new \InvalidArgumentException('The place at the counter needs x1 <= x2.');
        $spawnY[0] <= $spawnY[1] or throw new \InvalidArgumentException('The spawn band of the bar needs y1 <= y2.');
    }

    /**
     * The point of the place at the counter nearest to the character, brought onto the floor.
     */
    public function atCounter(Point $from): Point
    {
        [$x1, $x2, $y] = $this->counter;

        return $this->zone->clamp(new Point(\min(\max($from->x, $x1), $x2), $y));
    }

    /**
     * The layout as the config writes it, for the frontend's art.
     *
     * @return array{area: array{x1: float, y1: float, x2: float, y2: float}, obstacles: list<array<non-empty-string, float>>, counter: array{x1: float, x2: float, y: float}, spawn: array{y1: float, y2: float}, clink_reach: float}
     */
    public function toArray(): array
    {
        $zone = $this->zone;

        return [
            'area' => ['x1' => $zone->x1, 'y1' => $zone->y1, 'x2' => $zone->x2, 'y2' => $zone->y2],
            'obstacles' => \array_map(static fn($obstacle): array => $obstacle->toArray(), $zone->obstacles),
            'counter' => ['x1' => $this->counter[0], 'x2' => $this->counter[1], 'y' => $this->counter[2]],
            'spawn' => ['y1' => $this->spawnY[0], 'y2' => $this->spawnY[1]],
            'clink_reach' => $this->clinkReach,
        ];
    }
}
