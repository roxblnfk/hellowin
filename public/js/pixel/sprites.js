import { PALETTE } from './palette.js';

/**
 * Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y}, emit?: { cut?: [x,y], top?: [x,y] } }
 * rows: h strings of w chars, '.' is transparent. All coordinates are art pixels from the top-left corner.
 */

const PUMPKIN_LEGEND = {
    '.': null,
    O: 'outline',
    D: 'pumpkinDeep',
    d: 'pumpkinDark',
    o: 'pumpkin',
    l: 'pumpkinLight',
    s: 'pumpkinShine',
    T: 'stemDark',
    t: 'stem',
    y: 'stemLight',
    X: 'innerDark',
    x: 'inner',
    f: 'flesh',
    F: 'fleshDark',
    c: 'cream',
    e: 'seedShade',
    // Stages 5+: the white-hot core of the inner light, and the caps of stage 8 — a witch hat (violet, crimson band,
    // gold buckle), a hookah (brass, steel, clay bowl, green hose), a gold crown with crimson gems.
    w: 'flameCore',
    a: 'violetLight',
    A: 'violet',
    n: 'violetDark',
    q: 'crimson',
    Q: 'crimsonDark',
    b: 'gold',
    j: 'hayDark',
    g: 'grayLight',
    G: 'gray',
    K: 'steelDark',
    P: 'copper',
    p: 'copperLight',
    m: 'soilLight',
    M: 'soil',
    h: 'leafDark',
    H: 'leaf',
    // The cat-ears cap: the pink inside of the ears.
    k: 'blush',
};

// The pulp in the cut-outs before scooping (stages 1–3), in the back layer and the flattened sprite.
const PULP_LEGEND = { u: 'pulp', U: 'pulpShade' };

// The inner light (chars w s l o d in back and glow, the cut flesh f in rim) per stage: warm candlelight, then in
// scene 2 a green cast at the edges (9) and full dark-magic green (10+); the blood-moon variant swaps green for violet.
const GREENISH = { l: 'eyeGreen', o: 'eerieDark', d: 'eerieDeep', f: 'leafShine' };
const MAGIC = {
    green: { w: 'magicCore', s: 'eerieHot', l: 'eyeGreen', o: 'eerieDark', d: 'eerieDeep', f: 'leafShine' },
    violet: { w: 'violetHot', s: 'eyeViolet', l: 'violetGlow', o: 'violet', d: 'violetDark', f: 'violetLight' },
    // The cursed world after the finale: FrankenPHP's lime.
    franken: { w: 'frankenGlowCore', s: 'frankenGlowHot', l: 'frankenGlow', o: 'frankenGlowDark', d: 'frankenGlowDeep', f: 'frankenGlowHot' },
    // Spent: the finale's lightning puts the light out, the hollow inside goes dark (finale steps 2–4).
    spent: { w: 'inner', s: 'inner', l: 'inner', o: 'innerDark', d: 'innerDark', f: 'fleshDark' },
};
// Fire chars core → edge: w s l d D, plus smoke g G. The same flames burn warm, green or violet.
const FIRE = {
    warm: { '.': null, w: 'flameCore', s: 'pumpkinShine', l: 'pumpkinLight', d: 'pumpkinDark', D: 'pumpkinDeep', g: 'grayLight', G: 'gray' },
    green: { '.': null, w: 'magicCore', s: 'eyeGreen', l: 'eerieMid', d: 'eerieDark', D: 'eerieDeep', g: 'ecto', G: 'eerieDark' },
    violet: { '.': null, w: 'violetHot', s: 'eyeViolet', l: 'violetGlow', d: 'violet', D: 'violetDark', g: 'violetLight', G: 'violet' },
    franken: { '.': null, w: 'frankenGlowCore', s: 'frankenGlowHot', l: 'frankenGlow', d: 'frankenGlowDark', D: 'frankenGlowDeep', g: 'frankenGlowHot', G: 'frankenGlowDark' },
};

// A pumpkin is assembled per size from a generated body (lobes, skin ramp, silhouette tweak) and hand-drawn masks
// for eyes, mouth and stem, all picked by a seed. Masks use '#'; each grid has its own masks, nothing is scaled.
const PUMPKIN_SIZES = {
    l: {
        w: 44,
        // Left inset of every body row; the right side mirrors it unless the seed skews the silhouette.
        inset: [15, 11, 9, 7, 5, 4, 3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 2, 3, 4, 5, 7, 9, 11, 15],
        lobes: [3, 4, 5, 6],
        stemX: 19,
        stems: [
            ['....TT', '...tTT', '...ytT', '..yytT', '.yyttT', 'yyyttT'],
            ['TT....', 'yTT...', '.ytT..', '.yytT.', '.yyttT', 'yyyttT'],
            ['......', '......', '......', '.yytT.', 'yyyttT', 'yyyttT'],
            ['.TTTT.', '.T..tT', '....tT', '...ytT', '..yytT', '.yyttT'],
        ],
        eyesAt: [[8, 12], [27, 12]],
        eyeRim: 'both',
        eyes: [
            ['....#....', '...###...', '..#####..', '.#######.', '#########', '#########', '#########'],
            ['..#####..', '.#######.', '#########', '#########', '#########', '.#######.', '..#####..'],
            ['...###...', '..#####..', '.###.###.', '###...###', '##.....##', '#.......#', '.........'],
            ['##.......', '####.....', '######...', '########.', '#########', '#########', '.#######.'],
            ['....#....', '...###...', '..#####..', '.#######.', '#########', '.#######.', '..#####..'],
            ['#########', '#########', '#########', '.#######.', '..#####..', '...###...', '.........'],
        ],
        smallEye: ['.........', '.........', '....#....', '...###...', '..#####..', '.#######.', '.#######.'],
        mouthAt: [10, 21],
        mouthRim: 'bottom',
        mouths: [
            ['##....................##', '####..############..####', '.######################.', '..####################..', '...########..########...', '....######....######....'],
            ['#......................#', '##....................##', '.######################.', '..####################..', '...##################...', '.....##############.....'],
            ['.........######.........', '........########........', '.......##########.......', '.......##########.......', '........########........', '.........######.........'],
            ['##....................##', '.##########..##########.', '.######################.', '..####################..', '...##################...', '.....##############.....'],
            ['......................##', '...................####.', '...............#######..', '#..........#########....', '###############.........', '.############...........'],
        ],
        // Stage 3+: body rows above `top` are cut away and the opening is an ellipse (body coordinates).
        opening: { top: 3, cx: 21.5, cy: 7.5, rx: 13.5, ry: 4.2, thick: true },
    },
    m: {
        w: 34,
        inset: [11, 8, 6, 4, 3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 2, 3, 4, 6, 8, 11],
        lobes: [3, 4, 5],
        stemX: 14,
        stems: [
            ['...TT', '..tTT', '..ytT', '.yytT', 'yyttT'],
            ['TT...', 'yTT..', '.ytT.', '.yytT', 'yyttT'],
            ['.....', '.....', '.ytT.', 'yyttT', 'yyttT'],
            ['.TTT.', '.T.tT', '...tT', '..ytT', '.yytT'],
        ],
        eyesAt: [[6, 10], [21, 10]],
        eyeRim: 'both',
        eyes: [
            ['...#...', '..###..', '.#####.', '#######', '#######', '#######'],
            ['..###..', '.#####.', '#######', '#######', '.#####.', '..###..'],
            ['..###..', '.#####.', '###.###', '##...##', '#.....#', '.......'],
            ['##.....', '####...', '######.', '#######', '#######', '.#####.'],
            ['...#...', '..###..', '.#####.', '#######', '.#####.', '..###..'],
            ['#######', '#######', '.#####.', '..###..', '.......', '.......'],
        ],
        smallEye: ['.......', '...#...', '..###..', '.#####.', '.#####.', '.......'],
        mouthAt: [7, 17],
        mouthRim: 'bottom',
        mouths: [
            ['##................##', '####..########..####', '.##################.', '..#######..#######..', '...######..######...'],
            ['#..................#', '##................##', '.##################.', '..################..', '....############....'],
            ['.......######.......', '......########......', '......########......', '......########......', '.......######.......'],
            ['##................##', '.########..########.', '.##################.', '..################..', '....############....'],
            ['..................##', '...............####.', '#..........######...', '###############.....', '.###########........'],
        ],
        opening: { top: 2, cx: 16.5, cy: 5.5, rx: 10.5, ry: 3.3, thick: true },
    },
    s: {
        w: 26,
        inset: [8, 5, 3, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 2, 3, 5, 8],
        lobes: [3, 4, 5],
        stemX: 11,
        stems: [
            ['..TT', '.tT.', '.ytT', 'yytT'],
            ['TT..', '.tT.', '.ytT', 'yytT'],
            ['....', '....', 'ytT.', 'yytT'],
            ['TTT.', '..tT', '.ytT', 'yytT'],
        ],
        eyesAt: [[4, 7], [15, 7]],
        eyeRim: 'both',
        eyes: [
            ['...#...', '..###..', '.#####.', '#######'],
            ['.#####.', '#######', '#######', '.#####.'],
            ['..###..', '.#####.', '##...##', '#.....#'],
            ['##.....', '####...', '######.', '#######'],
            ['..###..', '.#####.', '.#####.', '..###..'],
            ['#######', '#######', '.#####.', '..###..'],
        ],
        smallEye: ['.......', '...#...', '..###..', '.#####.'],
        mouthAt: [6, 13],
        mouthRim: 'bottom',
        mouths: [
            ['#............#', '###..####..###', '.############.', '..####..####..', '...###..###...'],
            ['#............#', '##..........##', '.############.', '..##########..', '....######....'],
            ['.....####.....', '....######....', '....######....', '....######....', '.....####.....'],
            ['##..........##', '.#####..#####.', '.############.', '..##########..', '....######....'],
            ['............##', '.........####.', '#.....######..', '###########...', '.#########....'],
        ],
        opening: { top: 1, cx: 12.5, cy: 4, rx: 7.5, ry: 2.4, thick: false },
    },
};

const GROOVES = {
    3: [-0.42, 0.42],
    4: [-0.62, 0, 0.62],
    5: [-0.8, -0.36, 0.36, 0.8],
    6: [-0.82, -0.48, 0, 0.48, 0.82],
};

const SKINS = [
    {},
    { d: 'pumpkinRedDark', o: 'pumpkinRed', l: 'pumpkinRedLight' },
    { d: 'pumpkinGoldDark', o: 'pumpkinGold', l: 'pumpkinGoldLight' },
];

/**
 * Stages drawn: 0–4 carve the face (scene 0); 5 a small glow inside, 6 the glow grows, 7 fire out of the top, 8 a cap
 * on and flame jets from the eyes (scene 1); 9 a green cast in the cuts, 10 green radiance, 11 floating (scene 2).
 * Stages past it look like the last one.
 */
export const PUMPKIN_STAGES = 11;

// Per size for stages 5+: how far the inner light reaches (art px); from stage 7 the padding above (fire, caps) and
// at the sides (eye jets, aura); the top fire's height, the eye jets' length × width, the aura's reach.
const LIT = {
    l: { reach: 15, padTop: 18, padSide: 7, fire: 21, jet: [12, 5], aura: 3 },
    m: { reach: 12, padTop: 15, padSide: 6, fire: 17, jet: [9, 4], aura: 2 },
    s: { reach: 9, padTop: 12, padSide: 5, fire: 12, jet: [7, 3], aura: 2 },
};

