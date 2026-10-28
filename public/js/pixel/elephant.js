// The elephant over the sky (docs/spec/frontend.md, «Слон»): its frames, the «ROADRUNNER 3 RELEASED» banner it tows,
// the path of the whole train and the PHP Fart Time cloud of its fart — all without PixiJS, so the timeline and the
// geometry are the same for the scene, the sound and the tests.
// Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as flyers.js.

const TAU = 2 * Math.PI;
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

function toSprite(grid, legend, anchor) {
    return {
        w: grid[0].length,
        h: grid.length,
        rows: grid.map((r) => r.join('')),
        legend: { '.': null, ...legend },
        anchor,
    };
}

const blank = (w, h) => Array.from({ length: h }, () => Array(w).fill('.'));
const inside = (g, x, y) => y >= 0 && y < g.length && x >= 0 && x < g[0].length;
const N4 = [[1, 0], [-1, 0], [0, 1], [0, -1]];

// ---------------------------------------------------------------- the elephant

const ELEPHANT_LEGEND = {
    O: 'outline',
    L: 'elephantLight',
    E: 'elephant',
    D: 'elephantDark',
    p: 'blush',
    k: 'eyeBlack',
    s: 'skyLight',
    b: 'eyeBlue',
};

const EW = 36;
const EH = 30;
const ellipse = (cx, cy, rx, ry) => (x, y) => ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1;
const capsule = (x1, y1, x2, y2, r) => (x, y) => {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const t = clamp(((x - x1) * dx + (y - y1) * dy) / (dx * dx + dy * dy), 0, 1);
    return Math.hypot(x - x1 - dx * t, y - y1 - dy * t) <= r;
};
// An ellipse whose long axis points along `deg` from (cx, cy).
const tilted = (cx, cy, rx, ry, deg) => {
    const a = (deg * Math.PI) / 180;
    const [c, s] = [Math.cos(a), Math.sin(a)];
    return (x, y) => (((x - cx) * c + (y - cy) * s) / rx) ** 2 + ((-(x - cx) * s + (y - cy) * c) / ry) ** 2 <= 1;
};

// The ears are its wings: they swing about the back of the head from above it to below it.
const EAR_PIVOT = [23, 13];
const EAR_DEG = { up: -105, mid: 180, down: 125 };
// How high the body rides on each frame: a downstroke lifts it.
const FLAP = [{ ear: 'up', lift: 0 }, { ear: 'mid', lift: -1 }, { ear: 'down', lift: -1 }, { ear: 'mid', lift: 0 }];

function earAt(deg, grow = 0) {
    const a = (deg * Math.PI) / 180;
    return tilted(EAR_PIVOT[0] + 4.6 * Math.cos(a), EAR_PIVOT[1] + 4.6 * Math.sin(a), 6.6 + grow, 4.6 + grow, deg);
}

/**
 * One frame facing right: `ear` — up, mid or down; `strain` — the moment of the fart: eyes squeezed, the trunk and the
 * tail up, a drop of sweat. The anchor is the middle of the body; HOOK is where the banner's rope is tied.
 */
