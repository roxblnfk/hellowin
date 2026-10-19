import { PALETTE } from './palette.js';

/**
 * Flyers for the duck shoot, plus the bush they rise from and the score digits.
 * Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as sprites.js.
 * Flyers face right; the scene mirrors them for flights to the left.
 */

const LEGEND = {
    '.': null,
    O: 'outline',
    K: 'outline',
    G: 'duckGray',
    g: 'duckGrayDark',
    B: 'duckBrown',
    b: 'duckBrownLight',
    H: 'mallard',
    h: 'mallardLight',
    s: 'speculum',
    w: 'white',
    W: 'chickenShade',
    Y: 'beak',
    R: 'comb',
    o: 'pumpkin',
};

// Shapes are described once in a 24-px-wide reference frame and rasterised per depth onto its own grid, so each
// depth is drawn at its pixel size rather than scaled.
const ellipse = (cx, cy, rx, ry) => (x, y) => ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1;
const circle = (cx, cy, r) => ellipse(cx, cy, r, r);
const capsule = (x1, y1, x2, y2, r) => (x, y) => {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const t = Math.max(0, Math.min(1, ((x - x1) * dx + (y - y1) * dy) / (dx * dx + dy * dy)));
    return Math.hypot(x - x1 - dx * t, y - y1 - dy * t) <= r;
};
const polygon = (pts) => (x, y) => {
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const [xi, yi] = pts[i];
        const [xj, yj] = pts[j];
        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
};

// Wing colouring by distance from the shoulder: light leading edge, then the speculum stripe, then dark tips.
const wingColor = (sx, sy, len, [lead, band, tip]) => (x, y) => {
    const t = Math.hypot(x - sx, y - sy) / len;
    return t < 0.38 ? lead : t < 0.58 ? band : tip;
};

const DUCK = {
    refH: 16,
    body: [11, 9.5],
    eye: [20.4, 4.2],
    parts: [
        { test: polygon([[5, 7.6], [0.6, 6.4], [1, 8.8], [5, 10.9]]), color: (x) => (x < 2.6 ? 'w' : 'g') },
        {
            test: ellipse(11, 9.5, 7.6, 4.1),
            color: (x, y) => (x > 14.5 && y < 11.6 ? (y < 8.4 ? 'b' : 'B') : x < 5.8 ? 'g' : y > 12.4 ? 'g' : 'G'),
        },
        { test: capsule(16, 8.6, 18.8, 6, 1.9), color: (x, y) => (y > 8.1 ? 'B' : y > 7.2 ? 'w' : 'H') },
        { test: circle(19.6, 4.8, 2.7), color: (x, y) => (x < 19.2 && y < 4.4 ? 'h' : 'H') },
        { test: circle(20.4, 4.2, 0.6), color: () => 'K', detail: true },
        { test: polygon([[21.5, 4.3], [24, 5.5], [21.5, 6.6]]), color: () => 'Y', detail: true },
    ],
    wings: {
        up: [[13.5, 7.4], [8, 7.8], [4, 0.2], [7.5, 0], [11.5, 2.5]],
        mid: [[13.5, 7.4], [8, 8], [1.5, 3.2], [5, 2], [11, 4.6]],
        down: [[13.5, 8], [8.5, 9], [5.5, 15.6], [9, 15.8], [12.5, 12]],
    },
    shoulder: [11, 8],
    wingColors: ['g', 's', 'g'],
    feet: { test: circle(8.6, 13.6, 0.8), color: () => 'o', detail: true },
};

const CHICKEN = {
    refH: 18,
    body: [11.5, 10.5],
    eye: [18.8, 4],
    parts: [
        {
            test: polygon([[6, 8], [1.6, 2.2], [0.6, 5], [2.8, 9.2], [5.6, 11.8]]),
            color: (x, y) => (y > 8 ? 'W' : 'w'),
        },
        {
            test: ellipse(11.5, 10.5, 7.2, 5.6),
            color: (x, y) => ((x - 11.5) * 0.5 + (y - 10.5) > 2.6 ? 'W' : 'w'),
        },
        { test: capsule(10.6, 15, 10.2, 17.6, 0.65), color: () => 'o', detail: true },
        { test: capsule(13.2, 15, 13.6, 17.6, 0.65), color: () => 'o', detail: true },
        { test: capsule(15.4, 8, 17.2, 5.6, 2.3), color: () => 'w' },
        { test: circle(17.8, 4.6, 3), color: () => 'w' },
        { test: (x, y) => circle(16.3, 1.7, 1.15)(x, y) || circle(17.8, 1.1, 1.25)(x, y) || circle(19.2, 1.8, 1.05)(x, y), color: () => 'R', detail: true },
        { test: circle(20.2, 7.4, 0.95), color: () => 'R', detail: true },
        { test: circle(18.8, 4, 0.6), color: () => 'K', detail: true },
        { test: polygon([[20.4, 3.7], [22.9, 4.8], [20.4, 5.9]]), color: () => 'Y', detail: true },
    ],
    // Short, clumsy wings: they barely clear the body.
    wings: {
        up: [[14.2, 8.4], [9.4, 9], [6.6, 2.2], [9.2, 1.6], [12.4, 4.2]],
        mid: [[14.2, 8.8], [9, 10], [3.8, 6.2], [6, 5], [10.8, 6.8]],
        down: [[14.2, 9.4], [9.4, 10.6], [8, 15.6], [11, 16], [13.2, 13]],
    },
    shoulder: [11.5, 9],
    wingColors: ['w', 'W', 'W'],
};

