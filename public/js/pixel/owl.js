/**
 * The owl — the moderator's character, sitting on the tree branch (DECOR.tree.perch) through every scene.
 * Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as sprites.js; bake it
 * with any of the modules' bake(). Front-facing, symmetric. Anchor: the talons' row, so placing the anchor on the
 * perch point lays the talons over the top row of the branch and the owl grips it instead of hovering above.
 */

const LEGEND = {
    '.': null,
    O: 'outline',
    D: 'owlDark',
    M: 'owl',
    L: 'owlLight',
    F: 'owlFace',
    E: 'owlEye',
    K: 'outline',
    b: 'hayDark',
    a: 'gray',
};

// At night only the eyes change: they glow instead of reflecting.
const NIGHT_LEGEND = { ...LEGEND, E: 'owlEyeGlow' };

// The owl is drawn on a 13 × 17 body and padded to 19 × 18, so the breathing, the raised wing and the hoot can grow
// past the outline without the canvas or the anchor moving between frames.
const PAD_X = 3;
const PAD_Y = 1;
const W = 13 + PAD_X * 2;
const H = 17 + PAD_Y;

// Great horned owl: ear tufts, a pale facial disc with big amber eyes, wings folded at the sides, a light breast
// barred with dark chevrons.
const BASE = [
    '.O.........O.',
    '.OD.......DO.',
    '.ODMOOOOOMDO.',
    'OMLLMMMMMMMMO',
    'OMFFFFMFFFFMO',
    'OFEEEFMFEEEFO',
    'OFEKEFbFEKEFO',
    'OFEEEFbFEEEFO',
    'OMFFFFFFFFFMO',
    'ODMLLLLLLLMDO',
    'ODMLDLLLDLMDO',
    'ODMLLLDLLLMDO',
    'ODMLDLLLDLMDO',
    '.ODMLLLLLMDO.',
    '.ODMMLLLMMDO.',
    '..OODMMMDOO..',
    '...aa...aa...',
];

const EYES = {
    blink: { 5: 'OFFFFFMFFFFFO', 6: 'OFDDDFbFDDDFO', 7: 'OFFFFFbFFFFFO' },
    // Beak open: the lower mandible drops a pixel and the mouth shows dark.
    open: { 7: 'OFEEEFKFEEEFO', 8: 'OMFFFFbFFFFMO' },
    // Half and full swivel to the viewer's left: the face slides across the head, the pupils lead.
    half: {
        0: 'O.........O..', 1: 'OD.......DO..', 2: 'ODMOOOOOMDOO.',
        4: 'OFFFFMFFFFMMO', 5: 'OEEEFMFEEEFMO', 6: 'OKEEFbFKEEFMO', 7: 'OEEEFbFEEEFMO', 8: 'OFFFFFFFFFMMO',
    },
    full: {
        0: 'O........O...', 1: 'OD......DO...', 2: 'ODMOOOOODOOO.',
        4: 'OFFFMFFFFMMMO', 5: 'OEEFMFEEEFMMO', 6: 'OKEFbFKEEFMMO', 7: 'OEEFbFEEEFMMO', 8: 'OFFFFFFFFMMMO',
    },
    // Swivelled all the way round: the back of the head, streaked.
    back: {
        3: 'OMLLMMMMMMMMO', 4: 'OMMDMMMMMDMMO', 5: 'OMMMMDMDMMMMO', 6: 'OMDMMMDMMMDMO', 7: 'OMMMMMMMMMMMO', 8: 'ODMMMMMMMMMDO',
    },
    // Hoot: round, staring eyes and the beak wide open.
    hoot: {
        4: 'OMFEEFMFEEFMO', 5: 'OFEEEFMFEEEFO', 6: 'OFEKEFbFEKEFO', 7: 'OFEEEFKFEEEFO', 8: 'OMFEEFbFEEFMO',
    },
};