function elephantFrame(ear, strain = false) {
    const g = blank(EW, EH);
    // Who owns a pixel: the near ear's rim is drawn where it meets the rest of the body.
    const own = blank(EW, EH);
    const fill = (test, key, owner = 'b') => {
        for (let y = 0; y < EH; y++) {
            for (let x = 0; x < EW; x++) {
                if (!test(x + 0.5, y + 0.5)) continue;
                g[y][x] = key;
                own[y][x] = owner;
            }
        }
    };
    const deg = EAR_DEG[ear];
    const farDeg = deg + (ear === 'mid' ? 0 : ear === 'up' ? 10 : -10);
    const a = (farDeg * Math.PI) / 180;
    fill(tilted(EAR_PIVOT[0] + 1.5 + 4.6 * Math.cos(a), EAR_PIVOT[1] - 1.5 + 4.6 * Math.sin(a), 6, 4.2, farDeg), 'D', 'f');
    fill(capsule(19, 24, 18.5, strain ? 26 : 27, 1.5), 'D', 'f');
    fill(capsule(6.5, 23.5, 5.5, strain ? 25.5 : 26.5, 1.5), 'D', 'f');
    // The tail, with its tuft: limp behind, or stiff up while it strains.
    if (strain) fill(capsule(6, 16.5, 2.5, 12.5, 0.95), 'E');
    else fill(capsule(6, 17.5, 2, 18.6, 0.95), 'E');
    fill(ellipse(15, 19, 10.5, 7), 'E');
    fill(ellipse(26, 15, 6, 6), 'E');
    if (strain) {
        fill(capsule(31, 16.5, 33.4, 12.2, 1.6), 'E');
        fill(capsule(33.4, 12.2, 34.6, 10.4, 1.1), 'E');
    } else {
        fill(capsule(31, 17, 32.4, 22, 1.6), 'E');
        fill(capsule(32.4, 22, 34, 21, 1.1), 'E');
    }
    fill(capsule(23, 24, 22.5, strain ? 26.5 : 27.5, 1.7), 'E');
    fill(capsule(10, 24.5, 9, strain ? 26.5 : 28, 1.7), 'E');
    // Lit from the top left: a pale cap over the head and the back, the belly in shade.
    for (let y = 0; y < EH; y++) {
        for (let x = 0; x < EW; x++) {
            if (g[y][x] !== 'E') continue;
            const below = inside(g, x, y + 2) ? g[y + 2][x] : '.';
            const above = inside(g, x - 1, y - 2) ? g[y - 2][x - 1] : '.';
            if (below === '.' || below === 'D') g[y][x] = 'D';
            else if (above === '.' && y < 17) g[y][x] = 'L';
        }
    }
    if (strain) {
        g[16][4] = 'D';
        g[12][1] = 'D';
        g[11][2] = 'D';
        g[11][1] = 'D';
    } else {
        g[19][1] = 'D';
        g[19][2] = 'D';
        g[18][1] = 'D';
    }
    // The near ear over everything, pink inside where it shows its front.
    const near = earAt(deg);
    const inner = earAt(deg, -2.4);
    for (let y = 0; y < EH; y++) {
        for (let x = 0; x < EW; x++) {
            if (!near(x + 0.5, y + 0.5)) continue;
            g[y][x] = ear !== 'down' && inner(x + 0.5, y + 0.5) ? 'p' : y < 12 && ear === 'up' ? 'L' : 'E';
            own[y][x] = 'e';
        }
    }
    for (let y = 0; y < EH; y++) {
        for (let x = 0; x < EW; x++) {
            if (own[y][x] !== 'e') continue;
            if (N4.some(([dx, dy]) => inside(g, x + dx, y + dy) && own[y + dy][x + dx] === 'b')) g[y][x] = 'D';
        }
    }
    if (strain) {
        // Squeezed shut, a blush, and a drop of sweat over the brow.
        g[14][27] = 'O';
        g[14][28] = 'O';
        g[13][29] = 'O';
        g[16][29] = 'p';
        g[16][28] = 'p';
        g[7][30] = 's';
        g[8][30] = 'b';
        g[8][29] = 's';
    } else {
        g[13][28] = 'k';
        g[14][28] = 'k';
        g[16][29] = 'p';
    }
    // The outline around the whole shape.
    const out = g.map((r) => r.slice());
    for (let y = 0; y < EH; y++) {
        for (let x = 0; x < EW; x++) {
            if (g[y][x] !== '.') continue;
            if (N4.some(([dx, dy]) => inside(g, x + dx, y + dy) && g[y + dy][x + dx] !== '.' && g[y + dy][x + dx] !== 's' && g[y + dy][x + dx] !== 'b')) out[y][x] = 'O';
        }
    }
    return toSprite(out, ELEPHANT_LEGEND, { x: 18, y: 18 });
}

/** Where the rope is tied, relative to the anchor of a frame facing right (the rump). */
export const HOOK = { x: -15, y: 3 };

/**
 * `fly` — 4 frames of the ears beating, each with `lift` (art px the body rides up on it); `strain` — the frame it holds
 * while it farts. All face right, anchored at the middle of the body.
 */
export const ELEPHANT = {
    fly: FLAP.map(({ ear, lift }) => ({ ...elephantFrame(ear), lift })),
    strain: { ...elephantFrame('mid', true), lift: 0 },
};

