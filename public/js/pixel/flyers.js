import { PALETTE } from './palette.js';
import { seedOf } from './sprites.js';

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
    // Night flyers, the witch and the ground runners.
    C: 'crowDark',
    c: 'crow',
    v: 'crowLight',
    a: 'gray',
    T: 'bat',
    U: 'batWing',
    V: 'batLight',
    r: 'eyeRed',
    q: 'ghost',
    Q: 'ghostShade',
    e: 'ghostEdge',
    E: 'ecto',
    P: 'violet',
    p: 'violetDark',
    x: 'crimson',
    z: 'zombie',
    Z: 'zombieShade',
    n: 'hay',
    N: 'hayDark',
    m: 'bark',
    d: 'denim',
    D: 'denimDark',
    l: 'hayLight',
    y: 'pumpkinShine',
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
    loose: ['G', 'B', 'g'],
    crossFill: 'H',
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
    loose: ['w', 'W', 'w'],
    crossFill: 'w',
};

// Depth 0 is hand-drawn below: at 4 px a rasterised shape loses the head.
const DEPTH_W = [4, 14, 26];

// Far flyers are specks with two wing positions: a duck is a dark head on a light body, a hen a white blob with a
// red comb. The four fly frames alternate them.
const FAR = {
    duck: {
        anchor: { x: 2, y: 1 },
        up: ['.g..', 'GGGK', '....'],
        down: ['....', 'GGGK', '.g..'],
        hit: ['g.g.', 'GGGK', '...G'],
    },
    chicken: {
        anchor: { x: 2, y: 1 },
        up: ['.W.R', 'wwww', '.ww.'],
        down: ['...R', 'wwww', '.Ww.'],
        hit: ['W.WR', 'wwww', 'w.w.'],
    },
    // A crow is the duck's speck in black with a grey beak; a bat is all wing.
    crow: {
        anchor: { x: 2, y: 1 },
        up: ['.C..', 'CcCa', '....'],
        down: ['....', 'CcCa', '.C..'],
        hit: ['C.C.', 'CcCa', '...c'],
    },
    bat: {
        anchor: { x: 2, y: 1 },
        up: ['U..U', 'UTTU', '....'],
        down: ['....', 'UTTU', 'U..U'],
        hit: ['U.U.', '.TT.', 'U..U'],
    },
    // The ghost has no wings: its frames wave the tail.
    ghost: {
        anchor: { x: 2, y: 2 },
        up: ['.qq.', 'qKqK', 'qqqq', 'q.q.'],
        down: ['.qq.', 'qKqK', 'qqqq', '.q.q'],
        hit: ['q..q', '.qq.', 'qKqK', 'Q..Q'],
    },
    // The witch gets one pixel more on each side: the hat and the broom are what make her a witch.
    witch: {
        anchor: { x: 3, y: 2 },
        up: ['...p..', '..pz..', 'p.PPz.', 'nnmmmm'],
        down: ['...p..', '..pz..', '.pPPz.', 'nnmmmm'],
        hit: ['..p...', '...z..', '.PPPz.', 'nn.mmm'],
    },
    // Ground runners (see below): legs move, not wings. The road-runner runs along the far horizon, so it is tiny
    // (7×4), but the long dark tail, the violet crest and the beak still read; the legs blur in a 4-frame cycle.
    roadrunner: {
        anchor: { x: 3, y: 3 },
        fly: [
            ['....Pd.', 'DDdddwy', '..dl...', '.Y...Y.'],
            ['....Pd.', 'DDdddwy', '..dl...', '..Y.Y..'],
            ['....Pd.', 'DDdddwy', '..dl...', '...YY..'],
            ['....Pd.', 'DDdddwy', '..dl...', '..Y.Y..'],
        ],
        hit: ['...P...', 'DDddwy.', '..dd...', '.Y..Y..'],
    },
    zombie: {
        anchor: { x: 1, y: 5 },
        up: ['.y.', 'zZ.', 'GGz', 'dd.', 'd.d'],
        down: ['.y.', 'zZ.', 'GGz', 'dd.', '.dd'],
        hit: ['y..', '.Zz', 'GG.', 'dd.', 'd.d'],
    },
};

function farSet(kind) {
    const far = FAR[kind];
    const make = (rows) => ({ w: rows[0].length, h: rows.length, rows, legend: LEGEND, anchor: { ...far.anchor } });
    const hit = make(far.hit);
    return {
        fly: (far.fly ?? [far.up, far.down, far.up, far.down]).map(make),
        hit,
        fall: [rotate90(hit), rotate180(hit)],
    };
}

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
    const eye = def.eye && [Math.floor(def.eye[0] * k), Math.floor(def.eye[1] * k)];
    if (eye && grid[eye[1]]?.[eye[0]] !== '.' && grid[eye[1]]) {
        grid[eye[1]][eye[0]] = 'K';
        detail[eye[1]][eye[0]] = true;
    }
    // Outline the silhouette; beak, comb, legs and eye keep their colour so they still read at depth 0.
    const out = grid.map((r) => r.slice());
    const empty = (x, y) => (grid[y]?.[x] ?? '.') === '.';
    const edge = (w <= 14 && def.outlineSmall) || def.outline || 'O';
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (grid[y][x] === '.' || detail[y][x]) continue;
            // On the smallest grid the top edge stays coloured, otherwise the head would be all outline.
            const up = w > 12 && empty(x, y - 1);
            if (up || empty(x, y + 1) || empty(x - 1, y) || empty(x + 1, y)) out[y][x] = edge;
            // The wing lies over the body in similar colours, so it gets a line of its own where it meets the body.
            else if (isWing[y][x] && w > 12
                && (!isWing[y + 1]?.[x] || !isWing[y - 1]?.[x] || !isWing[y][x - 1] || !isWing[y][x + 1])) out[y][x] = edge;
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
// Kinds drawn pose by pose bring their own hit pose in `hitPose` instead of the raised wing.
function hitFrame(def, w) {
    const k = w / 24;
    const loose = def.loose;
    const extra = [[3, 3], [21, 12], [6, 14.5]].map(([x, y], i) => ({
        test: capsule(x, y, x + 1.6, y - 0.8, 0.55),
        color: () => loose[i],
    }));
    const sprite = def.hitPose ? rasterise(def.hitPose, w, null, { extra }) : rasterise(def, w, 'up', { extra });
    if (w >= 20 && def.eye) {
        const rows = sprite.rows.map((r) => r.split(''));
        const ex = Math.floor(def.eye[0] * k);
        const ey = Math.floor(def.eye[1] * k);
        for (const [dx, dy] of [[-1, -1], [1, -1], [0, 0], [-1, 1], [1, 1]]) {
            if (rows[ey + dy]?.[ex + dx]) rows[ey + dy][ex + dx] = 'K';
        }
        for (const [dx, dy] of [[0, -1], [-1, 0], [1, 0], [0, 1]]) {
            if (rows[ey + dy]?.[ex + dx] === 'K' || rows[ey + dy]?.[ex + dx] === 'O') continue;
            if (rows[ey + dy]?.[ex + dx]) rows[ey + dy][ex + dx] = def.crossFill;
        }
        sprite.rows = rows.map((r) => r.join(''));
    }
    return sprite;
}

