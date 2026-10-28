<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The word of the rapier step in `hellowin:word` — `{round, wordAt, hinted}` — changed under the lock `word`: when the
 * players may say it, and whether the owl has hinted at it. A record of an earlier round reads as none, so a reset needs
 * not touch it.
 */
final readonly class WordStore
{
    private const KEY = 'hellowin:word';
    private const LOCK = 'word';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @return int|null Unix time in milliseconds from which the word is taken; null — the step has not opened it.
     */
    public function wordAt(int $round): ?int
    {
        return $this->read($round)['wordAt'];
    }

    /**
     * Fixes the moment the word opens; one fixed already stays.
     *
     * @param int $wordAt Unix time in milliseconds.
     * @return int The moment in force.
     * @throws LockBusyException
     */
    public function open(int $round, int $wordAt): int
    {
        return $this->locks->run([self::LOCK], function () use ($round, $wordAt): int {
            $record = $this->read($round);
            if ($record['wordAt'] !== null) {
                return $record['wordAt'];
            }
            $this->storage->set(self::KEY, ['round' => $round, 'wordAt' => $wordAt, 'hinted' => $record['hinted']]);

            return $wordAt;
        });
    }

    /**
     * Marks the owl's hint of the round.
     *
     * @return bool Whether this call marked it: of two calls only one gets true.
     * @throws LockBusyException
     */
    public function hint(int $round): bool
    {
        return $this->locks->run([self::LOCK], function () use ($round): bool {
            $record = $this->read($round);
            if ($record['hinted']) {
                return false;
            }
            $this->storage->set(self::KEY, ['round' => $round, 'wordAt' => $record['wordAt'], 'hinted' => true]);

            return true;
        });
    }

    /**
     * @return array{wordAt: int|null, hinted: bool}
     */
    private function read(int $round): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        if (!\is_array($value) || ($value['round'] ?? null) !== $round) {
            return ['wordAt' => null, 'hinted' => false];
        }
        /** @var mixed $wordAt */
        $wordAt = $value['wordAt'] ?? null;

        return ['wordAt' => \is_int($wordAt) ? $wordAt : null, 'hinted' => ($value['hinted'] ?? false) === true];
    }
}
