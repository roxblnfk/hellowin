// The autopilot of the evening (docs/spec/scenario.md, «Автопилот») as the lab plays it, and the speech table the
// server reads. The server runs its own copy of these rules; this one is the reference the lab and the tests check.

import { HOLD_MS, LINES_BY_LANG, SHOW, showMs } from '../js/owl.js';
import { STEP_MS, SUMMON_MS } from '../js/pixel/finale-timeline.js';

// `{player}` is counted at the longest name the server takes (PlayerName::MAX_LENGTH), `{attempt}` at three digits.
const NAME_CHARS = 24;
const fill = (text) => text.replaceAll('{player}', 'W'.repeat(NAME_CHARS)).replaceAll('{attempt}', '999');
// A line the client holds back: the countdown before finale step 1 comes first (frontend.md, «Призыв»); at step 6 the
// owl flies back to its branch before it speaks.
const DELAY = { 'finale-1': SUMMON_MS, 'finale-6': STEP_MS[6] };

function timesOf(line) {
    const firsts = [line.text, ...Object.values(line.scenes ?? {}), ...Object.values(line.steps ?? {})].map((t) => showMs(fill(t)));
    const then = (Array.isArray(line.then) ? line.then : line.then ? [line.then] : []).map((t) => showMs(fill(t)));
    const first = Math.max(...firsts);
    return {
        ms: first + then.reduce((sum, ms) => sum + ms, 0),
        hold: then.length ? first + Math.min(then[0], HOLD_MS) : Math.min(first, HOLD_MS),
        // The parts follow one another as each ends: the last one starts after all the others.
        lead: then.length ? first + then.slice(0, -1).reduce((sum, ms) => sum + ms, 0) : 0,
    };
}

/**
 * The content of public/autopilot.json: how long each catalog line keeps the owl talking (`ms`, all its parts, the
 * longest of its scene and step variants and of both languages) and holds the bubble against the next line (`hold`),
 * `delay` before it starts, `lead` from its start to its last part (a line in parts; the later of both languages); the rule for the moderator's own text (`showMs`, `holdMs`); each finale step's staging
 * (`steps`, ms from its event).
 */
export function speechTimes() {
    const lines = {};
    for (const id of Object.keys(LINES_BY_LANG.ru).sort()) {
        const [a, b] = Object.values(LINES_BY_LANG).map((catalog) => timesOf(catalog[id]));
        const lead = Math.max(a.lead, b.lead);
        lines[id] = { ms: Math.max(a.ms, b.ms), hold: Math.max(a.hold, b.hold), ...(DELAY[id] ? { delay: DELAY[id] } : {}), ...(lead > 0 ? { lead } : {}) };
    }
    return { showMs: { ...SHOW }, holdMs: HOLD_MS, nameChars: NAME_CHARS, lines, steps: { ...STEP_MS } };
}

/** {ms, hold, delay} of an `owl` event by the table: the own text by its length, an unknown id at the longest. */
export function speechOf(times, event) {
    if (typeof event?.text === 'string') {
        const s = times.showMs;
        const ms = Math.min(s.max, Math.max(s.min, s.base + event.text.trim().length * s.perChar));
        return { ms, hold: Math.min(ms, times.holdMs), delay: 0 };
    }
    const line = times.lines[event?.line];
    return line ? { delay: 0, ...line } : { ms: times.showMs.max, hold: times.holdMs, delay: 0 };
}

/**
 * When the owl stops talking, by the lines said so far: a line starts once the one before has held the bubble long
 * enough, and the speech lasts to the end of the latest one (never earlier than an earlier line's end).
 */
export function createSpeech(times) {
    let until = 0;
    let hold = 0;
    const startOf = (line, now) => Math.max(now + line.delay, hold);
    return {
        said(event, now) {
            const line = speechOf(times, event);
            const start = startOf(line, now);
            until = Math.max(until, start + line.ms);
            hold = start + line.hold;
        },
        /**
         * When the last part of `event` would show if it were said now, without saying it: an instruction line opens
         * its input then (`dice.openAt`, `finale.wordAt`; protocol.md, «Сова объясняет»), told before the line goes out.
         */
        opensAt(event, now) {
            const line = speechOf(times, event);
            return startOf(line, now) + (line.lead ?? 0);
        },
        until: () => until,
    };
}