const DEPTH_W = [12, 17, 26];

function rasterise(def, w, wing, { extra = [] } = {}) {
    const k = w / 24;
    const h = Math.round(def.refH * k);
    const grid = Array.from({ length: h }, () => Array(w).fill('.'));
    const detail = Array.from({ length: h }, () => Array(w).fill(false));
    const isWing = Array.from({ length: h }, () => Array(w).fill(false));
    const parts = [...def.parts];
    if (def.feet && w >= 20) parts.splice(2, 0, def.feet);
    if (wing) {
        const pts = def.wings[wing];
        const len = Math.max(...pts.map(([x, y]) => Math.hypot(x - def.shoulder[0], y - def.shoulder[1])));
        parts.push({ test: polygon(pts), color: wingColor(...def.shoulder, len, def.wingColors), wing: true });
    }
    parts.push(...extra);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const rx = (x + 0.5) / k;
            const ry = (y + 0.5) / k;
            for (const part of parts) {
                if (part.test(rx, ry)) {
                    grid[y][x] = part.color(rx, ry);
                    detail[y][x] = !!part.detail;
                    isWing[y][x] = !!part.wing;
                }
            }
        }
    }
    // Every part keeps at least one pixel on the small grids: the eye and beak sample between pixel centres.
    const eye = [Math.floor(def.eye[0] * k), Math.floor(def.eye[1] * k)];
    if (grid[eye[1]]?.[eye[0]] !== '.' && grid[eye[1]]) {
        grid[eye[1]][eye[0]] = 'K';
        detail[eye[1]][eye[0]] = true;
    }
    // Outline the silhouette; beak, comb, legs and eye keep their colour so they still read at depth 0.
    const out = grid.map((r) => r.slice());
    const empty = (x, y) => (grid[y]?.[x] ?? '.') === '.';
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (grid[y][x] === '.' || detail[y][x]) continue;
            // On the smallest grid the top edge stays coloured, otherwise the head would be all outline.
            const up = w > 12 && empty(x, y - 1);
            if (up || empty(x, y + 1) || empty(x - 1, y) || empty(x + 1, y)) out[y][x] = 'O';
            // The wing lies over the body in similar colours, so it gets a line of its own where it meets the body.
            else if (isWing[y][x] && w > 12
                && (!isWing[y + 1]?.[x] || !isWing[y - 1]?.[x] || !isWing[y][x - 1] || !isWing[y][x + 1])) out[y][x] = 'O';
        }
    }
    return {
        w,
        h,
        rows: out.map((r) => r.join('')),
        legend: LEGEND,
        anchor: { x: Math.round(def.body[0] * k), y: Math.round(def.body[1] * k) },
    };
}

function rotate90(sprite) {
    const rows = [];
    for (let x = 0; x < sprite.w; x++) {
        let row = '';
        for (let y = sprite.h - 1; y >= 0; y--) row += sprite.rows[y][x];
        rows.push(row);
    }
    return { ...sprite, w: sprite.h, h: sprite.w, rows, anchor: { x: sprite.h - 1 - sprite.anchor.y, y: sprite.anchor.x } };
}

function rotate180(sprite) {
    const rows = sprite.rows.map((r) => [...r].reverse().join('')).reverse();
    return { ...sprite, rows, anchor: { x: sprite.w - 1 - sprite.anchor.x, y: sprite.h - 1 - sprite.anchor.y } };
}