function flyerSet(def) {
    return DEPTH_W.map((w, depth) => {
        if (depth === 0) return farSet(def === DUCK ? 'duck' : 'chicken');
        const hit = hitFrame(def, w);
        return {
            fly: ['up', 'mid', 'down', 'mid'].map((wing) => rasterise(def, w, wing)),
            hit,
            fall: [rotate90(hit), rotate180(hit)],
        };
    });
}

// ---------------------------------------------------------------------------------------------------------------
// Kinds drawn pose by pose: each pose is a full part list in the same 24-px reference frame, rasterised per depth.
// A pose is { refH, body, eye, parts, outline? }; parts flagged `wing` get a line where they cross the body.

const shift = (test, dx, dy) => (x, y) => test(x - dx, y - dy);
const moved = (parts, dx, dy) => parts.map((p) => ({ ...p, test: shift(p.test, dx, dy), color: (x, y) => p.color(x - dx, y - dy) }));
const mirrorX = (pts) => pts.map(([x, y]) => [24 - x, y]);
const paint = (test, ch, extra = {}) => ({ test, color: typeof ch === 'function' ? ch : () => ch, ...extra });

// Crow: the duck's build with a wedge tail, a heavy beak and fingered primaries, in black with a violet sheen.
const CROW_BODY = [
    paint(polygon([[5.5, 7.8], [0.4, 5.6], [0, 8.6], [0.6, 11.4], [5.5, 10.6]]), (x) => (x < 2.2 ? 'C' : 'c')),
    paint(ellipse(11, 9.3, 7.2, 3.7), (x, y) => (y > 11.4 ? 'C' : x > 13 && y < 8.4 ? 'v' : 'c')),
    paint(capsule(15.5, 8.6, 18, 6.6, 2.2), (x, y) => (y < 6.6 ? 'v' : 'c')),
    paint(circle(18.8, 5.6, 2.6), (x, y) => (x < 18.4 && y < 5 ? 'v' : 'c')),
    paint(polygon([[20.6, 4.6], [24, 6.1], [20.6, 7.4]]), 'a', { detail: true }),
];
const CROW_WINGS = {
    up: [[13.5, 7.6], [8, 8], [3, 1.2], [4.6, 1.6], [4.8, 0], [6.6, 1.4], [7.4, 0], [9, 1.8], [12, 3.4]],
    mid: [[13.5, 7.6], [8, 8.2], [1, 4.2], [2.6, 3.8], [2.4, 2.4], [4.6, 3.2], [5, 1.8], [7, 3.4], [11, 5]],
    down: [[13.5, 8.2], [8.4, 9.2], [4.6, 15.6], [6, 15], [6.6, 16.4], [8, 14.8], [9, 16], [10.4, 14], [12.6, 12]],
};
function crowPose(wing) {
    return {
        refH: 17,
        body: [11, 9.3],
        eye: [19.4, 5],
        parts: [...CROW_BODY, paint(polygon(CROW_WINGS[wing]), (x, y) => (Math.hypot(x - 11, y - 8) < 5 ? 'c' : 'C'), { wing: true })],
    };
}
const CROW = {
    fly: ['up', 'mid', 'down', 'mid'].map(crowPose),
    hitPose: crowPose('up'),
    loose: ['C', 'c', 'C'],
    crossFill: 'c',
    eye: [19.4, 5],
};

// Bat, seen from the front: both wings beat together, the trailing edge scalloped between the fingers.
const BAT_WING = {
    up: [[13.2, 5.6], [15, 1.6], [18, 0], [21.5, 0], [23.6, 1.2], [22.4, 3.6], [20.6, 3], [19.4, 5.4], [17.4, 4.8], [16, 8], [13.4, 8.4]],
    mid: [[13.2, 5.4], [16.6, 3], [20.6, 2.4], [24, 4.4], [23, 7.6], [21.2, 6.6], [19.6, 9], [17.6, 7.8], [15.8, 10], [13.2, 9.4]],
    down: [[13.2, 5.6], [16.6, 6.4], [20.4, 8.6], [22.6, 12.4], [20.6, 12], [19, 11], [17.6, 12.6], [16, 10.6], [13.2, 10.4]],
};
function batPose(wing) {
    const membrane = (pts) => paint(polygon(pts), (x, y) => (Math.abs(x - 12) < 3.6 ? 'T' : 'U'), { wing: true });
    return {
        refH: 14,
        body: [12, 7.4],
        eye: null,
        // At depth 1 the wings are a pixel or two thick: edged in the bat's own dark, they stay purple, not black.
        outlineSmall: 'T',
        parts: [
            membrane(BAT_WING[wing]),
            membrane(mirrorX(BAT_WING[wing])),
            paint(polygon([[9.6, 3.6], [10.2, 0.6], [11.6, 2.8]]), 'T'),
            paint(polygon([[14.4, 3.6], [13.8, 0.6], [12.4, 2.8]]), 'T'),
            paint(ellipse(12, 8.2, 2.2, 3), (x, y) => (y > 9.2 ? 'V' : 'T')),
            paint(circle(12, 4.8, 2.2), 'T'),
            paint(circle(10.9, 4.6, 0.55), 'r', { detail: true }),
            paint(circle(13.1, 4.6, 0.55), 'r', { detail: true }),
        ],
    };
}
// Bats flap fast and flutter: wings snap between up and down, and the body jitters by a pixel.
const BAT = {
    fly: ['up', 'down', 'mid', 'down'].map(batPose),
    jitter: [0, 1, -1, 0],
    hitPose: batPose('up'),
    loose: ['T', 'U', 'T'],
};

