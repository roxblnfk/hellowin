<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * The goal of the cursed world in a round (docs/spec/scenario.md, «Рапира»): mugs drunk and zombies slain from the
 * cursed step on, and the moderator's additions to both goals. A goal is `$perPlayer` for every player on the floor plus
 * the addition, never below one, so it follows the players as they come and go.
 */
final readonly class Cursed
{
    public const DRINKS = 'drinks';
    public const ZOMBIES = 'zombies';

    /**
     * @param int<0, max> $drunk
     * @param int<0, max> $slain
     * @param int $drinksBonus The moderator's addition to the mugs goal.
     * @param int $zombiesBonus The moderator's addition to the zombies goal.
     * @param int<0, max> $players Players on the floor the clients were last told the goals for.
     */
    public function __construct(
        public int $drunk = 0,
        public int $slain = 0,
        public int $drinksBonus = 0,
        public int $zombiesBonus = 0,
        public int $players = 0,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (!\is_array($value)) {
            return new self();
        }

        $count = static fn(mixed $n): int => \is_int($n) && $n > 0 ? $n : 0;
        $int = static fn(mixed $n): int => \is_int($n) ? $n : 0;

        return new self(
            $count($value['drunk'] ?? null),
            $count($value['slain'] ?? null),
            $int($value['drinksBonus'] ?? null),
            $int($value['zombiesBonus'] ?? null),
            $count($value['players'] ?? null),
        );
    }

    public static function isGoal(mixed $goal): bool
    {
        return $goal === self::DRINKS || $goal === self::ZOMBIES;
    }

    public function drank(): self
    {
        return new self($this->drunk + 1, $this->slain, $this->drinksBonus, $this->zombiesBonus, $this->players);
    }

    public function slew(): self
    {
        return new self($this->drunk, $this->slain + 1, $this->drinksBonus, $this->zombiesBonus, $this->players);
    }

    /**
     * @param int<0, max> $players
     */
    public function toldFor(int $players): self
    {
        return new self($this->drunk, $this->slain, $this->drinksBonus, $this->zombiesBonus, $players);
    }

    /**
     * Moves a goal by `$delta`; a goal that would drop below one stays at one for the players there are now.
     *
     * @param self::DRINKS|self::ZOMBIES $goal
     * @param positive-int $perPlayer
     * @param int<0, max> $players
     */
    public function moved(string $goal, int $delta, int $perPlayer, int $players): self
    {
        $base = $perPlayer * \max(1, $players);
        $bonus = \max(1 - $base, ($goal === self::DRINKS ? $this->drinksBonus : $this->zombiesBonus) + $delta);

        return $goal === self::DRINKS
            ? new self($this->drunk, $this->slain, $bonus, $this->zombiesBonus, $this->players)
            : new self($this->drunk, $this->slain, $this->drinksBonus, $bonus, $this->players);
    }

    /**
     * @param self::DRINKS|self::ZOMBIES $goal
     * @param positive-int $perPlayer
     * @param int<0, max> $players Players on the floor; none counts as one.
     * @return positive-int
     */
    public function target(string $goal, int $perPlayer, int $players): int
    {
        return \max(1, $perPlayer * \max(1, $players) + ($goal === self::DRINKS ? $this->drinksBonus : $this->zombiesBonus));
    }

    /**
     * @param positive-int $perPlayer
     * @param int<0, max> $players
     */
    public function done(int $perPlayer, int $players): bool
    {
        return $this->drunk >= $this->target(self::DRINKS, $perPlayer, $players)
            && $this->slain >= $this->target(self::ZOMBIES, $perPlayer, $players);
    }

    /**
     * The counters and goals as the clients see them.
     *
     * @param positive-int $perPlayer
     * @param int<0, max> $players
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int}
     */
    public function toEvent(int $perPlayer, int $players): array
    {
        return [
            'drunk' => $this->drunk,
            'slain' => $this->slain,
            'drinksTarget' => $this->target(self::DRINKS, $perPlayer, $players),
            'zombiesTarget' => $this->target(self::ZOMBIES, $perPlayer, $players),
        ];
    }

    /**
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksBonus: int, zombiesBonus: int, players: int<0, max>}
     */
    public function toArray(): array
    {
        return [
            'drunk' => $this->drunk,
            'slain' => $this->slain,
            'drinksBonus' => $this->drinksBonus,
            'zombiesBonus' => $this->zombiesBonus,
            'players' => $this->players,
        ];
    }
}