// Inhale: the head rises a pixel on a longer neck, the outline of the body stays put.
const BREATH = { lift: true };
// Rows 15 wide, starting one column left of the body: the plumage puffs up past the folded wings.
const PUFF = {
    9: 'ODDMLLLLLLLMDDO', 10: 'ODDMLDLLLDLMDDO', 11: 'ODDMLLLDLLLMDDO', 12: 'ODDMLDLLLDLMDDO',
    13: '.ODDMLLLLLMDDO.', 14: '..ODMMLLLMMDO..',
};

// The right wing (viewer's right) lifted out from the shoulder: a talking gesture. Padded coordinates.
const WING = [
    ['..O.', '.OLO', '.OLO', 'OLDO', 'OLDO', 'OMO.', 'ODO.', 'OO..'].flatMap((r, y) => [...r].map((ch, x) => [15 + x, 5 + y, ch])),
    // Where the wing was folded, the barred breast shows.
    [[14, 10, 'L'], [14, 11, 'L'], [14, 12, 'L']],
].flat().filter(([, , ch]) => ch !== '.');

// Tufts pricked up for the hoot.
const TUFTS_UP = [[4, 0, 'O'], [14, 0, 'O'], [4, 1, 'D'], [14, 1, 'D']];

function frame({ rows = {}, wide = {}, patches = [], lift = false } = {}) {
    const grid = Array.from({ length: H }, () => Array(W).fill('.'));
    BASE.forEach((base, r) => {
        const row = rows[r] ?? base;
        // Lifted, the head rows move up one and the top breast row fills the gap under the face.
        const y = lift && r <= 8 ? r - 1 : r;
        [...row].forEach((ch, c) => {
            if (ch !== '.') grid[y + PAD_Y][c + PAD_X] = ch;
        });
        if (lift && r === 8) [...'ODMLLLLLLLMDO'].forEach((ch, c) => { grid[r + PAD_Y][c + PAD_X] = ch; });
        if (wide[r]) {
            for (let c = 0; c < 15; c++) grid[r + PAD_Y][c + PAD_X - 1] = wide[r][c];
        }
    });
    for (const [x, y, ch] of patches) grid[y][x] = ch;
    return {
        w: W,
        h: H,
        rows: grid.map((r) => r.join('')),
        legend: LEGEND,
        anchor: { x: PAD_X + 6, y: H - 1 },
    };
}

function mirror(sprite) {
    return { ...sprite, rows: sprite.rows.map((r) => [...r].reverse().join('')), anchor: { x: sprite.w - 1 - sprite.anchor.x, y: sprite.anchor.y } };
}

const half = frame({ rows: EYES.half });
const full = frame({ rows: EYES.full });

/**
 * idle — 2 frames, breathing (~700 ms each); blink — 1 frame (~120 ms) over either idle frame;
 * turnLeft / turnRight — [half, full] swivel, play forward and back; back — the head turned all the way round;
 * talk — 3 frames: closed, beak open, open with the wing raised (alternate the first two, add the third for
 * emphasis); hoot — puffed up, tufts raised, eyes staring, beak wide: the dramatic lines.
 * All frames are 19 × 18 with the anchor at (9, 17), on the talons.
 */
export const OWL = {
    idle: [frame(), frame(BREATH)],
    blink: frame({ rows: EYES.blink }),
    turnLeft: [half, full],
    turnRight: [mirror(half), mirror(full)],
    back: frame({ rows: EYES.back }),
    talk: [frame(), frame({ rows: EYES.open }), frame({ rows: EYES.open, patches: WING })],
    hoot: frame({ rows: EYES.hoot, wide: PUFF, patches: TUFTS_UP }),
};

// ---------------------------------------------------------------------------------------------------------------
// Flight (the finale's step 5: the owl leaves its branch). The body stays the perched drawing; the wings open out of
// it, behind the body, from the shoulders. Frames are 53 × 22 with the anchor on the talons like the perched ones, so
// the take-off starts exactly where the owl sat.