// Ghost: a dome with a wavy hem trailing behind, short arms reaching ahead. The hem waves instead of wings, and the
// lower edge is dithered out so the sky shows through it.
function ghostPose(phase, { arms = 0, scared = false } = {}) {
    const hem = (x) => 14.4 + 1.9 * Math.sin(x * 0.95 + phase) + Math.max(0, 8 - x) * 0.35;
    const sheet = (x, y) => {
        if (y < 7) return circle(14, 7, 6)(x, y);
        const left = 8 - (y - 7) * 0.9;
        const right = 20 - (y - 7) * 0.45;
        return x >= left && x <= right && y <= hem(x);
    };
    const eye = (cx) => paint(capsule(cx, scared ? 4.6 : 5.6, cx, scared ? 7.4 : 7, scared ? 1.05 : 0.9), 'K', { detail: true });
    return {
        refH: 20,
        body: [13, 9],
        eye: null,
        outline: 'e',
        parts: [
            paint(capsule(8.4, 13.4, 3.4, 15.6 + Math.sin(phase) * 1.2, 1.1), 'Q'),
            paint(sheet, (x, y) => (x + y * 0.5 > 22.5 || y > 12.5 ? 'Q' : 'q')),
            ...(arms ? [paint(capsule(18, 10.4 - arms, 21.6, 9.4 - arms * 1.5, 1.4), 'q')] : []),
            eye(15.6),
            eye(18.6),
            paint(scared ? ellipse(17.2, 11.2, 1.1, 1.4) : ellipse(17, 11.2, 0.9, 0.8), 'K', { detail: true }),
        ],
    };
}
const GHOST = {
    fly: [0, 1.6, 3.2, 4.8].map((phase) => ghostPose(phase)),
    hitPose: ghostPose(2.4, { arms: 2.2, scared: true }),
    loose: ['q', 'E', 'q'],
};

// Clears every other pixel along the bottom of each column: a 50% checker fades the hem into whatever is behind.
function ditherHem(sprite, depthRows) {
    const rows = sprite.rows.map((r) => r.split(''));
    for (let x = 0; x < sprite.w; x++) {
        let bottom = -1;
        for (let y = sprite.h - 1; y >= 0; y--) {
            if (rows[y][x] !== '.') {
                bottom = y;
                break;
            }
        }
        for (let y = bottom; y > bottom - depthRows && y >= 0; y--) {
            if ((x + y) % 2 === 0 && rows[y][x] !== 'K') rows[y][x] = '.';
        }
    }
    sprite.rows = rows.map((r) => r.join(''));
    return sprite;
}

// Witch on a broom: bristles behind, hat with a crimson band and a bent tip, cape streaming back. Only the cape and
// the bristles move in flight.
const WITCH_CAPE = [
    [[11.4, 9.4], [5, 8.6], [2.6, 10.4], [5.4, 10.8], [3.6, 12.8], [10, 13.4]],
    [[11.4, 9.4], [5.4, 9.8], [2.4, 12], [5.6, 11.8], [4.6, 14], [10, 13.6]],
    [[11.4, 9.4], [5.2, 10.6], [3, 13.6], [6, 12.6], [5.8, 14.8], [10.2, 13.8]],
    [[11.4, 9.4], [5.4, 9.8], [2.4, 12], [5.6, 11.8], [4.6, 14], [10, 13.6]],
];
function witchPose(frame, { hatOff = 0 } = {}) {
    const flick = frame % 2 ? 0.7 : 0;
    const hat = [
        paint(capsule(9.6, 5.4, 16.8, 5, 0.75), 'p'),
        paint(polygon([[10.8, 5.2], [15.4, 5], [13.6, 1.8], [9.6, 0.6 + flick], [12.2, 2.6]]), (x, y) => (y > 3.9 && y < 5 ? 'x' : 'p')),
    ];
    return {
        refH: 20,
        body: [12, 12],
        eye: [14.4, 7],
        parts: [
            paint(polygon([[0, 11.8 - flick], [6.4, 13.8], [6.4, 16.4], [0.6, 19.6 + flick], [-0.4, 15.6]]),
                (x, y) => ((Math.round(y * 1.3) % 3 === 0) ? 'N' : 'n')),
            paint(capsule(1, 15.6, 21.6, 13.6, 0.9), 'm', { detail: true }),
            paint(capsule(6, 13.8, 6.4, 16.4, 0.7), 'x', { detail: true }),
            paint(polygon(WITCH_CAPE[frame]), 'p', { wing: true }),
            paint(polygon([[11.6, 6.8], [8.8, 8.8], [7.4, 11.4], [10.4, 10.2], [12, 9]]), 'o'),
            paint(polygon([[9.2, 15], [10.6, 8.8], [14, 8.8], [15, 13], [17.2, 15]]), (x, y) => (x < 11 || y > 14 ? 'p' : 'P')),
            paint(capsule(13.6, 14.6, 16.6, 16.4, 0.9), 'P'),
            paint(capsule(16.8, 16.6, 18.4, 17.4, 0.8), 'p'),
            paint(capsule(13.2, 10.2, 17, 13.4, 0.85), 'P', { wing: true }),
            paint(circle(17.3, 13.6, 0.8), 'z', { detail: true }),
            paint(circle(13.4, 7.4, 2.1), (x, y) => (x < 12.6 && y > 8 ? 'Z' : 'z')),
            paint(capsule(15.2, 7.4, 16.5, 8.2, 0.55), 'z'),
            ...moved(hat, -hatOff * 0.5, -hatOff),
        ],
    };
}
const WITCH = {
    fly: [0, 1, 2, 3].map((f) => witchPose(f)),
    hitPose: witchPose(1, { hatOff: 3 }),
    loose: ['y', 'n', 'y'],
    crossFill: 'z',
    eye: [14.4, 7],
};