// ---------------------------------------------------------------- the banner

/** The banner's words; not translated — it is the release, not a line. */
export const BANNER_TEXT = 'ROADRUNNER 3 RELEASED';

const CAPS = {
    A: ['.#.', '#.#', '###', '#.#', '#.#'],
    D: ['##.', '#.#', '#.#', '#.#', '##.'],
    E: ['###', '#..', '##.', '#..', '###'],
    L: ['#..', '#..', '#..', '#..', '###'],
    N: ['#..#', '##.#', '#.##', '#..#', '#..#'],
    O: ['.#.', '#.#', '#.#', '#.#', '.#.'],
    R: ['##.', '#.#', '##.', '#.#', '#.#'],
    S: ['.##', '#..', '.#.', '..#', '##.'],
    U: ['#.#', '#.#', '#.#', '#.#', '###'],
    3: ['##.', '..#', '.#.', '..#', '##.'],
    ' ': ['..', '..', '..', '..', '..'],
};

/** Columns of `text` in a 3×5 font with a 1 px gap: [x, y] of each lit pixel and the width. */
function typeset(text, font) {
    const dots = [];
    let x = 0;
    for (const ch of text) {
        const glyph = font[ch];
        if (!glyph) continue;
        glyph.forEach((row, y) => [...row].forEach((c, dx) => c === '#' && dots.push([x + dx, y])));
        x += glyph[0].length + 1;
    }
    return { dots, w: Math.max(0, x - 1) };
}

const LETTERS = typeset(BANNER_TEXT, CAPS);

/**
 * The banner's measures, art px: the rope from the hook to the pole, the pole, the cloth (a margin of 3 around the
 * words, 9 rows with its outline), how far the cloth waves up and down, and how many frames one wave takes.
 */
export const BANNER = {
    rope: 7,
    margin: 3,
    clothW: LETTERS.w + 2 * 3 + 1,
    clothH: 9,
    amp: 1,
    // One wave runs along the cloth this many columns long.
    wave: 40,
    // The first columns at the pole hardly move: the wave grows to its full height over this many.
    calm: 18,
    frames: 8,
    frameMs: 110,
};

/** Width of the banner sprite, rope and pole included. */
export const BANNER_W = BANNER.rope + 1 + BANNER.clothW;

/**
 * How far column `d` of the cloth (1 — next to the pole) is lifted on wave frame `frame`, art px (down positive):
 * still at the pole, a full `amp` from `calm` columns on, the wave running away from the pole.
 */
export function waveAt(d, frame) {
    const grow = Math.min(1, d / BANNER.calm);
    return Math.round(BANNER.amp * grow * Math.sin(TAU * (frame / BANNER.frames) - (d * TAU) / BANNER.wave));
}

// The shaded half of a wave: the cloth turning away from the light.
const shadedAt = (d, frame) => Math.min(1, d / BANNER.calm) * Math.cos(TAU * (frame / BANNER.frames) - (d * TAU) / BANNER.wave) < -0.4;

const BANNER_LEGEND = { O: 'outline', c: 'cream', C: 'chickenShade', r: 'crimson', R: 'crimsonDark', m: 'bark', M: 'barkDark' };
const banners = new Map();

/**
 * The banner on wave frame `frame`, hooked on the `side` it is towed from: 'right' — the elephant flies right and the
 * banner trails on its left, 'left' — the other way. The words read left to right either way: the banner is never
 * mirrored. Anchor: the hook, where the two ropes meet.
 */
