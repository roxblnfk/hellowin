<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * A flyer in the sky: every client draws the same trajectory from the `flyer` event, the server only needs to know
 * when it leaves the screen.
 */
final readonly class Flyer
{
    public const KIND_DUCK = 'duck';

    /** Flies a wobbling path: zigzags and loops. */
    public const KIND_BAT = 'bat';

    public const KIND_WITCH = 'witch';

    /** A ground target of the cursed world, walking out of the patch. */
    public const KIND_ZOMBIE = 'zombie';

    /** A ground target of the final scene: crawls out of the lawn, wriggles across it and back into the earth. */
    public const KIND_WORM = 'worm';

    /** A small flyer of the cursed world: a sluggish wobble at the middle and near depths, its own few points. */
    public const KIND_FIREFLY = 'firefly';

    /** The flyer of the bar: loops about the room and lands on the tables and the counter for a while. */
    public const KIND_FLY = 'fly';

    /**
     * Crosses the far sky with a banner on its own timer: no shot brings it down, the first one makes it fart
     * (docs/spec/protocol.md, «Слон»).
     */
    public const KIND_ELEPHANT = 'elephant';

    /**
     * @param non-empty-string $id
     * @param int<0, max> $depth 0 is the farthest.
     * @param non-empty-string|null $bush Bush it takes off from; `from` is then the bush.
     * @param Point|null $via Control point of a quadratic curve; null for a straight flight.
     * @param positive-int $duration Milliseconds from `from` to `to`.
     * @param int<0, max> $points
     * @param int $launchedAt Unix time in milliseconds.
     * @param non-empty-string $kind
     * @param Wobble|null $wobble Swing across the path; null flies the base trajectory as it is.
     * @param list<Stop> $stops Where the flyer lands between `from` and `to`, in order; the sits are within its duration.
     * @param bool $wander A zombie that stays on the lawn until it is shot: each browser walks it from `from`, and it
     *        has no flight — `to` is `from` and `duration` is 1 for the shape's sake, neither leaves the server.
     * @param non-empty-string|null $by Public id of the player whose ability launched the flyer.
     */
    public function __construct(
        public string $id,
        public int $depth,
        public Point $from,
        public Point $to,
        public ?Point $via,
        public ?string $bush,
        public int $duration,
        public int $points,
        public int $launchedAt,
        public string $kind = self::KIND_DUCK,
        public ?Wobble $wobble = null,
        public array $stops = [],
        public bool $wander = false,
        public ?string $by = null,
    ) {}

    /**
     * A zombie of the cursed world that wanders from where it stands up until it is shot.
     *
     * @param non-empty-string $id
     * @param int<0, max> $depth
     * @param int<0, max> $points
     * @param int $launchedAt Unix time in milliseconds.
     */
    public static function wanderer(string $id, int $depth, Point $from, int $points, int $launchedAt): self
    {
        return new self($id, $depth, $from, $from, null, null, 1, $points, $launchedAt, self::KIND_ZOMBIE, wander: true);
    }

    /**
     * Flyer ids come from clients in RPC `shoot` and name a lock, so only the generated shape is accepted.
     *
     * @psalm-assert-if-true non-empty-string $id
     */
    public static function isId(mixed $id): bool
    {
        return \is_string($id) && \preg_match('/^f-[0-9a-f]{8}$/', $id) === 1;
    }

    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value)) {
            return null;
        }

        $wander = ($value['wander'] ?? false) === true;
        $from = Point::fromArray($value['from'] ?? null);
        $to = $wander ? $from : Point::fromArray($value['to'] ?? null);
        $id = $value['flyer'] ?? null;
        $kind = $value['kind'] ?? null;
        $depth = $value['depth'] ?? null;
        $duration = $wander ? 1 : $value['duration'] ?? null;
        $points = $value['points'] ?? null;
        $launchedAt = $value['launchedAt'] ?? null;
        /** @var mixed $bush */
        $bush = $value['bush'] ?? null;
        /** @var mixed $by */
        $by = \is_array($value['by'] ?? null) ? $value['by']['id'] ?? null : null;

        if (!self::isId($id) || $from === null || $to === null || !\is_string($kind) || $kind === ''
            || !\is_int($depth) || $depth < 0 || !\is_int($duration) || $duration < 1
            || !\is_int($points) || $points < 0 || !\is_int($launchedAt)) {
            return null;
        }

        return new self(
            $id,
            $depth,
            $from,
            $to,
            Point::fromArray($value['via'] ?? null),
            \is_string($bush) && $bush !== '' ? $bush : null,
            $duration,
            $points,
            $launchedAt,
            $kind,
            Wobble::fromArray($value['wobble'] ?? null),
            self::stops($value['stops'] ?? null),
            $wander,
            \is_string($by) && $by !== '' ? $by : null,
        );
    }

    /**
     * @return int Unix time in milliseconds when the flyer is off the screen.
     */
    public function expiresAt(): int
    {
        return $this->launchedAt + $this->duration;
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function flyingAt(int $now): bool
    {
        return $this->wander || $now < $this->expiresAt();
    }

    /**
     * Whether a shot in time brings it down: the elephant only farts.
     */
    public function downable(): bool
    {
        return $this->kind !== self::KIND_ELEPHANT;
    }

    /**
     * Fields of the `flyer` event: docs/spec/protocol.md.
     */
    public function toEvent(): array
    {
        $event = ['flyer' => $this->id, 'kind' => $this->kind, 'depth' => $this->depth];
        if ($this->wander) {
            return $event + [
                'from' => $this->from->toArray(),
                'points' => $this->points,
                'launchedAt' => $this->launchedAt,
                'wander' => true,
            ];
        }
        $this->bush === null or $event['bush'] = $this->bush;
        $event['from'] = $this->from->toArray();
        $this->via === null or $event['via'] = $this->via->toArray();
        $this->stops === [] or $event['stops'] = \array_map(static fn(Stop $stop): array => $stop->toArray(), $this->stops);
        $event['to'] = $this->to->toArray();
        $this->wobble === null or $event['wobble'] = $this->wobble->toArray();
        $event['duration'] = $this->duration;
        $event['points'] = $this->points;
        // A backgrounded tab receives queued publications late; these let it drop flyers already gone
        $event['launchedAt'] = $this->launchedAt;
        $event['expiresAt'] = $this->expiresAt();
        $this->by === null or $event['by'] = ['id' => $this->by];

        return $event;
    }

    public function toArray(): array
    {
        $data = $this->toEvent();
        unset($data['expiresAt']);

        return $data;
    }

    /**
     * @return list<Stop>
     */
    private static function stops(mixed $value): array
    {
        $stops = [];
        /** @var mixed $item */
        foreach (\is_array($value) ? $value : [] as $item) {
            $stop = Stop::fromArray($item);
            $stop === null or $stops[] = $stop;
        }

        return $stops;
    }
}
