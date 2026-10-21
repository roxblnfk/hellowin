<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Player\Look;
use App\Domain\Player\PlayerId;

/**
 * A player's character on the lawn. Name and look are copied from the player when the character enters or the
 * player registers again, so the snapshot reads one key instead of every player.
 *
 * In the bar the character holds its own item — a beer or nothing — and remembers whether it has clinked with this
 * mug; everywhere else every character holds the item of the scene.
 */
final readonly class Walker
{
    /** The only item a character holds of its own: a mug from the bar. */
    public const BEER = 'beer';

    /**
     * @param non-empty-string $id Public id of the player.
     * @param non-empty-string|null $name
     * @param int $seenAt Unix time in milliseconds when the player was last seen connected.
     * @param Clink|null $clink The clink the character is walking to; any new walk cancels it.
     * @param int|null $clinkedAt Unix time in milliseconds of the character's last clink.
     * @param non-empty-string|null $item The character's own item in the bar: {@see BEER} or empty hands.
     * @param bool $clinked The mug in hand has been clinked: it may be drunk.
     * @param int|null $takeAt Unix time in milliseconds when the character reaches the counter for a mug; any new walk
     *        cancels it.
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public Look $look,
        public Walk $walk,
        public int $seenAt,
        public ?Clink $clink = null,
        public ?int $clinkedAt = null,
        public ?string $item = null,
        public bool $clinked = false,
        public ?int $takeAt = null,
    ) {}

    public static function fromArray(mixed $id, mixed $value): ?self
    {
        if (!PlayerId::isValid($id) || !\is_array($value)) {
            return null;
        }

        /** @var mixed $name */
        $name = $value['name'] ?? null;
        $look = Look::fromArray($value['look'] ?? null);
        $walk = Walk::fromArray($value['walk'] ?? null);
        /** @var mixed $seenAt */
        $seenAt = $value['seenAt'] ?? null;
        /** @var mixed $clinkedAt */
        $clinkedAt = $value['clinkedAt'] ?? null;
        /** @var mixed $takeAt */
        $takeAt = $value['takeAt'] ?? null;
        $beer = ($value['item'] ?? null) === self::BEER;

        return $look !== null && $walk !== null && \is_int($seenAt)
            ? new self(
                $id,
                \is_string($name) && $name !== '' ? $name : null,
                $look,
                $walk,
                $seenAt,
                Clink::fromArray($value['clink'] ?? null),
                \is_int($clinkedAt) ? $clinkedAt : null,
                $beer ? self::BEER : null,
                $beer && ($value['clinked'] ?? false) === true,
                \is_int($takeAt) ? $takeAt : null,
            )
            : null;
    }

    /**
     * Sets off on a new walk, which cancels the clink or the mug the character was walking to.
     */
    public function walking(Walk $walk): self
    {
        return new self($this->id, $this->name, $this->look, $walk, $this->seenAt, null, $this->clinkedAt, $this->item, $this->clinked);
    }

    public function clinking(Walk $walk, Clink $clink): self
    {
        return new self($this->id, $this->name, $this->look, $walk, $this->seenAt, $clink, $this->clinkedAt, $this->item, $this->clinked);
    }

    /**
     * Walks to the counter for a mug: it is taken, or not, on arrival.
     *
     * @param int $at Unix time in milliseconds of the arrival.
     */
    public function taking(Walk $walk, int $at): self
    {
        return new self($this->id, $this->name, $this->look, $walk, $this->seenAt, null, $this->clinkedAt, $this->item, $this->clinked, $at);
    }

    /**
     * The walk to the counter is over: with a fresh mug, not clinked yet, or with nothing when the counter was empty.
     */
    public function tookMug(bool $taken): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->look,
            $this->walk,
            $this->seenAt,
            $this->clink,
            $this->clinkedAt,
            $taken ? self::BEER : $this->item,
            $taken ? false : $this->clinked,
        );
    }

    /**
     * The mug in hand counts a clink: now it may be drunk.
     */
    public function clinkedMug(): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $this->seenAt, $this->clink, $this->clinkedAt, $this->item, $this->item === self::BEER, $this->takeAt);
    }

    /**
     * Empty hands: the mug is drunk, or the room has left the bar.
     */
    public function emptyHanded(): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $this->seenAt, $this->clink, $this->clinkedAt, null, false, $this->takeAt);
    }

    public function hasBeer(): bool
    {
        return $this->item === self::BEER;
    }

    /**
     * The clink the character walked to has happened or fallen through.
     *
     * @param int|null $at Unix time in milliseconds of the clink; null when it fell through.
     */
    public function clinked(?int $at): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $this->seenAt, null, $at ?? $this->clinkedAt, $this->item, $this->clinked, $this->takeAt);
    }

    /**
     * Whether a clink of the character is still playing.
     *
     * @param int $now Unix time in milliseconds.
     * @param positive-int $duration Milliseconds a clink plays.
     */
    public function clinkPlaying(int $now, int $duration): bool
    {
        return $this->clinkedAt !== null && $now < $this->clinkedAt + $duration;
    }

    /**
     * Whether the character has reached the counter for a mug.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function atCounter(int $now): bool
    {
        return $this->takeAt !== null && $now >= $this->takeAt;
    }

    /**
     * @param non-empty-string|null $name
     */
    public function dressed(?string $name, Look $look): self
    {
        return new self($this->id, $name, $look, $this->walk, $this->seenAt, $this->clink, $this->clinkedAt, $this->item, $this->clinked, $this->takeAt);
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function seen(int $now): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $now, $this->clink, $this->clinkedAt, $this->item, $this->clinked, $this->takeAt);
    }

    /**
     * The character as the client contract shows it (docs/spec/protocol.md, `Walker`).
     *
     * @param non-empty-string|null $item What the character holds: the item of the scene and finale step, or in the
     *        bar its own ({@see $item}).
     * @return array{id: non-empty-string, name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, item: non-empty-string|null, walk: array, clink?: array}
     */
    public function toArray(?string $item = null): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'look' => $this->look->toArray(),
            'item' => $item,
            'walk' => $this->walk->toArray(),
        ];
        $this->clink === null or $data['clink'] = $this->clink->toTarget();

        return $data;
    }

    /**
     * @return array{name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, walk: array, seenAt: int, clink?: array, clinkedAt?: int, item?: non-empty-string, clinked?: true, takeAt?: int}
     */
    public function toRecord(): array
    {
        $record = [
            'name' => $this->name,
            'look' => $this->look->toArray(),
            'walk' => $this->walk->toArray(),
            'seenAt' => $this->seenAt,
        ];
        $this->clink === null or $record['clink'] = $this->clink->toArray();
        $this->clinkedAt === null or $record['clinkedAt'] = $this->clinkedAt;
        $this->item === null or $record['item'] = $this->item;
        $this->clinked and $record['clinked'] = true;
        $this->takeAt === null or $record['takeAt'] = $this->takeAt;

        return $record;
    }
}
