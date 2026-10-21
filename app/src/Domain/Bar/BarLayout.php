<?php

declare(strict_types=1);

namespace App\Domain\Bar;

use App\Domain\Flyer\Point;
use App\Domain\Walk\WalkZone;

/**
 * Where characters walk in the bar (docs/spec/protocol.md, «Бар»): the floor minus the tables and the counter, the
 * place in front of the counter where a mug is taken, the band new characters appear in and how close two characters
 * stand to clink by themselves, and where flies land on the furniture. Percents of the screen, like the lawn.
 */
final readonly class BarLayout
{
    /**
     * @param array{float, float, float} $counter The place at the counter: from x1 to x2 along the line y.
     * @param array{float, float} $spawnY Top and bottom of the band new characters appear in.
     * @param float $clinkReach Percents of the screen: two characters at most this far apart clink by themselves.
     * @param list<Perch> $perches Where flies land besides the tables and the counter, each a surface of its own.
     * @param Point|null $door The threshold of the entrance door; null — new characters appear in the spawn band.
     * @param float $doorIn Percents of the screen a new character walks in from the threshold, down the screen.
     * @param Point|null $roadrunner Where RoadRunner stands behind the counter after the dawn: `y` is the back edge of
     *        the counter; null — no RoadRunner to clink with.
     */
    public function __construct(
        public WalkZone $zone,
        public array $counter,
        public array $spawnY,
        public float $clinkReach,
        public array $perches = [],
        public ?Point $door = null,
        public float $doorIn = 6.0,
        public ?Point $roadrunner = null,
    ) {
        $counter[0] <= $counter[1] or throw new \InvalidArgumentException('The place at the counter needs x1 <= x2.');
        $spawnY[0] <= $spawnY[1] or throw new \InvalidArgumentException('The spawn band of the bar needs y1 <= y2.');
        $names = \array_map(static fn(Perch $perch): string => $perch->name, $perches);
        \count(\array_unique($names)) === \count($names) or throw new \InvalidArgumentException(
            'The perches of the bar need unique names: each is a surface of its own.',
        );
    }

    /**
     * The point of the place at the counter nearest to the character that is at least `$gap` from every other
     * character there or on its way there, brought onto the floor; with the counter full — just the nearest one, so
     * two characters do not stand on each other while there is room.
     *
     * @param list<float> $taken Where along the counter the others stand or are heading.
     * @param float $gap About the width of a character, percents of the screen; 0 takes the nearest point.
     */
    public function atCounter(Point $from, array $taken = [], float $gap = 0.0): Point
    {
        [$x1, $x2, $y] = $this->counter;
        $nearest = \min(\max($from->x, $x1), $x2);

        // Places along the counter a gap apart, outwards from the nearest one; the first free one is the nearest free
        $free = null;
        if ($gap > 0.0) {
            for ($k = 0.0; $free === null && ($nearest - $k * $gap >= $x1 - 1e-6 || $nearest + $k * $gap <= $x2 + 1e-6); $k += 1.0) {
                foreach ([$nearest - $k * $gap, $nearest + $k * $gap] as $x) {
                    if ($x < $x1 - 1e-6 || $x > $x2 + 1e-6) {
                        continue;
                    }
                    $clear = true;
                    foreach ($taken as $other) {
                        \abs($other - $x) < $gap - 1e-6 and $clear = false;
                    }
                    if ($clear) {
                        $free = $x;
                        break;
                    }
                }
            }
        }

        return $this->zone->clamp(new Point($free ?? $nearest, $y));
    }

    /**
     * The way a new character comes in: from the threshold, exactly where the frontend opens the door for a path that
     * starts there, `$doorIn` down the screen onto the floor.
     *
     * @return array{Point, Point}|null Null without a door.
     */
    public function doorway(): ?array
    {
        $door = $this->door;

        return $door === null ? null : [$door, $this->zone->clamp(new Point($door->x, $door->y + $this->doorIn))];
    }

    /**
     * Where along the counter a character stands or is heading, if its walk ends at the counter.
     */
    public function counterSpot(Point $end): ?float
    {
        [$x1, $x2, $y] = $this->counter;

        return \abs($end->y - $y) < 0.5 && $end->x >= $x1 - 0.5 && $end->x <= $x2 + 0.5 ? $end->x : null;
    }

    /**
     * The layout as the config writes it, for the frontend's art.
     *
     * @return array{area: array{x1: float, y1: float, x2: float, y2: float}, obstacles: list<array<non-empty-string, float>>, counter: array{x1: float, x2: float, y: float}, spawn: array{y1: float, y2: float}, clink_reach: float, perches: list<array{x1: float, x2: float, y: float, name: non-empty-string}>, door?: array{x: float, y: float}, door_in?: float, roadrunner?: array{x: float, y: float}}
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
            'perches' => \array_map(static fn(Perch $perch): array => $perch->toArray(), $this->perches),
        ] + ($this->door === null ? [] : ['door' => $this->door->toArray(), 'door_in' => $this->doorIn])
            + ($this->roadrunner === null ? [] : ['roadrunner' => $this->roadrunner->toArray()]);
    }
}
