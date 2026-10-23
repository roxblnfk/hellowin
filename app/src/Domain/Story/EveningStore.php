<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The reveal numbers of the cycles before the current one, for an evening that went back to the bar: `hellowin:evening`
 * — `{through, clicks, jobs, burned, shot, drinks, slain, damage, rolls, lastPumpkin}`, changed under the lock
 * `evening`. `through` is the last round folded in, so a round folds once. The calls and the dropped repeats are not
 * here: their tally lives through the return to the bar by itself.
 */
final readonly class EveningStore
{
    private const KEY = 'hellowin:evening';
    private const LOCK = 'evening';
    private const COUNTS = ['clicks', 'jobs', 'burned', 'shot', 'drinks', 'slain', 'damage', 'rolls'];

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * Adds the numbers of a finished round; a round folded already changes nothing.
     *
     * @throws LockBusyException
     */
    public function fold(int $round, Reveal $reveal): void
    {
        $this->locks->run([self::LOCK], function () use ($round, $reveal): void {
            $value = $this->read();
            if ($value !== null && $value[0] >= $round) {
                return;
            }

            $sum = $value === null ? $reveal : $reveal->plus($value[1]);
            $this->storage->set(self::KEY, [
                'through' => $round,
                'clicks' => $sum->clicks,
                'jobs' => $sum->jobs,
                'burned' => $sum->burned,
                'shot' => $sum->shot,
                'drinks' => $sum->drinks,
                'slain' => $sum->slain,
                'damage' => $sum->damage,
                'rolls' => $sum->rolls,
                'lastPumpkin' => $sum->lastPumpkin,
            ]);
        });
    }

    /**
     * The numbers carried into the round, null when nothing was carried into it.
     */
    public function carried(int $round): ?Reveal
    {
        $value = $this->read();

        return $value !== null && $value[0] < $round ? $value[1] : null;
    }

    public function forget(): void
    {
        $this->storage->delete(self::KEY);
    }

    /**
     * @return array{id: non-empty-string, name: non-empty-string|null}|null
     */
    private static function player(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        /** @var mixed $id */
        $id = $value['id'] ?? null;
        /** @var mixed $name */
        $name = $value['name'] ?? null;

        return \is_string($id) && $id !== ''
            ? ['id' => $id, 'name' => \is_string($name) && $name !== '' ? $name : null]
            : null;
    }

    /**
     * @return array{int, Reveal}|null
     */
    private function read(): ?array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $through */
        $through = \is_array($value) ? $value['through'] ?? null : null;
        if (!\is_array($value) || !\is_int($through)) {
            return null;
        }

        $counts = [];
        foreach (self::COUNTS as $name) {
            /** @var mixed $count */
            $count = $value[$name] ?? null;
            $counts[$name] = \is_int($count) && $count > 0 ? $count : 0;
        }

        return [$through, new Reveal(
            $counts['clicks'],
            $counts['jobs'],
            $counts['burned'],
            $counts['shot'],
            self::player($value['lastPumpkin'] ?? null),
            $counts['drinks'],
            $counts['slain'],
            $counts['damage'],
            $counts['rolls'],
        )];
    }
}