// Inner light per stage: reach multiplier and ramp from the source out; 'X' leaves the far corners unlit.
const GLOW_STAGES = {
    5: { k: 0.75, ramp: ['s', 'l', 'o', 'X'] },
    6: { k: 1, ramp: ['s', 'l', 'o', 'd'] },
    7: { k: 1.25, ramp: ['s', 'l', 'o', 'd'] },
    8: { k: 1.6, ramp: ['w', 's', 'l', 'o'] },
    9: { k: 1.4, ramp: ['s', 'l', 'o', 'd'] },
    10: { k: 1.6, ramp: ['w', 's', 'l', 'o'] },
    11: { k: 1.7, ramp: ['w', 's', 'l', 'o'] },
};
// Light intensity per glow frame: the reach is scaled by it, so cycling the frames flickers.
const FLICKER = [0.86, 1, 1.14];
const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];

/**
 * Paints flame tongues into a char grid (w s l d D, core → edge). A tongue is { x, y, len, wid, ang, phase, sway }:
 * base point and size in art px, angle from straight up in radians. frame (0–3) changes height and sway; allow(x, y)
 * keeps pixels off. Shared with the bonfire.
 */
export function paintFlames(g, tongues, frame, allow = () => true) {
    const h = g.length;
    const w = g[0].length;
    const heat = Array.from({ length: h }, () => new Float32Array(w));
    const at = Array.from({ length: h }, () => new Float32Array(w));
    const touch = (x, y, i, u) => {
        if (y >= 0 && y < h && x >= 0 && x < w && i > heat[y][x]) {
            heat[y][x] = i;
            at[y][x] = u;
        }
    };
    tongues.forEach((t, n) => {
        const ph = (t.phase ?? 0) + (frame * Math.PI) / 2;
        const len = t.len * (0.86 + 0.14 * Math.sin(ph) + 0.06 * Math.sin(ph * 2.3 + n));
        const sway = (t.sway ?? 1) * t.wid * 0.45;
        const ang = t.ang ?? 0;
        const ux = Math.sin(ang);
        const uy = -Math.cos(ang);
        const R = Math.ceil(len + t.wid);
        for (let y = Math.floor(t.y - R); y <= t.y + R; y++) {
            for (let x = Math.floor(t.x - R); x <= t.x + R; x++) {
                const dx = x - t.x;
                const dy = y - t.y;
                const u = (dx * ux + dy * uy) / len;
                if (u < -0.08 || u > 1) continue;
                const v = dx * -uy + dy * ux;
                const c = sway * Math.sin(u * 3 + ph) * u;
                // Widest a fifth of the way up, then tapering to the tip.
                const hw = (t.wid / 2) * (u < 0.2 ? 0.7 + 1.5 * Math.max(0, u) : ((1 - u) / 0.8) ** 0.75);
                touch(x, y, (1 - Math.abs(v - c) / Math.max(0.5, hw)) * (1 - 0.35 * u), u);
            }
        }
        // Every few frames a tongue throws off a lick of flame above its tip.
        if ((frame + n) % 3 === 0) {
            const tip = len * 1.18;
            const x = Math.round(t.x + ux * tip + sway * Math.sin(3 + ph));
            const y = Math.round(t.y + uy * tip);
            touch(x, y, 0.32, 1);
            touch(x, y - 1, 0.2, 1);
        }
    });
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const i = heat[y][x];
            if (i <= 0 || !allow(x, y)) continue;
            g[y][x] = i > 0.74 && at[y][x] < 0.45 ? 'w' : i > 0.5 ? 's' : i > 0.28 ? 'l' : i > 0.1 ? 'd' : 'D';
        }
    }
}

/**
 * Paints a smoke column (g light, G shade) rising from (x, y) for `height` px: puffs that grow and drift as they
 * rise, thinning out in Bayer steps toward the top. frame (0–3) moves the puffs up one step.
 */
export function paintSmoke(g, x, y, height, frame, { width = 3, drift = 1 } = {}) {
    const h = g.length;
    const w = g[0].length;
    const step = Math.max(2, Math.round(width * 0.9));
    for (let k = -1; k * step < height; k++) {
        const rise = k * step + (frame * step) / 4;
        if (rise < 0 || rise > height) continue;
        const t = rise / height;
        const cx = x + drift * Math.sin(rise * 0.35 + 1) * (1 + t * 2);
        const cy = y - rise;
        const r = width * (0.5 + t * 0.8);
        for (let yy = Math.floor(cy - r); yy <= cy + r; yy++) {
            for (let xx = Math.floor(cx - r); xx <= cx + r; xx++) {
                const d = Math.hypot(xx - cx, (yy - cy) * 1.2) / r;
                if (d > 1 || yy < 0 || yy >= h || xx < 0 || xx >= w) continue;
                if (BAYER[yy & 3][xx & 3] / 16 >= (1 - t) * 1.3 - d * 0.4) continue;
                g[yy][xx] = (xx - cx) + (yy - cy) < -r * 0.3 ? 'g' : 'G';
            }
        }
    }
}

// Caps for stage 8, picked by seed; append new ones at the end — reordering changes every pumpkin.
const CAPS = ['lid', 'witch', 'hookah', 'crown', 'cat'];
// The first four were picked evenly; each cap appended later takes an even slice off the bottom of every earlier
// cap's share, so a pumpkin keeps its cap unless it lands in the new cap's slice.
const FIRST_CAPS = 4;

function pickCap(r) {
    let i = Math.floor(r * FIRST_CAPS);
    let u = r * FIRST_CAPS - i;
    for (let k = FIRST_CAPS; k < CAPS.length; k++) {
        const slice = 1 / (k + 1);
        if (u < slice) {
            i = k;
            u /= slice;
        } else {
            u = (u - slice) / (1 - slice);
        }
    }
    return CAPS[i];
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
    let c = n;
    for (let k = 0; k < 8; k++) {
        c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    }
    return c >>> 0;
});

/** CRC32 of the id's UTF-8 bytes, unsigned: the seed that fixes how this pumpkin looks for everyone. */
export function seedOf(id) {
    let crc = 0xffffffff;
    for (const byte of new TextEncoder().encode(String(id))) {
        crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    }
    return (crc ^ 0xffffffff) >>> 0;
}

