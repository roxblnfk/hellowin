// The autopilot of the evening (docs/spec/scenario.md, «Автопилот») as the lab plays it, and the speech table the
// server reads. The server runs its own copy of these rules; this one is the reference the lab and the tests check.

import { HOLD_MS, LINES_BY_LANG, SHOW, SOMEONE_CHARS, bareText as bare, fillsOf, showMs } from '../js/owl.js';
import { STEP_MS, SUMMON_MS } from '../js/pixel/finale-timeline.js';

// A line the client holds back: the countdown before finale step 1 comes first (frontend.md, «Призыв»); at step 6 the
// owl flies back to its branch before it speaks.
const DELAY = { 'finale-1': SUMMON_MS, 'finale-6': STEP_MS[6] };
const partsOf = (line) => (Array.isArray(line.then) ? line.then : line.then ? [line.then] : []);
const sum = (list) => list.reduce((a, b) => a + b, 0);

// A variant: its first part, then the line's own `then` parts, each said as the one before ends; every part at the
// longer of its ru and en text, as the bubble times it (owl.js, partTimes). With placeholders (a line of one part) — also
// `chars`, the longer text without them, and `fill`, which ones it has, for the server to count the real length.
function variantOf(ru, en, thenRu, thenEn) {
    const a = Math.max(showMs(bare(ru)), showMs(bare(en)));
    const rest = thenRu.map((t, i) => Math.max(showMs(t), showMs(thenEn[i])));
    const lead = rest.length ? a + sum(rest.slice(0, -1)) : 0;
    const fill = fillsOf(ru);
    return {
        ms: a + sum(rest),
        hold: rest.length ? lead + Math.min(rest.at(-1), HOLD_MS) : Math.min(a, HOLD_MS),
        ...(lead > 0 ? { lead } : {}),
        ...(fill.length ? { chars: Math.max(bare(ru).length, bare(en).length), fill } : {}),
    };
}

const mapValues = (object, fn) => Object.fromEntries(Object.entries(object).map(([k, v]) => [k, fn(v, k)]));

/**
 * The content of public/autopilot.json (protocol.md, «Автопилот»): per catalog line its default variant (`text`) at the
 * top — `ms` it keeps the owl talking with all its parts, `hold` it keeps the bubble, `lead` from its start to its last
 * part —, its `scenes`, `steps` and `abilities` variants the same way, `delay` before it starts, `aside` for a line
 * said aside; both languages at the later. The rule for the moderator's own text (`showMs`, `holdMs`), the name
 * standing in for a missing player (`someone`); each finale step's staging (`steps`, ms from its event).
 */
export function speechTimes() {
    const lines = {};
    for (const id of Object.keys(LINES_BY_LANG.ru).sort()) {
        const ru = LINES_BY_LANG.ru[id];
        const en = LINES_BY_LANG.en[id];
        const [thenRu, thenEn] = [partsOf(ru), partsOf(en)];
        const of = (r, e) => variantOf(r, e, thenRu, thenEn);
        lines[id] = {
            ...of(ru.text, en.text),
            ...(DELAY[id] ? { delay: DELAY[id] } : {}),
            ...(ru.aside ? { aside: true } : {}),
            ...(ru.scenes ? { scenes: mapValues(ru.scenes, (t, k) => of(t, en.scenes[k])) } : {}),
            ...(ru.steps ? { steps: mapValues(ru.steps, (t, k) => of(t, en.steps[k])) } : {}),
            ...(ru.abilities ? { abilities: mapValues(ru.abilities, (list, a) => list.map((t, i) => of(t, en.abilities[a][i]))) } : {}),
        };
    }
    return { showMs: { ...SHOW }, holdMs: HOLD_MS, someone: SOMEONE_CHARS, lines, steps: { ...STEP_MS } };
}

const showOf = (s, chars) => Math.min(s.max, Math.max(s.min, s.base + chars * s.perChar));