// Hit: wings flung up and a few feathers already loose around the body; on the big grid the eye becomes a cross.
function hitFrame(def, w) {
    const k = w / 24;
    const loose = def === DUCK ? ['G', 'B', 'g'] : ['w', 'W', 'w'];
    const extra = [[3, 3], [21, 12], [6, 14.5]].map(([x, y], i) => ({
        test: capsule(x, y, x + 1.6, y - 0.8, 0.55),
        color: () => loose[i],
    }));
    const sprite = rasterise(def, w, 'up', { extra });
    if (w >= 20) {
        const rows = sprite.rows.map((r) => r.split(''));
        const ex = Math.floor(def.eye[0] * k);
        const ey = Math.floor(def.eye[1] * k);
        for (const [dx, dy] of [[-1, -1], [1, -1], [0, 0], [-1, 1], [1, 1]]) {
            if (rows[ey + dy]?.[ex + dx]) rows[ey + dy][ex + dx] = 'K';
        }
        for (const [dx, dy] of [[0, -1], [-1, 0], [1, 0], [0, 1]]) {
            if (rows[ey + dy]?.[ex + dx] === 'K' || rows[ey + dy]?.[ex + dx] === 'O') continue;
            if (rows[ey + dy]?.[ex + dx]) rows[ey + dy][ex + dx] = def === DUCK ? 'H' : 'w';
        }
        sprite.rows = rows.map((r) => r.join(''));
    }
    return sprite;
}

