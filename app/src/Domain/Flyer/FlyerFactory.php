<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use App\Domain\Walk\WalkZone;
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
 * Worms are ground targets of a scene's set: they crawl at the middle depth from one point of the lawn to another
 * beside it, out of the earth and back into it, never over the patch, wriggling by a small wobble.
 *
 * Fireflies fly like the others but only at the middle and near depths ({@see FIREFLY_DEPTHS}), wobble sluggishly
 * ({@see FIREFLY_AMP}, {@see FIREFLY_WAVES}) and bring their own points instead of the depth's.
 *
 * Kinds come by scene; the cursed world has a set of its own instead of its scene's. Sometimes a launch is the rare flyer of the scene instead — a special one, as the moderator
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

    /** Depths a firefly flies at, clamped to the depths there are. */
    private const FIREFLY_DEPTHS = [1, 2];

    /** Range of a firefly's swing across its path, percents of the screen: smaller than a bat's. */
    private const FIREFLY_AMP = [2.0, 5.0];

    /** Range of a firefly's full swings over its flight: fewer than a bat's, so it drifts rather than darts. */
    private const FIREFLY_WAVES = [1.5, 3.0];

    /** Range of a worm's crawl, percents of the screen: it surfaces and burrows within one stretch of the lawn. */
    private const WORM_LENGTH = [12.0, 25.0];

    /** Range of a worm's wriggle across its crawl, percents of the screen. */
    private const WORM_AMP = [0.8, 2.0];

    /** Range of a worm's wriggles over its crawl. */
    private const WORM_WAVES = [3.0, 6.0];

    /** Attempts to find a crawl that stays on the lawn before falling back to one along its bottom. */
    private const WORM_ATTEMPTS = 20;

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
     * @param WalkZone|null $lawn Where worms crawl; needed only when a set has worms.
     * @param list<non-empty-string> $cursed Kinds of the cursed world in place of its scene's set; an empty set
     *        launches no flyers there but the rare one of the scene.
     * @param positive-int $fireflyPoints Points for a firefly at any depth.
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
        private ?WalkZone $lawn = null,
        private array $cursed = [],
        private int $fireflyPoints = 1,
    ) {
        $lawn !== null || !\in_array(Flyer::KIND_WORM, \array_merge($cursed, ...$kinds), true) or throw new \InvalidArgumentException(
            'Worms crawl over the lawn: pass the walk zone to the flyer factory.',
        );

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
     * @param bool $cursed The cursed world: its own set of kinds, the rare flyer still of the scene.
     * @return Flyer|null Null when the set has no flyers and this launch is not the rare one.
     */
    public function launch(int $now, int $scene = 0, bool $cursed = false): ?Flyer
    {
        $rare = $this->rare === [] ? null : $this->rare[\min(\max(0, $scene), \count($this->rare) - 1)];
        if ($rare !== null && $this->chance($this->rareChance)) {
            return $this->special($rare, $now);
        }

        $kinds = $cursed ? $this->cursed : $this->kinds[\min(\max(0, $scene), \count($this->kinds) - 1)];
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
        if ($kind === Flyer::KIND_WORM) {
            return $this->worm($id, $now);
        }
        if ($kind === Flyer::KIND_FIREFLY) {
            return $this->firefly($id, $now, $bush, $arc, $from, $via, $to);
        }

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

    /**
     * Keeps the path drawn for the launch unless it starts at a bush outside the firefly depths: then it comes from
     * the edge instead.
     *
     * @param non-empty-string $id
     */
    private function firefly(string $id, int $now, ?Bush $bush, bool $arc, Point $from, ?Point $via, Point $to): Flyer
    {
        $last = \count($this->depths) - 1;
        $min = \min(self::FIREFLY_DEPTHS[0], $last);
        $max = \min(self::FIREFLY_DEPTHS[1], $last);

        if ($bush !== null && ($bush->depth < $min || $bush->depth > $max)) {
            $bush = null;
            [$from, $via, $to] = $this->fromEdge($arc);
        }
        /** @var int<0, max> $depth */
        $depth = $bush?->depth ?? $this->random->getInt($min, $max);

        return new Flyer(
            id: $id,
            depth: $depth,
            from: $from,
            to: $to,
            via: $via,
            bush: $bush?->id,
            duration: $this->depths[$depth]->duration,
            points: $this->fireflyPoints,
            launchedAt: $now,
            kind: Flyer::KIND_FIREFLY,
            wobble: new Wobble($this->float(...self::FIREFLY_AMP), $this->float(...self::FIREFLY_WAVES)),
        );
    }

    /**
     * @param non-empty-string $id
     */
    private function worm(string $id, int $now): Flyer
    {
        /** @var int<0, max> $depth */
        $depth = \min(1, \count($this->depths) - 1);
        [$from, $to] = $this->crawl();

        return new Flyer(
            id: $id,
            depth: $depth,
            from: $from,
            to: $to,
            via: null,
            bush: null,
            duration: $this->depths[$depth]->duration,
            points: $this->depths[$depth]->points,
            launchedAt: $now,
            kind: Flyer::KIND_WORM,
            wobble: new Wobble($this->float(...self::WORM_AMP), $this->float(...self::WORM_WAVES)),
        );
    }

    /**
     * Both ends on the lawn and the straight line between them clear of the patch, so the worm never leaves the lawn
     * however it wriggles.
     *
     * @return array{Point, Point}
     */
    private function crawl(): array
    {
        $lawn = $this->lawn ?? throw new \LogicException('Worms need the lawn.');
        for ($i = 0; $i < self::WORM_ATTEMPTS; ++$i) {
            $from = new Point($this->float($lawn->x1, $lawn->x2), $this->float($lawn->y1, $lawn->y2));
            $angle = $this->random->getFloat(0.0, 2.0 * \M_PI);
            $length = $this->float(...self::WORM_LENGTH);
            $to = new Point(\round($from->x + $length * \cos($angle), 1), \round($from->y + $length * \sin($angle), 1));

            if ($lawn->contains($from) && $lawn->contains($to) && \count($lawn->route($from, $to)) === 2) {
                return [$from, $to];
            }
        }

        // Along the bottom of the lawn, under the patch
        $y = \round($lawn->y2 - 2.0, 1);

        return [new Point(\round($lawn->cx - 20.0, 1), $y), new Point(\round($lawn->cx - 5.0, 1), $y)];
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
