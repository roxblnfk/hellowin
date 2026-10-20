import { halo } from './night.js';
import { paintFlames, paintSmoke } from './sprites.js';

/**
 * The bonfire in the middle of the patch (scenes 1–2), its explosion (2 → 3), the ash pit it leaves (scene 3) and
 * the red magic circle drawn on the pit.
 * Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as sprites.js.
 */

// Every bonfire frame is W×H with the anchor at the centre of the heap's foot, so stages swap in place.
const W = 72;
const H = 88;
const FOOT = { x: 36, y: 84 };

const BASE_LEGEND = {
    '.': null,
    O: 'outline',
    j: 'hayLight',
    Y: 'hay',
    H: 'hayDark',
    y: 'stemLight',
    t: 'stem',
    T: 'stemDark',
    p: 'barkLight',
    q: 'bark',
    Q: 'barkDark',
    L: 'leafDark',
    M: 'soilDark',
    A: 'ashLight',
    a: 'ash',
    K: 'ashDark',
    g: 'grayLight',
    G: 'gray',
};
// Fire chars core → edge w s l d D, embers r. Stage 5 keeps a warm core in green edges; 6–7 burn fully green.
const FIRE_LEGENDS = {
    warm: { '.': null, w: 'flameCore', s: 'pumpkinShine', l: 'pumpkinLight', d: 'pumpkinDark', D: 'pumpkinDeep', r: 'ember' },
    turning: { '.': null, w: 'flameCore', s: 'pumpkinShine', l: 'eyeGreen', d: 'eerieDark', D: 'eerieDeep', r: 'ember' },
    green: { '.': null, w: 'magicCore', s: 'eyeGreen', l: 'eerieMid', d: 'eerieDark', D: 'eerieDeep', r: 'eerieHot' },
};

const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];

function hash(x, y, s = 0) {
    let h = Math.imul(x | 0, 374761393) ^ Math.imul(y | 0, 668265263) ^ Math.imul(s | 0, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

// Smooth value noise, so dithered edges wobble in blobs rather than salt-and-pepper.
function noise(x, y, s = 0) {
    const xi = Math.floor(x);
    const yi = Math.floor(y);
    const fx = x - xi;
    const fy = y - yi;
    const u = fx * fx * (3 - 2 * fx);
    const v = fy * fy * (3 - 2 * fy);
    const a = hash(xi, yi, s);
    const b = hash(xi + 1, yi, s);
    const c = hash(xi, yi + 1, s);
    const d = hash(xi + 1, yi + 1, s);
    return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
}

const grid = (w, h) => Array.from({ length: h }, () => Array(w).fill('.'));
const toSprite = (g, legend, anchor) => {
    const rows = g.map((r) => r.join(''));
    return { w: rows[0].length, h: rows.length, rows, legend, anchor };
};

function line(g, x0, y0, x1, y1, ch) {
    const n = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0), 1);
    for (let i = 0; i <= n; i++) {
        const x = Math.round(x0 + ((x1 - x0) * i) / n);
        const y = Math.round(y0 + ((y1 - y0) * i) / n);
        if (g[y]?.[x] !== undefined) g[y][x] = typeof ch === 'function' ? ch(i / n) : ch;
    }
}

// The heap of dry tops and brush cleared from the patch: a low dome of straw-coloured stalks lit from the top-left,
// sticks and withered vines poking out, a few curled leaves; `char` 0–1 is how much of it has burnt from the middle.
const HEAP = { rx: 20, ry: 9 };

