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

export const PUMPKIN_STAGES = 4;

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
 */
export function pumpkinLayers(stage, size, seed = 0) {
    const def = PUMPKIN_SIZES[size] ?? PUMPKIN_SIZES.m;
    const st = Math.max(0, Math.min(PUMPKIN_STAGES, stage | 0));
    const key = `${size}:${st}:${seed >>> 0}`;
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
                    k = ((x * 37 + y * 101) ^ (x * y)) % 7 === 0 ? 'seed' : 'pulp';
                }
                kind[top + y][x] = k;
            }
        }
    }

    const filled = (x, y) => (kind[y]?.[x] ?? '.') !== '.';
    const back = blank();
    const body = blank();
    const rim = blank();
    const stem = blank();
    const glow = blank();
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const k = kind[y][x];
            if (k === '.') continue;
            back[y][x] = { wall: 'x', pulp: 'F', seed: 'c' }[k] ?? 'X';
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
    const anchor = { x: w >> 1, y: h };
    const legend = { ...PUMPKIN_LEGEND, ...look.skin };
    const result = {
        w,
        h,
        anchor,
        emit,
        layers: {
            back: layerSprite(back, PUMPKIN_LEGEND, anchor),
            body: layerSprite(body, legend, anchor),
            rim: layerSprite(rim, PUMPKIN_LEGEND, anchor),
            stem: st < 3 ? layerSprite(stem, PUMPKIN_LEGEND, anchor) : null,
            glow: layerSprite(glow, { '.': null, w: 'white' }, anchor),
        },
    };
    layersCache.set(key, result);
    return result;
}

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
// one long branch reaching right. Deterministic, so every page draws the same tree.
const TREE_W = 124;
const TREE_H = 104;
const TRUNK_X = 34;
const BRANCH = { x0: 38, x1: 120, y0: 64, rise: 13 };
const PERCH_X = 104;

// [cx, cy, r], drawn back to front: lower clusters sit behind the ones above them.
const TREE_CLUSTERS = [
    [8, 38, 7], [60, 36, 7], [22, 48, 9], [46, 48, 9], [34, 46, 12],
    [20, 34, 13], [48, 34, 13], [14, 24, 10], [54, 22, 10], [34, 22, 14],
    [26, 10, 9], [42, 10, 9], [78, 58, 5], [118, 49, 3],
];

function branchTop(x) {
    const s = (x - BRANCH.x0) / (BRANCH.x1 - BRANCH.x0);
    return Math.round(BRANCH.y0 - BRANCH.rise * Math.sin((s * Math.PI) / 2));
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

    // Branch: tapers from 4 to 2 px, light on top, outline underneath.
    for (let x = BRANCH.x0; x <= BRANCH.x1; x++) {
        const s = (x - BRANCH.x0) / (BRANCH.x1 - BRANCH.x0);
        const top = branchTop(x);
        const th = Math.max(2, Math.round(4 - 2.4 * s));
        for (let k = 0; k < th; k++) {
            set(x, top + k, k === 0 ? 'p' : k === th - 1 ? 'Q' : 'q');
        }
        set(x, top + th, 'O');
    }
    // A short twig rising off the branch.
    for (let k = 1; k <= 5; k++) {
        set(70 + k, branchTop(70) - k, k < 3 ? 'q' : 'p');
        set(70 + k, branchTop(70) - k + 1, 'Q');
    }

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

export const DECOR = {
    tree: treeSprite(),
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
