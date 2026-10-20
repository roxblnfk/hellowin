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

/** The same frame with glowing eyes, for the night scenes. */
export function glowing(sprite) {
    return { ...sprite, legend: NIGHT_LEGEND };
}
