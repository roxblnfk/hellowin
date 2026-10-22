<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * FrankenPHP awake with its health (docs/spec/scenario.md, «Шаг 6 — босс FrankenPHP»): the whole room strikes this one
 * thing, and the strike that takes the last point is the one that fells it. Its maximum follows the limit `boss` for the
 * room plus the moderator's bonus; the damage stays whatever the maximum does, so the health is `max(1, max − damage)`
 * until the last strike.
 */
final readonly class Boss
{
    /**
     * @param int<0, max> $hp
     * @param positive-int $max
     * @param int<0, max> $damage Strikes taken: the reveal's damage, whatever the maximum did.
     * @param int $bonus The moderator's addition to the maximum; gone with the round.
     */
    public function __construct(
        public int $hp,
        public int $max,
        public int $damage = 0,
        public int $bonus = 0,
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
        /** @var mixed $bonus */
        $bonus = $value['bonus'] ?? null;

        return \is_int($hp) && $hp >= 0 && \is_int($max) && $max > 0
            ? new self($hp, \max($max, $hp, 1), \is_int($damage) && $damage > 0 ? $damage : 0, \is_int($bonus) ? $bonus : 0)
            : null;
    }

    public function down(): bool
    {
        return $this->hp === 0;
    }

    public function struck(): self
    {
        return new self(\max(0, $this->hp - 1), $this->max, $this->damage + 1, $this->bonus);
    }

    /**
     * A new maximum — the room changed, or the moderator's bonus did: the damage stays, the health never drops below
     * one by it. A fallen boss stays down.
     *
     * @param positive-int $max
     */
    public function rescaled(int $max, ?int $bonus = null): self
    {
        return $this->down()
            ? $this
            : new self(\max(1, $max - $this->damage), $max, $this->damage, $bonus ?? $this->bonus);
    }

    /**
     * Felled at once: the moderator took the dawn without waiting for the room.
     */
    public function felled(): self
    {
        return new self(0, $this->max, $this->damage, $this->bonus);
    }

    /**
     * @return array{hp: int<0, max>, max: positive-int}
     */
    public function toEvent(): array
    {
        return ['hp' => $this->hp, 'max' => $this->max];
    }

    /**
     * @return array{hp: int<0, max>, max: positive-int, damage: int<0, max>, bonus: int}
     */
    public function toArray(): array
    {
        return ['hp' => $this->hp, 'max' => $this->max, 'damage' => $this->damage, 'bonus' => $this->bonus];
    }
}