function paintHeap(g, char) {
    const { x: cx, y: fy } = FOOT;
    const inDome = (x, y) => {
        const dx = (x - cx) / HEAP.rx;
        const dy = (y - fy) / HEAP.ry;
        return y <= fy && dx * dx + dy * dy <= 1 + 0.18 * (noise(x * 0.5, y * 0.5, 3) - 0.5);
    };
    // Sticks and dry vines first, so the straw piles over their lower ends.
    const sticks = [[-9, -4, -14, -12], [6, -5, 10, -13], [-2, -7, -4, -14], [3, -7, 6, -12], [13, -3, 18, -8], [-14, -2, -19, -7]];
    sticks.forEach(([x0, y0, x1, y1], i) => {
        line(g, cx + x0, fy + y0, cx + x1, fy + y1, i % 2 ? 'q' : 'p');
        line(g, cx + x0 + 1, fy + y0, cx + x1 + 1, fy + y1, 'Q');
    });
    const vines = [[-6, -6, -10, -11, -8, -13], [9, -5, 13, -10, 15, -9]];
    for (const [x0, y0, x1, y1, x2, y2] of vines) {
        line(g, cx + x0, fy + y0, cx + x1, fy + y1, 't');
        line(g, cx + x1, fy + y1, cx + x2, fy + y2, 'y');
    }
    for (let y = fy - HEAP.ry - 1; y <= fy; y++) {
        for (let x = cx - HEAP.rx - 1; x <= cx + HEAP.rx + 1; x++) {
            if (!inDome(x, y)) continue;
            const dx = (x - cx) / HEAP.rx;
            const dy = (y - fy) / HEAP.ry;
            // Straw strokes: value noise stretched along slanted stalks, lit from the top-left.
            const n = noise(x * 0.9 + y * 0.6, y * 1.6, 7);
            const lv = -0.6 * dx - 0.9 * (dy + 0.5) + (n - 0.5) * 1.3;
            let ch = lv > 0.55 ? 'j' : lv > 0.1 ? 'Y' : lv > -0.45 ? 'H' : n > 0.55 ? 't' : 'T';
            if (hash(x, y, 9) < 0.04) ch = 'L';
            // Burnt from the middle outward: ash on top, charcoal deeper in.
            const burnt = Math.hypot(dx, dy * 0.8) < char * 1.05 + (n - 0.5) * 0.3;
            if (burnt) ch = lv > 0.3 ? 'A' : lv > -0.3 ? 'a' : 'K';
            if (!inDome(x, y + 1)) ch = 'O';
            else if (!inDome(x + 1, y) && dx > 0) ch = burnt ? 'K' : 'T';
            g[y][x] = ch;
        }
    }
    // Curled dry leaves lying on the heap.
    for (const [dx, dy] of [[-12, -3], [-4, -7], [9, -6], [14, -2], [2, -4]]) {
        const x = cx + dx;
        const y = fy + dy;
        if (char > 0.5 && Math.abs(dx) < HEAP.rx * char) continue;
        g[y][x] = 'H';
        g[y][x + 1] = 't';
        g[y - 1][x] = 'Y';
    }
    // Ground shadow under the heap.
    for (let x = cx - HEAP.rx + 2; x <= cx + HEAP.rx - 2; x++) {
        if (g[fy + 1]) g[fy + 1][x] = 'M';
    }
}

// Logs leant together in a teepee from stage 4, charred toward the fire.
function paintLogs(g) {
    const { x: cx, y: fy } = FOOT;
    const logs = [[-14, 0, -2, -16], [14, 0, 2, -16], [-6, 1, 3, -18], [7, 1, -3, -17]];
    for (const [x0, y0, x1, y1] of logs) {
        line(g, cx + x0, fy + y0 - 2, cx + x1, fy + y1, (t) => (t > 0.55 ? 'K' : 'q'));
        line(g, cx + x0 + 1, fy + y0 - 2, cx + x1 + 1, fy + y1, (t) => (t > 0.55 ? 'a' : 'Q'));
    }
}

// Embers glowing in the heap: a few spots that blink between frames.
function paintEmbers(g, frame, count, spread) {
    const { x: cx, y: fy } = FOOT;
    for (let i = 0; i < count; i++) {
        const x = Math.round(cx + (hash(i, 1, 21) - 0.5) * 2 * spread);
        const y = Math.round(fy - 1 - hash(i, 2, 21) * 4);
        const on = hash(i, frame, 22);
        if (on < 0.25) continue;
        g[y][x] = on > 0.8 ? 's' : on > 0.5 ? 'l' : 'r';
    }
}

