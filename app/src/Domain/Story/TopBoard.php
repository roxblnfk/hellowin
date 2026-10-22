<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Player\PlayerId;

/**
 * The shooters' top of the evening (docs/spec/protocol.md, «Топ стрелков»): the points each player has for flyers shot
 * down over the whole evening, in any scene, with when the current score was reached — of two equal scores the earlier
 * one ranks higher.
 *
 * @psalm-type Row = array{player: array{id: non-empty-string, name: non-empty-string|null}, points: positive-int}
 */
final readonly class TopBoard
{
    /**
     * @param array<non-empty-string, array{points: positive-int, at: int, name: non-empty-string|null}> $players
     */
    public function __construct(
        public array $players = [],
    ) {}

    public static function fromArray(mixed $value): self
    {
        /** @var mixed $stored */
        $stored = \is_array($value) ? $value['players'] ?? null : null;
        $players = [];
        /** @var mixed $row */
        foreach (\is_array($stored) ? $stored : [] as $id => $row) {
            $id = (string) $id;
            /** @var mixed $points */
            $points = \is_array($row) ? $row['points'] ?? null : null;
            /** @var mixed $at */
            $at = \is_array($row) ? $row['at'] ?? null : null;
            /** @var mixed $name */
            $name = \is_array($row) ? $row['name'] ?? null : null;
            if (PlayerId::isValid($id) && \is_int($points) && $points > 0 && \is_int($at)) {
                $players[$id] = ['points' => $points, 'at' => $at, 'name' => \is_string($name) && $name !== '' ? $name : null];
            }
        }

        return new self($players);
    }

    /**
     * The player's points as their record holds them. The score only grows: a repeated or late hit carrying a score the
     * board already has changes only the name and keeps the moment the score was reached; a player without points stays
     * off the board.
     *
     * @param non-empty-string $player
     * @param non-empty-string|null $name
     * @param int<0, max> $points The player's points for every flyer shot down.
     * @param int $at Unix time in milliseconds of the hit.
     */
    public function reached(string $player, ?string $name, int $points, int $at): self
    {
        $row = $this->players[$player] ?? null;
        if ($row === null && $points < 1) {
            return $this;
        }

        $players = $this->players;
        $players[$player] = $row === null || $points > $row['points']
            ? ['points' => \max(1, $points), 'at' => $at, 'name' => $name]
            : ['points' => $row['points'], 'at' => $row['at'], 'name' => $name];

        return new self($players);
    }

    /**
     * @param positive-int $limit
     * @return list<Row> Most points first, of equal ones the earlier, then by id.
     */
    public function board(int $limit): array
    {
        $rows = [];
        foreach ($this->players as $id => $row) {
            // A key of digits only comes back from a PHP array as an int
            /** @psalm-suppress RedundantCastGivenDocblockType */
            $rows[] = ['id' => (string) $id, 'points' => $row['points'], 'at' => $row['at'], 'name' => $row['name']];
        }
        \usort(
            $rows,
            /**
             * @param array{id: non-empty-string, points: positive-int, at: int, name: non-empty-string|null} $a
             * @param array{id: non-empty-string, points: positive-int, at: int, name: non-empty-string|null} $b
             */
            static fn(array $a, array $b): int => [$b['points'], $a['at'], $a['id']] <=> [$a['points'], $b['at'], $b['id']],
        );

        return \array_map(
            static fn(array $row): array => ['player' => ['id' => $row['id'], 'name' => $row['name']], 'points' => $row['points']],
            \array_slice($rows, 0, $limit),
        );
    }

    /**
     * @return array{players: array<non-empty-string, array{points: positive-int, at: int, name: non-empty-string|null}>}
     */
    public function toArray(): array
    {
        return ['players' => $this->players];
    }
}