export function bannerFrame(frame, side = 'right') {
    const key = `${side}:${((frame % BANNER.frames) + BANNER.frames) % BANNER.frames}`;
    if (banners.has(key)) return banners.get(key);
    const f = Number(key.split(':')[1]);
    const { rope, clothW, clothH, amp, margin } = BANNER;
    const w = BANNER_W;
    const top = amp + 1;
    const h = clothH + 2 * top;
    const g = blank(w, h);
    const mid = top + (clothH >> 1);
    // Column of the cloth's d-th column from the pole (1-based) on this side; the pole and the hook.
    const right = side === 'right';
    const colOf = (d) => (right ? w - 1 - rope - d : rope + d);
    const pole = right ? w - 1 - rope : rope;
    const hook = right ? w - 1 : 0;
    const textAt = new Map(LETTERS.dots.map(([x, y]) => [`${x},${y}`, true]));
    for (let d = 1; d <= clothW; d++) {
        const x = colOf(d);
        const dy = waveAt(d, f);
        const shade = shadedAt(d, f);
        // The words' column here: the pole edges the cloth on its side, the outline its far end.
        const tx = right ? clothW - 1 - margin - d : d - 1 - margin;
        for (let r = 0; r < clothH; r++) {
            const y = top + r + dy;
            const edge = r === 0 || r === clothH - 1 || d === clothW;
            let key = edge ? 'O' : shade ? 'C' : 'c';
            if (!edge && textAt.has(`${tx},${r - 2}`)) key = shade ? 'R' : 'r';
            g[y][x] = key;
        }
    }
    for (let y = top - 1; y <= top + clothH; y++) g[y][pole] = y === top - 1 || y === top + clothH ? 'M' : 'm';
    // Two ropes from the pole's ends to the hook.
    for (const end of [top - 1, top + clothH]) {
        for (let k = 1; k <= rope; k++) {
            const x = right ? pole + k : pole - k;
            const y = Math.round(end + ((mid - end) * k) / rope);
            if (g[y][x] === '.') g[y][x] = 'M';
        }
    }
    const sprite = toSprite(g, BANNER_LEGEND, { x: hook, y: mid });
    banners.set(key, sprite);
    return sprite;
}

// ---------------------------------------------------------------- the path

// How far the elephant's sprite reaches in front of its anchor (the trunk) and behind it to the hook.
const FRONT = EW - 18;

/**
 * Where the anchor of the elephant starts and ends on a screen `artW` art px wide, so that the whole train — the
 * elephant, the rope and the banner — comes in from beyond one edge and is gone beyond the other within the flight.
 * The server's `from`/`to` only say which way it flies and how high.
 */
export function trainSpan(artW, ltr) {
    const behind = -HOOK.x + BANNER_W + 2;
    const enter = -(FRONT + 2);
    const leave = artW + behind;
    return ltr ? { from: enter, to: leave } : { from: artW - enter, to: -behind };
}

/** The x of the anchor `t` (0–1) into the flight. */
export function trainX(artW, ltr, t) {
    const { from, to } = trainSpan(artW, ltr);
    return from + (to - from) * clamp(t, 0, 1);
}

/** The hook of a flying elephant at anchor (x, y): behind it, on the side it flies away from. */
export const hookOf = (x, y, ltr) => ({ x: x + (ltr ? HOOK.x : -HOOK.x), y: y + HOOK.y });

// Flap and bob, ms.
export const FLAP_MS = 170;
const BOB_MS = 4200;

/** The elephant's frame and how far it rides above its path `local` ms into the flight (0 with reduced motion). */
export function flightAt(local, reduced = false) {
    if (reduced) return { frame: 1, dy: 0, wave: 0 };
    const frame = Math.floor(local / FLAP_MS) % FLAP.length;
    return {
        frame,
        dy: Math.round(1.5 * Math.sin((TAU * local) / BOB_MS)) + FLAP[frame].lift,
        wave: Math.floor(local / BANNER.frameMs) % BANNER.frames,
    };
}

// ---------------------------------------------------------------- the fart

/**
 * The fart's timeline, ms from `at`: the elephant strains until `strain`; `puffCount` green puffs burst out behind it
 * `puffGap` apart, each living `puffLife`; the cloud forms at `cloud`, is grown by `grown`, rises `rise` px drifting `drift`
 * px back and keeps swelling, melts away from `melt` and is gone at `gone`. A `fart` heard later than `staleMs`
 * stays silent. One elephant keeps at most `pool` clouds: one pushed out melts away within `hurry`.
 */
export const FART = {
    strain: 700,
    puffCount: 7,
    puffGap: 45,
    puffLife: 420,
    cloud: 250,
    grown: 900,
    melt: 2500,
    gone: 3800,
    rise: 36,
    drift: 8,
    staleMs: 1500,
    pool: 4,
    hurry: 400,
};