function mulberry32(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const mirror = (rows) => rows.map((r) => [...r].reverse().join(''));

// The order of draws is part of the contract: reordering it changes every pumpkin.
function pumpkinLook(size, seed) {
    const def = PUMPKIN_SIZES[size];
    const rnd = mulberry32(seed);
    const pick = (n) => Math.floor(rnd() * n);
    const eye = pick(def.eyes.length + 1);
    return {
        rightFirst: rnd() < 0.5,
        eyes: eye < def.eyes.length
            ? [def.eyes[eye], mirror(def.eyes[eye])]
            : [def.eyes[0], mirror(def.smallEye)],
        mouth: def.mouths[pick(def.mouths.length)],
        grooves: GROOVES[def.lobes[pick(def.lobes.length)]],
        skin: SKINS[pick(SKINS.length)],
        stem: def.stems[pick(def.stems.length)],
        stemShift: pick(3) - 1,
        skew: pick(4),
    };
}

function bodyInsets(def, skew) {
    const left = def.inset.slice();
    const right = def.inset.slice();
    const h = left.length;
    if (skew === 1 || skew === 2) {
        // One shoulder sits a pixel lower.
        const side = skew === 1 ? right : left;
        for (let y = 1; y <= 5; y++) side[y] += 1;
    } else if (skew === 3) {
        for (let y = h - 6; y < h - 1; y++) left[y] += 1;
    }
    return { left, right };
}

// Skin tones lit from the top-left: each lobe is lighter on its left, the body darkens to the right and downwards,
// and the lobes start darkening at staggered heights so the shading does not band.
function bodyTones(def, insets, grooves) {
    const w = def.w;
    const h = def.inset.length;
    const grid = Array.from({ length: h }, () => Array(w).fill('.'));
    const groovesAt = [];
    for (let y = 0; y < h; y++) {
        const a = insets.left[y];
        const b = w - 1 - insets.right[y];
        const c = (a + b) / 2;
        const half = Math.max(1, (b - a) / 2);
        const gx = grooves.map((f) => Math.round(c + f * half));
        groovesAt.push(gx);
        const ny = y / (h - 1);
        for (let x = a; x <= b; x++) {
            let k = 0;
            while (k < gx.length && x > gx[k]) k++;
            const left = k ? gx[k - 1] : a;
            const right = k < gx.length ? gx[k] : b;
            const u = (x - left) / Math.max(1, right - left);
            const nx = (x - c) / half;
            const lv = 2.6 - 0.9 * nx + (u < 0.34 ? 0.55 : u > 0.68 ? -0.55 : 0) + (ny < 0.28 ? 0.45 : 0)
                - 2.4 * Math.max(0, ny - 0.5 - 0.05 * ((k * 3) % 4));
            let ch = 'Ddol'[Math.max(0, Math.min(3, Math.floor(lv)))];
            if (gx.includes(x) && y > 0 && y < h - 1) {
                ch = nx < 0.1 && ny < 0.55 ? 'd' : 'D';
            }
            grid[y][x] = ch;
        }
    }
    // Shine: a short stroke on the lobe left of centre, leaning down-left like the highlight on a curved rib.
    const strokeRows = Math.round(h * 0.4);
    for (let y = 1; y <= strokeRows; y++) {
        const gx = groovesAt[y];
        const a = insets.left[y];
        const c = (a + w - 1 - insets.right[y]) / 2;
        const target = c - 0.3 * ((w - 1 - insets.right[y] - a) / 2);
        let k = 0;
        while (k < gx.length && target > gx[k]) k++;
        const left = k ? gx[k - 1] : a;
        const right = k < gx.length ? gx[k] : w - 1 - insets.right[y];
        const x = Math.round(left + (right - left) * 0.35 - (y - 1) * 0.25);
        if (grid[y][x] === 'l' || grid[y][x] === 'o') {
            grid[y][x] = 's';
        }
    }
    return grid;
}

// The cut wall of a hole that faces the viewer and the light: bottom and/or right inner edge. Only where the hole
// is thick enough, so thin strokes stay dark.
function holeRim(mask, rule) {
    const at = (x, y) => mask[y]?.[x] === '#';
    return mask.map((row, y) => [...row].map((ch, x) => {
        if (ch !== '#' || rule === 'none') return false;
        const bottom = !at(x, y + 1) && at(x, y - 1) && at(x, y - 2);
        const right = !at(x + 1, y) && at(x - 1, y) && at(x - 2, y);
        return rule === 'both' ? bottom || right : rule === 'bottom' ? bottom : right;
    }));
}

function layerSprite(grid, legend, anchor) {
    const rows = grid.map((r) => r.join(''));
    return { w: rows[0].length, h: rows.length, rows, legend, anchor };
}

const layersCache = new Map();

/**
 * The pumpkin as aligned layers, drawn back → body → rim → stem; glow goes above back and under body, only at night.
 * Every layer has the same w×h and anchor. seed is an unsigned 32-bit number, normally seedOf(id).
 *
 * Stages 5+ add (null where a stage has none): layers.fire — flames and smoke over everything, light; layers.aura —
 * magic light around the silhouette, under everything; glowFrames[3], fireFrames[4], auraFrames[3] to animate them;
 * the stem slot holds the stage-8 cap, named in `cap`. From stage 7 the sprite is padded (LIT), so w×h grow; the
 * anchor stays at the foot. tone 'green' | 'violet' | 'franken' colours the magic of stages 9+ (violet: the blood moon,
 * franken: the cursed world's lime).
 */
export function pumpkinLayers(stage, size, seed = 0, tone = 'green') {
    const def = PUMPKIN_SIZES[size] ?? PUMPKIN_SIZES.m;
    const st = Math.max(0, Math.min(PUMPKIN_STAGES, stage | 0));
    const key = `${size}:${st}:${seed >>> 0}${st >= 9 ? `:${tone}` : ''}`;
    if (layersCache.has(key)) {
        return layersCache.get(key);
    }

    const look = pumpkinLook(PUMPKIN_SIZES[size] ? size : 'm', seed >>> 0);
    const w = def.w;
    const bodyH = def.inset.length;
    const top = Math.max(...def.stems.map((s) => s.length)) - 1;
    const h = top + bodyH;
    const blank = () => Array.from({ length: h }, () => Array(w).fill('.'));
    const insets = bodyInsets(def, look.skew);
    const tones = bodyTones(def, insets, look.grooves);

    // 'skin' | 'hole' | 'rim' | 'open' | 'wall' | 'pulp' | 'seed' per pixel; '.' outside.
    const kind = blank();
    for (let y = 0; y < bodyH; y++) {
        for (let x = 0; x < w; x++) {
            if (tones[y][x] !== '.' && !(st >= 3 && y < def.opening.top)) kind[top + y][x] = 'skin';
        }
    }
    const carve = (mask, x0, y0, rule) => {
        const rim = holeRim(mask, rule);
        mask.forEach((row, dy) => [...row].forEach((ch, dx) => {
            if (ch === '#') kind[top + y0 + dy][x0 + dx] = rim[dy][dx] ? 'rim' : 'hole';
        }));
    };
    const order = look.rightFirst ? [1, 0] : [0, 1];
    if (st >= 1) carve(look.eyes[order[0]], ...def.eyesAt[order[0]], def.eyeRim);
    if (st >= 2) carve(look.eyes[order[1]], ...def.eyesAt[order[1]], def.eyeRim);
    if (st >= 3) carve(look.mouth, ...def.mouthAt, def.mouthRim);

    if (st >= 3) {
        const o = def.opening;
        const inside = (x, y) => ((x - o.cx) / o.rx) ** 2 + ((y - o.cy) / o.ry) ** 2 <= 1;
        for (let y = o.top; y < o.cy + o.ry + 1; y++) {
            for (let x = 0; x < w; x++) {
                if (!inside(x, y)) continue;
                // The near lip of the cut faces the viewer and is drawn 2 px thick where the grid allows.
                const edge = !inside(x, y - 1) || !inside(x, y + 1) || !inside(x - 1, y) || !inside(x + 1, y)
                    || (o.thick && y > o.cy && !inside(x, y + 2));
                const wall = !edge && !inside(x, y - 2) && inside(x, y + 2);
                let k = edge ? 'rim' : wall ? 'wall' : 'open';
                if (k === 'open' && st === 3 && y > o.cy - 0.5) {
                    const n = ((x * 37 + y * 101) ^ (x * y)) % 7;
                    k = n === 0 ? 'seed' : n === 3 ? 'fibre' : 'pulp';
                }
                kind[top + y][x] = k;
            }
        }
    }

    const filled = (x, y) => (kind[y]?.[x] ?? '.') !== '.';
    // Until scooped (stage 4) the cut-outs are full of pulp, not dark: shaded where the skin's edge overhangs it
    // (top and left, against the light), seeds and fibres inside; under the opening the far wall shades into it.
    const unscooped = st >= 1 && st <= 3;
    const isCut = (x, y) => kind[y]?.[x] === 'hole' || kind[y]?.[x] === 'rim';
    const pulpAt = (x, y) => {
        if (!isCut(x, y - 1) || !isCut(x - 1, y)) return 'U';
        if (!isCut(x, y - 2)) return 'F';
        const n = ((x * 37 + y * 101) ^ (x * y)) % 9;
        return n === 0 ? 'c' : n === 4 ? 'F' : 'u';
    };
    const backOf = unscooped
        ? { wall: 'U', open: 'F', pulp: 'u', fibre: 'F', seed: 'c' }
        : { wall: 'x', pulp: 'F', seed: 'c' };
    const back = blank();
    const body = blank();
    const rim = blank();
    const stem = blank();
    const glow = blank();
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const k = kind[y][x];
            if (k === '.') continue;
            back[y][x] = unscooped && k === 'hole' ? pulpAt(x, y) : backOf[k] ?? 'X';
            if (k === 'skin') {
                let ch = tones[y - top][x];
                if (!filled(x, y - 1) || !filled(x, y + 1) || !filled(x - 1, y) || !filled(x + 1, y)) {
                    const lit = (!filled(x, y - 1) && x < w * 0.6) || (!filled(x - 1, y) && y - top < bodyH * 0.55);
                    ch = lit ? 'D' : 'O';
                }
                body[y][x] = ch;
            } else {
                glow[y][x] = 'w';
                if (k === 'rim') rim[y][x] = 'f';
            }
        }
    }
    const stemX = def.stemX + look.stemShift;
    if (st < 3) {
        look.stem.forEach((row, dy) => [...row].forEach((ch, dx) => {
            if (ch !== '.') stem[top - look.stem.length + 1 + dy][stemX + dx] = ch;
        }));
    }

    const centre = (mask, [x, y]) => [x + (mask[0].length >> 1), top + y + (mask.length >> 1)];
    const cut = st < 2
        ? centre(look.eyes[order[st]], def.eyesAt[order[st]])
        : centre(look.mouth, def.mouthAt);
    const emit = { cut, top: [Math.round(def.opening.cx), top + Math.round(def.opening.cy)] };
    let grids = { back, body, rim, glow, stem: st < 3 ? stem : null };
    let extra = null;
    if (st >= 5) {
        extra = dressUp(st, PUMPKIN_SIZES[size] ? size : 'm', seed >>> 0, { def, look, kind, top, tones, grids, emit });
        grids = extra.grids;
    }
    const pw = grids.body[0].length;
    const ph = grids.body.length;
    const anchor = { x: pw >> 1, y: ph };
    const legend = { ...PUMPKIN_LEGEND, ...look.skin, ...(unscooped ? PULP_LEGEND : {}) };
    const magic = MAGIC[tone] ?? MAGIC.green;
    const cast = { l: magic.l, o: magic.o, d: magic.d, f: magic.f };
    // The inner light: warm up to stage 8, a magic cast at the edges at 9, full magic from 10.
    const lightLegend = st >= 10 ? { ...PUMPKIN_LEGEND, ...magic } : st === 9 ? { ...PUMPKIN_LEGEND, ...cast } : PUMPKIN_LEGEND;
    const glowLegend = st >= 5 ? lightLegend : { '.': null, w: 'white' };
    const fireLegend = st >= 9 ? FIRE[tone] ?? FIRE.green : FIRE.warm;
    // In the cursed world only the cuts glow: no ring of light around the silhouette.
    const auraFrames = tone === 'franken' ? null : extra?.auraFrames;
    const result = {
        w: pw,
        h: ph,
        anchor,
        emit,
        layers: {
            back: layerSprite(grids.back, unscooped ? { ...lightLegend, ...PULP_LEGEND } : lightLegend, anchor),
            body: layerSprite(grids.body, legend, anchor),
            rim: layerSprite(grids.rim, st >= 9 ? { ...PUMPKIN_LEGEND, f: magic.f } : PUMPKIN_LEGEND, anchor),
            // From stage 8 the stem slot holds the cap; the lid reuses the skin colours.
            stem: grids.stem ? layerSprite(grids.stem, st >= 8 ? legend : PUMPKIN_LEGEND, anchor) : null,
            glow: layerSprite(grids.glow, glowLegend, anchor),
            fire: extra?.fireFrames ? layerSprite(extra.fireFrames[0], fireLegend, anchor) : null,
            aura: auraFrames ? layerSprite(auraFrames[0], glowLegend, anchor) : null,
        },
    };
    if (extra) {
        result.glowFrames = extra.glowFrames.map((g) => layerSprite(g, glowLegend, anchor));
        if (extra.fireFrames) result.fireFrames = extra.fireFrames.map((g) => layerSprite(g, fireLegend, anchor));
        if (auraFrames) result.auraFrames = auraFrames.map((g) => layerSprite(g, glowLegend, anchor));
        result.cap = extra.cap;
    }
    layersCache.set(key, result);
    return result;
}

/**
 * Stages 5+ on top of the hollow face of stage 4: the inner light (5, 6), fire out of the top (7), a cap and eye jets
 * (8), magic glow (9, 10), the aura with motes (10, 11). From stage 7 the grids are padded above and at the sides.
 * Draws from its own random stream, so pumpkinLook — and with it every earlier stage — stays as it was.
 */