// Sparks rising over the fire: each climbs a few pixels per frame on its own column and wraps around.
function paintSparks(g, frame, count, height, spread, fromY) {
    const { x: cx } = FOOT;
    for (let i = 0; i < count; i++) {
        const span = height * (0.5 + hash(i, 3, 31) * 0.5);
        const rise = (hash(i, 4, 31) * span + frame * (span / 4)) % span;
        const x = Math.round(cx + (hash(i, 5, 31) - 0.5) * 2 * spread + Math.sin(rise * 0.3 + i) * 2);
        const y = Math.round(fromY - rise);
        if (g[y]?.[x] === undefined || g[y][x] !== '.') continue;
        g[y][x] = rise > span * 0.7 ? 'd' : rise > span * 0.35 ? 'l' : 's';
    }
}

/**
 * Bonfire stages: what each one draws. 0 the cleared heap; scene 1: 1 smoke, 2 sparks and embers, 3 small flames,
 * 4 the bonfire; scene 2: 5 the flames turn green, 6 green and bigger, 7 a huge green blaze about to blow.
 */
const STAGES = [
    { char: 0 },
    { char: 0, smoke: [3, 26, 2.2] },
    { char: 0.15, smoke: [4, 34, 2.8], embers: [16, 10], sparks: [8, 24, 7] },
    { char: 0.35, smoke: [5, 40, 3.2], embers: [12, 11], sparks: [7, 28, 8], flames: { n: 3, len: 15, wid: 7, spread: 7 } },
    { char: 0.75, logs: true, embers: [16, 14], sparks: [12, 44, 12], flames: { n: 5, len: 27, wid: 12, spread: 11 } },
    { char: 0.85, logs: true, embers: [16, 14], sparks: [14, 50, 13], flames: { n: 5, len: 32, wid: 13, spread: 12 }, tone: 'turning' },
    { char: 0.95, logs: true, embers: [18, 15], sparks: [18, 58, 15], flames: { n: 6, len: 42, wid: 15, spread: 13 }, tone: 'green' },
    { char: 1, logs: true, embers: [20, 16], sparks: [26, 70, 20], flames: { n: 7, len: 56, wid: 19, spread: 15 }, tone: 'green', swell: true },
];

/** Last bonfire stage drawn; stages past it look like it. */
export const BONFIRE_STAGES = STAGES.length - 1;

function bonfireFrame(stage, frame) {
    const def = STAGES[stage];
    const base = grid(W, H);
    const fire = grid(W, H);
    paintHeap(base, def.char);
    if (def.logs) paintLogs(base);
    if (def.smoke) {
        const [width, height, w] = def.smoke;
        paintSmoke(base, FOOT.x + 1, FOOT.y - HEAP.ry - 1, height, frame, { width: w, drift: 1 + (width > 3 ? 1 : 0) });
    }
    if (def.embers) paintEmbers(fire, frame, ...def.embers);
    if (def.flames) {
        const { n, len, wid, spread } = def.flames;
        const tongues = Array.from({ length: n }, (_, i) => {
            const t = n === 1 ? 0.5 : i / (n - 1);
            // The middle tongues are the tallest; the outer ones lean out a little.
            const centre = 1 - Math.abs(t - 0.5) * 2;
            return {
                x: FOOT.x + (t - 0.5) * 2 * spread,
                y: FOOT.y - 2,
                len: len * (0.6 + 0.4 * centre),
                wid,
                ang: (t - 0.5) * 0.35,
                phase: i * 2.1,
            };
        });
        paintFlames(fire, tongues, frame);
        if (def.swell) {
            // About to blow: a bulging core ball in the middle of the blaze, pulsing with the frames.
            const r = 9 + (frame % 2);
            const cy = FOOT.y - 22;
            for (let y = cy - r; y <= cy + r; y++) {
                for (let x = FOOT.x - r; x <= FOOT.x + r; x++) {
                    const d = Math.hypot(x - FOOT.x, (y - cy) * 1.1) / r;
                    if (d <= 1) fire[y][x] = d < 0.5 ? 'w' : d < 0.8 ? 's' : 'l';
                }
            }
        }
    }
    if (def.sparks) {
        const [count, height, spread] = def.sparks;
        paintSparks(fire, frame, count, height, spread, FOOT.y - 8);
    }
    return {
        base: toSprite(base, BASE_LEGEND, FOOT),
        fire: def.embers || def.flames ? toSprite(fire, FIRE_LEGENDS[def.tone ?? 'warm'], FOOT) : null,
    };
}