// Road-runner, in the colours of a cartoon road-runner: blue with a violet crest, a tan throat and orange legs that
// blur into a wheel. Stretched flat for speed, tail straight back.
const RR_LEGS = [
    [[16.4, 17.4], [6.6, 16.4]],
    [[13.6, 15], [9.6, 17.6]],
    [[7, 16.8], [16, 17.2]],
    [[10.4, 17.6], [14.4, 14.6]],
];
function roadrunnerPose(frame) {
    const bob = frame % 2 ? -0.6 : 0;
    const leg = ([fx, fy], far) => [
        paint(capsule(12, 11.6, (12 + fx) / 2 + (far ? -0.4 : 0.6), 14.4, 0.6), far ? 'o' : 'Y', { detail: true }),
        paint(capsule((12 + fx) / 2 + (far ? -0.4 : 0.6), 14.4, fx, fy, 0.6), far ? 'o' : 'Y', { detail: true }),
        paint(capsule(fx, fy, fx + 1.4, fy, 0.5), far ? 'o' : 'Y', { detail: true }),
    ];
    const [near, far] = RR_LEGS[frame];
    const upper = [
        paint(polygon([[9.4, 7.4], [0, 3.4], [0, 6.8], [9.4, 11]]), (x, y) => (y > 0.36 * x + 5.4 ? 'd' : 'D')),
        paint(ellipse(11.6, 9.6, 5.2, 3.2), (x, y) => (y > 10.6 && x > 9.6 ? 'l' : 'd')),
        paint(capsule(14, 8.8, 17, 5.6, 1.9), (x, y) => (x - y > 9.4 ? 'l' : 'd')),
        paint(polygon([[16.6, 2.4], [11.8, 0], [13.4, 1.8], [11.6, 2], [15.6, 3.8]]), 'P'),
        paint(circle(18, 4.6, 2.7), 'd'),
        paint(circle(18.9, 4, 1), 'w', { detail: true }),
        paint(polygon([[20.2, 4.2], [24, 5.6], [20.2, 6.2]]), 'Y', { detail: true }),
    ];
    return {
        refH: 18,
        body: [12, 10],
        eye: [19.3, 4.1],
        parts: [...leg(far, true), ...moved(upper, 0, bob), ...leg(near, false)],
    };
}
const ROADRUNNER = {
    fly: [0, 1, 2, 3].map(roadrunnerPose),
    hitPose: roadrunnerPose(0),
    loose: ['d', 'D', 'P'],
    crossFill: 'd',
    eye: [19.2, 4.2],
};

// A shambling worker zombie, about as tall as a player's character: yellow hard hat, denim overalls over a torn
// grey shirt, arms out front. Drawn at native size (24-px grid, k = 1); the anchor is between the feet.
// `only` keeps some of the parts (farLeg, farArm, nearLeg, torso, face, hat, nearArm): the pieces a zombie falls into.
function zombiePose({ near = [14, 30], far = [9, 30], bob = 0, sway = 0, arms = 0, head = 0, hat = 0, cross = false, only = null } = {}) {
    const hip = [11.5, 21 + bob];
    const leg = ([fx, fy], dark) => [
        paint(capsule(hip[0] + (dark ? -0.8 : 0.8), hip[1], fx, fy - 1, 1.7), dark ? 'D' : 'd'),
        paint(capsule(fx - 0.8, fy, fx + 2, fy, 1), 'm'),
    ];
    const torso = [
        paint(polygon([[7.4, 12.4], [15.4, 12.4], [16, 21.4], [7, 21.4]]), (x, y) => (x < 9 ? 'g' : 'G')),
        paint(polygon([[9, 15.2], [15, 15.2], [16, 21.8], [7.2, 21.8]]), (x) => (x < 9.2 ? 'D' : 'd')),
        paint(capsule(10, 12.8, 10, 15.2, 0.5), 'd', { detail: true }),
        paint(capsule(14, 12.8, 14, 15.2, 0.5), 'd', { detail: true }),
        // A ragged tear in the shirt shows green skin.
        paint(circle(12.6, 14, 0.7), 'Z', { detail: true }),
    ];
    const farArm = [
        paint(capsule(12.6, 14, 19.4 + sway, 15 + arms, 1.6), 'g'),
        paint(capsule(19.6 + sway, 15 + arms, 21.4 + sway, 15.4 + arms, 1.3), 'Z'),
    ];
    const nearArm = [
        paint(capsule(11.2, 13.6, 18.4 - sway, 13 + arms, 1.7), (x, y) => (y > 13.6 + arms * 0.5 ? 'g' : 'G'), { wing: true }),
        paint(capsule(18.8 - sway, 13 + arms, 21.4 - sway, 13.2 + arms, 1.4), 'z', { wing: true }),
    ];
    const face = [
        paint(capsule(11, 11.6, 11.6, 13, 1.2), 'Z'),
        paint(ellipse(12.2, 8, 4.2, 4.2), (x, y) => (x < 9.6 || y > 10.8 ? 'Z' : 'z')),
        paint(capsule(14.2, 7.2, 14.8, 7.2, 0.55), 'w', { detail: true }),
        paint(capsule(14.4, 10.2, 15.6, 10.4, 0.5), 'K', { detail: true }),
    ];
    const hardHat = [
        paint((x, y) => ellipse(11.8, 4.6, 4.6, 3)(x, y) && y < 4.8, (x, y) => (x < 10.6 && y < 3.4 ? 'y' : 'Y')),
        paint(capsule(7, 4.8, 17.6, 4.8, 0.7), 'Y'),
    ];
    const lean = head * 0.5;
    const pose = {
        refH: 32,
        body: [11.5, 31],
        eye: cross ? [14.5, 7.2] : null,
        parts: Object.entries({
            farLeg: leg(far, true),
            farArm: moved(farArm, lean, bob),
            nearLeg: leg(near, false),
            torso: moved(torso, 0, bob),
            face: moved(face, lean + head, bob + Math.abs(head) * 0.3),
            hat: moved(hardHat, lean + head - hat * 2.4, bob - hat),
            nearArm: moved(nearArm, lean, bob),
        }).flatMap(([name, list]) => (!only || only.includes(name) ? list : [])),
    };
    return pose;
}
const ZOMBIE_WALK = [
    { near: [15, 30], far: [8, 30], bob: 0, sway: 0 },
    { near: [12.6, 29.2], far: [10.2, 30], bob: -1, sway: 0.8, arms: -0.6, head: 0.4 },
    { near: [9.6, 30], far: [13, 30], bob: 0, sway: 0, arms: 0.4 },
    // The far leg drags: it slides along the ground instead of stepping.
    { near: [11, 30], far: [11.8, 30], bob: 0.5, sway: -0.6, arms: 0.8, head: -0.4 },
];

