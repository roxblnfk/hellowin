<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * FrankenPHP awake with its health (docs/spec/scenario.md, «Шаг 6 — босс FrankenPHP»): the whole room strikes this one
 * thing, and the strike that takes the last point is the one that fells it.
 */
final readonly class Boss
{
    /**
     * @param int<0, max> $hp
     * @param positive-int $max
     * @param int<0, max> $damage Strikes taken: the reveal's damage, whatever the moderator did to the health.
     */
    public function __construct(
        public int $hp,
        public int $max,
        public int $damage = 0,
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value)) {
            return null;
        }

        /** @var mixed $hp */
        $hp = $value['hp'] ?? null;
        /** @var mixed $max */
        $max = $value['max'] ?? null;
        /** @var mixed $damage */
        $damage = $value['damage'] ?? null;

        return \is_int($hp) && $hp >= 0 && \is_int($max) && $max > 0
            ? new self($hp, \max($max, $hp, 1), \is_int($damage) && $damage > 0 ? $damage : 0)
            : null;
    }

    public function down(): bool
    {
        return $this->hp === 0;
    }

    public function struck(): self
    {
        return new self(\max(0, $this->hp - 1), $this->max, $this->damage + 1);
    }

    /**
     * The moderator's tuning: health and its maximum move together, the health never below one.
     */
    public function tuned(int $delta): self
    {
        $hp = \max(1, $this->hp + $delta);

        return new self($hp, \max($hp, $this->max + $delta, 1), $this->damage);
    }

    /**
     * Felled at once: the moderator took the dawn without waiting for the room.
     */
    public function felled(): self
    {
        return new self(0, $this->max, $this->damage);
    }

    /**
     * @return array{hp: int<0, max>, max: positive-int}
     */
    public function toEvent(): array
    {
        return ['hp' => $this->hp, 'max' => $this->max];
    }

    /**
     * @return array{hp: int<0, max>, max: positive-int, damage: int<0, max>}
     */
    public function toArray(): array
    {
        return ['hp' => $this->hp, 'max' => $this->max, 'damage' => $this->damage];
    }
}