// Ground light per stage as halo(rx, ry, tone) arguments; none before the embers.
const LIGHT = [null, null, [14, 5, 'warm'], [22, 7, 'warm'], [40, 12, 'warm'], [42, 13, 'warm'], [46, 14, 'green'], [54, 16, 'green']];

/**
 * BONFIRE.stages[stage] = [{ base, fire }, …] — 1 frame at stage 0, 4 frames (~110 ms each) from stage 1; base is lit
 * by the scene's lighting, fire is light (bake it unlit, may be null). All 72×88, anchor (36, 84) at the heap's foot.
 * BONFIRE.light[stage] — the dithered ground light under it (or null), centred on the foot; additive, alpha ~0.6.
 * BONFIRE.hit — the clickable core around the anchor: { x, y, w, h } relative to the sprite's top-left.
 */
export const BONFIRE = {
    stages: STAGES.map((_, st) => (st === 0 ? [bonfireFrame(0, 0)] : [0, 1, 2, 3].map((f) => bonfireFrame(st, f)))),
    light: LIGHT.map((l) => (l ? halo(...l) : null)),
    hit: { x: FOOT.x - HEAP.rx, y: FOOT.y - HEAP.ry - 8, w: HEAP.rx * 2 + 1, h: HEAP.ry + 9 },
};

// The explosion: one bright ball of green fire that swells, breaks into a fireball with a ground shock ring, then
// rolls up as a dark mushroom of smoke and thins out.
const EX_W = 132;
const EX_H = 116;
const EX_FOOT = { x: 66, y: 108 };
const EX_LEGEND = {
    '.': null,
    w: 'magicCore',
    s: 'eerieHot',
    l: 'eyeGreen',
    d: 'eerieDark',
    D: 'eerieDeep',
    y: 'pumpkinShine',
    o: 'pumpkinLight',
    r: 'ember',
    K: 'ashDark',
    a: 'ash',
    A: 'ashLight',
    g: 'grayLight',
    G: 'gray',
};