function flyerSet(def) {
    return DEPTH_W.map((w) => {
        const hit = hitFrame(def, w);
        return {
            fly: ['up', 'mid', 'down', 'mid'].map((wing) => rasterise(def, w, wing)),
            hit,
            fall: [rotate90(hit), rotate180(hit)],
        };
    });
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

const FEATHER_LEGEND = { G: 'duckGray', g: 'duckGrayDark', B: 'duckBrown', b: 'duckBrownLight', w: 'white', W: 'chickenShade' };

/** Indexed by depth (0 — far, 2 — near): { fly: 4 wingbeat frames, hit, fall: 2 tumbling frames }. */
export const FLYERS = {
    duck: flyerSet(DUCK),
    chicken: flyerSet(CHICKEN),
    // Each feather knows whose it is, so a burst can pick the right ones.
    feathers: [
        { ...sprite(['.G', 'Gg', 'g.'], FEATHER_LEGEND), kind: 'duck' },
        { ...sprite(['bB.', '.Bb'], FEATHER_LEGEND), kind: 'duck' },
        { ...sprite(['.w', 'wW', 'W.'], FEATHER_LEGEND), kind: 'chicken' },
        { ...sprite(['ww.', '.wW', '..W'], FEATHER_LEGEND), kind: 'chicken' },
    ],
};

export function feathersOf(kind) {
    const own = FLYERS.feathers.filter((f) => f.kind === kind);
    return own.length ? own : FLYERS.feathers;
}

const BUSH_W = 28;
const BUSH_H = 20;
const BUSH_LEGEND = { O: 'outline', L: 'leafDark', l: 'leaf', i: 'leafLight', h: 'leafShine' };

// [cx, cy, r], back to front, in two shapes.
const BUSH_SHAPES = [
    [[6, 13, 5], [22, 13, 5], [10, 9, 6], [19, 9, 6], [14, 6, 6], [14, 13, 7]],
    [[5, 14, 4], [23, 12, 5], [9, 10, 5], [16, 7, 7], [21, 15, 4], [12, 14, 6]],
];

// Rustle frames nudge the clusters and throw a couple of leaves off the top.
const RUSTLE = [
    { shift: [1, -1, 0, 1, -1, 0], lift: 0, leaves: [[6, 1], [21, 3]] },
    { shift: [-1, 1, 1, -1, 0, 1], lift: -1, leaves: [[4, 0], [23, 1], [13, 0]] },
    { shift: [0, 0, -1, 0, 1, -1], lift: 0, leaves: [[2, 0], [25, 0]] },
];

function bushSprite(shape, frame) {
    const grid = Array.from({ length: BUSH_H }, () => Array(BUSH_W).fill('.'));
    const owner = Array.from({ length: BUSH_H }, () => Array(BUSH_W).fill(-1));
    BUSH_SHAPES[shape].forEach(([cx0, cy0, r], id) => {
        const cx = cx0 + (frame?.shift[id] ?? 0);
        const cy = cy0 + (id >= 3 ? frame?.lift ?? 0 : 0);
        for (let y = 0; y < BUSH_H - 1; y++) {
            for (let x = 0; x < BUSH_W; x++) {
                const dx = (x - cx) / r;
                const dy = (y - cy) / r;
                const edge = 1 + 0.12 * Math.sin(Math.atan2(dy, dx) * 6 + cx);
                if (dx * dx + dy * dy > edge * edge) continue;
                const v = -(0.45 * dx + 0.6 * dy) + 0.4 * (1 - Math.hypot(dx, dy)) - 0.2 - (cy - 9) / 16;
                grid[y][x] = v > 0.55 ? 'h' : v > 0.1 ? 'i' : v > -0.45 ? 'l' : 'L';
                owner[y][x] = id;
            }
        }
    });
    const out = grid.map((r) => r.slice());
    for (let y = 0; y < BUSH_H; y++) {
        for (let x = 0; x < BUSH_W; x++) {
            if (owner[y][x] < 0) continue;
            if (y > 0 && owner[y - 1][x] > owner[y][x]) out[y][x] = 'L';
            const below = grid[y + 1]?.[x] ?? '.';
            const right = grid[y][x + 1] ?? '.';
            if (below === '.' || right === '.') out[y][x] = 'O';
        }
    }
    for (const [x, y] of frame?.leaves ?? []) {
        out[y][x] = 'i';
        if (out[y][x + 1] === '.') out[y][x + 1] = 'L';
    }
    return sprite(out.map((r) => r.join('')), BUSH_LEGEND, { x: BUSH_W >> 1, y: BUSH_H });
}

/** idle — two bush shapes; rustle — three frames of the first shape shaking as a duck takes off. Anchor: bottom centre. */
export const BUSH = {
    idle: [bushSprite(0), bushSprite(1)],
    rustle: RUSTLE.map((frame) => bushSprite(0, frame)),
};

const GLYPHS = {
    0: ['###', '#.#', '#.#', '#.#', '###'],
    1: ['.#.', '##.', '.#.', '.#.', '###'],
    2: ['###', '..#', '###', '#..', '###'],
    3: ['###', '..#', '.##', '..#', '###'],
    4: ['#.#', '#.#', '###', '..#', '..#'],
    5: ['###', '#..', '###', '..#', '###'],
    6: ['###', '#..', '###', '#.#', '###'],
    7: ['###', '..#', '.#.', '.#.', '.#.'],
    8: ['###', '#.#', '###', '#.#', '###'],
    9: ['###', '#.#', '###', '..#', '###'],
    '+': ['...', '.#.', '###', '.#.', '...'],
};

// 3×5 glyphs inside a 1 px outline: 5×7 per character.
function glyphSprite(glyph) {
    const grid = Array.from({ length: 7 }, () => Array(5).fill('.'));
    glyph.forEach((row, y) => [...row].forEach((ch, x) => {
        if (ch !== '#') return;
        for (let dy = 0; dy <= 2; dy++) {
            for (let dx = 0; dx <= 2; dx++) {
                if (grid[y + dy][x + dx] === '.') grid[y + dy][x + dx] = 'O';
            }
        }
    }));
    glyph.forEach((row, y) => [...row].forEach((ch, x) => {
        if (ch === '#') grid[y + 1][x + 1] = y >= 3 ? 'k' : 'Y';
    }));
    return sprite(grid.map((r) => r.join('')), { O: 'outline', Y: 'gold', k: 'pumpkinLight' });
}

/** Score font: '0'–'9' and '+', 5×7 each including the outline. */
export const DIGITS = Object.fromEntries(Object.entries(GLYPHS).map(([ch, g]) => [ch, glyphSprite(g)]));

/** A string such as '+3' as one sprite; neighbouring glyphs share their outline column. Anchor: centre. */
export function digitsSprite(text) {
    const glyphs = [...String(text)].map((ch) => DIGITS[ch]).filter(Boolean);
    const w = glyphs.length * 4 + 1;
    const grid = Array.from({ length: 7 }, () => Array(w).fill('.'));
    glyphs.forEach((g, i) => g.rows.forEach((row, y) => [...row].forEach((ch, x) => {
        if (ch !== '.' && (grid[y][i * 4 + x] === '.' || ch !== 'O')) grid[y][i * 4 + x] = ch;
    })));
    return sprite(grid.map((r) => r.join('')), DIGITS[0].legend);
}

/** w×h canvas, one pixel per art pixel. */
export function bake(sprite) {
    const canvas = document.createElement('canvas');
    canvas.width = sprite.w;
    canvas.height = sprite.h;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    const img = ctx.createImageData(sprite.w, sprite.h);
    for (let y = 0; y < sprite.h; y++) {
        for (let x = 0; x < sprite.w; x++) {
            const key = sprite.legend[sprite.rows[y][x]];
            if (!key) continue;
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