function trim(sprite) {
    const filled = (r) => /[^.]/.test(r);
    let top = sprite.rows.findIndex(filled);
    let bottom = sprite.rows.length - 1 - [...sprite.rows].reverse().findIndex(filled);
    if (top < 0) return sprite;
    let left = sprite.w;
    let right = 0;
    for (const r of sprite.rows) {
        const a = r.search(/[^.]/);
        if (a < 0) continue;
        left = Math.min(left, a);
        right = Math.max(right, r.length - 1 - [...r].reverse().findIndex((ch) => ch !== '.'));
    }
    const rows = sprite.rows.slice(top, bottom + 1).map((r) => r.slice(left, right + 1));
    return { ...sprite, rows, w: right - left + 1, h: bottom - top + 1, anchor: { x: sprite.anchor.x - left, y: sprite.anchor.y - top } };
}

function zombieSet() {
    const walk = ZOMBIE_WALK.map((p) => rasterise(zombiePose(p), 24));
    // Hit: head snapped back, arms flung up, the hard hat popping off.
    const hit = rasterise(zombiePose({ near: [14, 30], far: [9, 30], bob: 0, sway: -3, arms: -6, head: -1.4, hat: 1.4, cross: true }), 24);
    // Collapse: knees first (legs folded under a lowered body), then flat on the back, hat rolled away.
    const kneel = rasterise(zombiePose({ near: [16, 30], far: [14.6, 30], bob: 6, sway: -1, arms: 3, head: 1.2 }), 24);
    const flat = rotate90(rotate180(rasterise(zombiePose({ near: [13, 30], far: [10, 30], arms: -2, head: -0.8, hat: 1.2, cross: true }), 24)));
    const ground = (s) => {
        const t = trim(s);
        return { ...t, anchor: { x: t.w >> 1, y: t.h } };
    };
    // The deaths of finale step 5 (DEATHS): the hit pose without its head, the kneel and the flat body likewise, and
    // the pieces it falls apart into, each outlined on its own and trimmed to its pixels.
    const HIT_POSE = { near: [14, 30], far: [9, 30], bob: 0, sway: -3, arms: -6, head: -1.4, hat: 1.4, cross: true };
    const BODY = ['farLeg', 'farArm', 'nearLeg', 'torso', 'nearArm'];
    const piece = (only) => {
        const t = trim(rasterise(zombiePose({ ...HIT_POSE, only }), 24));
        return { ...t, anchor: { x: t.w >> 1, y: t.h >> 1 } };
    };
    const headless = {
        hit: { ...rasterise(zombiePose({ ...HIT_POSE, only: BODY }), 24), anchor: { x: 12, y: 31 } },
        kneel: { ...rasterise(zombiePose({ near: [16, 30], far: [14.6, 30], bob: 6, sway: -1, arms: 3, only: BODY }), 24), anchor: { x: 12, y: 31 } },
        flat: ground(rotate90(rotate180(rasterise(zombiePose({ near: [13, 30], far: [10, 30], arms: -2, only: BODY }), 24)))),
    };
    const pieces = {
        head: piece(['face', 'hat']),
        skull: piece(['face']),
        hat: piece(['hat']),
        torso: piece(['torso']),
        arms: [piece(['nearArm']), piece(['farArm'])],
        legs: [piece(['nearLeg']), piece(['farLeg'])],
    };
    // Every depth shares the depth-1 size: a ground walker does not shrink with depth the way a flyer does.
    const near = {
        fly: walk.map((s) => ({ ...s, anchor: { x: 12, y: 31 } })),
        hit: { ...hit, anchor: { x: 12, y: 31 } },
        fall: [{ ...kneel, anchor: { x: 12, y: 31 } }, ground(flat)],
        headless,
        pieces,
    };
    return [farSet('zombie'), near, near];
}

// A cursed earthworm for the blood-moon lawn, charged with the same violet energy as the pumpkins: its back, saddle
// and eye glow — light sources (night.js, EMISSIVE), so they stay lit when the scene relights the rest; a plain
// colour on the lawn would sink into the maroon. Only the belly is lit like the ground, which keeps the body round.
// Side view on one 16×9 grid for every frame, head to the right; the anchor is on the ground under the middle.
const WORM_LEGEND = {
    '.': null, O: 'outline', b: 'violetGlow', s: 'violet', h: 'violetHot', v: 'eyeViolet', V: 'violetHot', e: 'flameCore',
    w: 'magicCore', d: 'soil', D: 'soilDark', l: 'soilLight',
};
const WORM_W = 16;
const WORM_H = 9;
const WORM_NOSE = 14;
const bump = (u, a, b) => (u > a && u < b ? Math.sin((Math.PI * (u - a)) / (b - a)) : 0);

/**
 * The worm's body `len` columns long ending in the nose at WORM_NOSE, raised off the ground by `lift(u)` (u: 0 at the
 * tail, 1 at the nose). Columns outside `from`–`to` are underground. Thin tail, a thicker saddle, a two-pixel nose.
 */