function explosionFrame(f) {
    const g = grid(EX_W, EX_H);
    const cx = EX_FOOT.x;
    const set = (x, y, ch) => {
        if (g[y]?.[x] !== undefined) g[y][x] = ch;
    };
    // [radius, centre height above the foot, fire share 0–1, smoke share 0–1]
    const BALL = [[12, 26, 1, 0], [22, 30, 1, 0], [32, 34, 0.9, 0.1], [40, 38, 0.6, 0.4], [42, 46, 0.3, 0.7], [44, 54, 0.1, 0.9], [44, 60, 0, 0.6], [42, 64, 0, 0.3]];
    const [r, up, heat, smoke] = BALL[f];
    const cy = EX_FOOT.y - up;
    for (let y = cy - r - 4; y <= cy + r + 4; y++) {
        for (let x = cx - r - 4; x <= cx + r + 4; x++) {
            const n = noise(x * 0.18, y * 0.18, 40 + f) - 0.5;
            const d = Math.hypot(x - cx, (y - cy) * 1.05) / r + n * (0.25 + 0.25 * smoke);
            if (d > 1) continue;
            const i = 1 - d;
            let ch = null;
            const fireHere = hash(x, y, f) < heat * 1.4 - d * (1 - heat) * 1.2;
            if (fireHere) {
                ch = i > 0.75 ? 'w' : i > 0.5 ? (f >= 3 ? 'y' : 's') : i > 0.28 ? (f >= 3 ? 'o' : 'l') : i > 0.12 ? 'd' : 'D';
            } else if (smoke > 0) {
                if (BAYER[y & 3][x & 3] / 16 > smoke * 1.3 - (f >= 6 ? d * 0.8 : 0)) continue;
                ch = n + (cy - y) / r * 0.3 > 0.15 ? 'A' : n > -0.15 ? 'a' : 'K';
                if (f >= 4 && hash(x, y, 77 + f) < 0.02) ch = 'r';
            }
            if (ch) set(x, y, ch);
        }
    }
    // The column under the mushroom from frame 4.
    if (f >= 4 && f <= 6) {
        const top = cy + r - 6;
        for (let y = top; y <= EX_FOOT.y; y++) {
            const half = 5 + (y - top) * 0.25 + noise(y * 0.3, f, 5) * 3;
            for (let x = Math.round(cx - half); x <= cx + half; x++) {
                if (g[y][x] !== '.' || BAYER[y & 3][x & 3] / 16 > (f === 6 ? 0.45 : 0.8)) continue;
                g[y][x] = x < cx - half * 0.3 ? 'a' : 'K';
            }
        }
    }
    // The shock ring along the ground, flattened like the ground.
    if (f >= 1 && f <= 4) {
        const rx = 18 + f * 13;
        const ry = rx * 0.22;
        for (let a = 0; a < Math.PI * 2; a += 0.01) {
            const x = Math.round(cx + Math.cos(a) * rx);
            const y = Math.round(EX_FOOT.y + Math.sin(a) * ry);
            if (hash(x, y, f + 90) < 1.1 - f * 0.2) set(x, y, f <= 2 ? 's' : 'l');
        }
    }
    // Flying debris: dark clods and green sparks on outward arcs.
    if (f >= 1 && f <= 5) {
        for (let i = 0; i < 26; i++) {
            const a = -Math.PI * (0.05 + 0.9 * hash(i, 1, 60));
            const v = 0.6 + hash(i, 2, 60);
            const t = f * 9 * v;
            const x = Math.round(cx + Math.cos(a) * t * 1.4);
            const y = Math.round(EX_FOOT.y - 20 + Math.sin(a) * t + f * f * 1.2);
            set(x, y, i % 3 === 0 ? 'l' : 'K');
            if (i % 3) set(x + 1, y, 'a');
        }
    }
    return toSprite(g, EX_LEGEND, EX_FOOT);
}

/**
 * EXPLOSION — 8 frames, 132×116, anchor (66, 108) on the bonfire's foot: ~70 ms for the first three, ~120 ms after.
 * Light frames (bake unlit); flash the scene white-green on frame 0.
 */
export const EXPLOSION = Array.from({ length: 8 }, (_, f) => explosionFrame(f));

// The pit after the blast: a scorched ring dithered into the ground, a dark crater of ash with charred stick ends.
const PIT = { w: 64, h: 22, rx: 28, ry: 9 };

function ashPit() {
    const g = grid(PIT.w, PIT.h);
    const glow = grid(PIT.w, PIT.h);
    const cx = PIT.w >> 1;
    const cy = PIT.h >> 1;
    for (let y = 0; y < PIT.h; y++) {
        for (let x = 0; x < PIT.w; x++) {
            const n = noise(x * 0.3, y * 0.5, 11) - 0.5;
            const d = Math.hypot((x - cx) / PIT.rx, (y - cy) / PIT.ry) + n * 0.25;
            if (d > 1) continue;
            if (d > 0.72) {
                // Scorched soil fading into the grass.
                if (BAYER[y & 3][x & 3] / 16 < (1 - d) / 0.28) g[y][x] = hash(x, y, 3) < 0.5 ? 'M' : 'K';
                continue;
            }
            // The crater: lit on the far (upper) inner wall, ash heaped in the middle.
            const wall = d > 0.55;
            const lv = wall ? (y < cy ? 0.6 : -0.6) : n + 0.2 - Math.abs(d - 0.25);
            g[y][x] = lv > 0.35 ? 'A' : lv > -0.1 ? 'a' : 'K';
            if (wall && y > cy) g[y][x] = 'O';
        }
    }
    for (const [x0, y0, x1, y1] of [[22, 9, 27, 7], [36, 12, 42, 10], [30, 6, 33, 9], [25, 13, 29, 14]]) line(g, x0, y0, x1, y1, 'Q');
    // Dying embers in the ash: the separate glow layer can pulse or fade out.
    for (const [x, y, ch] of [[28, 10, 'r'], [35, 11, 's'], [31, 13, 'r'], [40, 9, 'r'], [24, 11, 'd']]) {
        glow[y][x] = ch;
        g[y][x] = ch;
    }
    return { pit: toSprite(g, { ...BASE_LEGEND, r: 'ember', s: 'pumpkinShine', d: 'pumpkinDark' }, { x: cx, y: cy }), embers: toSprite(glow, FIRE_LEGENDS.warm, { x: cx, y: cy }) };
}

