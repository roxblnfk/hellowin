<?php

declare(strict_types=1);

namespace App\Domain\Story;

use Random\Randomizer;

/**
 * The rules of the autopilot (docs/spec/scenario.md and docs/spec/protocol.md, «Автопилот»): from the story, its record
 * and when the owl stops talking — the next move and whether it is due now. The reference the lab plays and the
 * frontend tests check is `autoTick` in public/dev/autopilot.js; this is the same algorithm.
 *
 * @psalm-type Next = array{action: self::ACTION_*, to?: int, at?: int, wait?: self::WAIT_*, force?: true}
 * @psalm-type EmptyRoom = array{since: int, done: bool}
 */
final readonly class Autopilot
{
    public const ACTION_BAR = 'bar.ready';
    public const ACTION_SCENE = 'scene.next';
    public const ACTION_STEP = 'finale.step';
    public const ACTION_DICE = 'dice.owl';
    public const ACTION_AGAIN = 'again';
    public const WAIT_ROOM = 'room';
    public const WAIT_PAUSE = 'pause';

    /** Milliseconds a move that changed nothing (a lost job, `busy`) waits before it is tried again. */
    public const RETRY_MS = 5000;

    /** Finale steps with no task of the room: only their staging and the owl hold them. */
    private const STAGED = [Story::SCATTER_STEP, 3, Story::REVEAL_STEP];

    /**
     * @param int<0, max> $pauseMs After the owl has finished, before a move; and between the owl's throws.
     * @param int<0, max> $dawnMinMs
     * @param int<0, max> $dawnMaxMs Not less than `$dawnMinMs`.
     * @param int<0, max> $diceMs How long the room throws before the owl does; 0 — the owl never does.
     * @param int<0, max> $stallMs How long without the room's task before a move with `force`; 0 — never.
     * @param int<0, max> $emptyResetMs How long the room stays without a connection before the story starts over;
     *        0 — never.
     */
    public function __construct(
        private SpeechTable $table,
        private int $pauseMs,
        private int $dawnMinMs,
        private int $dawnMaxMs,
        private int $diceMs,
        private int $stallMs,
        private Randomizer $random = new Randomizer(),
        private int $emptyResetMs = 0,
    ) {}

    /**
     * The empty room (docs/spec/scenario.md, «Автопилот»): once nobody has been connected for `$emptyResetMs`, the
     * story starts over, so whoever comes later starts it from the bar. One reset per empty spell: a room that stays
     * empty keeps `done` until somebody connects. Only the server plays this rule; the lab's `autoTick` has no presence.
     *
     * @param EmptyRoom|null $empty As the previous count of the presence left it: Unix ms the room has been empty since
     *        and whether its reset is made; null — somebody was connected.
     * @param int<0, max> $connections Every connection to the channel, anonymous ones included.
     * @param int $now Unix ms.
     * @return array{EmptyRoom|null, bool} The state to keep and whether to reset now.
     */
    public function empty(?array $empty, int $connections, int $now): array
    {
        if ($this->emptyResetMs === 0 || $connections > 0) {
            return [null, false];
        }
        if ($empty === null) {
            return [['since' => $now, 'done' => false], false];
        }
        if ($empty['done'] || $now - $empty['since'] < $this->emptyResetMs) {
            return [$empty, false];
        }

        return [['since' => $empty['since'], 'done' => true], true];
    }

    /**
     * @param AutoRecord|null $record Null right after switching on: everything counts from now.
     * @param int $talk When the owl stops talking, Unix ms.
     * @param int $now Unix ms.
     * @return array{AutoRecord, Next, bool} The new record, the next move and whether to make it now.
     */
    public function tick(AutoScene $scene, ?AutoRecord $record, int $talk, int $now): array
    {
        $ctx = $scene->ctx();
        $kept = $record?->ctx === $ctx ? $record : null;
        $since = $kept?->since ?? $now;
        $readyAt = $kept?->readyAt;
        $dawnUntil = $kept?->dawnUntil;
        $owlRollAt = $kept?->owlRollAt;
        $acted = $kept?->acted;
        $resumedAt = $record?->resumedAt ?? 0;
        $paused = $record?->paused ?? false;

        $scene->ready && $readyAt === null and $readyAt = $now;
        if ($scene->step !== null && $scene->step >= Story::DAWN_STEP && $dawnUntil === null) {
            $dawnUntil = $since + $this->dawnMinMs + $this->random->getInt(0, \max(0, $this->dawnMaxMs - $this->dawnMinMs));
        }
        $paused && !$scene->paused and $resumedAt = $now;

        $plan = $this->plan($scene, $since, $readyAt, $dawnUntil, $owlRollAt, $talk);
        $due = false;
        if ($scene->paused) {
            unset($plan['at'], $plan['force']);
            $plan['wait'] = self::WAIT_PAUSE;
            $next = $plan;
        } else {
            $next = $plan;
            isset($next['at']) and $next['at'] = \max($next['at'], $resumedAt + $this->pauseMs);
            // The owl's throws are spaced by owlRollAt instead: each one is a new throw, not a retry
            $key = $next['action'] . ':' . ($next['to'] ?? '');
            $retry = $next['action'] !== self::ACTION_DICE && $acted !== null && $acted['key'] === $key && $now - $acted['at'] < self::RETRY_MS;
            $due = isset($next['at']) && $now >= $next['at'] && !$retry;
            if ($due) {
                $acted = ['key' => $key, 'at' => $now];
                $next['action'] === self::ACTION_DICE and $owlRollAt = $now;
            }
        }

        return [new AutoRecord($ctx, $since, $readyAt, $dawnUntil, $owlRollAt, $acted, $resumedAt, $scene->paused), $next, $due];
    }

    /**
     * The move by the state, before the pause has its say: an `at` when it is known, `wait: room` while the room's
     * task is not done.
     *
     * @return Next
     */
    private function plan(AutoScene $scene, int $since, ?int $readyAt, ?int $dawnUntil, ?int $owlRollAt, int $talk): array
    {
        if ($scene->step === null) {
            if ($scene->bar && !$scene->ready) {
                return $this->stall(self::ACTION_BAR, null, $since);
            }
            $to = $scene->scene + 1;

            return $scene->ready
                ? ['action' => self::ACTION_SCENE, 'to' => $to, 'at' => \max($readyAt ?? $since, $talk) + $this->pauseMs]
                : $this->stall(self::ACTION_SCENE, $to, $since);
        }

        $step = $scene->step;
        $staged = $since + $this->table->step($step);
        if ($step >= Story::DAWN_STEP) {
            return ['action' => self::ACTION_AGAIN, 'at' => \max($dawnUntil ?? $since, $staged, $talk + $this->pauseMs)];
        }
        $to = $step + 1;
        if (\in_array($step, self::STAGED, true)) {
            return ['action' => self::ACTION_STEP, 'to' => $to, 'at' => \max($staged, $talk) + $this->pauseMs];
        }
        if ($step === Story::RAPIER_STEP) {
            return $this->stall(self::ACTION_STEP, $to, $since);
        }
        if ($scene->ready) {
            return ['action' => self::ACTION_STEP, 'to' => $to, 'at' => \max($readyAt ?? $since, $staged, $talk) + $this->pauseMs];
        }
        if ($step === Story::DICE_STEP && $scene->dice && $this->diceMs > 0) {
            return ['action' => self::ACTION_DICE, 'at' => $owlRollAt === null
                ? $staged + $this->diceMs
                : \max($staged + $this->diceMs, $owlRollAt + $this->pauseMs)];
        }

        return $this->stall(self::ACTION_STEP, $to, $since);
    }

    /**
     * A move that waits for the room, or goes without it once the room has stalled long enough.
     *
     * @param self::ACTION_BAR|self::ACTION_SCENE|self::ACTION_STEP $action
     * @return Next
     */
    private function stall(string $action, ?int $to, int $since): array
    {
        $go = ['action' => $action];
        $to === null or $go['to'] = $to;

        return $this->stallMs > 0
            ? $go + ['at' => $since + $this->stallMs, 'force' => true]
            : $go + ['wait' => self::WAIT_ROOM];
    }
}
