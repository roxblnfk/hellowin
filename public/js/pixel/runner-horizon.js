// RoadRunner's runs along the horizon (docs/spec/frontend.md, «RoadRunner на горизонте»): every scene but the bar —
// the day, the sunset, the night, the finale until his entrance at step 3 and again from step 5. A function of the
// server time alone, without PixiJS, so every viewer sees the same run and hears the same «Мип-мип!».

/**
 * One run of RUN_MS per slot of LOOP_MS, starting up to JITTER_MS into the slot (a hash of the slot), so the runs come
 * 20–30 s apart; the direction is a hash of the slot too. With meeps (the dawn), about half the slots stop him for
 * STOP_MS by the right edge of the screen, with a «Мип-мип!» MEEP_MS long from MEEP_DELAY_MS after he stops.
 * JITTER_MS + RUN_MS + STOP_MS stays under LOOP_MS: a run never spills into the next slot.
 */
export const HORIZON = {
    loopMs: 25000,
    jitterMs: 5000,
    runMs: 6500,
    stopMs: 1700,
    meepDelayMs: 200,
    meepMs: 1300,
};

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

const ltrOf = (slot) => mix(0x7a11, slot) % 2 === 0;

/** Server ms when slot `slot`'s run starts. */
export const horizonStart = (slot) => slot * HORIZON.loopMs + (mix(0x0ff5, slot) % (HORIZON.jitterMs + 1));

/**
 * Where slot `slot`'s meeping run stops, as a share of the way across (`k` of horizonAt); null — the slot runs
 * through. On the screen it is 0.84–0.94 of the width: the middle of the horizon is behind the patch's fence, the left
 * of it behind the tree.
 */
export function horizonStop(slot) {
    if (mix(0x3e3e, slot) % 2 !== 0) return null;
    const x = 0.84 + (mix(0x5709, slot) % 11) / 100;
    return ltrOf(slot) ? x : 1 - x;
}

/**
 * RoadRunner on the horizon at server time `now` (ms), or null off screen: { slot, k — 0 → 1 along the way across,
 * ltr — running left to right, run — ms of running so far (the frame clock; it stands still while stopped), meepAt —
 * server ms of the slot's meep (null — none), meep — whether it is on }. `meeps` lets the slots stop and meep.
 */
export function horizonAt(now, meeps = false) {
    const slot = Math.floor(now / HORIZON.loopMs);
    const start = horizonStart(slot);
    const into = now - start;
    if (into < 0) return null;
    const ltr = ltrOf(slot);
    const stop = meeps ? horizonStop(slot) : null;
    if (stop === null) {
        if (into >= HORIZON.runMs) return null;
        return { slot, k: into / HORIZON.runMs, ltr, run: into, meepAt: null, meep: false };
    }
    const stopAt = Math.round(HORIZON.runMs * stop);
    if (into >= HORIZON.runMs + HORIZON.stopMs) return null;
    const run = into < stopAt ? into : Math.max(stopAt, into - HORIZON.stopMs);
    const meepAt = start + stopAt + HORIZON.meepDelayMs;
    const meep = now >= meepAt && now < meepAt + HORIZON.meepMs;
    return { slot, k: run / HORIZON.runMs, ltr, run, meepAt, meep };
}

/**
 * The server ms of the first run at or after `from` when he is `x` of the way across the screen from its left edge,
 * whichever way he runs: for the lab.
 */
export function nextHorizonRun(from, x = 0.5) {
    for (let slot = Math.floor(from / HORIZON.loopMs); ; slot++) {
        const at = horizonStart(slot) + Math.round(HORIZON.runMs * (ltrOf(slot) ? x : 1 - x));
        if (at >= from) return at;
    }
}

/** The server ms in the middle of the first meep of the horizon at or after `from`: for the lab. */
export function nextHorizonMeep(from) {
    for (let slot = Math.floor(from / HORIZON.loopMs); ; slot++) {
        const stop = horizonStop(slot);
        if (stop === null) continue;
        const mid = horizonStart(slot) + Math.round(HORIZON.runMs * stop) + HORIZON.meepDelayMs + HORIZON.meepMs / 2;
        if (mid >= from) return Math.round(mid);
    }
}
