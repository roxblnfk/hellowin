/**
 * A wandering zombie worker burning out at finale step 6 (docs/spec/frontend.md, «Рапира», «Зомби сгорают»): the
 * server sends `burn {flyer, at, heal}` once the zombie has stood `boss.burnMs` on the field, and everything here is a
 * function of ms since that `at` on the server clock, so every viewer sees the same moment and a late event joins
 * midway. No DOM: the scene draws it, sound.js and the Node tests read the same numbers.
 */

export const ZBURN = {
    // The last `warnMs` before its deadline the zombie smoulders: green sparks off it, embers on its outline.
    warnMs: 1000,
    // Past the deadline without a `burn` (a held-back event) it smoulders on for `graceMs`, then stops pretending.
    graceMs: 2000,
    // Body frames (pixel/deaths.js, zombieBurnArt().body) start at these ms: the flash, the char climbing from the
    // feet, the charred body, the crumble into a heap, the heap cooling; it blinks out from `blink` and is gone by
    // `bodyEnd`.
    bodyAt: [0, 120, 300, 480, 760, 900, 1040, 1180, 1450],
    blink: 1450,
    bodyEnd: 1600,
    flickerMs: 90,
    // The flames over the standing body until `heapAt`, then over the heap, out by `flameEnd`.
    heapAt: 760,
    flameEnd: 1450,
    // The green wisp leaves the fire at `wispAt` and reaches FrankenPHP `wispMs` later — the heal lands then.
    wispAt: 260,
    wispMs: 640,
    // Over FrankenPHP: «+heal» rises `riseMs` by `risePx` art px and hangs until `healMs` after the landing; his
    // green flash fades out over `flashMs`.
    healMs: 900,
    riseMs: 600,
    risePx: 14,
    flashMs: 450,
    // A `burn` heard later than this after its `at` stays silent (the picture still plays what is left of it).
    staleMs: 1500,
};

/** When the heal lands on FrankenPHP, ms after `at`. */
export const HEAL_AT = ZBURN.wispAt + ZBURN.wispMs;
/** When the whole burn is over, ms after `at`. */
export const BURN_END = Math.max(ZBURN.bodyEnd, HEAL_AT + ZBURN.healMs);

const clamp01 = (v) => Math.max(0, Math.min(1, v));

/**
 * The server ms zombie `launchedAt` burns at, or null when no zombie burns now: FrankenPHP awake and standing, with
 * `burnMs` — `boss` of the snapshot or the event (protocol.md, «Рапира»). A zombie older than the boss counts from his
 * waking, `awakeAt`.
 */
export function burnDeadline(launchedAt, boss) {
    if (!boss || !(boss.hp > 0) || !(boss.burnMs > 0) || !Number.isFinite(launchedAt)) return null;
    const from = Number.isFinite(boss.awakeAt) ? Math.max(launchedAt, boss.awakeAt) : launchedAt;
    return from + boss.burnMs;
}

/** How hard a zombie `left` ms before its deadline smoulders: 0–2, or null while it does not. */
export function smoulderAt(left) {
    if (!Number.isFinite(left) || left > ZBURN.warnMs || left < -ZBURN.graceMs) return null;
    return left > 600 ? 0 : left > 250 ? 1 : 2;
}

/** The body frame at `t` ms after `at`: an index into zombieBurnArt().body, or null once it is gone. */
export function burnBodyAt(t) {
    if (!(t >= 0) || t >= ZBURN.bodyEnd) return null;
    let k = 0;
    while (k + 1 < ZBURN.bodyAt.length && t >= ZBURN.bodyAt[k + 1]) k++;
    return k;
}

/**
 * The flames at `t`: { over: 'body' | 'heap', level, phase } — `level` indexes zombieBurnArt().flames (0–2, over the
 * body) or .heapFlames (0–1, over the heap), `phase` the flicker — or null without fire.
 */
export function burnFlameAt(t) {
    if (!(t >= 60) || t >= ZBURN.flameEnd) return null;
    const phase = Math.floor(t / ZBURN.flickerMs) % 4;
    if (t < ZBURN.heapAt) return { over: 'body', level: t < 200 ? 0 : t < 350 ? 1 : 2, phase };
    return { over: 'heap', level: t < 1100 ? 0 : 1, phase };
}

/** The fire's light on the ground at `t`, 0–1 in four steps: up by 200 ms, held while it burns, out by 1500. */
export function burnGlowAt(t) {
    if (!(t >= 0) || t >= 1500) return 0;
    const v = t < 200 ? t / 200 : t < 800 ? 1 : 1 - (t - 800) / 700;
    return Math.ceil(clamp01(v) * 4) / 4;
}

/**
 * The green wisp of the heal at `t`: { k } — 0–1 of its way, eased in and out — or null when it is not in the air.
 * `wispPoint` places it.
 */
export function wispAt(t) {
    const s = (t - ZBURN.wispAt) / ZBURN.wispMs;
    if (!(s >= 0) || s >= 1) return null;
    return { k: s < 0.5 ? 2 * s * s : 1 - 2 * (1 - s) ** 2 };
}