/**
 * The variant the client says (owl.js, lineText): at finale `step` (null outside the final scene) its `steps` one, else
 * the scene's, else the ability's phrase `n % length`, else the default.
 */
function pickVariant(line, params, scene, step) {
    const list = line.abilities?.[params?.ability];
    const n = Number.isInteger(params?.n) && params.n >= 0 ? params.n : 0;
    return (step === null ? undefined : line.steps?.[step]) ?? line.scenes?.[scene] ?? (list?.length ? list[n % list.length] : undefined) ?? line;
}

// How long a placeholder's value is, as the client fills it: the trimmed name or the stand-in, the attempt's digits or «?».
function fillLength(times, fill, params) {
    if (fill === 'player') {
        const name = params?.player?.name;
        return typeof name === 'string' && name.trim() !== '' ? name.trim().length : times.someone;
    }
    return Number.isInteger(params?.attempt) ? String(params.attempt).length : 1;
}

/**
 * {ms, hold, delay, lead, aside} of an `owl` event said in `scene` at finale `step`: the own text by its length; a
 * catalog line by its variant, one with placeholders by its length with them filled; an unknown id at the longest.
 */
export function speechOf(times, event, { scene = 0, step = null } = {}) {
    const s = times.showMs;
    if (typeof event?.text === 'string') {
        const ms = showOf(s, event.text.trim().length);
        return { ms, hold: Math.min(ms, times.holdMs), delay: 0, lead: 0, aside: false };
    }
    const line = times.lines[event?.line];
    if (!line) return { ms: s.max, hold: times.holdMs, delay: 0, lead: 0, aside: false };
    const v = pickVariant(line, event.params, scene, step);
    const common = { delay: line.delay ?? 0, aside: line.aside === true };
    if (v.chars === undefined) return { ms: v.ms, hold: v.hold, lead: v.lead ?? 0, ...common };
    const ms = showOf(s, v.chars + sum(v.fill.map((f) => fillLength(times, f, event.params))));
    return { ms, hold: Math.min(ms, times.holdMs), lead: 0, ...common };
}

/**
 * The owl's clock as the client's bubble runs it (owl.js, createOwl; protocol.md, «Счёт речи»): `until` — when the story
 * lines end, `hold` — until when the shown line keeps the bubble, `slot` — when the waiting line takes it. A story line
 * waits for the hold and replaces a line still waiting; it cuts the shown one off, so its end is the end; once the
 * story is over it goes at once, cutting a line aside off. A line aside
 * is said only into a free bubble — nothing waiting, `now ≥ hold` and `now ≥ until` — and leaves `until` alone; else it
 * is dropped. The moderator's own text goes at once. `ctx` — {scene, step} the event is said in. `said` returns when the
 * line starts, null for a line aside dropped.
 */
export function createSpeech(times) {
    let until = 0;
    let hold = 0;
    let slot = 0;
    // A story line waits for the hold only while the story speaks (a hold past the story's end is an aside's); an aside
    // comes only into a free bubble, where the slot is now anyway.
    const slotOf = (event, now) => (typeof event?.text === 'string' ? now : slot > now ? slot : now < until ? Math.max(now, hold) : now);
    return {
        said(event, now, ctx = {}) {
            const line = speechOf(times, event, ctx);
            if (line.aside && (slot > now || now < hold || now < until)) return null;
            slot = slotOf(event, now);
            const start = Math.max(now + line.delay, slot);
            if (!line.aside) until = start + line.ms;
            hold = start + line.hold;
            return start;
        },
        /**
         * When the last part of `event` would show if it were said now, without saying it: an instruction line opens
         * its input then (`dice.openAt`, `finale.wordAt`; protocol.md, «Сова объясняет»), told before the line goes out.
         */
        opensAt(event, now, ctx = {}) {
            const line = speechOf(times, event, ctx);
            return Math.max(now + line.delay, slotOf(event, now)) + line.lead;
        },
        until: () => until,
        /** The clock as the server stores it (KV `hellowin:owl`). */
        state: () => ({ until, hold, slot }),
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
