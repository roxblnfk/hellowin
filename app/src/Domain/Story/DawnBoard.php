<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Player\PlayerId;

/**
 * The shooters of the dawn (docs/spec/scenario.md, «Топ-10 рассвета»): flyers each player shot down over the whole
 * evening, in any scene, with when the current score was reached — of two equal scores the earlier one ranks higher.
 *
 * @psalm-type Row = array{player: array{id: non-empty-string, name: non-empty-string|null}, shots: positive-int}
 */
final readonly class DawnBoard
{
    /**
     * @param array<non-empty-string, array{shots: positive-int, at: int, name: non-empty-string|null}> $players
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
            /** @var mixed $shots */
            $shots = \is_array($row) ? $row['shots'] ?? null : null;
            /** @var mixed $at */
            $at = \is_array($row) ? $row['at'] ?? null : null;
            /** @var mixed $name */
            $name = \is_array($row) ? $row['name'] ?? null : null;
            if (PlayerId::isValid($id) && \is_int($shots) && $shots > 0 && \is_int($at)) {
                $players[$id] = ['shots' => $shots, 'at' => $at, 'name' => \is_string($name) && $name !== '' ? $name : null];
            }
        }

        return new self($players);
    }

    /**
     * The player's count as their record holds it. The count only grows: a repeated or late hit carrying a count
     * the board already has changes only the name, and keeps the moment the score was reached.
     *
     * @param non-empty-string $player
     * @param non-empty-string|null $name
     * @param int<0, max> $shots Flyers the player has shot down in all.
     * @param int $at Unix time in milliseconds of the hit.
     */
    public function reached(string $player, ?string $name, int $shots, int $at): self
    {
        $row = $this->players[$player] ?? null;
        if ($shots < 1 && $row === null) {
            return $this;
        }

        $players = $this->players;
        $players[$player] = $row === null || $shots > $row['shots']
            ? ['shots' => \max(1, $shots), 'at' => $at, 'name' => $name]
            : ['shots' => $row['shots'], 'at' => $row['at'], 'name' => $name];

        return new self($players);
    }

    /**
     * @param positive-int $limit
     * @return list<Row> Most hits first, of equal ones the earlier.
     */
    public function board(int $limit): array
    {
        $rows = [];
        foreach ($this->players as $id => $row) {
            // A key of digits only comes back from a PHP array as an int
            /** @psalm-suppress RedundantCastGivenDocblockType */
            $rows[] = ['id' => (string) $id, 'shots' => $row['shots'], 'at' => $row['at'], 'name' => $row['name']];
        }
        \usort(
            $rows,
            /**
             * @param array{id: non-empty-string, shots: positive-int, at: int, name: non-empty-string|null} $a
             * @param array{id: non-empty-string, shots: positive-int, at: int, name: non-empty-string|null} $b
             */
            static fn(array $a, array $b): int => [$b['shots'], $a['at'], $a['id']] <=> [$a['shots'], $b['at'], $b['id']],
        );

        return \array_map(
            static fn(array $row): array => ['player' => ['id' => $row['id'], 'name' => $row['name']], 'shots' => $row['shots']],
            \array_slice($rows, 0, $limit),
        );
    }

    /**
     * @return array{players: array<non-empty-string, array{shots: positive-int, at: int, name: non-empty-string|null}>}
     */
    public function toArray(): array
    {
        return ['players' => $this->players];
    }
}
