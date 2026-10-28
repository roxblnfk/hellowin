// The finale's timeline on its own, without PixiJS: the scene draws by it and the sound (sound.js) plays by it.

/**
 * The owl's countdown «Призыв через 3… 2… 1…» before step 1 (docs/spec/frontend.md, «Призыв»), a second a number: a
 * live step 0 → 1 starts this long after its event. The client holds the scene, the dice, the sound and the owl's lines
 * back for it, so T of step 1 runs from the countdown's end.
 */
export const SUMMON_MS = 3000;

/** Timeline of the steps, ms since the step's event (step 1: since the end of SUMMON_MS). */
export const T = {
    // Step 1: the circle flashes, the column lights up, smoke rolls, the silhouette rises; the ground rumbles while the
    // pumpkins are pulled into a round dance.
    flash: 90,
    column: 180,
    cloud: 300,
    rise: 600,
    riseFrame: 400,
    rumble: 2200,
    pull: 1500,
    // Step 2: three strikes; the third cuts to the night under its flash, then the smoke clears off FrankenPHP and the
    // pumpkins' lights go out as they drop.
    strikes: [0, 380, 760],
    strikeFrame: 60,
    night: 780,
    reveal: 900,
    revealFrame: 150,
    fling: 700,
    // Step 3: on a diagonal from the screen edge at the horizon down to the front, a beat of standing, «мип-мип».
    run: 4600,
    meep: 5000,
    meepMs: 1800,
    // Step 5: the pumpkins rise and light up; RoadRunner runs back up the diagonal and off the edge; FrankenPHP keeps
    // quiet while the owl says its last line and flies away.
    rise5: 1000,
    leave: 1200,
    back: 4400,
    quiet: 14000,
    // Step 6: the owl flies back in (2.5 s of wingbeats, then the landing), FrankenPHP keeps quiet through its three
    // lines; the boss flinches at a strike (his burning at zero health — BURN).
    owlFly: 2500,
    owlLand: 150,
    quiet6: 23000,
    hit: 120,
    // Step 7: the night fades into the dawn while the sun climbs out from behind the hills; the pumpkins settle down.
    dawnFade: 6000,
    sunrise: 8000,
    settle: 1000,
};

/**
 * FrankenPHP burning to ash (docs/spec/frontend.md, «Груда пепла»), ms since he started: the fire catches at his feet
 * and climbs him by `climb`, the body chars from the feet up behind it, from `crumble` it falls from the top into a
 * heap; the body is `frames` frames of `frameMs`, then the flames die down on the heap by `out` and its glow by `cool`.
 * The boss's glasses drop off his face at `glassesAt`, when the crumbling reaches his eyes.
 */
export const BURN = {
    frames: 12,
    frameMs: 220,
    flickerMs: 90,
    climb: 700,
    charLag: 250,
    charMs: 1000,
    crumble: 1150,
    crumbleMs: 1300,
    glassesAt: 1450,
    out: 3200,
    cool: 5200,
};

const clamp01 = (v) => Math.max(0, Math.min(1, v));

/** How far the burning has got at `s` ms, each 0–1: the fire up his height, the char behind it, the fall from the top. */
export const burnBody = (s) => ({
    ignite: clamp01(s / BURN.climb),
    char: clamp01((s - BURN.charLag) / BURN.charMs),
    fall: clamp01((s - BURN.crumble) / BURN.crumbleMs),
});

/** The body frame at `down` ms, or null once he is a heap. */
export const burnFrameOf = (down) => (down >= 0 && down < BURN.frames * BURN.frameMs ? Math.floor(down / BURN.frameMs) : null);

/** The flames' size at `down` ms, 0–1: they flare up, hold while he burns, die down on the heap. */
export function burnFlame(down) {
    const end = BURN.frames * BURN.frameMs;
    if (down < 0 || down >= BURN.out) return 0;
    if (down < 500) return (down / 500) ** 0.7;
    if (down < 1700) return 1;
    if (down < end) return 1 - (0.5 * (down - 1700)) / (end - 1700);
    return (0.5 * (BURN.out - down)) / (BURN.out - end);
}

/** The light of the fire at `down` ms, 0–1: the flames', then the smouldering heap's, fading out by `cool`. */
export function burnLight(down) {
    if (down < 0 || down >= BURN.cool) return 0;
    const end = BURN.frames * BURN.frameMs;
    const ember = down < end ? 0 : 0.3 * (1 - (down - end) / (BURN.cool - end));
    return Math.max(burnFlame(down), ember);
}

function hash01(n, i) {
    let h = Math.imul(n | 0, 374761393) ^ Math.imul(i | 0, 668265263) ^ 0x5bd1e995;
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

// A spark leaves the fire every SPARK_EVERY ms from SPARK_FROM to SPARK_TO and lives up to SPARK_LIFE[1]; FrankenPHP
// stands SPARK_HEIGHT art px tall.
const SPARK_EVERY = 60;
const SPARK_FROM = 120;
const SPARK_TO = 3000;
const SPARK_LIFE = [600, 1200];
const SPARK_HEIGHT = 84;
/** How many sparks burnSparksAt() may return at once. */
export const BURN_SPARKS = Math.ceil(SPARK_LIFE[1] / SPARK_EVERY) + 1;

/**
 * The sparks and cinders over the fire at `down` ms: { kind: 'small' | 'big' | 'cinder', x, y, fade 0–2 }, art px
 * from his foot. Each is a hash of its number, so every viewer sees the same ones at the same moment.
 */
export function burnSparksAt(down) {
    const out = [];
    if (down < SPARK_FROM) return out;
    const last = Math.floor((Math.min(down, SPARK_TO) - SPARK_FROM) / SPARK_EVERY);
    for (let n = Math.max(0, last - BURN_SPARKS + 1); n <= last; n++) {
        const born = SPARK_FROM + n * SPARK_EVERY;
        const r = (i) => hash01(n, i);
        const life = SPARK_LIFE[0] + r(0) * (SPARK_LIFE[1] - SPARK_LIFE[0]);
        const a = down - born;
        if (a < 0 || a >= life || r(1) > 0.25 + 0.75 * burnFlame(born)) continue;
        const { ignite, fall } = burnBody(born);
        // They leave the burning part of him: up to the fire's front, below where he has crumbled; then off the heap.
        const top = Math.max(6, SPARK_HEIGHT * Math.min(ignite, 1 - fall * 0.9));
        const y0 = -r(2) * top;
        const half = fall >= 1 ? 18 : y0 > -22 ? 12 : 24;
        const x0 = (r(3) - 0.5) * 2 * half;
        const kind = r(8) < 0.2 ? 'cinder' : r(8) < 0.45 ? 'big' : 'small';
        const k = a / life;
        const s = a / 1000;
        const vy = kind === 'cinder' ? 14 + r(4) * 16 : 35 + r(4) * 45;
        out.push({
            kind,
            x: x0 + Math.sin(s * (3 + r(5) * 3) + n) * (2 + r(6) * 3) + (r(7) - 0.5) * 14 * k,
            y: y0 - vy * s,
            fade: k < 0.5 ? 0 : k < 0.8 ? 1 : 2,
        });
    }
    return out;
}
