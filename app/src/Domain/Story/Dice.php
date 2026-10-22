<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Player\PlayerId;

/**
 * The dice of finale step 1 in a round (docs/spec/scenario.md, «Кости 6-6-6»): the last attempt of every player, when
 * it was thrown and what it threw, the owl's throws, the room's new throws, and who rolled 6-6-6 first. The faces of the
 * last attempt are written with its number in the same write, so a repeat whose kept answer is lost still gets the
 * same throw.
 *
 * @psalm-type Faces = array{int<1, 6>, int<1, 6>, int<1, 6>}
 * @psalm-type Winner = array{player: array{id: non-empty-string, name: non-empty-string|null}, attempt: positive-int}|array{owl: true, attempt: positive-int}
 */
final readonly class Dice
{
    /**
     * @param array<non-empty-string, array{attempt: positive-int, at: int, dice: Faces}> $players
     * @param Winner|null $winner
     * @param int<0, max> $owl The owl's throws.
     * @param int<0, max> $rolls New throws of the players: the reveal's number.
     */
    public function __construct(
        public array $players = [],
        public ?array $winner = null,
        public int $owl = 0,
        public int $rolls = 0,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (!\is_array($value)) {
            return new self();
        }

        $players = [];
        /** @var mixed $stored */
        $stored = $value['players'] ?? null;
        /** @var mixed $last */
        foreach (\is_array($stored) ? $stored : [] as $id => $last) {
            // A key of digits only comes back from a PHP array as an int
            $id = (string) $id;
            /** @var mixed $attempt */
            $attempt = \is_array($last) ? $last['attempt'] ?? null : null;
            /** @var mixed $at */
            $at = \is_array($last) ? $last['at'] ?? null : null;
            $dice = self::faces(\is_array($last) ? $last['dice'] ?? null : null);
            if (PlayerId::isValid($id) && \is_int($attempt) && $attempt > 0 && \is_int($at) && $dice !== null) {
                $players[$id] = ['attempt' => $attempt, 'at' => $at, 'dice' => $dice];
            }
        }

        return new self($players, self::winner($value['winner'] ?? null), self::count($value['owl'] ?? null), self::count($value['rolls'] ?? null));
    }

    /**
     * @return int<0, max> The player's last attempt; 0 — none yet.
     */
    public function last(string $player): int
    {
        return $this->players[$player]['attempt'] ?? 0;
    }

    public function lastAt(string $player): ?int
    {
        return $this->players[$player]['at'] ?? null;
    }

    /**
     * @return Faces|null What the player's last attempt threw; null — none yet.
     */
    public function lastDice(string $player): ?array
    {
        return $this->players[$player]['dice'] ?? null;
    }

    /**
     * @param non-empty-string $player
     * @param positive-int $attempt
     * @param Faces $dice
     */
    public function rolled(string $player, int $attempt, int $at, array $dice): self
    {
        $players = $this->players;
        $players[$player] = ['attempt' => $attempt, 'at' => $at, 'dice' => $dice];

        return new self($players, $this->winner, $this->owl, $this->rolls + 1);
    }

    public function owlRolled(): self
    {
        return new self($this->players, $this->winner, $this->owl + 1, $this->rolls);
    }

    /**
     * @param Winner $winner
     */
    public function won(array $winner): self
    {
        return new self($this->players, $winner, $this->owl, $this->rolls);
    }

    /**
     * @return array{players: array<non-empty-string, array{attempt: positive-int, at: int, dice: Faces}>, winner: Winner|null, owl: int<0, max>, rolls: int<0, max>}
     */
    public function toArray(): array
    {
        return ['players' => $this->players, 'winner' => $this->winner, 'owl' => $this->owl, 'rolls' => $this->rolls];
    }

    /**
     * @return Faces|null
     */
    private static function faces(mixed $value): ?array
    {
        if (!\is_array($value) || !\array_is_list($value) || \count($value) !== 3) {
            return null;
        }
        foreach ($value as $face) {
            if (!\is_int($face) || $face < 1 || $face > 6) {
                return null;
            }
        }

        /** @var Faces $value */
        return $value;
    }

    /**
     * @return Winner|null
     */
    private static function winner(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        /** @var mixed $attempt */
        $attempt = $value['attempt'] ?? null;
        if (!\is_int($attempt) || $attempt < 1) {
            return null;
        }
        if (($value['owl'] ?? null) === true) {
            return ['owl' => true, 'attempt' => $attempt];
        }
        /** @var mixed $player */
        $player = $value['player'] ?? null;
        /** @var mixed $id */
        $id = \is_array($player) ? $player['id'] ?? null : null;
        if (!PlayerId::isValid($id)) {
            return null;
        }
        /** @var mixed $name */
        $name = \is_array($player) ? $player['name'] ?? null : null;

        return ['player' => ['id' => $id, 'name' => \is_string($name) && $name !== '' ? $name : null], 'attempt' => $attempt];
    }

    /**
     * @return int<0, max>
     */
    private static function count(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }
}
