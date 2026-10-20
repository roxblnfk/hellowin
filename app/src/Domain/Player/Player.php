<?php

declare(strict_types=1);

namespace App\Domain\Player;

/**
 * Statistics of one browser. The secret never gets here: only its hash, the public id.
 */
final readonly class Player
{
    /**
     * @param non-empty-string $id Public id, {@see PlayerId}.
     * @param non-empty-string|null $name Null until the player registers a name.
     * @param int<0, max> $clicks Accepted clicks on pumpkins.
     * @param int<0, max> $finished Pumpkin transitions this player's click caused.
     * @param int<0, max> $flyers Flyers shot down.
     * @param int<0, max> $points Points for the flyers.
     * @param Look|null $look Null until the player picks a character: such a player stays off the lawn.
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public int $clicks = 0,
        public int $finished = 0,
        public int $flyers = 0,
        public int $points = 0,
        public ?Look $look = null,
    ) {}

    /**
     * @param non-empty-string $id
     */
    public static function fromArray(string $id, mixed $value): self
    {
        if (!\is_array($value)) {
            return new self($id);
        }

        return new self(
            $id,
            isset($value['name']) && \is_string($value['name']) && $value['name'] !== '' ? $value['name'] : null,
            self::count($value['clicks'] ?? null),
            self::count($value['finished'] ?? null),
            self::count($value['flyers'] ?? null),
            self::count($value['points'] ?? null),
            Look::fromArray($value['look'] ?? null),
        );
    }

    /**
     * @param non-empty-string $name
     * @param Look|null $look Null keeps the current look.
     */
    public function named(string $name, ?Look $look = null): self
    {
        return new self($this->id, $name, $this->clicks, $this->finished, $this->flyers, $this->points, $look ?? $this->look);
    }

    public function clicked(): self
    {
        return new self($this->id, $this->name, $this->clicks + 1, $this->finished, $this->flyers, $this->points, $this->look);
    }

    public function finishedPumpkin(): self
    {
        return new self($this->id, $this->name, $this->clicks, $this->finished + 1, $this->flyers, $this->points, $this->look);
    }

    /**
     * @param int<0, max> $points
     */
    public function shotFlyer(int $points): self
    {
        return new self($this->id, $this->name, $this->clicks, $this->finished, $this->flyers + 1, $this->points + $points, $this->look);
    }

    /**
     * @return array{id: non-empty-string, name: non-empty-string|null, look: array<non-empty-string, non-empty-string>|null, clicks: int<0, max>, finished: int<0, max>, flyers: int<0, max>, points: int<0, max>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'look' => $this->look?->toArray(),
            'clicks' => $this->clicks,
            'finished' => $this->finished,
            'flyers' => $this->flyers,
            'points' => $this->points,
        ];
    }

    /**
     * The player as channel events show it.
     *
     * @return array{id: non-empty-string, name: non-empty-string|null}
     */
    public function ref(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    /**
     * @return int<0, max>
     */
    private static function count(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }
}