const PIT_ART = ashPit();

/**
 * The magic circle drawn on the pit, progress 0–1: the outer ring, then the inner ring, the five strokes of the
 * pentagram, then the rune ticks between the rings, all on the flattened ground. The pen tip glows bright. Same size
 * and anchor as ASH.pit (64×22, centre).
 */
export function magicCircle(progress) {
    const g = grid(PIT.w, PIT.h);
    const cx = PIT.w >> 1;
    const cy = PIT.h >> 1;
    const squash = 0.34;
    const R = 26;
    const r = 21;
    const strokes = [];
    const ring = (rad) => {
        const pts = [];
        for (let a = 0; a <= Math.PI * 2 + 0.001; a += 0.02) pts.push([cx + Math.cos(a - Math.PI / 2) * rad, cy + Math.sin(a - Math.PI / 2) * rad * squash]);
        return pts;
    };
    strokes.push(ring(R), ring(r));
    const star = [0, 2, 4, 1, 3, 0].map((k) => {
        const a = -Math.PI / 2 + (k * 2 * Math.PI) / 5;
        return [cx + Math.cos(a) * r, cy + Math.sin(a) * r * squash];
    });
    for (let k = 0; k < 5; k++) {
        const [x0, y0] = star[k];
        const [x1, y1] = star[k + 1];
        const n = Math.ceil(Math.hypot(x1 - x0, y1 - y0) * 2);
        strokes.push(Array.from({ length: n + 1 }, (_, i) => [x0 + ((x1 - x0) * i) / n, y0 + ((y1 - y0) * i) / n]));
    }
    // Rune ticks: short radial marks between the rings.
    for (let k = 0; k < 10; k++) {
        const a = (k + 0.5) * (Math.PI / 5) - Math.PI / 2;
        strokes.push([0, 0.5, 1].map((t) => {
            const rad = r + 1.2 + t * (R - r - 2.4);
            return [cx + Math.cos(a) * rad, cy + Math.sin(a) * rad * squash];
        }));
    }
    const total = strokes.reduce((s, p) => s + p.length, 0);
    let budget = Math.round(total * Math.max(0, Math.min(1, progress)));
    let tip = null;
    for (const pts of strokes) {
        for (const [x, y] of pts) {
            if (budget-- <= 0) break;
            const px = Math.round(x);
            const py = Math.round(y);
            if (g[py]?.[px] === undefined) continue;
            g[py][px] = 'r';
            if (g[py + 1]?.[px] === '.') g[py + 1][px] = 'R';
            tip = [px, py];
        }
    }
    if (tip && progress < 1) {
        g[tip[1]][tip[0]] = 'w';
        for (const [dx, dy] of [[1, 0], [-1, 0], [0, -1]]) {
            if (g[tip[1] + dy]?.[tip[0] + dx] !== undefined) g[tip[1] + dy][tip[0] + dx] = 'W';
        }
    }
    return toSprite(g, { '.': null, r: 'rune', R: 'runeDark', W: 'bloodMoonLight', w: 'white' }, { x: cx, y: cy });
}

/**
 * ASH — scene 3's centre. pit: the crater (64×22, anchor at its centre: put it on the bonfire's foot), lit by the
 * scene; embers: its dying embers alone, light; circle: 8 frames of the magic circle being drawn (light, same size
 * and anchor), the last one complete.
 */
export const ASH = {
    pit: PIT_ART.pit,
    embers: PIT_ART.embers,
    circle: Array.from({ length: 8 }, (_, i) => magicCircle((i + 1) / 8)),
};