function wormBody(len, lift = () => 0, { eye = 'e', from = 0, to = WORM_W - 1 } = {}) {
    const fill = Array.from({ length: WORM_H }, () => Array(WORM_W).fill(null));
    const tops = [];
    const tail = WORM_NOSE - len + 1;
    for (let x = Math.max(tail, from); x <= Math.min(WORM_NOSE, to); x++) {
        const u = (x - tail) / (len - 1);
        const saddle = u >= 0.6 && u <= 0.76;
        const nose = x === WORM_NOSE;
        const th = nose || u < 0.1 ? 2 : u < 0.3 ? 3 : saddle ? 5 : 4;
        const bottom = WORM_H - 1 - Math.max(0, Math.round(lift(u))) - (nose ? 1 : 0);
        tops[x] = bottom - th + 1;
        for (let y = tops[x]; y <= bottom; y++) fill[y][x] = saddle ? 'v' : 'b';
    }
    const empty = (x, y) => !fill[y]?.[x];
    const rows = fill.map((r, y) => r.map((c, x) => {
        if (!c) return '.';
        if (empty(x - 1, y) || empty(x + 1, y) || empty(x, y - 1) || empty(x, y + 1)) return 'O';
        // The upper row catches the light (a cream ring every third column), the lower one is in shade.
        if (y === tops[x] + 1) return c === 'v' ? 'V' : x % 3 === 0 ? 'h' : 'b';
        return c === 'v' ? 'v' : 's';
    }));
    if (tops[WORM_NOSE - 1] !== undefined && to >= WORM_NOSE) rows[tops[WORM_NOSE - 1] + 1][WORM_NOSE - 1] = eye;
    return wormSprite(rows.map((r) => r.join('')));
}

// Overlays a patch of rows ('.' is see-through) on a worm frame: the dirt it comes out of or goes into.
function overlay(sprite, patch) {
    const rows = sprite.rows.map((r, y) => [...r].map((ch, x) => (patch[y]?.[x] && patch[y][x] !== '.' ? patch[y][x] : ch)).join(''));
    return wormSprite(rows);
}

// Every frame shares the grid and the anchor; `box` is the opaque bounding box, the base of the hit box.
function wormSprite(rows) {
    let left = WORM_W;
    let right = -1;
    let top = WORM_H;
    rows.forEach((r, y) => [...r].forEach((ch, x) => {
        if (ch === '.') return;
        left = Math.min(left, x);
        right = Math.max(right, x);
        top = Math.min(top, y);
    }));
    return {
        w: WORM_W,
        h: WORM_H,
        rows,
        legend: WORM_LEGEND,
        anchor: { x: WORM_W >> 1, y: WORM_H },
        box: { x: left, y: top, w: right - left + 1, h: WORM_H - top },
    };
}

const blank = (n) => Array(n).fill('.'.repeat(WORM_W));

function wormSet() {
    // Crawl: stretched, the tail bunching up into a hump, the full arch, the hump running out toward the nose.
    const fly = [
        wormBody(15),
        wormBody(13, (u) => 2 * bump(u, 0.04, 0.6)),
        wormBody(11, (u) => 3 * bump(u, 0.12, 0.72)),
        wormBody(13, (u) => 2 * bump(u, 0.4, 0.96)),
    ];
    // Emerge: a nose pokes out of a mound, rears up, then the front lies down on the lawn with the tail still in.
    const emerge = [
        wormSprite([...blank(6),
            '......OO........',
            '....lObeOl......',
            '...lDdDDdl......']),
        wormSprite([...blank(3),
            '.......OO.......',
            '......ObeO......',
            '......ObsO......',
            '.....ObsO.......',
            '....lOhsOl......',
            '...lDdDDdl......']),
        overlay(wormBody(9, () => 0, { from: 6 }), [...blank(7),
            '....lDdl........',
            '...lDdDdDl......']),
    ];
    // Burrow at the end of the path: the nose dives into a mound ahead, then only the tail tip sticks out.
    const burrow = [
        overlay(wormBody(11, () => 0, { to: 11 }), [...blank(7),
            '..........lDdl..',
            '.........lDdDdDl']),
        wormSprite([...blank(5),
            '..........O.....',
            '.........ObO....',
            '........lObOl...',
            '........lDdDdDl.']),
    ];
    // Hit: wide-eyed, it writhes into an S (the scene throws it from side to side); then it sinks where it was hit —
    // a loop of it left above the dirt, then the mound with a last violet spark.
    const hit = wormBody(13, (u) => 3.4 * (2 * u - 1) ** 2, { eye: 'w' });
    const fall = [
        wormSprite([...blank(4),
            '......OOOO......',
            '.....ObhbbO.....',
            '.....ObOOsO.....',
            '....lOsOOsOl....',
            '...lDdDdDdDdl...']),
        wormSprite([...blank(6),
            '........V.......',
            '......ldvdl.....',
            '....lDdDdDdl....']),
    ];
    const set = { fly, emerge, burrow, hit, fall };
    return [set, set, set];
}