function dressUp(st, size, seed, { def, look, kind: kind0, top: top0, tones, grids, emit }) {
    const lit = LIT[size];
    const rnd = mulberry32((seed ^ 0x5bd1e995) >>> 0);
    // The order of draws is part of the contract, as in pumpkinLook.
    const capName = pickCap(rnd());
    const capSeed = Math.floor(rnd() * 4294967296);
    const firePhase = rnd() * 6.28;

    const big = st >= 7;
    const ox = big ? lit.padSide : 0;
    const oy = big ? lit.padTop : 0;
    const pad = (g) => (!big ? g : [
        ...Array.from({ length: oy }, () => Array(g[0].length + 2 * ox).fill('.')),
        ...g.map((row) => [...Array(ox).fill('.'), ...row, ...Array(ox).fill('.')]),
    ]);
    const kind = pad(kind0);
    const back = pad(grids.back);
    const body = pad(grids.body);
    const rim = pad(grids.rim);
    const h = kind.length;
    const w = kind[0].length;
    const top = top0 + oy;
    const blank = () => Array.from({ length: h }, () => Array(w).fill('.'));
    const o = def.opening;
    const ocx = o.cx + ox;
    const ocy = top + o.cy;
    const inside = (x, y) => {
        const k = kind[y]?.[x];
        return k !== undefined && k !== '.' && k !== 'skin';
    };

    // The light sits low inside, behind the mouth; once it burns out of the top it fills the middle.
    const floor = top + def.mouthAt[1] + look.mouth.length - 1;
    const light = [Math.round(ocx), st >= 7 ? Math.round((floor + ocy) / 2) : floor - 1];
    const { k: reachK, ramp } = GLOW_STAGES[Math.min(st, 11)];
    const band = (x, y, k) => {
        const d = Math.hypot(x - light[0], (y - light[1]) * 0.85) / (lit.reach * reachK * k)
            + (BAYER[y & 3][x & 3] / 16 - 0.5) * 0.18;
        return ramp[d < 0.3 ? 0 : d < 0.62 ? 1 : d < 1 ? 2 : 3];
    };
    const glowFrames = FLICKER.map((k) => {
        const g = blank();
        for (let y = 0; y < h; y++) {
            for (let x = 0; x < w; x++) {
                if (!inside(x, y)) continue;
                const ch = band(x, y, k);
                if (ch !== 'X') g[y][x] = ch;
            }
        }
        return g;
    });
    // The back carries the steady light too, so the pumpkin reads lit with the glow layer hidden.
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (inside(x, y)) back[y][x] = glowFrames[1][y][x] === '.' ? 'X' : glowFrames[1][y][x];
        }
    }

    const fireFrames = [0, 1, 2, 3].map(blank);
    let burning = false;
    const flames = (tongues, allow) => {
        burning = true;
        fireFrames.forEach((g, f) => paintFlames(g, tongues, f, allow));
    };
    if (st === 7) {
        // Fire out of the top cut: tongues across the opening, kept behind the near lip and the skin.
        const n = size === 'l' ? 4 : size === 'm' ? 3 : 2;
        const spread = o.rx * 0.55;
        flames(Array.from({ length: n }, (_, i) => ({
            x: ocx + (n === 1 ? 0 : -spread + (2 * spread * i) / (n - 1)),
            y: ocy,
            len: lit.fire * (i === 1 || n === 2 ? 1 : 0.7),
            wid: o.rx * 0.62,
            phase: firePhase + i * 1.9,
        })), (x, y) => ['.', 'open', 'wall'].includes(kind[y]?.[x] ?? '.'));
    }
    if (st === 8) {
        // Jets of flame out of both eyes, slanting up and away from the face.
        const [len, wid] = lit.jet;
        emit.eyes = [0, 1].map((i) => {
            const mask = look.eyes[i];
            const [ex, ey] = def.eyesAt[i];
            return [ex + ox + (i ? mask[0].length - 2 : 1), top + ey + (mask.length >> 1)];
        });
        flames(emit.eyes.map(([x, y], i) => ({ x, y, len, wid, ang: i ? 1.05 : -1.05, phase: firePhase + i * 2.6, sway: 0.6 })));
    }

    let cap = null;
    let capTop = null;
    if (st >= 8) {
        cap = blank();
        const put = (x, y, ch) => {
            x = Math.round(x);
            y = Math.round(y);
            if (y >= 0 && y < h && x >= 0 && x < w) {
                cap[y][x] = ch;
                capTop = capTop === null ? y : Math.min(capTop, y);
            }
        };
        CAP_PAINTERS[capName]({
            put, ocx, ocy, o, size, def, look, tones, top, ox, kind, rnd: mulberry32(capSeed),
            flames: (tongues) => flames(tongues),
            smoke: (x, y, height, opts) => {
                burning = true;
                fireFrames.forEach((g, f) => paintSmoke(g, x, y, height, f, opts));
            },
        });
    }

    let auraFrames = null;
    if (st >= 10) {
        // A dithered ring of magic light around the silhouette, pulsing over three frames; motes drift at 11.
        const solid = (x, y) => [body, rim, cap].some((g) => g && (g[y]?.[x] ?? '.') !== '.');
        const R = lit.aura + (st >= 11 ? 1 : 0);
        const dist = blank().map((row) => row.map(() => Infinity));
        for (let y = 0; y < h; y++) {
            for (let x = 0; x < w; x++) {
                if (!solid(x, y)) continue;
                for (let dy = -R; dy <= R; dy++) {
                    for (let dx = -R; dx <= R; dx++) {
                        const d = Math.hypot(dx, dy);
                        if (d <= R + 0.5 && dist[y + dy]?.[x + dx] > d) dist[y + dy][x + dx] = d;
                    }
                }
            }
        }
        const motes = mulberry32((seed * 7 + st) >>> 0);
        auraFrames = [0, 1, 2].map((f) => {
            const g = blank();
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    const d = dist[y][x];
                    if (d === Infinity || solid(x, y)) continue;
                    const density = (0.8 - (d - 1) / (R + 0.5)) * (f === 1 ? 1.1 : f === 2 ? 0.85 : 0.95);
                    if (BAYER[(y + f) & 3][(x + 2 * f) & 3] / 16 < density) g[y][x] = d <= 1.5 ? 'l' : 'o';
                }
            }
            if (st >= 11) {
                for (let i = 0; i < 6; i++) {
                    const x = Math.floor(motes() * w);
                    const y = Math.floor(motes() * (h - oy * 0.5) + oy * 0.5);
                    if (!solid(x, y) && g[y]?.[x] !== undefined) g[y][x] = i % 3 ? 's' : 'w';
                }
            }
            return g;
        });
    }

    if (big) {
        emit.cut = [emit.cut[0] + ox, emit.cut[1] + oy];
        emit.top = [emit.top[0] + ox, emit.top[1] + oy];
    }
    emit.light = light;
    if (cap) emit.cap = [Math.round(ocx), capTop];
    return {
        grids: { back, body, rim, glow: glowFrames[1], stem: cap },
        glowFrames,
        fireFrames: burning ? fireFrames : null,
        auraFrames,
        cap: cap ? capName : null,
    };
}

// Cap painters: put(x, y, ch) in sprite coords onto the stem layer (PUMPKIN_LEGEND chars plus the skin), ocx/ocy is
// the centre of the top cut, flames/smoke paint the animated fire layer.
const CAP_PAINTERS = {
    // The pumpkin's own top put back a pixel high, so the cut lip and the light under it show, with the stem chopped
    // to a stump, its pale cut face up.
    lid({ put, o, def, look, tones, top, ox }) {
        const lift = 1;
        const inLid = (x, y) => tones[y]?.[x] !== undefined && tones[y][x] !== '.'
            && (y < o.top || ((x - o.cx) / o.rx) ** 2 + ((y - o.cy) / o.ry) ** 2 <= 1);
        for (let y = 0; y <= Math.ceil(o.cy + o.ry); y++) {
            for (let x = 0; x < def.w; x++) {
                if (!inLid(x, y)) continue;
                let ch = tones[y][x];
                if (!inLid(x, y + 1) || !inLid(x + 1, y)) ch = 'O';
                else if (!inLid(x, y - 1) || !inLid(x - 1, y)) ch = x < def.w * 0.6 ? 'D' : 'O';
                put(x + ox, top + y - lift, ch);
            }
        }
        const n = Math.min(3, look.stem.length);
        const stemX = def.stemX + look.stemShift + ox;
        look.stem.slice(-n).forEach((row, dy) => [...row].forEach((ch, dx) => {
            if (ch !== '.') put(stemX + dx, top - lift - (n - 1 - dy), dy === 0 ? 'c' : ch);
        }));
    },

    // A witch hat: the brim rests on the cut, the cone bends over at the tip; a crimson band with a gold buckle.
    witch({ put, ocx, ocy, o, size, rnd }) {
        const lean = rnd() < 0.5 ? -1 : 1;
        const H = { l: 17, m: 14, s: 10 }[size];
        const brx = o.rx + (size === 's' ? 2 : 3);
        const bry = Math.max(1.6, o.ry * 0.62);
        const by = ocy - o.ry * 0.3;
        const brim = (front) => {
            for (let y = Math.floor(by - bry); y <= Math.ceil(by + bry); y++) {
                for (let x = Math.floor(ocx - brx); x <= Math.ceil(ocx + brx); x++) {
                    const e = ((x - ocx) / brx) ** 2 + ((y - by) / bry) ** 2;
                    if (e > 1 || (y >= by) !== front) continue;
                    const edge = ((x - ocx) / brx) ** 2 + ((y + 1 - by) / bry) ** 2 > 1;
                    put(x, y, front ? (edge ? 'O' : 'n') : 'A');
                }
            }
        };
        brim(false);
        const baseHW = o.rx * 0.58;
        for (let k = 0; k <= H; k++) {
            const t = k / H;
            const cx = ocx + lean * Math.max(0, t - 0.5) ** 2 * H * 1.1;
            const hw = baseHW * (1 - t) ** 0.85 + 0.45;
            const a = Math.round(cx - hw);
            const b = Math.round(cx + hw);
            for (let x = a; x <= b; x++) {
                const rel = (x - a) / Math.max(1, b - a);
                put(x, by - 1 - k, x === b && b > a ? 'O' : rel < 0.3 ? 'a' : rel > 0.75 ? 'n' : 'A');
            }
        }
        const bandRows = size === 's' ? 1 : 2;
        const bw = Math.round(baseHW * 0.92);
        for (let r = 0; r < bandRows; r++) {
            for (let x = Math.round(ocx - bw); x <= Math.round(ocx + bw); x++) put(x, by - 1 - r, r === 0 ? 'Q' : 'q');
        }
        put(ocx, by - 1, 'b');
        if (size !== 's') put(ocx, by - 2, 'b');
        brim(true);
    },

    // A hookah: a brass shaft with a bulb rises out of the cut to a tray and a clay bowl with a glowing coal; a green
    // hose curls down one side. Smoke rises from the bowl.
    hookah({ put, ocx, ocy, size, rnd, flames, smoke }) {
        const side = rnd() < 0.5 ? -1 : 1;
        const shaft = { l: 13, m: 10, s: 7 }[size];
        const thick = size === 's' ? 1 : 2;
        const x = Math.round(ocx) - (thick - 1);
        const y0 = Math.round(ocy);
        for (let k = 0; k <= shaft; k++) {
            for (let i = 0; i < thick; i++) put(x + i, y0 - k, k % 5 === 4 ? 'K' : i === 0 ? 'p' : 'P');
        }
        // The bulb a third of the way up, and the hose leaving it.
        const bulbY = y0 - Math.round(shaft * 0.3);
        const br = size === 'l' ? 2.6 : size === 'm' ? 2.1 : 1.5;
        const mid = x + (thick - 1) / 2;
        for (let y = Math.floor(bulbY - br); y <= bulbY + br; y++) {
            for (let xx = Math.floor(mid - br); xx <= mid + br; xx++) {
                const d = Math.hypot(xx - mid, y - bulbY);
                if (d <= br) put(xx, y, d > br - 1 && xx > mid ? 'K' : xx < mid ? 'p' : 'P');
            }
        }
        const hoseLen = { l: 12, m: 9, s: 6 }[size];
        let hx = Math.round(mid + side * (br + 1));
        let hy = bulbY;
        for (let k = 0; k < hoseLen; k++) {
            put(hx, hy, 'h');
            put(hx, hy - 1, 'H');
            if (k / hoseLen < 0.45 || k % 2 === 0) hx += side;
            else hy += 1;
        }
        put(hx, hy, 'p');
        put(hx, hy + 1, 'p');
        // Tray and bowl.
        const ty = y0 - shaft - 1;
        const tw = { l: 4, m: 3, s: 2 }[size];
        for (let xx = x - tw; xx <= x + thick - 1 + tw; xx++) put(xx, ty, xx === x + thick - 1 + tw ? 'G' : 'g');
        const bowlH = size === 's' ? 2 : 3;
        for (let r = 1; r <= bowlH; r++) {
            const half = tw - 1 - (r === 1 ? 1 : 0);
            for (let xx = x - half; xx <= x + thick - 1 + half; xx++) put(xx, ty - r, xx <= mid ? 'm' : 'M');
        }
        const coal = ty - bowlH;
        flames([{ x: mid, y: coal, len: size === 's' ? 2 : 3, wid: thick + 1, sway: 0.3 }]);
        smoke(mid, coal - 3, { l: 12, m: 10, s: 7 }[size], { width: size === 's' ? 1.6 : 2.2, drift: side });
    },

    // A gold crown of spikes on the cut, crimson gems on the band, a small flame on every spike.
    crown({ put, ocx, ocy, o, size, flames }) {
        const W = Math.round(o.rx * 1.7);
        const x0 = Math.round(ocx - W / 2);
        const yb = Math.round(ocy);
        const n = size === 'l' ? 5 : size === 'm' ? 4 : 3;
        const hs = { l: 5, m: 4, s: 3 }[size];
        const tips = [];
        for (let i = 0; i < n; i++) {
            const cx = x0 + Math.round((W * (i + 0.5)) / n);
            tips.push(cx);
            for (let k = 0; k < hs; k++) {
                const half = Math.max(0, Math.round((1 - k / hs) * (size === 's' ? 1.4 : 2.2)));
                for (let xx = cx - half; xx <= cx + half; xx++) put(xx, yb - 2 - k, xx > cx ? 'j' : 'b');
            }
        }
        for (let xx = x0; xx <= x0 + W; xx++) {
            put(xx, yb - 1, xx === x0 + W ? 'j' : 'b');
            put(xx, yb, (xx - x0) % 4 === 2 && size !== 's' ? 'q' : 'j');
        }
        flames(tips.map((cx, i) => ({ x: cx, y: yb - 1 - hs, len: hs + 1, wid: size === 's' ? 2 : 3, phase: i * 1.7, sway: 0.5 })));
    },

    // Cat ears grown from the pumpkin's own skin on both sides of the cut, leaning out, pink inside; whiskers scored
    // into the cheeks beside the mouth make it read as a cat and not as horns.
    cat({ put, ocx, ocy, o, size, def, look, top, ox, kind }) {
        const H = { l: 10, m: 8, s: 6 }[size];
        const half = { l: 4.8, m: 4, s: 3 }[size];
        const lean = size === 's' ? 1 : 2;
        const yb = Math.round(ocy) + 1;
        for (const side of [-1, 1]) {
            const bx = ocx + side * o.rx * 0.62;
            for (let k = 0; k <= H; k++) {
                const t = k / H;
                const cx = bx + side * lean * t;
                const hw = half * (1 - t);
                const a = Math.round(cx - hw);
                const b = Math.round(cx + hw);
                for (let x = a; x <= b; x++) {
                    let ch = x - cx < -hw * 0.3 ? 'l' : x - cx > hw * 0.35 ? 'd' : 'o';
                    if (k >= 1 && k <= H - 3 && Math.abs(x - cx + 0.3) < hw - 1.9) ch = 'k';
                    // Edges like the body's: lit on the top-left, dark on the right.
                    if (x === b) ch = 'O';
                    else if (x === a || k === H) ch = 'D';
                    put(x, yb - k, ch);
                }
            }
        }
        // Whiskers: two short strokes fanning out from each corner of the mouth, only on solid skin.
        const holes = look.mouth.flatMap((row, y) => [...row].map((ch, x) => (ch === '#' ? [x + def.mouthAt[0] + ox, y + def.mouthAt[1] + top] : null)))
            .filter(Boolean);
        const mx0 = Math.min(...holes.map(([x]) => x));
        const mx1 = Math.max(...holes.map(([x]) => x));
        const my = Math.min(...holes.filter(([x]) => x === mx0 || x === mx1).map(([, y]) => y));
        const len = size === 's' ? 2 : 3;
        for (const [x0, dir] of [[mx0 - 2, -1], [mx1 + 2, 1]]) {
            for (let i = 0; i < len; i++) {
                for (const [dy, rise] of [[0, -1], [2, 1]]) {
                    const x = x0 + dir * i;
                    const y = my + dy + (i === len - 1 ? rise : 0);
                    if (kind[y]?.[x] === 'skin') put(x, y, 'O');
                }
            }
        }
    },
};

