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

    /**
     * @param non-empty-string $id
     * @param int<0, max> $depth 0 is the farthest.
     * @param non-empty-string|null $bush Bush it takes off from; `from` is then the bush.
     * @param Point|null $via Control point of a quadratic curve; null for a straight flight.
     * @param positive-int $duration Milliseconds from `from` to `to`.
     * @param positive-int $points
     * @param int $launchedAt Unix time in milliseconds.
     * @param non-empty-string $kind
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
    ) {}

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

        $from = Point::fromArray($value['from'] ?? null);
        $to = Point::fromArray($value['to'] ?? null);
        $id = $value['flyer'] ?? null;
        $kind = $value['kind'] ?? null;
        $depth = $value['depth'] ?? null;
        $duration = $value['duration'] ?? null;
        $points = $value['points'] ?? null;
        $launchedAt = $value['launchedAt'] ?? null;
        /** @var mixed $bush */
        $bush = $value['bush'] ?? null;

        if (!self::isId($id) || $from === null || $to === null || !\is_string($kind) || $kind === ''
            || !\is_int($depth) || $depth < 0 || !\is_int($duration) || $duration < 1
            || !\is_int($points) || $points < 1 || !\is_int($launchedAt)) {
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
        return $now < $this->expiresAt();
    }

    /**
     * Fields of the `flyer` event: docs/spec/protocol.md.
     */
    public function toEvent(): array
    {
        $event = ['flyer' => $this->id, 'kind' => $this->kind, 'depth' => $this->depth];
        $this->bush === null or $event['bush'] = $this->bush;
        $event['from'] = $this->from->toArray();
        $this->via === null or $event['via'] = $this->via->toArray();
        $event['to'] = $this->to->toArray();
        $event['duration'] = $this->duration;
        $event['points'] = $this->points;
        // A backgrounded tab receives queued publications late; these let it drop flyers already gone
        $event['launchedAt'] = $this->launchedAt;
        $event['expiresAt'] = $this->expiresAt();

        return $event;
    }

    public function toArray(): array
    {
        $data = $this->toEvent();
        unset($data['expiresAt']);

        return $data;
    }
}