// A firefly of the cursed world, drawn by hand per depth (a speck, ~8 px, ~12 px): side view, head to the right, a
// red shield behind the head, dark wing cases, pale wings beating over them, the lantern at the tail. The body is
// drawn as it is like any flyer; the lantern's light is a separate layer in FrankenPHP's lime (finale.js glow keys,
// night.js EMISSIVE) to draw over it — `glow`: [dim, mid, bright] frames on the same grid; a shot firefly's lantern
// shows dull, unlit. `halo` — the lantern point for the scene's soft light around it.
const FIREFLY_LEGEND = {
    '.': null, O: 'outline', k: 'barkDark', b: 'bark', x: 'crimson', X: 'crimsonDark', w: 'ghost', W: 'ghostShade',
    a: 'frankenDeep', A: 'frankenDark', L: 'frankenGlowCore', l: 'frankenGlowHot', g: 'frankenGlow', G: 'frankenGlowDark',
};
const FIREFLY = [
    {
        anchor: { x: 1, y: 1 },
        body: ['.wk', 'Akx'],
        wings: [['.w.', '...'], ['...', '...'], ['...', '.w.'], ['...', '...']],
        hit: ['w.w', 'Akx'],
        glow: [['...', 'G..'], ['...', 'g..'], ['...', 'L..']],
        lantern: [0, 1],
    },
    {
        anchor: { x: 4, y: 3 },
        body: [
            '........',
            '........',
            '..Okkxx.',
            'OAAkkxXO',
            '.OaO.O..',
        ],
        wings: [
            ['...ww...', '..wW....'],
            ['........', '...ww...'],
            ['........', '........'],
            ['........', '...ww...'],
        ],
        down: ['........', '........', '..Okkxx.', 'OAAkWxXO', '.OaWwO..'],
        hit: ['.w...w..', '..w.w...', '..Okkxx.', 'OAAkkxXO', '.OaO.O..'],
        glow: [
            ['........', '........', '........', '.G......', '..G.....'],
            ['........', '........', '........', '.gG.....', '..G.....'],
            ['........', '........', '........', '.lL.....', '..l.....'],
        ],
        lantern: [1, 3],
    },
    {
        anchor: { x: 6, y: 5 },
        body: [
            '............',
            '............',
            '............',
            '....OOOOO...',
            '..OObkkkxxO.',
            '.OAAkkkkxXxO',
            'OAaAkkkbOOOO',
            '.OaaOOOO.O..',
            '..OO..O.O...',
        ],
        wings: [
            ['......ww....', '.....wWw....', '....wWw.....', '............'],
            ['............', '............', '...wwWw.....', '..wWww......'],
            ['............', '............', '............', '............'],
            ['............', '............', '...wwWw.....', '..wWww......'],
        ],
        down: [
            '............', '............', '............', '....OOOOO...', '..OObkkkxxO.',
            '.OAAkWWwxXxO', 'OAaAWwwbOOOO', '.OaaOwwO.O..', '..OO..O.O...',
        ],
        hit: [
            '..w......w..', '...w....w...', '....w..w....', '....OOOOO...', '..OObkkkxxO.',
            '.OAAkkkkxXxO', 'OAaAkkkbOOOO', '.OaaOOOO.O..', '..OO..O.O...',
        ],
        glow: [
            ['', '', '', '', '', '..G.........', '.GgG........', '..GG........'],
            ['', '', '', '', '', '..g.........', '.glg........', '..gG........'],
            ['', '', '', '', '', '..lg........', '.lLl........', '..ll........'],
        ],
        lantern: [2, 6],
    },
];

function fireflySet() {
    return FIREFLY.map((d) => {
        const w = d.body[0].length;
        const make = (rows) => {
            const full = d.body.map((_, i) => (rows[i] || '').padEnd(w, '.'));
            return { w, h: full.length, rows: full, legend: FIREFLY_LEGEND, anchor: { ...d.anchor } };
        };
        // Wings over the body rows; the down stroke has the wing over the wing case (`down`).
        const fly = d.wings.map((wing, i) => make(i === 2 && d.down ? d.down : d.body.map((row, y) => {
            const over = wing[y];
            return over ? [...row].map((ch, x) => (over[x] && over[x] !== '.' ? over[x] : ch)).join('') : row;
        })));
        const hit = make(d.hit);
        return { fly, hit, fall: [rotate90(hit), rotate180(hit)], glow: d.glow.map(make), lantern: d.lantern };
    });
}

// A fly of the bar: a dark speck ~6×4 with a red eye, head to the right, one size at every depth. The wings are the
// brightest pixels it has, two tones swapped every frame, so the fast flicker reads as see-through and stays apart
// from the warm wood. Drawn as it is and lit by the hall's fire like any flyer, nothing emissive. The anchor is under
// its feet: a sitting fly stands on its stop.
const FLY_LEGEND = { '.': null, O: 'outline', k: 'crowDark', c: 'crowLight', r: 'crimson', w: 'white', W: 'ghostShade' };
const FLY = {
    fly: [
        ['..wW..', '..w...', 'OkkcOr', '.OkkO.', '......'],
        ['......', '.wWw..', 'OkkcOr', '.OkkO.', '......'],
        ['......', '......', 'OkkcOr', '.OkkO.', '..Ww..'],
        ['......', '.WwW..', 'OkkcOr', '.OkkO.', '......'],
    ],
    // Wings folded back over the body, a pale roof on the dark; then the front legs rubbed together (two frames) and a
    // twitch of the wings.
    sit: [
        ['......', '.wWW..', 'WkkcOr', '.OkkO.', '.O.O..'],
        ['......', '.wWW..', 'WkkcOr', '.OkkOO', '.O..O.'],
        ['......', '.wWW..', 'WkkcOr', '.OkkOO', '.O.O..'],
        ['.W.w..', 'w.W...', 'OkkcOr', '.OkkO.', '.O.O..'],
    ],
    hit: ['.w..w.', '..ww..', 'OkkcOr', '.OkkO.', '.O.O..'],
    // Where a shot fly ends up: a dark smear with a drop of red.
    splat: ['k..r..k', '.kOOOk.', 'k.kOk.k'],
};

function flySet() {
    const make = (rows) => ({
        ...sprite(rows, FLY_LEGEND, { x: 3, y: 4 }),
        // One body box for every frame, so the hit box does not jump with the wings.
        box: { x: 0, y: 1, w: 6, h: 4 },
    });
    const hit = make(FLY.hit);
    const set = {
        fly: FLY.fly.map(make),
        sit: FLY.sit.map(make),
        hit,
        fall: [rotate90(hit), rotate180(hit)],
        splat: sprite(FLY.splat, FLY_LEGEND, { x: 3, y: 2 }),
    };
    return [set, set, set];
}