/**
 * Adds a fart at `at` to an elephant's `farts` ([{at, hurry}], oldest first) heard at `now`, server ms: the same `at`
 * twice is one fart, a gone one is dropped; past `pool` clouds the oldest not yet hurried gets `hurry: now`. False
 * when nothing new was added.
 */
export function addFart(farts, at, now) {
    for (let i = farts.length - 1; i >= 0; i--) if (now - farts[i].at >= FART.gone) farts.splice(i, 1);
    if (now - at >= FART.gone || farts.some((f) => f.at === at)) return false;
    farts.push({ at, hurry: null });
    farts.sort((a, b) => a.at - b.at);
    const over = farts.filter((f) => f.hurry === null).length - FART.pool;
    for (const f of farts.filter((f) => f.hurry === null).slice(0, Math.max(0, over))) f.hurry = now;
    return true;
}

const easeOut = (p) => 1 - (1 - p) ** 2.4;
const step = (v, q) => Math.round(v / q) * q;

/**
 * The fart `ms` after its `at`. Offsets are art px from the hook at the moment of the fart: `back` — away from where
 * the elephant flies, `up` — upwards. Returns { strain, puffs: [{back, up, size: 0–2}], cloud: {back, up, scale,
 * melt} | null, done }; `scale` steps by 0.05 and `melt` by 1/8, so the sprites are few. Reduced motion: no puffs, the
 * cloud stands still at full size and blinks out at the end. `hurried` — ms since the cloud was pushed out of the pool
 * (null — it was not): it melts away within FART.hurry, the puffs and the strain run on.
 */
export function fartAt(ms, reduced = false, hurried = null) {
    if (!(ms >= 0)) return { strain: false, puffs: [], cloud: null, done: false };
    const out = hurried !== null && hurried >= FART.hurry;
    const done = ms >= FART.gone || (out && ms >= FART.strain && ms >= FART.puffLife + FART.puffGap * (FART.puffCount - 1));
    const strain = ms < FART.strain;
    if (reduced) {
        const cloud = ms < FART.gone && !out ? { back: 12, up: 16, scale: 1, melt: 0 } : null;
        return { strain, puffs: [], cloud, done };
    }
    const puffs = [];
    for (let i = 0; i < FART.puffCount; i++) {
        const k = (ms - i * FART.puffGap) / FART.puffLife;
        if (k < 0 || k >= 1) continue;
        const e = easeOut(k);
        puffs.push({ back: Math.round(2 + (9 + i * 2.5) * e), up: Math.round((i % 2 ? 2 : -2) * e + (2 + i * 1.2) * e), size: Math.min(2, Math.floor(0.5 + k * 3)) });
    }
    let cloud = null;
    if (ms >= FART.cloud && !done && !out) {
        const life = (ms - FART.cloud) / (FART.gone - FART.cloud);
        const grow = easeOut(clamp((ms - FART.cloud) / (FART.grown - FART.cloud), 0, 1));
        const swell = clamp((ms - FART.grown) / (FART.gone - FART.grown), 0, 1);
        const melt = Math.max(clamp((ms - FART.melt) / (FART.gone - FART.melt), 0, 1), hurried === null ? 0 : clamp(hurried / FART.hurry, 0, 1));
        cloud = {
            back: Math.round(10 + FART.drift * life),
            up: Math.round(6 + FART.rise * life ** 0.85),
            scale: step(0.35 + 0.65 * grow + 0.2 * swell, 0.05),
            melt: step(melt, 1 / 8),
        };
    }
    return { strain, puffs, cloud, done };
}

const FART_LEGEND = { l: 'fartLight', g: 'fart', d: 'fartDark', i: 'fartInk', w: 'white' };

// The logo's cloud at scale 1, centred: lumps [x, y, r], its lime and dark spots, the drops around it.
const LUMPS = [[-9, 1, 7], [-3, -5, 8], [6, -4, 7], [10, 3, 6.5], [2, 6, 7], [-7, 6, 6]];
const SPOTS = [[-9, -1, 2.2, 'l'], [-1, -9, 1.6, 'l'], [10, 0, 2.6, 'l'], [5, -10, 1.8, 'd'], [2, 10, 1.8, 'l']];
const DROPS = [[-14, -10, 1.3], [17, 8, 1.4], [-10, 14, 1.3], [13, 14, 1.2]];

