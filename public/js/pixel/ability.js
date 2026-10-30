// The players' abilities in the scene (docs/spec/frontend.md, «Способности»): the pillar of light under a walker who
// got one, its timeline, and the sparkle of a launch of flyers — without PixiJS, so the tests load it.
// Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as elephant.js.

const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const step = (v, s) => Math.round(v / s) * s;

/** The pillar's timeline, ms from the event's `at`: it shoots up by `rise`, holds, melts away from `melt` to `end`. */
export const PILLAR = { rise: 350, melt: 2400, end: 3000, frameMs: 90, frames: 4 };

/**
 * The pillar `ms` after its event: { height: 0–1 of its full height, melt: 0–1 eaten by dithered holes, frame: the
 * shimmer's frame }, or null before it and once it is over. Reduced motion: the whole pillar at once, still, and
 * gone at the end instead of melting.
 */
export function pillarAt(ms, reduced = false) {
    if (!(ms >= 0) || ms >= PILLAR.end) return null;
    if (reduced) return { height: 1, melt: 0, frame: 0 };
    return {
        height: step(clamp(ms / PILLAR.rise, 0, 1) ** 0.6, 1 / 8),
        melt: step(clamp((ms - PILLAR.melt) / (PILLAR.end - PILLAR.melt), 0, 1), 1 / 8),
        frame: Math.floor(ms / PILLAR.frameMs) % PILLAR.frames,
    };
}

const PILLAR_W = 21;
const PILLAR_H = 58;
// Where its light fades out at the top, rows.
const TAPER = 14;
const LEGEND = { c: 'goldCore', h: 'goldHot', g: 'goldGlow', d: 'goldDeep' };
const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const pillars = new Map();

function toSprite(rows, anchor) {
    return { w: rows[0].length, h: rows.length, rows, legend: { '.': null, ...LEGEND }, anchor };
}

/**
 * The pillar's sprite: a gold column, hot in the middle and dithered at the rims, its top fading out; bands of
 * light run up it by `frame`. `height` and `melt` as pillarAt gives them. Drawn additively behind the walker. Anchor: the
 * middle of its foot.
 */
export function pillarSprite(height, melt = 0, frame = 0) {
    const key = `${height}:${melt}:${frame}`;
    if (pillars.has(key)) return pillars.get(key);
    const h = Math.max(1, Math.round(PILLAR_H * height));
    const cx = (PILLAR_W - 1) / 2;
    const rows = [];
    for (let y = 0; y < h; y++) {
        let row = '';
        // The band runs up as the frames go: the same band is 3 rows higher on the next frame.
        const band = (y + frame * 3) % 9 === 0;
        for (let x = 0; x < PILLAR_W; x++) {
            const dx = Math.abs(x - cx);
            let ch = dx < 1 ? 'h' : dx <= 3 ? 'g' : dx <= 6 ? 'd' : dx <= 9 && (x + y) % 2 === 0 ? 'd' : '.';
            if (band && ch !== '.' && dx <= 6) ch = dx <= 3 ? 'c' : 'h';
            const fade = y < TAPER ? 1 - y / TAPER : 0;
            if (ch !== '.' && BAYER[y & 3][x & 3] < fade * 16) ch = '.';
            if (ch !== '.' && BAYER[(y + 1) & 3][x & 3] < melt * 16) ch = '.';
            row += ch;
        }
        rows.push(row);
    }
    const sprite = toSprite(rows, { x: Math.floor(cx), y: h - 1 });
    pillars.set(key, sprite);
    return sprite;
}

/** The glowing ring on the ground at the pillar's foot, melted like it. Anchor: its middle. */
export function pillarRing(melt = 0) {
    const key = `ring:${melt}`;
    if (pillars.has(key)) return pillars.get(key);
    const rx = 10;
    const ry = 3.2;
    const rows = [];
    for (let y = 0; y < 7; y++) {
        let row = '';
        for (let x = 0; x < 21; x++) {
            const d = Math.hypot((x - 10) / rx, (y - 3) / ry);
            let ch = d <= 0.55 ? 'h' : d <= 0.85 ? 'g' : d <= 1.05 && (x + y) % 2 === 0 ? 'd' : '.';
            if (ch !== '.' && BAYER[y & 3][x & 3] < melt * 16) ch = '.';
            row += ch;
        }
        rows.push(row);
    }
    const sprite = toSprite(rows, { x: 10, y: 3 });
    pillars.set(key, sprite);
    return sprite;
}

/** Motes rising up the pillar: one every this many ms while it holds. */
export const MOTE_MS = 70;
/** The gold of the motes, hottest first (an ember cools along it). */
export const MOTE_COLORS = ['goldCore', 'goldHot', 'goldGlow', 'goldDeep'];
/** The sparks of a launch of flyers: cool white to sky blue, so they read apart from the pillar's gold. */
export const LAUNCH_COLORS = ['white', 'skyLight', 'sky', 'lightningEdge'];
/** How many sparks a launch of flyers throws up. */
export const LAUNCH_SPARKS = 22;
/** The Fart Time cloud of a player is the elephant's, this much smaller: a walker is smaller than the elephant. */
export const FART_SCALE = 0.8;