function poseSet(def, kind, { post = (s) => s } = {}) {
    return DEPTH_W.map((w, depth) => {
        if (depth === 0) return farSet(kind);
        const fly = def.fly.map((pose, i) => {
            const s = post(rasterise(pose, w, null), w);
            if (def.jitter) s.anchor = { ...s.anchor, y: s.anchor.y + def.jitter[i] };
            return s;
        });
        const hit = post(hitFrame(def, w), w);
        return { fly, hit, fall: [rotate90(hit), rotate180(hit)] };
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

const FEATHER_LEGEND = {
    G: 'duckGray', g: 'duckGrayDark', B: 'duckBrown', b: 'duckBrownLight', w: 'white', W: 'chickenShade',
    C: 'crowDark', c: 'crow', v: 'crowLight', T: 'bat', U: 'batWing', q: 'ghost', E: 'ecto', Q: 'ghostShade',
    n: 'hay', N: 'hayDark', y: 'pumpkinShine', s: 'white', d: 'denim', D: 'denimDark', z: 'zombie', Z: 'zombieShade',
    o: 'soil', O: 'soilDark', l: 'soilLight', P: 'violetGlow',
};

/**
 * Indexed by depth (0 — far, 2 — near): { fly: 4 wingbeat frames, hit, fall: 2 tumbling frames }.
 * `roadrunner` and `zombie` move on the ground: `fly` is their run / walk cycle. The road-runner's far sprite is
 * 7×4, a little more than a speck. The zombie keeps one size at depths 1 and 2, its anchor is between the feet, and its
 * fall frames collapse in place (kneel, then flat) instead of tumbling; for its deaths (DEATHS) it adds `headless`
 * (hit, kneel, flat without the head) and `pieces` (head, skull, hat, torso, arms, legs — anchored at their middle). `worm` crawls on the lawn too: one 16×9 set at
 * every depth, anchor on the ground under its middle, `box` — the opaque bounds; besides the crawl it has `emerge` (3
 * frames, out of a mound) and `burrow` (2, into one), its `hit` writhes and `fall` sinks it into the ground in place.
 * `firefly` adds `glow` (its lantern: dim, mid, bright, on the same grid, to draw unlit over the body) and `lantern` —
 * the lantern pixel, for the halo. `fly` (the bar's) is one 6×5 set at every depth, anchored under its feet, with a
 * fixed body `box`; besides 4 fast wing frames it has `sit` (wings folded, two leg-rub frames, a wing twitch) and
 * `splat` — what is left of a shot fly.
 */
export const FLYERS = {
    duck: flyerSet(DUCK),
    chicken: flyerSet(CHICKEN),
    crow: poseSet(CROW, 'crow'),
    bat: poseSet(BAT, 'bat'),
    ghost: poseSet(GHOST, 'ghost', { post: (s, w) => ditherHem(s, w > 20 ? 2 : 1) }),
    witch: poseSet(WITCH, 'witch'),
    roadrunner: poseSet(ROADRUNNER, 'roadrunner'),
    zombie: zombieSet(),
    worm: wormSet(),
    firefly: fireflySet(),
    fly: flySet(),
    // Each feather knows whose it is, so a burst can pick the right ones.
    feathers: [
        { ...sprite(['.G', 'Gg', 'g.'], FEATHER_LEGEND), kind: 'duck' },
        { ...sprite(['bB.', '.Bb'], FEATHER_LEGEND), kind: 'duck' },
        { ...sprite(['.w', 'wW', 'W.'], FEATHER_LEGEND), kind: 'chicken' },
        { ...sprite(['ww.', '.wW', '..W'], FEATHER_LEGEND), kind: 'chicken' },
        { ...sprite(['..c', '.cC', 'C..'], FEATHER_LEGEND), kind: 'crow' },
        { ...sprite(['vc.', '.cC', '..C'], FEATHER_LEGEND), kind: 'crow' },
        // Bats lose scraps of wing, not feathers.
        { ...sprite(['U.U', 'UUU'], FEATHER_LEGEND), kind: 'bat' },
        { ...sprite(['T.', 'TU'], FEATHER_LEGEND), kind: 'bat' },
        // A ghost bursts into puffs of ectoplasm.
        { ...sprite(['.q.', 'qEq', '.Q.'], FEATHER_LEGEND), kind: 'ghost' },
        { ...sprite(['Eq', 'qE'], FEATHER_LEGEND), kind: 'ghost' },
        { ...sprite(['.E.', 'EsE', '.E.'], FEATHER_LEGEND), kind: 'ghost' },
        // The witch sheds broom straw and sparks of her magic.
        { ...sprite(['n..', '.N.', '..n'], FEATHER_LEGEND), kind: 'witch' },
        { ...sprite(['..n', 'nN.'], FEATHER_LEGEND), kind: 'witch' },
        { ...sprite(['.y.', 'ysy', '.y.'], FEATHER_LEGEND), kind: 'witch' },
        { ...sprite(['.d', 'dD', 'D.'], FEATHER_LEGEND), kind: 'roadrunner' },
        { ...sprite(['z.', 'Zz'], FEATHER_LEGEND), kind: 'zombie' },
        { ...sprite(['GG', 'g.'], FEATHER_LEGEND), kind: 'zombie' },
        // A worm throws up clods of dirt and a violet spark.
        { ...sprite(['.o', 'oO'], FEATHER_LEGEND), kind: 'worm' },
        { ...sprite(['lo.', 'oOO'], FEATHER_LEGEND), kind: 'worm' },
        { ...sprite(['o'], FEATHER_LEGEND), kind: 'worm' },
        { ...sprite(['.P.', 'P.P'], FEATHER_LEGEND), kind: 'worm' },
        // A firefly bursts into lime sparks of its lantern.
        { ...sprite(['L'], FIREFLY_LEGEND), kind: 'firefly' },
        { ...sprite(['.g.', 'gLg', '.g.'], FIREFLY_LEGEND), kind: 'firefly' },
        { ...sprite(['l.', '.G'], FIREFLY_LEGEND), kind: 'firefly' },
    ],
};

/**
 * How a zombie worker dies in the cursed world (docs/spec/frontend.md, «Рапира»): DEATHS[crc32(flyer) % DEATHS.length],
 * the same for everyone, the server sends nothing. Add new kinds at the end, so the ones there keep their indexes.
 */
export const DEATHS = ['pieces', 'meat', 'dust', 'head', 'fire', 'sink'];

/** The death of the zombie with flyer id `id`. */
export const deathOf = (id) => DEATHS[seedOf(id) % DEATHS.length];

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
