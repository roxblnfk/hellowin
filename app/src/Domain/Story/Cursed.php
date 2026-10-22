<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Balance\Limit;

/**
 * The goal of the cursed world in a round (docs/spec/scenario.md, «Рапира»): mugs drunk and zombies slain from the
 * cursed step on, and the moderator's additions to both goals. A goal is its limit for the figures on the floor plus
 * the addition, never below one, so it follows the room as it comes and goes.
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
     * @param int<0, max> $drinksTold The mugs goal the clients were last told; 0 — none yet.
     * @param int<0, max> $zombiesTold The zombies goal the clients were last told; 0 — none yet.
     */
    public function __construct(
        public int $drunk = 0,
        public int $slain = 0,
        public int $drinksBonus = 0,
        public int $zombiesBonus = 0,
        public int $drinksTold = 0,
        public int $zombiesTold = 0,
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
            $count($value['drinksTold'] ?? null),
            $count($value['zombiesTold'] ?? null),
        );
    }

    public static function isGoal(mixed $goal): bool
    {
        return $goal === self::DRINKS || $goal === self::ZOMBIES;
    }

    public function drank(): self
    {
        return new self($this->drunk + 1, $this->slain, $this->drinksBonus, $this->zombiesBonus, $this->drinksTold, $this->zombiesTold);
    }

    public function slew(): self
    {
        return new self($this->drunk, $this->slain + 1, $this->drinksBonus, $this->zombiesBonus, $this->drinksTold, $this->zombiesTold);
    }

    /**
     * @param array{drinksTarget: positive-int, zombiesTarget: positive-int, ...} $event The goals the clients get.
     */
    public function told(array $event): self
    {
        return new self($this->drunk, $this->slain, $this->drinksBonus, $this->zombiesBonus, $event['drinksTarget'], $event['zombiesTarget']);
    }

    /**
     * Moves a goal by `$delta`; a goal that would drop below one stays at one for the room there is now.
     *
     * @param self::DRINKS|self::ZOMBIES $goal
     * @param int<0, max> $players
     */
    public function moved(string $goal, int $delta, Limit $limit, int $players, float $k): self
    {
        return $goal === self::DRINKS
            ? new self($this->drunk, $this->slain, $limit->moved($this->drinksBonus, $delta, $players, $k), $this->zombiesBonus, $this->drinksTold, $this->zombiesTold)
            : new self($this->drunk, $this->slain, $this->drinksBonus, $limit->moved($this->zombiesBonus, $delta, $players, $k), $this->drinksTold, $this->zombiesTold);
    }

    /**
     * @param int<0, max> $players
     */
    public function done(Limit $drinks, Limit $zombies, int $players, float $k): bool
    {
        return $this->drunk >= $drinks->with($this->drinksBonus, $players, $k)
            && $this->slain >= $zombies->with($this->zombiesBonus, $players, $k);
    }

    /**
     * The counters and goals as the clients see them.
     *
     * @param int<0, max> $players
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int}
     */
    public function toEvent(Limit $drinks, Limit $zombies, int $players, float $k): array
    {
        return [
            'drunk' => $this->drunk,
            'slain' => $this->slain,
            'drinksTarget' => $drinks->with($this->drinksBonus, $players, $k),
            'zombiesTarget' => $zombies->with($this->zombiesBonus, $players, $k),
        ];
    }

    /**
     * The counters and goals the clients were last told.
     *
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: int<0, max>, zombiesTarget: int<0, max>}
     */
    public function toldEvent(): array
    {
        return ['drunk' => $this->drunk, 'slain' => $this->slain, 'drinksTarget' => $this->drinksTold, 'zombiesTarget' => $this->zombiesTold];
    }

    /**
     * @return array{drunk: int<0, max>, slain: int<0, max>, drinksBonus: int, zombiesBonus: int, drinksTold: int<0, max>, zombiesTold: int<0, max>}
     */
    public function toArray(): array
    {
        return [
            'drunk' => $this->drunk,
            'slain' => $this->slain,
            'drinksBonus' => $this->drinksBonus,
            'zombiesBonus' => $this->zombiesBonus,
            'drinksTold' => $this->drinksTold,
            'zombiesTold' => $this->zombiesTold,
        ];
    }
}
