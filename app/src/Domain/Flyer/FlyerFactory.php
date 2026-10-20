<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use Random\Randomizer;

/**
 * Picks a random flyer: where it comes from, its depth and how it flies. The server sends a finished trajectory, so
 * every client sees the same flight.
 *
 * - From the edge: in from behind the left or right edge (sometimes from a top corner) and out behind the
 *   opposite one, high in the sky, at a random depth.
 * - From a bush: up from the bush and out across the screen through the top or a side edge, at the bush depth.
 *
 * Either way the flight is straight or, with the arc chance, a quadratic curve through `via`.
 * Bats also wobble across it: a random swing of {@see WOBBLE_AMP} percents and {@see WOBBLE_WAVES} waves.
 *
 * Kinds come by scene. Sometimes a launch is the rare flyer of the scene instead — a special one, as the moderator
 * launches by hand: the roadrunner runs along the horizon, any other special flies from the edge for the rare points.
 */
final readonly class FlyerFactory
{
    /** Coordinates behind the screen edges: far enough for the largest sprite to start fully hidden. */
    private const OUT = [-8.0, 108.0];

    /** Range of a bat's swing across its path, percents of the screen. */
    private const WOBBLE_AMP = [3.0, 8.0];

    /** Range of full swings over a bat's flight: few are zigzags, many with a large swing read as loops. */
    private const WOBBLE_WAVES = [2.0, 5.0];

    /** @var non-empty-list<FlyerDepth> */
    private array $depths;

    /** @var list<Bush> */
    private array $bushes;

    /**
     * @param non-empty-list<FlyerDepth> $depths Index is the depth: 0 is the farthest.
     * @param list<Bush> $bushes Each at one of the depths.
     * @param float $bushChance Probability to take off from a bush, when there are bushes.
     * @param float $arcChance Probability of a curved flight.
     * @param non-empty-list<list<non-empty-string>> $kinds Kinds by scene, picked with equal chance; scenes past the list
     *        take the last set. A scene with an empty set launches no flyers but its rare one.
     * @param list<non-empty-string|null> $rare The rare flyer by scene, null for none; scenes past the list take the last.
     * @param float $rareChance Probability that a launch is the rare flyer of the scene.
     * @param positive-int $rarePoints Points for a rare flyer other than the roadrunner.
     * @param float $horizon The line the roadrunner runs along, in percents from the top.
     */
    public function __construct(
        array $depths,
        array $bushes,
        private float $bushChance,
        private float $arcChance,
        private Randomizer $random = new Randomizer(),
        private array $kinds = [[Flyer::KIND_DUCK]],
        private array $rare = [],
        private float $rareChance = 0.0,
        private int $rarePoints = 10,
        private float $horizon = 38.0,
    ) {
        foreach ($bushes as $bush) {
            $bush->depth < \count($depths) or throw new \InvalidArgumentException(\sprintf(
                'Bush "%s" has depth %d, but flyers have only %d depths.',
                $bush->id,
                $bush->depth,
                \count($depths),
            ));
        }

        $this->depths = $depths;
        $this->bushes = $bushes;
    }

    /**
     * @param int $now Unix time in milliseconds.
     * @return Flyer|null Null when the scene has no flyers and this launch is not its rare one.
     */
    public function launch(int $now, int $scene = 0): ?Flyer
    {
        $rare = $this->rare === [] ? null : $this->rare[\min(\max(0, $scene), \count($this->rare) - 1)];
        if ($rare !== null && $this->chance($this->rareChance)) {
            return $this->special($rare, $now);
        }

        $kinds = $this->kinds[\min(\max(0, $scene), \count($this->kinds) - 1)];
        if ($kinds === []) {
            return null;
        }
        $arc = $this->chance($this->arcChance);

        $bush = $this->bushes !== [] && $this->chance($this->bushChance)
            ? $this->bushes[$this->random->getInt(0, \count($this->bushes) - 1)]
            : null;

        /** @var int<0, max> $depth */
        $depth = $bush?->depth ?? $this->random->getInt(0, \count($this->depths) - 1);
        $settings = $this->depths[$depth];

        [$from, $via, $to] = $bush === null ? $this->fromEdge($arc) : $this->fromBush($bush->at, $arc);
        $id = 'f-' . \bin2hex($this->random->getBytes(4));
        $kind = $kinds[$this->random->getInt(0, \count($kinds) - 1)];

        return new Flyer(
            id: $id,
            depth: $depth,
            from: $from,
            to: $to,
            via: $via,
            bush: $bush?->id,
            duration: $settings->duration,
            points: $settings->points,
            launchedAt: $now,
            kind: $kind,
            wobble: $kind === Flyer::KIND_BAT ? $this->wobble() : null,
        );
    }

    /**
     * A flyer of the given kind: the roadrunner runs along the horizon at the far depth as fast as the nearest
     * flyers and brings no points; any other kind flies from the edge for the rare points.
     *
     * @param non-empty-string $kind
     * @param int $now Unix time in milliseconds.
     */
    public function special(string $kind, int $now): Flyer
    {
        $id = 'f-' . \bin2hex($this->random->getBytes(4));

        if ($kind === Flyer::KIND_ROADRUNNER) {
            $leftToRight = $this->chance(0.5);

            return new Flyer(
                id: $id,
                depth: 0,
                from: new Point($leftToRight ? self::OUT[0] : self::OUT[1], $this->horizon),
                to: new Point($leftToRight ? self::OUT[1] : self::OUT[0], $this->horizon),
                via: null,
                bush: null,
                duration: $this->depths[\count($this->depths) - 1]->duration,
                points: 0,
                launchedAt: $now,
                kind: $kind,
            );
        }

        /** @var int<0, max> $depth */
        $depth = $this->random->getInt(0, \count($this->depths) - 1);
        [$from, $via, $to] = $this->fromEdge($this->chance($this->arcChance));

        return new Flyer(
            id: $id,
            depth: $depth,
            from: $from,
            to: $to,
            via: $via,
            bush: null,
            duration: $this->depths[$depth]->duration,
            points: $this->rarePoints,
            launchedAt: $now,
            kind: $kind,
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

    private function wobble(): Wobble
    {
        return new Wobble($this->float(...self::WOBBLE_AMP), $this->float(...self::WOBBLE_WAVES));
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
