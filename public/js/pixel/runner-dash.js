// RoadRunner's dashes across the foreground of the finale (docs/spec/frontend.md, «Финал», «Перебежки RoadRunner»):
// a function of the server time alone, without PixiJS, so every viewer sees the same dash and a page opened in the
// middle of one joins it there.

/**
 * One slot of LOOP_MS: he stands on his spot (home, right of the circle), dashes out to the far side left of it in
 * RUN_MS, stands there AWAY_MS with a «Мип-мип!» MEEP_MS long from MEEP_DELAY_MS after he stops, dashes back and stands
 * until the slot ends. The dash out starts OUT_MS plus a hash of the slot up to OUT_JITTER_MS into it.
 */
export const DASH = {
    loopMs: 10000,
    outMs: 2500,
    outJitterMs: 2500,
    runMs: 1000,
    awayMs: 1900,
    meepDelayMs: 150,
    meepMs: 1300,
    // Dust behind his feet: a puff every DUST_EVERY_MS of the run, each thinning out over DUST_LIFE_MS.
    dustEveryMs: 90,
    dustLifeMs: 480,
};

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

/** Slot `slot`'s moments, ms from its start: { out, away, back, home, meep }. */
export function dashPlan(slot) {
    const out = DASH.outMs + (mix(0x5d45, slot) % (DASH.outJitterMs + 1));
    const away = out + DASH.runMs;
    const back = away + DASH.awayMs;
    return { out, away, back, home: back + DASH.runMs, meep: away + DASH.meepDelayMs };
}

// Off the mark fast, braking at the end.
const ease = (p) => 1 - (1 - p) ** 2.2;

/**
 * Where the dash is at server time `now` (ms): { slot, start — the slot's server ms, phase — 'home' | 'out' | 'away'
 * | 'back', k — 0 on the home spot, 1 on the far one, run — ms into the current run (null standing), flip — facing
 * left (the sprite faces right), meepAt — server ms of the slot's meep, meep — whether it is on }.
 */
export function dashAt(now) {
    const slot = Math.floor(now / DASH.loopMs);
    const start = slot * DASH.loopMs;
    const into = now - start;
    const plan = dashPlan(slot);
    const meepAt = start + plan.meep;
    const meep = now >= meepAt && now < meepAt + DASH.meepMs;
    const base = { slot, start, meepAt, meep };
    if (into < plan.out || into >= plan.home) return { ...base, phase: 'home', k: 0, run: null, flip: true };
    if (into < plan.away) return { ...base, phase: 'out', k: ease((into - plan.out) / DASH.runMs), run: into - plan.out, flip: true };
    if (into < plan.back) return { ...base, phase: 'away', k: 1, run: null, flip: false };
    return { ...base, phase: 'back', k: 1 - ease((into - plan.back) / DASH.runMs), run: into - plan.back, flip: false };
}

/**
 * The dust of the run at `now`: [{ k, fade }] — where along home → far each puff was left (as dashAt's `k`) and how
 * far it has thinned, 0–3. Puffs outlive the run they were kicked up in.
 */
export function dustAt(now) {
    const slot = Math.floor(now / DASH.loopMs);
    const into = now - slot * DASH.loopMs;
    const plan = dashPlan(slot);
    const out = [];
    for (const [from, there] of [[plan.out, (p) => ease(p)], [plan.back, (p) => 1 - ease(p)]]) {
        for (let s = DASH.dustEveryMs; s < DASH.runMs; s += DASH.dustEveryMs) {
            const age = into - from - s;
            if (age < 0 || age >= DASH.dustLifeMs) continue;
            out.push({ k: there(s / DASH.runMs), fade: Math.floor((age / DASH.dustLifeMs) * 4) });
        }
    }
    return out;
}

// Half the foreground sprite's width (RUNNER.xl, 52 px, anchored at the middle) plus a few pixels of air.
const EDGE = 30;

/**
 * The two ends of the dash on a screen `artW` art px wide with the circle's foot at `cx`: { home, away } in art px.
 * Home is RoadRunner's spot right of the circle; the far side lies left of it, a little farther out.
 */
export function dashSpan(artW, cx) {
    const home = Math.round(Math.min(artW - 24, cx + artW * 0.2));
    const away = Math.round(Math.max(EDGE, cx - (home - cx) - artW * 0.1));
    return { home, away };
}

/** A server-ms offset into a slot that lands in the middle of `phase` ('home' — before the dash): for the lab. */
export function dashMoment(slot, phase) {
    const p = dashPlan(slot);
    const mid = { home: p.out / 2, out: p.out + DASH.runMs * 0.45, away: p.meep + DASH.meepMs / 2, back: p.back + DASH.runMs * 0.45 };
    return Math.round(mid[phase] ?? mid.home);
}