// Soft shadows for floating pumpkins, per size: [low, mid, high] — the higher it floats, the smaller and fainter.
// Dithered outline pixels; the scene draws them at the pumpkin's home point with alpha ~0.5.
function floatShadow(rx, ry, density) {
    const g = Array.from({ length: 2 * ry + 1 }, () => Array(2 * rx + 1).fill('.'));
    for (let y = 0; y <= 2 * ry; y++) {
        for (let x = 0; x <= 2 * rx; x++) {
            const d = Math.hypot((x - rx) / (rx + 0.5), (y - ry) / (ry + 0.5));
            if (d <= 1 && BAYER[y & 3][x & 3] / 16 < density * (1.25 - d)) g[y][x] = 'O';
        }
    }
    return layerSprite(g, { '.': null, O: 'outline' }, { x: rx, y: ry });
}

export const PUMPKIN_SHADOW = {
    l: [floatShadow(17, 3, 1), floatShadow(15, 3, 0.8), floatShadow(13, 2, 0.6)],
    m: [floatShadow(13, 2, 1), floatShadow(12, 2, 0.8), floatShadow(10, 2, 0.6)],
    s: [floatShadow(10, 2, 1), floatShadow(9, 2, 0.8), floatShadow(7, 1, 0.6)],
};

const pumpkinCache = new Map();

/** The day look as one sprite: back, body, rim and stem flattened. Without a seed it is seed 0. */
export function pumpkinSprite(stage, size, seed = 0) {
    const key = `${size}:${stage}:${seed >>> 0}`;
    if (pumpkinCache.has(key)) {
        return pumpkinCache.get(key);
    }
    const { w, h, anchor, emit, layers } = pumpkinLayers(stage, size, seed);
    const rows = [];
    for (let y = 0; y < h; y++) {
        let row = '';
        for (let x = 0; x < w; x++) {
            let ch = '.';
            for (const layer of [layers.back, layers.body, layers.rim, layers.stem]) {
                const c = layer?.rows[y][x];
                if (c && c !== '.') ch = c;
            }
            row += ch;
        }
        rows.push(row);
    }
    const sprite = { w, h, rows, legend: layers.body.legend, anchor, emit };
    pumpkinCache.set(key, sprite);
    return sprite;
}

// The cursed world's pumpkins hold beer: a mug with a handle, a foam head over the rim and an amber body with a
// highlight stripe, drawn with the handle on the left (towards the body when the mug is on the right). Lit chars go to
// the `lit` layer (glass, arms), beer chars to the `beer` layer, which the scene bakes keeping its hue at night.
// grip — the handle pixel the mitten closes over; reach — how far the hand sits out from the side in the hold pose.
const ARMS_LIT = { '.': null, O: 'outline', t: 'cream', T: 'hayLight', G: 'grayLight' };
const ARMS_BEER = { '.': null, w: 'white', c: 'cream', y: 'pumpkinShine', Y: 'pumpkinLight', o: 'pumpkin' };
const ARMS = {
    l: {
        reach: 3,
        grip: [0, 6],
        glass: [
            '....cww..',
            '..cwwwwc.',
            '..wwwwwww',
            '..GwwwwGw',
            'GGGyYYoG.',
            'G.GyYYoG.',
            'G.GyYYoG.',
            'G.GyYYoG.',
            'GGGyYYoG.',
            '..GyYYoG.',
            '..GGGGGG.',
        ],
    },
    m: {
        reach: 2,
        grip: [0, 5],
        glass: [
            '...cww..',
            '..cwwwwc',
            '..wwwwww',
            'GGGyYoG.',
            'G.GyYoG.',
            'G.GyYoG.',
            'GGGyYoG.',
            '..GyYoG.',
            '..GGGGG.',
        ],
    },
    s: {
        reach: 2,
        grip: [0, 4],
        glass: [
            '..cww.',
            '.cwwwc',
            '.wwwww',
            'GGyYoG',
            'GGyYoG',
            '.GyYoG',
            '.GGGGG',
        ],
    },
};
// Arm paths from the shoulder, [dx, dy] with dx away from the body; the last point is the hand. `rise` lifts the
// raised glass clear above the pumpkin's top.
const ARM_POSES = {
    hold: (r) => [[0, 0], [1, 0], ...Array.from({ length: r - 1 }, (_, i) => [2 + i, -1 - (i >> 1)])],
    half: (r, rise) => [[0, 0], [1, -1], ...Array.from({ length: Math.round(rise / 2) }, (_, i) => [2 + (i >> 2), -2 - i])],
    up: (r, rise) => [[0, 0], [1, -1], [2, -2], ...Array.from({ length: rise - 2 }, (_, i) => [2 + (i >> 2), -3 - i])],
};
const line = ([x0, y0], [x1, y1]) => {
    const n = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0), 1);
    return Array.from({ length: n }, (_, i) => [Math.round(x0 + ((x1 - x0) * (i + 1)) / n), Math.round(y0 + ((y1 - y0) * (i + 1)) / n)]);
};
// Out of the shoulder, bent at the elbow most of the way out and a little down, then to the hand.
const reachTo = (hand) => {
    const elbow = [Math.max(1, Math.round(hand[0] * 0.6)), Math.round(hand[1] * 0.3)];
    return [[0, 0], ...line([0, 0], elbow), ...line(elbow, hand)];
};
const between = (a, b) => [Math.round((a[0] + b[0]) / 2), Math.round((a[1] + b[1]) / 2)];
// The hand in the top cut, and a new mug pulled out over the top; `top` — the top cut's [dx, dy].
const DIP = ({ top }) => [[0, 0], ...line([0, 0], [2, -2]), ...line([2, -2], [2, top[1]]), ...line([2, top[1]], top)];
const PULL = ({ top }) => [[0, 0], ...line([0, 0], [2, -2]), ...line([2, -2], [2, top[1] - 3]), ...line([2, top[1] - 3], [Math.round(top[0] / 2), top[1] - 5])];
const FREE_ARM = (r) => [[0, 0], [1, 1], ...Array.from({ length: r - 1 }, (_, i) => [2 + (i >> 1), 2 + i])];
const armsCache = new Map();

/** The frames of pumpkinArms by name. */
export const ARMS_FRAME = { hold: 0, half: 1, up: 2, lower: 3, offer: 4, letGo: 5, lowerEmpty: 6, holdEmpty: 7, dip: 8, pull: 9, reach: 10, strike: 11, clinkLower: 12 };

// A walker comes up to a pumpkin's mug and stands STAND_GAP art px past its outer edge, a pixel in front of the
// pumpkin's foot, so it is drawn over the arm held out to it.
const STAND_GAP = 15;
const STAND_DY = 1;

/**
 * Arms with a beer mug for a pumpkin of the cursed world: { anchor, frames, stand }. `frames` by ARMS_FRAME, each
 * { lit, beer } sprites of one w×h and that anchor at the pumpkin's foot, like pumpkinLayers; they reach past the
 * pumpkin's own grid, so the scene places them by their own anchor:
 * - hold, half, up — the mug at rest, halfway up, raised over the top;
 * - the hand-over to a walker: lower and offer (held out over the walker's hand), letGo (the walker has it),
 *   lowerEmpty and holdEmpty on the way back, dip (the hand in the top cut), pull (a new mug out over the top);
 * - the clink: clinkLower, reach (a pixel short of the walker's glass), strike (touching it).
 * There is one mug in every frame at most. `stand` — where the walker stands for both: `x` art px from the foot
 * toward the mug, `y` below the foot. `meet` places the walker's glass: `mug` — its {near, far, top, bottom} in art px
 * from the walker's feet in the clink pose (forward, up negative), a pixel further forward at the strike; `lean` — how
 * far the walker leans in at the strike; `lift` — how high the pumpkin floats meanwhile. The mug's side follows the seed.
 */
