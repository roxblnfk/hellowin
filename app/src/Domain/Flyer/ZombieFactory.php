<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use App\Domain\Walk\WalkZone;
use Random\Randomizer;

/**
 * Zombies of the cursed world: ground targets that crawl out of the patch edge and shamble straight away from its
 * centre to the edge of the lawn. They are flyers to the rest of the engine — the same key, index, shot and sweep.
 */
final readonly class ZombieFactory
{
    /** Attempts to find a patch edge point inside the lawn before falling back to the bottom of the patch. */
    private const ATTEMPTS = 20;

    /**
     * @param positive-int $duration Milliseconds from the patch to the lawn edge.
     * @param positive-int $points
     * @param int<0, max> $depth
     */
    public function __construct(
        private WalkZone $zone,
        private int $duration,
        private int $points,
        private int $depth = 1,
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function launch(int $now): Flyer
    {
        [$from, $to] = $this->path();

        return new Flyer(
            id: 'f-' . \bin2hex($this->random->getBytes(4)),
            depth: $this->depth,
            from: $from,
            to: $to,
            via: null,
            bush: null,
            duration: $this->duration,
            points: $this->points,
            launchedAt: $now,
            kind: Flyer::KIND_ZOMBIE,
        );
    }

    /**
     * Along a ray from the patch centre: from where it crosses the patch edge to where it leaves the lawn rectangle.
     * The part of the patch edge outside the lawn (behind the horizon) is skipped.
     *
     * @return array{Point, Point}
     */
    private function path(): array
    {
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $path = $this->ray($this->random->getFloat(0.0, 2.0 * \M_PI));
            if ($path !== null) {
                return $path;
            }
        }

        return $this->ray(\M_PI / 2.0) ?? [new Point($this->zone->cx, $this->zone->cy + $this->zone->ry), new Point($this->zone->cx, $this->zone->y2)];
    }

    /**
     * @return array{Point, Point}|null Null when the patch edge at this angle is outside the lawn.
     */
    private function ray(float $angle): ?array
    {
        $dx = $this->zone->rx * \cos($angle);
        $dy = $this->zone->ry * \sin($angle);

        // The ray is c + t·d: t = 1 on the patch edge, the exit is the nearest side of the rectangle ahead
        $exit = \INF;
        $dx > 0.0 and $exit = \min($exit, ($this->zone->x2 - $this->zone->cx) / $dx);
        $dx < 0.0 and $exit = \min($exit, ($this->zone->x1 - $this->zone->cx) / $dx);
        $dy > 0.0 and $exit = \min($exit, ($this->zone->y2 - $this->zone->cy) / $dy);
        $dy < 0.0 and $exit = \min($exit, ($this->zone->y1 - $this->zone->cy) / $dy);

        if ($exit <= 1.0 || $exit === \INF) {
            return null;
        }

        return [
            new Point(\round($this->zone->cx + $dx, 2), \round($this->zone->cy + $dy, 2)),
            new Point(\round($this->zone->cx + $dx * $exit, 2), \round($this->zone->cy + $dy * $exit, 2)),
        ];
    }
}
