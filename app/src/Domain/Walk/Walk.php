<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Flyer\Point;

/**
 * A character's walk along a polyline at a constant speed, on the server clock. Every client moves the character
 * by the same walk, so all of them show it in the same place. A standing character has a path of one point.
 */
final readonly class Walk
{
    /**
     * @param non-empty-list<Point> $path From the start to the target.
     * @param int $startedAt Unix time in milliseconds.
     * @param int $arrivesAt Unix time in milliseconds, not before `$startedAt`.
     */
    private function __construct(
        public array $path,
        public int $startedAt,
        public int $arrivesAt,
    ) {}

    /**
     * @param int $now Unix time in milliseconds.
     */
    public static function standing(Point $at, int $now): self
    {
        return new self([$at], $now, $now);
    }

    /**
     * Starts a walk along the path now.
     *
     * @param non-empty-list<Point> $path
     * @param int $now Unix time in milliseconds.
     * @param float $speed Percents of the screen per second along the path.
     */
    public static function along(array $path, int $now, float $speed): self
    {
        $points = [$path[0]];
        foreach ($path as $point) {
            $last = $points[\count($points) - 1];
            $point->x === $last->x && $point->y === $last->y or $points[] = $point;
        }

        $duration = (int) \round(self::length($points) / $speed * 1000.0);

        return \count($points) === 1 || $duration === 0
            ? self::standing($points[\count($points) - 1], $now)
            : new self($points, $now, $now + $duration);
    }

    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value) || !isset($value['path']) || !\is_array($value['path'])) {
            return null;
        }

        $path = [];
        /** @var mixed $point */
        foreach ($value['path'] as $point) {
            $point = Point::fromArray($point);
            if ($point === null) {
                return null;
            }
            $path[] = $point;
        }

        /** @var mixed $startedAt */
        $startedAt = $value['startedAt'] ?? null;
        /** @var mixed $arrivesAt */
        $arrivesAt = $value['arrivesAt'] ?? null;

        return $path !== [] && \is_int($startedAt) && \is_int($arrivesAt) && $arrivesAt >= $startedAt
            ? new self($path, $startedAt, $arrivesAt)
            : null;
    }

    /**
     * Where the character is at the moment: the start before the walk, the target after it.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function positionAt(int $now): Point
    {
        $last = $this->path[\count($this->path) - 1];
        if ($now >= $this->arrivesAt) {
            return $last;
        }
        if ($now <= $this->startedAt) {
            return $this->path[0];
        }

        $left = self::length($this->path) * (float) ($now - $this->startedAt) / (float) ($this->arrivesAt - $this->startedAt);
        for ($i = 1, $count = \count($this->path); $i < $count; ++$i) {
            $from = $this->path[$i - 1];
            $to = $this->path[$i];
            $segment = \hypot($to->x - $from->x, $to->y - $from->y);
            if ($segment > 0 && $left <= $segment) {
                $t = $left / $segment;
                return new Point($from->x + ($to->x - $from->x) * $t, $from->y + ($to->y - $from->y) * $t);
            }
            $left -= $segment;
        }

        return $last;
    }

    /**
     * The walk cut short at the moment: standing where the character is then, the point rounded to hundredths like
     * every point the server routes.
     *
     * @param int $now Unix time in milliseconds.
     * @return self|null Null when the walk is over by then: there is nothing to cut.
     */
    public function stoppedAt(int $now): ?self
    {
        if ($now >= $this->arrivesAt) {
            return null;
        }
        $at = $this->positionAt($now);

        return self::standing(new Point(\round($at->x, 2), \round($at->y, 2)), $now);
    }

    /**
     * @return array{path: non-empty-list<array{x: float, y: float}>, startedAt: int, arrivesAt: int}
     */
    public function toArray(): array
    {
        return [
            'path' => \array_map(static fn(Point $point): array => $point->toArray(), $this->path),
            'startedAt' => $this->startedAt,
            'arrivesAt' => $this->arrivesAt,
        ];
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
}
