<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Clock\Clock;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * When the owl stops talking (docs/spec/protocol.md, «Автопилот», «Счёт речи»): `hellowin:owl` `{until, hold, slot}`,
 * changed under the lock `owl` on every line the channel gets. It plays the client's bubble (`createSpeech` in
 * `public/dev/autopilot.js` is the reference): a story line takes the bubble once the one on show has held it long
 * enough and cuts that one off; of the lines waiting meanwhile only the newest is said; once the story is over it goes
 * at once. The speech lasts to the end of the latest story line. A line aside is said only into a free bubble and
 * leaves that end where it was; otherwise the client drops it and the clock stays as it is.
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
     * @param array<array-key, mixed> $params The event's params: they pick the variant and fill its placeholders.
     * @param int $scene The scene the line is said in.
     * @param int|null $step The finale step it is said at; null outside the final scene.
     * @return int|null Unix time in milliseconds the line's last part starts: the moment its instruction opens the
     *         input; null — a line aside the client does not say, the bubble being busy.
     */
    public function line(string $id, array $params = [], int $scene = 0, ?int $step = null): ?int
    {
        $speech = $this->table->line($id, $params, $scene, $step);
        $start = $this->said($speech, live: false);

        return $start === null ? null : $start + $speech['lead'];
    }

    /**
     * The moment {@see line()} would answer now, without counting the line: for an event that has to carry it before
     * the line goes out. A failing store counts only the delay, like {@see said()}.
     *
     * @return int Unix time in milliseconds.
     */
    public function opens(string $id, int $scene = 0, ?int $step = null): int
    {
        $speech = $this->table->line($id, [], $scene, $step);
        $now = $this->clock->now();
        try {
            [$until, $hold, $slot] = $this->read();
        } catch (\Throwable) {
            $until = $hold = $slot = 0;
        }

        return \max($now + $speech['delay'], self::slot($now, $until, $hold, $slot)) + $speech['lead'];
    }

    /**
     * The moderator's own text: the client says it at once, over the line on show and instead of the one waiting.
     */
    public function text(string $text): void
    {
        $this->said($this->table->text($text), live: true);
    }

    /**
     * @return int Unix time in milliseconds the owl has said everything by; 0 — she has said nothing yet.
     */
    public function until(): int
    {
        return $this->read()[0];
    }

    /**
     * When a story line takes the bubble: with a line still waiting for it, that line's moment, as the new one replaces
     * it; while the story speaks, once the line on show has held the bubble; after it, at once — a hold left then is a
     * line aside's, and the story cuts it off.
     */
    private static function slot(int $now, int $until, int $hold, int $slot): int
    {
        return $slot > $now ? $slot : ($now < $until ? \max($now, $hold) : $now);
    }

    /**
     * The line has gone out already: a busy lock or a failing store loses the mark, and the autopilot only waits less;
     * the start answered then counts only the delay.
     *
     * @param Speech $speech
     * @param bool $live The moderator's own text, said at once.
     * @return int|null Unix time in milliseconds the line starts; null — a line aside into a busy bubble.
     */
    private function said(array $speech, bool $live): ?int
    {
        $now = $this->clock->now();
        try {
            return $this->locks->run([self::LOCK], function () use ($speech, $live, $now): ?int {
                [$until, $hold, $slot] = $this->read();
                if ($speech['aside'] && ($slot > $now || $now < $hold || $now < $until)) {
                    return null;
                }
                $slot = $live ? $now : self::slot($now, $until, $hold, $slot);
                $start = \max($now + $speech['delay'], $slot);
                $this->storage->set(self::KEY, [
                    // No max with the old end: the client cuts the line on show off and never says the one replaced
                    'until' => $speech['aside'] ? $until : $start + $speech['ms'],
                    'hold' => $start + $speech['hold'],
                    'slot' => $slot,
                ]);

                return $start;
            });
        } catch (\Throwable) {
            return $now + $speech['delay'];
        }
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