export function pumpkinArms(stage, size, seed = 0, side = seed & 1 ? -1 : 1, meet = {}) {
    const sz = ARMS[size] ? size : 'm';
    const lift = meet.lift ?? 0;
    const lean = meet.lean ?? 0;
    const mug = meet.mug ?? { near: 6, far: 10, top: -12, bottom: -7 };
    const key = `${sz}:${Math.max(0, Math.min(PUMPKIN_STAGES, stage | 0))}:${seed >>> 0}:${side < 0 ? -1 : 1}:${lift}:${lean}:${mug.near},${mug.far},${mug.top},${mug.bottom}`;
    if (armsCache.has(key)) return armsCache.get(key);
    const L = pumpkinLayers(stage, sz, seed);
    const rows = L.layers.body.rows.map((row) => [...row].map((ch, x) => (ch !== '.' ? x : -1)).filter((x) => x >= 0));
    const solid = rows.map((xs, y) => (xs.length ? y : -1)).filter((y) => y >= 0);
    const y0 = solid[0];
    const shoulder = Math.round(y0 + (solid[solid.length - 1] - y0) * 0.58);
    const xl = Math.min(...rows[shoulder]);
    const xr = Math.max(...rows[shoulder]);
    const { reach, grip, glass: drawn } = ARMS[sz];
    const dir = side < 0 ? -1 : 1;
    // Mirrored for a mug on the left; the highlight stays on the lit left side.
    const glass = dir > 0 ? drawn : drawn.map((row) => [...row].reverse().map((ch) => ({ y: 'o', o: 'y' }[ch] ?? ch)).join(''));
    const gw = glass[0].length;
    const gh = glass.length;
    const rise = shoulder - y0 + gh - grip[1] + 1;
    const sx = dir > 0 ? xr + 1 : xl - 1;
    const gripX = dir > 0 ? grip[0] : gw - 1 - grip[0];
    // A hand [dx, dy] from the shoulder ↔ the top-left corner of the glass it holds by the handle, in grid px.
    const glassOf = ([dx, dy]) => [sx + dir * dx + dir - gripX, shoulder + dy - grip[1]];
    const handOf = ([gx, gy]) => [(gx - dir + gripX - sx) * dir, gy + grip[1] - shoulder];
    // The hand whose glass has its outer edge `edge` art px from the foot, level with the walker's glass.
    const ax = L.anchor.x;
    const glassY = Math.round(L.anchor.y + lift + STAND_DY + (mug.top + mug.bottom) / 2 - (gh - 1) / 2);
    const edgeAt = (edge) => handOf([dir > 0 ? ax + edge - gw + 1 : ax - edge, glassY]);
    const hold = ARM_POSES.hold(reach);
    const holdHand = hold[hold.length - 1];
    const [hgx] = glassOf(holdHand);
    const stand = (dir > 0 ? hgx + gw - 1 - ax : ax - hgx) + STAND_GAP;
    // Offered: the glass over the walker's, where its reaching hand closes on it. The clink: the glasses touch at the
    // strike, the walker's a pixel further forward and leaning in.
    const offer = edgeAt(stand - mug.near + Math.floor((gw - (mug.far - mug.near + 1)) / 2));
    const strike = edgeAt(stand - mug.far - 2 - lean);
    const short = edgeAt(stand - mug.far - 3 - lean);
    const ctx = { top: [Math.round(((xl + xr) / 2 - sx) * dir), y0 - shoulder - 1] };
    const poses = [
        { arm: hold, glass: true },
        { arm: ARM_POSES.half(reach, rise), glass: true },
        { arm: ARM_POSES.up(reach, rise), glass: true },
        { arm: reachTo(between(holdHand, offer)), glass: true },
        { arm: reachTo(offer), glass: true },
        { arm: reachTo(offer), glass: false },
        { arm: reachTo(between(holdHand, offer)), glass: false },
        { arm: hold, glass: false },
        { arm: DIP(ctx), glass: false },
        { arm: PULL(ctx), glass: true },
        { arm: reachTo(short), glass: true },
        { arm: reachTo(strike), glass: true },
        { arm: reachTo(between(holdHand, short)), glass: true },
    ];
    const frames = poses.map((pose) => {
        const px = new Map();
        const put = (x, y, ch) => px.set(`${x},${y}`, { x, y, ch });
        // 2 px thick: a light core, the dark outline under it and on its right.
        const stroke = (x0, d, pts) => {
            const at = pts.map(([dx, dy]) => [x0 + d * dx, shoulder + dy]);
            at.forEach(([x, y]) => {
                put(x, y + 1, 'O');
                put(x + 1, y, 'O');
            });
            at.forEach(([x, y]) => put(x, y, 't'));
        };
        const mitten = (x, y, d) => {
            [[0, -1], [d, -1], [0, 0], [d, 0]].forEach(([dx, dy]) => put(x + dx, y + dy, 'T'));
            put(x, y + 1, 'O');
            put(x + d, y + 1, 'O');
        };
        stroke(sx, dir, pose.arm);
        const hand = pose.arm[pose.arm.length - 1];
        if (pose.glass) {
            const [gx, gy] = glassOf(hand);
            glass.forEach((row, dy) => [...row].forEach((ch, dx) => ch !== '.' && put(gx + dx, gy + dy, ch)));
        }
        mitten(sx + dir * hand[0], shoulder + hand[1], dir);
        const fx = dir > 0 ? xl - 1 : xr + 1;
        const free = FREE_ARM(Math.max(2, reach - 1));
        stroke(fx, -dir, free);
        const [ex, ey] = free[free.length - 1];
        mitten(fx - dir * ex, shoulder + ey + 1, -dir);
        return px;
    });
    const all = frames.flatMap((px) => [...px.values()]);
    const minX = Math.min(0, ...all.map((p) => p.x));
    const maxX = Math.max(L.w - 1, ...all.map((p) => p.x));
    const minY = Math.min(0, ...all.map((p) => p.y));
    const maxY = Math.max(L.h - 1, ...all.map((p) => p.y));
    const anchor = { x: L.anchor.x - minX, y: L.anchor.y - minY };
    const layer = (px, legend) => {
        const grid = Array.from({ length: maxY - minY + 1 }, () => Array(maxX - minX + 1).fill('.'));
        px.forEach(({ x, y, ch }) => {
            if (ch in legend) grid[y - minY][x - minX] = ch;
        });
        return layerSprite(grid, legend, anchor);
    };
    const result = { anchor, stand: { x: stand, y: STAND_DY }, frames: frames.map((px) => ({ lit: layer(px, ARMS_LIT), beer: layer(px, ARMS_BEER) })) };
    armsCache.set(key, result);
    return result;
}

export function silhouette(sprite, key = 'white') {
    const legend = {};
    for (const [ch, k] of Object.entries(sprite.legend)) {
        legend[ch] = k === null ? null : key;
    }
    return { ...sprite, legend };
}

function sprite(rows, legend, anchor) {
    return {
        w: rows[0].length,
        h: rows.length,
        rows,
        legend: { '.': null, ...legend },
        anchor: anchor ?? { x: rows[0].length >> 1, y: rows.length >> 1 },
    };
}

const BITS = {
    l: 'pumpkinLight',
    o: 'pumpkin',
    d: 'pumpkinDark',
    D: 'pumpkinDeep',
    f: 'flesh',
    F: 'fleshDark',
    c: 'cream',
    e: 'seedShade',
    g: 'grayLight',
    G: 'gray',
    w: 'white',
    s: 'pumpkinShine',
    W: 'flameCore',
    r: 'ember',
    a: 'ash',
    A: 'ashLight',
    m: 'magicCore',
    h: 'eerieHot',
    n: 'eyeGreen',
    N: 'eerieDark',
    v: 'violetHot',
    V: 'eyeViolet',
    u: 'violetGlow',
};

export const PARTICLES = {
    chunk: [
        sprite(['lo', 'fd'], BITS),
        sprite(['.lo', 'loD', 'fF.'], BITS),
        sprite(['lod', 'ffd'], BITS),
        sprite(['l.', 'od', 'fd'], BITS),
    ],
    seed: [
        sprite(['cc.', '.ce'], BITS),
        sprite(['c.', 'cc', '.e'], BITS),
        sprite(['.c', 'ce'], BITS),
    ],
    pulp: [
        sprite(['F.', 'fF', '.F', '.f'], BITS),
        sprite(['fF..', '.FFf'], BITS),
        sprite(['.F', 'F.', 'fF'], BITS),
    ],
    spark: [
        sprite(['.g.', 'gwg', '.g.'], BITS),
        sprite(['g.', '.G'], BITS),
        sprite(['G', 'g'], BITS),
    ],
    // Scene 1, lighting the pumpkins: hot embers that cool from white through yellow to orange.
    ember: [
        sprite(['.s.', 'sWs', '.l.'], BITS),
        sprite(['W', 's'], BITS),
        sprite(['s.', '.l'], BITS),
        sprite(['l', 'd'], BITS),
    ],
    // Flecks of burnt skin and ash drifting off the burning pumpkins and the bonfire.
    cinder: [
        sprite(['rd', '.a'], BITS),
        sprite(['A', 'a'], BITS),
        sprite(['a.', 'Aa'], BITS),
    ],
    // Scene 2, the spell: green motes, a four-point twinkle and dots.
    magic: [
        sprite(['.n.', 'nmn', '.n.'], BITS),
        sprite(['h', 'N'], BITS),
        sprite(['..h..', '..n..', 'hnmnh', '..n..', '..h..'], BITS),
        sprite(['m.', '.n'], BITS),
    ],
    // Scene 3, the violet energy of the blood moon.
    hex: [
        sprite(['.V.', 'VvV', '.V.'], BITS),
        sprite(['v', 'u'], BITS),
        sprite(['..V..', '..u..', 'VuvuV', '..u..', '..V..'], BITS),
    ],
};

const DECOR_LEGEND = {
    O: 'outline',
    S: 'sun',
    s: 'cream',
    k: 'pumpkinLight',
    w: 'white',
    b: 'skyLight',
    L: 'leafDark',
    l: 'leaf',
    i: 'leafLight',
    h: 'leafShine',
    T: 'stemDark',
    t: 'stem',
    y: 'stemLight',
    M: 'soilDark',
    m: 'soil',
    n: 'soilLight',
    c: 'cream',
    Q: 'barkDark',
    q: 'bark',
    p: 'barkLight',
    a: 'hazeLight',
    u: 'haze',
    U: 'hazeDark',
    H: 'hayDark',
    Y: 'hay',
    j: 'hayLight',
    g: 'grayLight',
    G: 'gray',
};

// Far hills with a forest edge in front, in haze tones. Every wave and crown wraps at HILLS_W, so the strip tiles.
const HILLS_W = 80;
const HILLS_H = 24;
const HILL_CROWNS = [[3, 4], [10, 3], [16, 4], [24, 5], [31, 3], [37, 4], [45, 4], [52, 3], [58, 5], [66, 4], [73, 3]];