/** Defaults of the env (engine.md, «Env»): HELLOWIN_AUTO_PAUSE_MS, _DAWN_MIN_MS, _DAWN_MAX_MS, _DICE_MS, _STALL_MS. */
export const AUTO = { pauseMs: 4000, dawnMinMs: 300000, dawnMaxMs: 600000, diceMs: 90000, stallMs: 0 };
// An action whose effect has not come (a lost job, `busy`) is tried again after this long.
export const RETRY_MS = 5000;
const DAWN = 7;
const STAGED = [2, 3, 4];
const BOSS = 6;
const DICE = 1;

const ctxOf = (s) => `${s.round}:${s.scene}:${s.step ?? '-'}`;

// The next move from the state: an `at` when it is known, `wait: 'room'` while the room's task is not done.
function nextOf(s, r, talk, cfg) {
    const after = (t) => Math.max(t, talk) + cfg.pauseMs;
    const stall = (go) => (cfg.stallMs > 0 ? { ...go, at: r.since + cfg.stallMs, force: true } : { ...go, wait: 'room' });
    if (s.scene < s.sceneStages) {
        if (s.scene === 0 && !s.ready) return cfg.stallMs > 0 ? { action: 'bar.ready', at: r.since + cfg.stallMs } : { action: 'bar.ready', wait: 'room' };
        const go = { action: 'scene.next', to: s.scene + 1 };
        return s.ready ? { ...go, at: after(r.readyAt) } : stall(go);
    }
    const step = s.step ?? 0;
    const staged = r.since + (cfg.steps[step] ?? 0);
    if (step >= DAWN) return { action: 'again', at: Math.max(r.dawnUntil, staged, talk + cfg.pauseMs) };
    const go = { action: 'finale.step', to: step + 1 };
    if (STAGED.includes(step)) return { ...go, at: after(staged) };
    if (step === BOSS) return stall(go);
    if (s.ready) return { ...go, at: after(Math.max(r.readyAt, staged)) };
    if (step === DICE && s.dice?.on === true && cfg.diceMs > 0) return { action: 'dice.owl', at: Math.max(staged + cfg.diceMs, (r.owlRollAt ?? -Infinity) + cfg.pauseMs) };
    return stall(go);
}

/**
 * One tick of the autopilot. `s` — the story {round, scene, sceneStages, ready, step (null outside the final scene),
 * paused, dice}; `rec` — the stored record (null after switching on: everything counts from now); `talk` — when the owl
 * stops talking. Returns the new record, the `next` for the clients and whether to act on it now.
 */
export function autoTick(s, rec, talk, now, cfg, random = Math.random) {
    let r = rec?.ctx === ctxOf(s) ? { ...rec } : { ctx: ctxOf(s), since: now, readyAt: null, dawnUntil: null, owlRollAt: null, acted: null, resumedAt: rec?.resumedAt ?? 0, paused: rec?.paused ?? false };
    if (s.ready && r.readyAt === null) r.readyAt = now;
    if ((s.step ?? 0) >= DAWN && s.scene >= s.sceneStages && r.dawnUntil === null) r.dawnUntil = r.since + cfg.dawnMinMs + Math.floor(random() * (cfg.dawnMaxMs - cfg.dawnMinMs + 1));
    if (r.paused && !s.paused) r.resumedAt = now;
    r.paused = s.paused;
    const plan = nextOf(s, r, talk, cfg);
    if (s.paused) {
        const { at, force, ...rest } = plan;
        return { rec: r, next: { ...rest, wait: 'pause' }, due: false };
    }
    const next = plan.at === undefined ? plan : { ...plan, at: Math.max(plan.at, r.resumedAt + cfg.pauseMs) };
    // The owl's rolls are spaced by owlRollAt instead: each one is a new roll, not a retry.
    const key = `${next.action}:${next.to ?? ''}`;
    const retry = next.action !== 'dice.owl' && r.acted?.key === key && now - r.acted.at < RETRY_MS;
    const due = next.at !== undefined && now >= next.at && !retry;
    if (due) {
        r.acted = { key, at: now };
        if (next.action === 'dice.owl') r.owlRollAt = now;
    }
    return { rec: r, next, due };
}
