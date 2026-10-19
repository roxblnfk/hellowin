<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use Random\Randomizer;

/**
 * Picks a random flyer: depth, where it comes from and how it flies. The server sends a finished trajectory, so
 * every client sees the same flight.
 *
 * - From the edge: in from behind the left or right edge (sometimes from a top corner) and out behind the
 *   opposite one, high in the sky.
 * - From a bush: up from the bush and out across the screen through the top or a side edge.
 *
 * Either way the flight is straight or, with the arc chance, a quadratic curve through `via`.
 */
final readonly class FlyerFactory
{
    /** Coordinates behind the screen edges: far enough for the largest sprite to start fully hidden. */
    private const OUT = [-8.0, 108.0];

    /** @var non-empty-list<FlyerDepth> */
    private array $depths;

    /** @var list<Bush> */
    private array $bushes;

    /**
     * @param non-empty-list<FlyerDepth> $depths Index is the depth: 0 is the farthest.
     * @param list<Bush> $bushes
     * @param float $bushChance Probability to take off from a bush, when there are bushes.
     * @param float $arcChance Probability of a curved flight.
     */
    public function __construct(
        array $depths,
        array $bushes,
        private float $bushChance,
        private float $arcChance,
        private Randomizer $random = new Randomizer(),
    ) {
        $this->depths = $depths;
        $this->bushes = $bushes;
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function launch(int $now): Flyer
    {
        /** @var int<0, max> $depth */
        $depth = $this->random->getInt(0, \count($this->depths) - 1);
        $settings = $this->depths[$depth];
        $arc = $this->chance($this->arcChance);

        $bush = $this->bushes !== [] && $this->chance($this->bushChance)
            ? $this->bushes[$this->random->getInt(0, \count($this->bushes) - 1)]
            : null;

        [$from, $via, $to] = $bush === null ? $this->fromEdge($arc) : $this->fromBush($bush->at, $arc);

        return new Flyer(
            id: 'f-' . \bin2hex($this->random->getBytes(4)),
            depth: $depth,
            from: $from,
            to: $to,
            via: $via,
            bush: $bush?->id,
            duration: $settings->duration,
            points: $settings->points,
            launchedAt: $now,
        );
    }

    /**
     * @return array{Point, Point|null, Point}
     */
    private function fromEdge(bool $arc): array
    {
        $leftToRight = $this->chance(0.5);
        $from = $this->chance(0.25)
            ? new Point($leftToRight ? $this->float(0, 25) : $this->float(75, 100), self::OUT[0])
            : new Point($leftToRight ? self::OUT[0] : self::OUT[1], $this->float(5, 40));
        $to = new Point($leftToRight ? self::OUT[1] : self::OUT[0], $this->float(-5, 35));

        $via = null;
        if ($arc) {
            $via = $this->chance(0.5)
                ? new Point($this->float(30, 70), \max(-10.0, \min($from->y, $to->y) - $this->float(10, 25)))
                : new Point($this->float(30, 70), \min(55.0, \max($from->y, $to->y) + $this->float(10, 20)));
        }

        return [$from, $via, $to];
    }

    /**
     * @return array{Point, Point|null, Point}
     */
    private function fromBush(Point $bush, bool $arc): array
    {
        $rightward = $bush->x < 50;
        $to = $this->chance(0.5)
            ? new Point($rightward ? $this->float(55, 100) : $this->float(0, 45), self::OUT[0])
            : new Point($rightward ? self::OUT[1] : self::OUT[0], $this->float(-5, 25));

        $via = $arc
            ? new Point($bush->x + ($to->x - $bush->x) * $this->float(0.15, 0.4), $this->float(5, 30))
            : null;

        return [$bush, $via, $to];
    }

    private function chance(float $probability): bool
    {
        return $this->random->nextFloat() < $probability;
    }

    private function float(float $min, float $max): float
    {
        return \round($this->random->getFloat($min, $max), 1);
    }
}