function hillsSprite() {
    const wave = (x, k, phase) => Math.sin((2 * Math.PI * k * x) / HILLS_W + phase);
    const rows = [];
    for (let y = 0; y < HILLS_H; y++) {
        let row = '';
        for (let x = 0; x < HILLS_W; x++) {
            const far = Math.round(7 + 3 * wave(x, 1, 0) + 1.5 * wave(x, 3, 1));
            let crown = 0;
            let lit = false;
            for (const [cx, r] of HILL_CROWNS) {
                const dx = ((x - cx + HILLS_W * 1.5) % HILLS_W) - HILLS_W / 2;
                if (Math.abs(dx) <= r) {
                    const h = Math.sqrt(r * r - dx * dx);
                    if (h > crown) {
                        crown = h;
                        lit = dx > r * 0.3;
                    }
                }
            }
            const near = Math.round(15 + 1.5 * wave(x, 2, 2) - crown);
            let ch = '.';
            if (y >= near) {
                // The shaded flank of each crown keeps neighbouring crowns apart.
                ch = y >= HILLS_H - 3 || (lit && y < near + 3) ? 'U' : 'u';
            } else if (y >= far) {
                ch = 'a';
            }
            row += ch;
        }
        rows.push(row);
    }
    return sprite(rows, DECOR_LEGEND, { x: 0, y: HILLS_H });
}

// The tree is generated once at load: leaf clusters as scalloped discs lit from the top-left, a tapering trunk and
// one branch reaching right from under the crown. Deterministic, so every page draws the same tree.
const TREE_W = 104;
const TREE_H = 104;
const TRUNK_X = 34;
const BRANCH = { x0: 37, x1: 97, y0: 66, rise: 11 };
const PERCH_X = 93;

// [cx, cy, r], drawn back to front: lower clusters sit behind the ones above them.
const TREE_CLUSTERS = [
    [8, 38, 7], [60, 36, 7], [22, 48, 9], [46, 48, 9], [34, 46, 12],
    [20, 34, 13], [48, 34, 13], [14, 24, 10], [54, 22, 10], [34, 22, 14],
    [26, 10, 9], [42, 10, 9],
];

function branchTop(x) {
    const s = (x - BRANCH.x0) / (BRANCH.x1 - BRANCH.x0);
    // Eases out, so the last stretch under the perch is level.
    return Math.round(BRANCH.y0 - BRANCH.rise * (1 - (1 - s) ** 2));
}

function treeSprite() {
    const grid = Array.from({ length: TREE_H }, () => Array(TREE_W).fill('.'));
    const set = (x, y, ch) => {
        if (x >= 0 && x < TREE_W && y >= 0 && y < TREE_H) {
            grid[y][x] = ch;
        }
    };

    // Trunk with a root flare; the crown covers its top.
    for (let y = 40; y < TREE_H; y++) {
        const flare = Math.max(0, y - (TREE_H - 6));
        const x0 = TRUNK_X - 5 - flare - (flare > 3 ? 2 : 0);
        const x1 = TRUNK_X + 4 + flare + (flare > 4 ? 3 : 0);
        for (let x = x0; x <= x1; x++) {
            const i = x - x0;
            let ch = i === 0 ? 'Q' : i <= 2 ? 'p' : x >= x1 - 1 ? 'Q' : 'q';
            if (x === x1) ch = 'O';
            // Bark grooves: short dark streaks staggered down the trunk.
            if (ch === 'q' && (y + x * 5) % 11 < 3 && x === TRUNK_X + ((y >> 3) % 2)) ch = 'Q';
            set(x, y, ch);
        }
        if (y === TREE_H - 1) {
            for (let x = x0; x <= x1; x++) set(x, y, 'O');
        }
    }

    // Branch: thick where it leaves the trunk, tapering to 2 px; light on top, outline underneath.
    for (let x = BRANCH.x0; x <= BRANCH.x1; x++) {
        const t = (x - BRANCH.x0) / (BRANCH.x1 - BRANCH.x0);
        const top = branchTop(x);
        const th = Math.max(2, Math.round(6 - 4 * t ** 0.7)) + Math.max(0, Math.round((0.1 - t) * 30));
        for (let k = 0; k < th; k++) {
            set(x, top + k, k === 0 || (k === 1 && th >= 5) ? 'p' : k === th - 1 ? 'Q' : 'q');
        }
        set(x, top + th, 'O');
    }
    const tip = branchTop(BRANCH.x1);
    set(BRANCH.x1 + 1, tip, 'Q');
    set(BRANCH.x1 + 1, tip + 1, 'O');
    // One short knot-twig and two small leaves: at the twig's end and at the tip.
    const twig = branchTop(72);
    for (let k = 1; k <= 4; k++) {
        set(72 + k, twig - k, 'p');
        set(72 + k, twig - k + 1, 'Q');
    }
    const leaf = (x, y) => {
        set(x, y, 'i');
        set(x + 1, y, 'l');
        set(x, y + 1, 'l');
        set(x + 1, y + 1, 'L');
    };
    leaf(77, twig - 6);
    leaf(BRANCH.x1 + 2, tip - 1);

    const owner = Array.from({ length: TREE_H }, () => Array(TREE_W).fill(-1));
    TREE_CLUSTERS.forEach(([cx, cy, r], id) => {
        for (let y = cy - r - 2; y <= cy + r + 2; y++) {
            for (let x = cx - r - 2; x <= cx + r + 2; x++) {
                const dx = (x - cx) / r;
                const dy = (y - cy) / r;
                const edge = 1 + (0.08 + (id % 3) * 0.04) * Math.sin(Math.atan2(dy, dx) * (6 + (id % 3)) + cx);
                if (dx * dx + dy * dy > edge * edge || y < 0 || y >= TREE_H || x < 0 || x >= TREE_W) continue;
                // Lower clusters sit in the crown's own shadow.
                const v = -(0.45 * dx + 0.6 * dy) + 0.45 * (1 - Math.hypot(dx, dy)) - 0.2 - (cy - 28) / 40;
                set(x, y, v > 0.62 ? 'h' : v > 0.12 ? 'i' : v > -0.5 ? 'l' : 'L');
                owner[y][x] = id;
            }
        }
    });
    // A cluster in front casts a 1 px shadow onto the one behind it, so the masses stay apart.
    const out = grid.map((r) => r.slice());
    for (let y = 1; y < TREE_H; y++) {
        for (let x = 0; x < TREE_W; x++) {
            const a = owner[y][x];
            const above = owner[y - 1][x];
            if (a >= 0 && above > a) out[y][x] = 'L';
        }
    }
    // Crown outline only on the shadow side.
    for (let y = 0; y < TREE_H; y++) {
        for (let x = 0; x < TREE_W; x++) {
            if (owner[y][x] < 0) continue;
            const below = y + 1 < TREE_H ? grid[y + 1][x] : '.';
            const right = x + 1 < TREE_W ? grid[y][x + 1] : '.';
            if (below === '.' || right === '.') out[y][x] = 'O';
        }
    }

    const rows = out.map((r) => r.join(''));
    return {
        ...sprite(rows, DECOR_LEGEND, { x: TRUNK_X, y: TREE_H }),
        perch: [PERCH_X, branchTop(PERCH_X)],
    };
}

// A palmate pumpkin leaf: five tapering lobes fanned out from the stalk, light veins along each lobe, a dark lower
// edge so overlapping leaves stay apart. The anchor is the stalk's end.
function leafSprite(w, h, tilt = 0) {
    const grid = Array.from({ length: h }, () => Array(w).fill('.'));
    const bx = (w - 1) / 2;
    const by = h - 2.5;
    const r = h - 2.5;
    const lobes = [[-70, 0.66], [-36, 0.9], [0, 0.98], [36, 0.9], [70, 0.66]].map(([deg, len]) => {
        const a = ((deg + tilt) * Math.PI) / 180;
        return { dx: Math.sin(a) * len * r, dy: -Math.cos(a) * len * r, len };
    });
    const sx = Math.min(1, (w / 2 - 0.5) / Math.max(...lobes.map((l) => Math.abs(l.dx))));
    lobes.forEach((l) => { l.dx *= sx; });
    const inLobe = (x, y, l) => {
        const px = x - bx;
        const py = y - by;
        const t = Math.max(0, Math.min(1, (px * l.dx + py * l.dy) / (l.dx * l.dx + l.dy * l.dy)));
        const d = Math.hypot(px - l.dx * t, py - l.dy * t);
        return t > 0.08 && d <= 0.42 * r * l.len * (1 - 0.6 * t) + 0.3;
    };
    const inside = (x, y) => x >= 0 && y >= 0 && x < w && y < by + 1
        && (lobes.some((l) => inLobe(x, y, l)) || Math.hypot((x - bx) / sx, y - (by - 0.42 * r)) <= 0.46 * r);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (!inside(x, y)) continue;
            const v = -(0.55 * (x - bx) / (w / 2) + 0.65 * (y - (by - 0.5 * r)) / (0.5 * r));
            grid[y][x] = !inside(x, y + 1) || !inside(x + 1, y) ? 'L' : v > 0.25 ? 'i' : 'l';
        }
    }
    for (const l of lobes) {
        const steps = Math.ceil(Math.hypot(l.dx, l.dy));
        for (let k = 1; k < steps * 0.8; k++) {
            const x = Math.round(bx + (l.dx * k) / steps);
            const y = Math.round(by + (l.dy * k) / steps);
            if (grid[y]?.[x] === 'l') grid[y][x] = 'i';
            else if (grid[y]?.[x] === 'i') grid[y][x] = 'h';
        }
    }
    const stalk = Math.round(bx);
    for (let y = Math.round(by); y < h; y++) grid[y][stalk] = 'L';
    return sprite(grid.map((row) => row.join('')), DECOR_LEGEND, { x: stalk, y: h - 1 });
}

// A wattle fence section: a post on the left, thin stakes, and rods woven over and under them in bands. Sections
// tile along x; the band phase differs between the two variants so a row of them does not repeat too evenly.
const FENCE_W = 16;
const FENCE_H = 16;

function fenceSection(phase) {
    const grid = Array.from({ length: FENCE_H }, () => Array(FENCE_W).fill('.'));
    const stakes = [6, 10, 14];
    // Rods stacked with no gaps, like real wattle; the variants differ in where the weave starts.
    const bands = phase ? [4, 6, 8, 10, 12] : [3, 5, 7, 9, 11];
    for (const x of stakes) {
        for (let y = 2; y < FENCE_H; y++) grid[y][x] = y === 2 ? 'p' : 'Q';
    }
    bands.forEach((y0, b) => {
        for (let x = 3; x < FENCE_W; x++) {
            const s = stakes.indexOf(x);
            // At every other stake the rod passes behind it.
            if (s >= 0 && (s + b + phase) % 2 === 0) continue;
            grid[y0][x] = 'p';
            grid[y0 + 1][x] = (x + b * 3) % 7 === 0 ? 'Q' : 'q';
        }
    });
    for (let y = 0; y < FENCE_H; y++) {
        grid[y][0] = y === 0 ? '.' : 'p';
        grid[y][1] = 'q';
        grid[y][2] = y === 0 ? '.' : 'O';
    }
    grid[0][1] = 'p';
    return sprite(grid.map((r) => r.join('')), DECOR_LEGEND, { x: FENCE_W >> 1, y: FENCE_H });
}