/** The wisp's point `k` of the way from `from` to `to` (art px): an arc rising over the straight line. */
export function wispPoint(from, to, k) {
    const lift = 20 + 0.3 * Math.hypot(to.x - from.x, to.y - from.y);
    const c = { x: (from.x + to.x) / 2, y: Math.min(from.y, to.y) - lift };
    const u = 1 - k;
    return { x: u * u * from.x + 2 * u * k * c.x + k * k * to.x, y: u * u * from.y + 2 * u * k * c.y + k * k * to.y };
}

/**
 * The heal landing on FrankenPHP at `t`: { rise } — how many art px «+heal» has risen, `shown` — false on the off
 * beats of its blink-out, `flash` — his green flash 0–1 in three steps — or null before the landing and after it.
 */
export function healAt(t) {
    const s = t - HEAL_AT;
    if (!(s >= 0) || s >= ZBURN.healMs) return null;
    const flash = s < ZBURN.flashMs ? Math.ceil((1 - s / ZBURN.flashMs) * 3) / 3 : 0;
    return {
        rise: Math.round(ZBURN.risePx * Math.min(1, s / ZBURN.riseMs)),
        shown: s < ZBURN.healMs - 200 || Math.floor(s / 100) % 2 === 0,
        flash,
    };
}

function hash01(n, i, seed) {
    let h = Math.imul(n | 0, 374761393) ^ Math.imul(i | 0, 668265263) ^ Math.imul(seed | 0, 2246822519) ^ 0x5bd1e995;
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

// Sparks of the burning zombie: one every SPARK_EVERY ms from SPARK_FROM to SPARK_TO, each living SPARK_LIFE ms; the
// zombie stands ZOMBIE_H art px tall. A smouldering one gives off one every SMOULDER_EVERY[level] ms.
const SPARK_EVERY = 45;
const SPARK_FROM = 0;
const SPARK_TO = 1300;
const SPARK_LIFE = [450, 900];
const ZOMBIE_H = 30;
const SMOULDER_EVERY = [200, 110, 60];
const SMOULDER_LIFE = 520;
/** How many sparks one zombie shows at once at most: sparksAt() and smoulderSparksAt() never return more. */
export const ZOMBIE_SPARKS = Math.ceil(SPARK_LIFE[1] / SPARK_EVERY) + 1;

function spark(n, a, life, seed, x0, y0, rise) {
    const r = (i) => hash01(n, i, seed);
    const k = a / life;
    const s = a / 1000;
    return {
        kind: r(8) < 0.3 ? 'big' : 'small',
        x: Math.round(x0 + Math.sin(s * (4 + r(5) * 3) + n) * (1 + r(6) * 2) + (r(7) - 0.5) * 10 * k),
        y: Math.round(y0 - rise * s),
        fade: k < 0.45 ? 0 : k < 0.75 ? 1 : 2,
    };
}

/**
 * The green sparks over a burning zombie at `t` ms after `at`: { kind: 'small' | 'big', x, y, fade 0–2 }, art px from
 * its foot. Each is a hash of its number and the zombie's `seed`, the same for every viewer.
 */
export function sparksAt(t, seed = 0) {
    const out = [];
    if (!(t >= SPARK_FROM)) return out;
    const last = Math.floor((Math.min(t, SPARK_TO) - SPARK_FROM) / SPARK_EVERY);
    for (let n = Math.max(0, last - ZOMBIE_SPARKS + 1); n <= last; n++) {
        const born = SPARK_FROM + n * SPARK_EVERY;
        const r = (i) => hash01(n, i, seed);
        const life = SPARK_LIFE[0] + r(0) * (SPARK_LIFE[1] - SPARK_LIFE[0]);
        const a = t - born;
        if (a < 0 || a >= life) continue;
        // Off the whole body while it stands, off the heap once it has crumbled.
        const top = born < ZBURN.heapAt ? ZOMBIE_H : 6;
        out.push(spark(n, a, life, seed, (r(3) - 0.5) * (born < ZBURN.heapAt ? 14 : 18), -r(2) * top, 30 + r(4) * 40));
    }
    return out.slice(-ZOMBIE_SPARKS);
}

/**
 * The few sparks off a smouldering zombie at server ms `now`, its deadline `deadline`: the same shape as sparksAt(),
 * fewer and slower, thicker as the deadline nears; none outside smoulderAt().
 */
export function smoulderSparksAt(now, deadline, seed = 0) {
    const out = [];
    const from = deadline - ZBURN.warnMs;
    if (smoulderAt(deadline - now) === null && now - from > SMOULDER_LIFE) return out;
    // Spark n is born at from + born(n), the gaps shrinking level by level.
    let born = 0;
    for (let n = 0; born <= now - from && born < ZBURN.warnMs + ZBURN.graceMs; n++) {
        const level = smoulderAt(ZBURN.warnMs - born) ?? 2;
        const a = now - from - born;
        if (a < SMOULDER_LIFE) {
            const r = (i) => hash01(n, i, seed ^ 0x51);
            out.push(spark(n, a, SMOULDER_LIFE, seed ^ 0x51, (r(3) - 0.5) * 14, -4 - r(2) * (ZOMBIE_H - 6), 18 + r(4) * 14));
        }
        born += SMOULDER_EVERY[level];
    }
    return out.slice(-ZOMBIE_SPARKS);
}
