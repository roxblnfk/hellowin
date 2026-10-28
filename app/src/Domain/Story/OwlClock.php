<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Clock\Clock;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * When the owl stops talking (docs/spec/protocol.md, «Автопилот», «Счёт речи»): `hellowin:owl` `{until, hold}`, changed
 * under the lock `owl` on every line the channel gets. A line starts once the one before has held the bubble long
 * enough, and the speech lasts to the end of the latest one, never earlier than an earlier line's end.
 *
 * @psalm-import-type Speech from SpeechTable
 */
final readonly class OwlClock
{
    private const KEY = 'hellowin:owl';
    private const LOCK = 'owl';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
        private SpeechTable $table,
        private Clock $clock,
    ) {}

    /**
     * @return int Unix time in milliseconds the line's last part starts: the moment its instruction opens the input.
     */
    public function line(string $id): int
    {
        $speech = $this->table->line($id);

        return $this->said($speech) + $speech['lead'];
    }

    /**
     * The moment {@see line()} would answer now, without counting the line: for an event that has to carry it before
     * the line goes out. A failing store counts only the delay, like {@see said()}.
     *
     * @return int Unix time in milliseconds.
     */
    public function opens(string $id): int
    {
        $speech = $this->table->line($id);
        try {
            $hold = $this->read()[1];
        } catch (\Throwable) {
            $hold = 0;
        }

        return \max($this->clock->now() + $speech['delay'], $hold) + $speech['lead'];
    }

    public function text(string $text): void
    {
        $this->said($this->table->text($text));
    }

    /**
     * @return int Unix time in milliseconds the owl has said everything by; 0 — she has said nothing yet.
     */
    public function until(): int
    {
        return $this->read()[0];
    }

    /**
     * The line has gone out already: a busy lock or a failing store loses the mark, and the autopilot only waits less;
     * the start answered then counts only the delay.
     *
     * @param Speech $speech
     * @return int Unix time in milliseconds the line starts.
     */
    private function said(array $speech): int
    {
        $now = $this->clock->now();
        $start = $now + $speech['delay'];
        try {
            $this->locks->run([self::LOCK], function () use ($speech, $now, &$start): void {
                [$until, $hold] = $this->read();
                $start = \max($now + $speech['delay'], $hold);
                $this->storage->set(self::KEY, [
                    'until' => \max($until, $start + $speech['ms']),
                    'hold' => $start + $speech['hold'],
                ]);
            });
        } catch (\Throwable) {
        }

        return $start;
    }

    /**
     * @return array{int, int} Until and hold.
     */
    private function read(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $until */
        $until = \is_array($value) ? $value['until'] ?? null : null;
        /** @var mixed $hold */
        $hold = \is_array($value) ? $value['hold'] ?? null : null;

        return [\is_int($until) ? $until : 0, \is_int($hold) ? $hold : 0];
    }
}