export const DECOR = {
    tree: treeSprite(),
    // Fence sections tile along x with a step of their width; fenceEnd closes the run on the right.
    fence: [fenceSection(0), fenceSection(1)],
    fenceEnd: sprite([
        '.p.',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
        'pqO',
    ], DECOR_LEGEND, { x: 1, y: 16 }),
    hay: sprite([
        '..HHHHHHHHHOOOOOOO..',
        '.HjjjjQjYYjjjQjjjjO.',
        'HjjjYYQjjjYYjQjjYYjO',
        'HjjjjjQYjjjjjQjjjjYO',
        'HHHHHHQHHHHHHQHHHHHO',
        'HjjYHHQYYYHHYQYYHHYO',
        'HjjYYYQHYYYYYQYYYYHO',
        'HjjYYYQYHHYYYQYYYYYO',
        'HjjYHHQYYYHHYQYYHHYO',
        'HjjYYYQHYYYYYQYYYYHO',
        'HjjYYYQYHHYYYQYYYYYO',
        'HjjYHHQYYYHHYQYYHHYO',
        'HjjYYYQHYYYYYQYYYYHO',
        '.OOOOOOOOOOOOOOOOOO.',
    ], DECOR_LEGEND, { x: 10, y: 14 }),
    can: sprite([
        '..OOOO......',
        '.O....O..OO.',
        'OggggggO.OgO',
        'OgwggGGOOgO.',
        'OgwggGGOgO..',
        'OgggGGGgO...',
        'OgggGGGO....',
        'OggGGGGO....',
        'OGGGGGGO....',
        '.OOOOOO.....',
    ], DECOR_LEGEND, { x: 6, y: 10 }),
    stone: [
        sprite([
            '..OOO.',
            '.OggGO',
            'OgGGGO',
            '.OOOO.',
        ], DECOR_LEGEND, { x: 3, y: 4 }),
        sprite([
            '.OO.',
            'OgGO',
            '.OO.',
        ], DECOR_LEGEND, { x: 2, y: 3 }),
        sprite([
            '..OOOO..',
            '.OggGGO.',
            'OgwgGGGO',
            'OgGGGGGO',
            '.OOOOOO.',
        ], DECOR_LEGEND, { x: 4, y: 5 }),
    ],
    basket: sprite([
        '.HHHHHHOOOOOO.',
        'HjjjjjjjjjjjjO',
        'HjQQQQQQQQQQjO',
        'HjjYYYYYYYYYYO',
        '.OjHYYHHYYHHO.',
        '.OjYHHYYHHYYO.',
        '..OjYYHHYYHO..',
        '..OjHHYYHHYO..',
        '...OjYHHYYO...',
        '...OOOOOOOO...',
    ], DECOR_LEGEND, { x: 7, y: 10 }),
    hills: hillsSprite(),
    farTree: [
        sprite([
            '...aUU...',
            '..aaUUU..',
            '.aaUUUUL.',
            '.aUUUUUL.',
            'aaUUUUULL',
            '.UUUUULL.',
            '.UUULLLL.',
            '..LLLLL..',
            '....L....',
            '....L....',
            '....L....',
            '...LLL...',
        ], DECOR_LEGEND, { x: 4, y: 12 }),
        sprite([
            '...a...',
            '..aaU..',
            '..aUU..',
            '.aaUUL.',
            '..aUL..',
            '.aaUUL.',
            'aaUUULL',
            '.aUUUL.',
            'aaUUULL',
            'aUUUULL',
            '.LLLLL.',
            '...L...',
            '...L...',
            '..LLL..',
        ], DECOR_LEGEND, { x: 3, y: 14 }),
        sprite([
            '..aUU...',
            '.aaUUU..',
            'aaUUUUL.',
            '.UUUULLL',
            '..LLLLL.',
            '...L....',
            '...L....',
            '...L....',
            '..LLL...',
        ], DECOR_LEGEND, { x: 3, y: 9 }),
    ],
    sun: sprite([
        '........................',
        '...........SS...........',
        '...........SS...........',
        '....SS............SS....',
        '....SS............SS....',
        '.........sssSSS.........',
        '........sssSSSSS........',
        '.......sssSSSSSSS.......',
        '......sssSSSSSSSSS......',
        '.....sssSSSSSSSSSSk.....',
        '.....ssSSSSSSSSSSSk.....',
        '.SS..sSSSSSSSSSSSSk..SS.',
        '.SS..SSSSSSSSSSSSSk..SS.',
        '.....SSSSSSSSSSSSkk.....',
        '.....SSSSSSSSSSSkkk.....',
        '......SSSSSSSSSkkk......',
        '.......SSSSSSSkkk.......',
        '........SSSSSkkk........',
        '.........kkkkkk.........',
        '....SS............SS....',
        '....SS............SS....',
        '...........SS...........',
        '...........SS...........',
        '........................',
    ], DECOR_LEGEND),
    cloud: [
        sprite([
            '..........wwww..........',
            '.....www.wwwwwww........',
            '....wwwwwwwwwwwww.ww....',
            '..wwwwwwwwwwwwwwwwwwww..',
            '.wwwwwwwwwwwwwwwwwwwwww.',
            'wwwwwwwwwwwwwwwwwwwwwwww',
            'bwwwwwwwwwwwwwwwwwwwwwwb',
            '.bbbbwwwwwbbbbbwwwwbbbb.',
            '...bbbbbbbbbbbbbbbbbb...',
        ], DECOR_LEGEND),
        sprite([
            '.....wwww.......',
            '...wwwwwwww.....',
            '..wwwwwwwwwwww..',
            '.wwwwwwwwwwwwww.',
            'bwwwwwwwwwwwwwwb',
            '.bbbwwwbbbwwbbb.',
            '...bbbbbbbbbb...',
        ], DECOR_LEGEND),
    ],
    // Vines start at the anchor: the top of the root knot.
    root: sprite([
        '..........hi........ih........',
        '.........hiiL......Liih.......',
        '..........LiiL.hi.LiiL........',
        '............LilliliL..........',
        '............OLliilLO..........',
        '...........OQpccpqqQO.........',
        '..........OppcppqqqqQO........',
        '.........OpppQqqqqQqQQO.......',
        '........OppqqQqqqqQqQQQO......',
        '......OOQppqqQqqqqQqqQQQOO....',
        '....OQpqOOOQqqqqqqQQOOOQQQO...',
        '..OnnQpqOOOOQQqqQQQOOOOQqQnnO.',
        '.OnnnQpOOOOOOOOOOOOOOOOQqnnnO.',
        'OnnnnQpnMMMMMMMMMMMMMMnQqnnmMO',
        'OnnnnnnnnnnnnnmmmmmmmmmmmmmMMO',
        'OnnnnnnmmmmmmmmmmmmmmmmmmMMMMO',
        '.OMMmmmmmmmmmmmmmmmmmmMMMMMMO.',
        '...OOOOOOOOOOOOOOOOOOOOOOOO...',
    ], DECOR_LEGEND, { x: 15, y: 5 }),
    leaf: [leafSprite(18, 15), leafSprite(14, 12, 8), leafSprite(9, 8, -6)],
    // The big leaves heap up around the root in the middle of the bed.
    bigLeaf: [leafSprite(22, 18, -6), leafSprite(22, 18, 9)],
    // A tendril hangs off the vine by its straight tail; the anchor is the tail's end.
    tendril: [
        sprite([
            '....iii.',
            '...i...i',
            '...i.i.i',
            '...i.ii.',
            '..i.....',
            '..i.....',
            '.i......',
            'i.......',
        ], DECOR_LEGEND, { x: 0, y: 7 }),
        sprite([
            '.iii...',
            'i...i..',
            'i.i.i..',
            '.ii.i..',
            '.....i.',
            '.....i.',
            '......i',
        ], DECOR_LEGEND, { x: 6, y: 6 }),
        sprite([
            '.iii....',
            'i...i...',
            'i.i.i...',
            '.ii.i...',
            '.....i..',
            '.....i..',
            '......i.',
            '.......i',
        ], DECOR_LEGEND, { x: 7, y: 7 }),
    ],
    weed: [
        sprite([
            '..kSk..',
            '..SSS..',
            '...l...',
            '...l...',
            '...l...',
            '...i...',
            '...l...',
            'i..l..i',
            'li.l.il',
            '.lilil.',
            'LllLllL',
        ], DECOR_LEGEND, { x: 3, y: 10 }),
        sprite([
            '..www..',
            '.wbwbw.',
            '.wwbww.',
            '..wbw..',
            '...l...',
            '...l...',
            '...i...',
            '...l...',
            '...l...',
            '.i.l.i.',
            'lililil',
            'LlLlLlL',
        ], DECOR_LEGEND, { x: 3, y: 11 }),
        sprite([
            '...i...',
            '...i..i',
            '.i.i..i',
            '.i.l.i.',
            '.i.l.i.',
            '..ll.l.',
            '..l.ll.',
            'i.l.l..',
            'i.l.l.i',
            '.ll.l.l',
            '.l.ll.l',
            '.l.l.l.',
            'LlLlLlL',
        ], DECOR_LEGEND, { x: 3, y: 12 }),
        sprite([
            '..iil.......',
            '.iiill..iil.',
            'iihilllLiilL',
            'ilhllllLhllL',
            '.lhlllLlhlL.',
            '..LhlLLlhL..',
            '...LhLLhL...',
            '..LLLLLLLL..',
        ], DECOR_LEGEND, { x: 6, y: 7 }),
        sprite([
            '.y...y.',
            'yty.yty',
            '.y...y.',
            'yty.yty',
            '.y...t.',
            '.t...t.',
            '.t..t..',
            '..t.t..',
            '..t.t..',
            '..tt...',
            '.ilil..',
            '.LlLl..',
        ], DECOR_LEGEND, { x: 3, y: 11 }),
        sprite([
            'i....',
            '.i..i',
            '.l.i.',
            '.l.l.',
            '..ll.',
            'i.l..',
            '.ll.i',
            '.l.l.',
            'LlLl.',
        ], DECOR_LEGEND, { x: 2, y: 8 }),
    ],
    grass: [
        sprite(['.i...', '.i.h.', 'li.i.', 'llil.', 'LlllL'], DECOR_LEGEND, { x: 2, y: 4 }),
        sprite(['...h...', '.i.i..i', '.ii.i.l', 'lilil.l', 'LllllLl'], DECOR_LEGEND, { x: 3, y: 4 }),
        sprite(['.h.', 'il.', 'lil', 'LlL'], DECOR_LEGEND, { x: 1, y: 3 }),
    ],
    flower: [
        sprite(['.w.', 'wSw', '.w.', '.l.', 'Ll.'], DECOR_LEGEND, { x: 1, y: 4 }),
        sprite(['.k.', 'kSk', '.k.', '.l.', '.lL'], DECOR_LEGEND, { x: 1, y: 4 }),
    ],
};

/** Returns a w×h canvas, one canvas pixel per art pixel. */
export function bake(sprite) {
    const canvas = document.createElement('canvas');
    canvas.width = sprite.w;
    canvas.height = sprite.h;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    const img = ctx.createImageData(sprite.w, sprite.h);
    for (let y = 0; y < sprite.h; y++) {
        const row = sprite.rows[y];
        for (let x = 0; x < sprite.w; x++) {
            const key = sprite.legend[row[x]];
            if (key === null || key === undefined) {
                continue;
            }
            const color = PALETTE[key];
            const i = (y * sprite.w + x) * 4;
            img.data[i] = (color >> 16) & 0xff;
            img.data[i + 1] = (color >> 8) & 0xff;
            img.data[i + 2] = color & 0xff;
            img.data[i + 3] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    return canvas;
}