const FLY_PAD_X = 20;
const FLY_PAD_Y = 4;
const FLY_W = 13 + FLY_PAD_X * 2;
const FLY_H = 22;

// The right wing (viewer's right) per pose, from the shoulder: leading edge out to the tip, then the trailing edge
// back. The left wing mirrors it.
const WING_POSES = {
    half: [[0, -1], [6, -7], [9, -9], [10, -6], [7, 0], [4, 3], [0, 4]],
    up: [[0, -1], [8, -8], [13, -12], [15, -11], [14, -6], [11, -1], [6, 3], [0, 4]],
    mid: [[0, -1], [8, -3], [15, -3], [19, 0], [17, 2], [13, 4], [7, 6], [0, 4]],
    down: [[0, -1], [7, 2], [12, 5], [14, 8], [10, 8.5], [6, 7.5], [2, 6], [0, 4]],
};

const inPolygon = (pts, x, y) => {
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const [xi, yi] = pts[i];
        const [xj, yj] = pts[j];
        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
};

function flightFrame(pose, { crouch = 0 } = {}) {
    const grid = Array.from({ length: FLY_H }, () => Array(FLY_W).fill('.'));
    const top = FLY_PAD_Y + crouch;
    const wings = Array.from({ length: FLY_H }, () => Array(FLY_W).fill('.'));
    const sx = FLY_PAD_X + 11.5;
    const sy = top + 9;
    const pts = WING_POSES[pose];
    const tip = Math.max(...pts.map(([x, y]) => Math.hypot(x, y)));
    for (let y = 0; y < FLY_H; y++) {
        for (let x = 0; x < FLY_W; x++) {
            for (const m of [1, -1]) {
                const dx = m * (x + 0.5 - (FLY_PAD_X + 6.5)) - 5;
                const dy = y + 0.5 - sy;
                if (!inPolygon(pts, dx, dy)) continue;
                // Coverts near the body, barred dark primaries out at the tip, a light leading edge.
                const t = Math.hypot(dx, dy) / tip;
                const lead = !inPolygon(pts, dx, dy - 1.2);
                wings[y][x] = lead ? 'L' : t > 0.62 ? ((x + y) % 3 === 0 ? 'L' : 'D') : 'M';
            }
        }
    }
    // Outline the wings, then lay the body over them.
    for (let y = 0; y < FLY_H; y++) {
        for (let x = 0; x < FLY_W; x++) {
            if (wings[y][x] !== '.') grid[y][x] = wings[y][x];
            else if ([[1, 0], [-1, 0], [0, 1], [0, -1]].some(([a, b]) => (wings[y + b]?.[x + a] ?? '.') !== '.')) grid[y][x] = 'O';
        }
    }
    const legs = crouch ? ['...aa...aa...'] : ['....a...a....', '...aa...aa...'];
    [...BASE.slice(0, 16), ...legs].forEach((row, r) => [...row].forEach((ch, c) => {
        if (ch !== '.' && grid[top + r]) grid[top + r][FLY_PAD_X + c] = ch;
    }));
    return {
        w: FLY_W,
        h: FLY_H,
        rows: grid.map((r) => r.join('')),
        legend: LEGEND,
        anchor: { x: FLY_PAD_X + 6, y: FLY_H - 1 },
    };
}

/**
 * The owl taking off and flying away, front view, 53 × 22, anchor (26, 21) on the talons — the perch point, as for
 * OWL. takeOff: crouched on the branch with the wings half open (~150 ms, then lift off); fly: 4 wingbeat frames
 * [up, mid, down, mid] (~90 ms each), talons hanging. Works with glowing() at night.
 */
export const OWL_FLIGHT = {
    takeOff: flightFrame('half', { crouch: 1 }),
    fly: [flightFrame('up'), flightFrame('mid'), flightFrame('down'), flightFrame('mid')],
};

/** The same frame with glowing eyes, for the night scenes. */
export function glowing(sprite) {
    return { ...sprite, legend: NIGHT_LEGEND };
}