const SMALL = {
    f: ['.##', '#..', '###', '#..', '#..'],
    a: ['...', '.##', '#.#', '#.#', '.##'],
    r: ['...', '#.#', '##.', '#..', '#..'],
    t: ['.#.', '###', '.#.', '.#.', '..#'],
    '!': ['#', '#', '#', '.', '#'],
    i: ['#', '.', '#', '#', '#'],
    m: ['.....', '##.#.', '#.#.#', '#.#.#', '#.#.#'],
    e: ['...', '.#.', '###', '#..', '.##'],
};
const WORDS = [typeset('fart!', SMALL), typeset('time', SMALL)];
// The words show once the cloud is big enough to hold them.
const WORDS_FROM = 0.75;

const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const clouds = new Map();

/**
 * The Fart Time cloud at `scale` (1 — the full logo, ~34×28) melted by `melt` (0–1: Bayer-dithered holes eat it
 * pixel by pixel instead of a fade — alpha would mix colours off the palette). Anchor: the middle.
 */
export function fartCloud(scale, melt = 0) {
    const key = `${scale.toFixed(2)}:${melt.toFixed(3)}`;
    if (clouds.has(key)) return clouds.get(key);
    const s = scale;
    const w = Math.ceil(38 * s) + 2;
    const h = Math.ceil(32 * s) + 2;
    const cx = w / 2;
    const cy = h / 2;
    const g = blank(w, h);
    const inLump = (x, y) => LUMPS.some(([lx, ly, r]) => Math.hypot(x - cx - lx * s, y - cy - ly * s) <= r * s);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const [px, py] = [x + 0.5, y + 0.5];
            if (inLump(px, py)) {
                // The logo's darker underside: the bottom rim of the cloud.
                g[y][x] = inLump(px, py + Math.max(1.5, 3 * s)) ? 'g' : 'd';
                const spot = SPOTS.find(([sx, sy, r]) => Math.hypot(px - cx - sx * s, py - cy - sy * s) <= r * s);
                if (spot && s >= 0.5) g[y][x] = spot[3];
            } else if (s >= 0.5 && DROPS.some(([dx, dy, r]) => Math.hypot(px - cx - dx * s, py - cy - dy * s) <= Math.max(0.8, r * s))) g[y][x] = 'd';
        }
    }
    if (s >= WORDS_FROM) {
        // «fart!» over «time», the second line set off to the right like the logo's; white, rimmed in dark green.
        const lines = [{ ...WORDS[0], dx: -1, dy: -6 }, { ...WORDS[1], dx: 3, dy: 1 }];
        const ink = [];
        for (const { dots, w: lw, dx, dy } of lines) {
            const x0 = Math.round(cx - lw / 2 + dx);
            const y0 = Math.round(cy + dy);
            for (const [x, y] of dots) ink.push([x0 + x, y0 + y]);
        }
        for (const [x, y] of ink) {
            for (let oy = -1; oy <= 1; oy++) for (let ox = -1; ox <= 1; ox++) if (inside(g, x + ox, y + oy)) g[y + oy][x + ox] = 'i';
        }
        for (const [x, y] of ink) if (inside(g, x, y)) g[y][x] = 'w';
    }
    if (melt > 0) {
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (BAYER[y & 3][x & 3] < melt * 16) g[y][x] = '.';
    }
    const sprite = toSprite(g, FART_LEGEND, { x: Math.floor(cx), y: Math.floor(cy) });
    clouds.set(key, sprite);
    return sprite;
}

/** The puffs bursting out behind it: three sizes of a green blob, lit from the top left. Anchor: the middle. */
export const PUFFS = [1.6, 2.6, 3.6].map((r) => {
    const n = Math.ceil(r * 2);
    const c = n / 2;
    const g = blank(n, n);
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            const [dx, dy] = [x + 0.5 - c, y + 0.5 - c];
            if (Math.hypot(dx, dy) > r) continue;
            g[y][x] = dx + dy < -r * 0.6 ? 'l' : dx + dy > r * 0.7 ? 'd' : 'g';
        }
    }
    return toSprite(g, FART_LEGEND, { x: Math.floor(c), y: Math.floor(c) });
});
