<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Player\Look;
use App\Domain\Player\PlayerId;

/**
 * A player's character on the lawn. Name and look are copied from the player when the character enters or the
 * player registers again, so the snapshot reads one key instead of every player.
 */
final readonly class Walker
{
    /**
     * @param non-empty-string $id Public id of the player.
     * @param non-empty-string|null $name
     * @param int $seenAt Unix time in milliseconds when the player was last seen connected.
     * @param Clink|null $clink The clink the character is walking to; any new walk cancels it.
     * @param int|null $clinkedAt Unix time in milliseconds of the character's last clink.
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public Look $look,
        public Walk $walk,
        public int $seenAt,
        public ?Clink $clink = null,
        public ?int $clinkedAt = null,
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

        return $look !== null && $walk !== null && \is_int($seenAt)
            ? new self(
                $id,
                \is_string($name) && $name !== '' ? $name : null,
                $look,
                $walk,
                $seenAt,
                Clink::fromArray($value['clink'] ?? null),
                \is_int($clinkedAt) ? $clinkedAt : null,
            )
            : null;
    }

    /**
     * Sets off on a new walk, which cancels the clink the character was walking to.
     */
    public function walking(Walk $walk): self
    {
        return new self($this->id, $this->name, $this->look, $walk, $this->seenAt, null, $this->clinkedAt);
    }

    public function clinking(Walk $walk, Clink $clink): self
    {
        return new self($this->id, $this->name, $this->look, $walk, $this->seenAt, $clink, $this->clinkedAt);
    }

    /**
     * The clink the character walked to has happened or fallen through.
     *
     * @param int|null $at Unix time in milliseconds of the clink; null when it fell through.
     */
    public function clinked(?int $at): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $this->seenAt, null, $at ?? $this->clinkedAt);
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
     * @param non-empty-string|null $name
     */
    public function dressed(?string $name, Look $look): self
    {
        return new self($this->id, $name, $look, $this->walk, $this->seenAt, $this->clink, $this->clinkedAt);
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function seen(int $now): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $now, $this->clink, $this->clinkedAt);
    }

    /**
     * The character as the client contract shows it (docs/spec/protocol.md, `Walker`).
     *
     * @param non-empty-string|null $item What the character holds: every character holds the item of the scene and finale step.
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
     * @return array{name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, walk: array, seenAt: int, clink?: array, clinkedAt?: int}
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

        return $record;
    }
}
