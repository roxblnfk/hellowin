<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Clock\Clock;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * When the owl stops talking (docs/spec/protocol.md, «Автопилот», «Счёт речи»): `hellowin:owl` `{until, hold, slot}`,
 * changed under the lock `owl` on every line the channel gets. It plays the client's bubble: a line takes the bubble
 * once the one on show has held it long enough and cuts that one off; of the lines waiting meanwhile only the newest
 * is said. The speech lasts to the end of the latest line; an aside takes the bubble but leaves that end where it was.
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
     * @param bool $aside Chatter beside the story (an ability, a fart, a witch): it must not hold the autopilot, or a
     *        busy room that keeps earning abilities would never let the story move on.
     * @return int Unix time in milliseconds the line's last part starts: the moment its instruction opens the input.
     */
    public function line(string $id, bool $aside = false): int
    {
        $speech = $this->table->line($id);

        return $this->said($speech, $aside, live: false) + $speech['lead'];
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
        $now = $this->clock->now();
        try {
            [, $hold, $slot] = $this->read();
        } catch (\Throwable) {
            $hold = $slot = 0;
        }

        return \max($now + $speech['delay'], self::slot($now, $hold, $slot)) + $speech['lead'];
    }

    /**
     * The moderator's own text: the client says it at once, over the line on show and instead of the one waiting.
     */
    public function text(string $text): void
    {
        $this->said($this->table->text($text), aside: false, live: true);
    }

    /**
     * @return int Unix time in milliseconds the owl has said everything by; 0 — she has said nothing yet.
     */
    public function until(): int
    {
        return $this->read()[0];
    }

    /**
     * When a new line takes the bubble: with a line still waiting for it, that line's moment, as the new one replaces
     * it; otherwise once the line on show has held the bubble.
     */
    private static function slot(int $now, int $hold, int $slot): int
    {
        return $slot > $now ? $slot : \max($now, $hold);
    }

    /**
     * The line has gone out already: a busy lock or a failing store loses the mark, and the autopilot only waits less;
     * the start answered then counts only the delay.
     *
     * @param Speech $speech
     * @return int Unix time in milliseconds the line starts.
     */
    private function said(array $speech, bool $aside, bool $live): int
    {
        $now = $this->clock->now();
        $start = $now + $speech['delay'];
        try {
            $this->locks->run([self::LOCK], function () use ($speech, $aside, $live, $now, &$start): void {
                [$until, $hold, $slot] = $this->read();
                $slot = $live ? $now : self::slot($now, $hold, $slot);
                $start = \max($now + $speech['delay'], $slot);
                $this->storage->set(self::KEY, [
                    // No max with the old end: the client cuts the line on show off and never says the one replaced
                    'until' => $aside ? $until : $start + $speech['ms'],
                    'hold' => $start + $speech['hold'],
                    'slot' => $slot,
                ]);
            });
        } catch (\Throwable) {
        }

        return $start;
    }

    /**
     * @return array{int, int, int} Until, hold and when the latest line took or takes the bubble.
     */
    private function read(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $until */
        $until = \is_array($value) ? $value['until'] ?? null : null;
        /** @var mixed $hold */
        $hold = \is_array($value) ? $value['hold'] ?? null : null;
        /** @var mixed $slot */
        $slot = \is_array($value) ? $value['slot'] ?? null : null;

        return [\is_int($until) ? $until : 0, \is_int($hold) ? $hold : 0, \is_int($slot) ? $slot : 0];
    }
}
