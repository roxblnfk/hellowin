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
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public Look $look,
        public Walk $walk,
        public int $seenAt,
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

        return $look !== null && $walk !== null && \is_int($seenAt)
            ? new self($id, \is_string($name) && $name !== '' ? $name : null, $look, $walk, $seenAt)
            : null;
    }

    public function walking(Walk $walk): self
    {
        return new self($this->id, $this->name, $this->look, $walk, $this->seenAt);
    }

    /**
     * @param non-empty-string|null $name
     */
    public function dressed(?string $name, Look $look): self
    {
        return new self($this->id, $name, $look, $this->walk, $this->seenAt);
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function seen(int $now): self
    {
        return new self($this->id, $this->name, $this->look, $this->walk, $now);
    }

    /**
     * The character as the client contract shows it (docs/spec/protocol.md, `Walker`).
     *
     * @param non-empty-string|null $item What the character holds: every character holds the item of the current scene.
     * @return array{id: non-empty-string, name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, item: non-empty-string|null, walk: array}
     */
    public function toArray(?string $item = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'look' => $this->look->toArray(),
            'item' => $item,
            'walk' => $this->walk->toArray(),
        ];
    }

    /**
     * @return array{name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, walk: array, seenAt: int}
     */
    public function toRecord(): array
    {
        return [
            'name' => $this->name,
            'look' => $this->look->toArray(),
            'walk' => $this->walk->toArray(),
            'seenAt' => $this->seenAt,
        ];
    }
}
