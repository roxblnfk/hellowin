import { PALETTE } from './palette.js';

/**
 * Paper-doll Halloween characters. A look picks one part per slot (body, cloak, face, eyes, hat); a frame is composed
 * from outlined layers: cloak-back → body → face → hat → cloak-front → item → arm with the item.
 * The body owns the animation: one shared rig gives, per direction/pose/frame, the torso drop, the head box and the
 * hand points; faces, hats and items are drawn once per direction and placed at those points.
 * Plain 2D canvas, no PixiJS: the join screen draws previews with it and the scene makes textures from the canvases.
 * Side view faces right; the caller mirrors it for the left.
 */

// Every frame of every look shares this canvas, so the anchor never moves between frames.
const W = 32;
const H = 40;
// Local coordinates: (0, 0) is the anchor — the bottom edge of the canvas, between the feet. Pixels have x in
// -16..15 and y in -40..-1; a figure symmetric around x = 0 mirrors onto itself when the caller flips the sprite.
const AX = 16;
const AY = 40;

const LEG_TOP = -5;
const FOOT = -2;
const TORSO_TOP = -11;
const TORSO_BOTTOM = -6;
const HEAD_Y = -21;

const HEAD_MASK = [
    '..########..',
    '.##########.',
    '############',
    '############',
    '############',
    '############',
    '############',
    '############',
    '############',
    '.##########.',
    '..########..',
];

// One step down every ramp: far limbs, the shaded side of the torso and the hair.
const DARK = {
    skin: 'skinShade',
    pale: 'paleShade',
    zombie: 'zombieShade',
    cream: 'seedShade',
    seedShade: 'hayDark',
    leafShine: 'leafLight',
    leafLight: 'leaf',
    violetLight: 'violet',
    violet: 'violetDark',
    comb: 'crimson',
    crimson: 'crimsonDark',
    crimsonDark: 'pumpkinDeep',
    denim: 'denimDark',
    barkLight: 'bark',
    bark: 'barkDark',
    hayLight: 'hay',
    hay: 'hayDark',
    white: 'grayLight',
    grayLight: 'gray',
    gray: 'duckGrayDark',
    haze: 'hazeDark',
    hazeLight: 'haze',
    pumpkin: 'pumpkinDark',
    pumpkinDark: 'pumpkinDeep',
    gold: 'pumpkinGold',
    stem: 'stemDark',
    stemLight: 'stem',
};
const dark = (key) => DARK[key] ?? key;

// ---------------------------------------------------------------------------------------------------------------
// Grid helpers: a layer is a W×H array of palette keys (null — empty) addressed in local coordinates.

const blank = () => new Array(W * H).fill(null);

function put(g, x, y, key) {
    const cx = AX + x;
    const cy = AY + y;
    if (!key || cx < 0 || cx >= W || cy < 0 || cy >= H) return;
    g[cy * W + cx] = key;
}

/** Rows of legend chars with the top-left at (x0, y0); '.' is skipped, a char missing from the legend is skipped. */
function stamp(g, x0, y0, rows, legend, flip = false) {
    const w = Math.max(...rows.map((r) => r.length));
    rows.forEach((row, y) => [...row].forEach((ch, i) => {
        if (legend[ch]) put(g, x0 + (flip ? w - 1 - i : i), y0 + y, legend[ch]);
    }));
}

/** Paints every pixel whose centre passes the test; color(x, y) may return null to leave a hole. */
function fill(g, test, color) {
    for (let y = -H; y < 0; y++) {
        for (let x = -AX; x < W - AX; x++) {
            if (test(x + 0.5, y + 0.5)) put(g, x, y, color(x, y));
        }
    }
}

const polygon = (pts) => (x, y) => {
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
        const [xi, yi] = pts[i];
        const [xj, yj] = pts[j];
        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
};

/** A limb: 2×2 blocks stepped from a to b; color(i, n, dx) gets the step, the step count and the block column. */
function stroke(g, [x1, y1], [x2, y2], color) {
    const n = Math.max(Math.abs(x2 - x1), Math.abs(y2 - y1));
    for (let i = 0; i <= n; i++) {
        const t = n ? i / n : 0;
        const bx = Math.round(x1 + (x2 - x1) * t);
        const by = Math.round(y1 + (y2 - y1) * t);
        for (let dy = 0; dy < 2; dy++) {
            for (let dx = 0; dx < 2; dx++) put(g, bx + dx, by + dy, color(i, n, dx));
        }
    }
}

// Effects (muzzle flash, smoke) glow without an outline.
const isFx = (key) => key === 'fxFlash' || key === 'fxCore' || key === 'fxSmoke';

function outlined(g) {
    const out = g.slice();
    for (let cy = 0; cy < H; cy++) {
        for (let cx = 0; cx < W; cx++) {
            if (g[cy * W + cx]) continue;
            const solid = (x, y) => x >= 0 && x < W && y >= 0 && y < H && g[y * W + x] && !isFx(g[y * W + x]);
            if (solid(cx - 1, cy) || solid(cx + 1, cy) || solid(cx, cy - 1) || solid(cx, cy + 1)) {
                out[cy * W + cx] = 'outline';
            }
        }
    }
    return out;
}

function over(base, top) {
    for (let i = 0; i < base.length; i++) if (top[i]) base[i] = top[i];
}

// ---------------------------------------------------------------------------------------------------------------
// Rig: the shared skeleton. Arm points are top-left corners of 2×2 blocks; the back view mirrors the front one, a
// block at x mirrors to -x - 2.

const mirrorBlock = ([x, y]) => [-x - 2, y];

function rig(dir, pose, frame) {
    const side = dir === 'side';
    let bob = 0;
    let sx = 0;
    let lift = [0, 0];
    let step = [0, 0];
    let swing = [0, 0];
    let sway = 0;
    if (pose === 'idle') {
        bob = frame;
    } else if (pose === 'fidget') {
        // Weight goes onto one foot: the hips slide over it and the other knee relaxes.
        if (side) {
            lift = frame ? [1, 0] : [0, 1];
            step = frame ? [-1, 0] : [0, 1];
        } else {
            sx = frame ? 1 : -1;
            lift = frame ? [1, 0] : [0, 1];
        }
        sway = frame;
    } else if (pose === 'walk') {
        sway = frame & 1;
        if (side) {
            // [far, near]: contact, passing, contact, passing; the body is lowest on contact.
            bob = frame % 2 === 0 ? 1 : 0;
            step = [[-2, 2], [0, 0], [2, -2], [0, 0]][frame];
            lift = [[0, 0], [1, 0], [0, 0], [0, 1]][frame];
            swing = [[2, -2], [0, 0], [-2, 2], [0, 0]][frame];
        } else {
            bob = frame % 2;
            lift = [[1, 0], [0, 0], [0, 1], [0, 0]][frame];
            swing = [[0, -1], [0, 0], [-1, 0], [0, 0]][frame];
        }
    } else if (pose === 'shoot') {
        // The recoil frame rocks the hips back, away from the shot.
        sx = side ? -frame : dir === 'front' ? frame : -frame;
    }

    const top = TORSO_TOP + bob;
    const sh = top + 1;
    const handY = -7 + bob;
    const r = { dir, pose, frame, bob, sx, top, sway, head: { x: -6 + sx, y: HEAD_Y + bob } };
    const leg = (base, i, far) => ({
        hip: base + sx,
        foot: base + step[i] + (lift[i] ? sx : 0),
        lift: lift[i],
        far,
    });
    if (side) {
        r.torso = [-3 + sx, 3 + sx];
        r.legs = [leg(-2, 0, true), leg(-2, 1, false)];
        r.arms = [
            { sh: [sx, sh], hand: [sx + swing[0], handY], far: true },
            { sh: [-1 + sx, sh], hand: [-1 + sx + swing[1], handY], far: false },
        ];
        r.itemArm = 1;
        r.hold = { sh: [-1 + sx, sh], elbow: [-1 + sx, -7 + bob], hand: [2 + sx, -8 + bob] };
        r.aim = frame
            ? { sh: [-1 + sx, sh], hand: [5 + sx, -10] }
            : { sh: [-1 + sx, sh], hand: [6 + sx, -10] };
    } else {
        r.torso = [-5 + sx, 4 + sx];
        r.legs = [leg(-4, 0, false), leg(1, 1, false)];
        r.arms = [
            { sh: [-7 + sx, sh], hand: [-7 + sx, handY + swing[0]], far: false },
            { sh: [5 + sx, sh], hand: [5 + sx, handY + swing[1]], far: false },
        ];
        // The item hand is the character's right: viewer's left from the front, viewer's right from the back.
        const hold = { sh: [-7 + sx, sh], elbow: [-8 + sx, -7 + bob], hand: [-10 + sx, -9 + bob] };
        const aim = frame
            ? { sh: [-7 + sx, sh], hand: [-10 + sx, -15] }
            : { sh: [-7 + sx, sh], hand: [-11 + sx, -14] };
        if (dir === 'back') {
            const m = (p) => Object.fromEntries(Object.entries(p).map(([k, v]) => [k, mirrorBlock([v[0] - 2 * sx, v[1]])]));
            r.hold = m(hold);
            r.aim = m(aim);
            r.itemArm = 1;
        } else {
            r.hold = hold;
            r.aim = aim;
            r.itemArm = 0;
        }
    }
    return r;
}

// ---------------------------------------------------------------------------------------------------------------
// Bodies. torso(i, y, dir, w): i — column inside the torso box, y — local row (patterns stay put when the torso
// drops); rows below TORSO_BOTTOM belong to the skirt. leg(i, yy, y): i — column 0..2, yy — row from the hip.
// sleeve(i, n, dx): step along the arm. hair(c, r, dir, inHead): head-box coordinates, may reach one pixel outside.

const pick = (s, i) => PICK[s[i]] ?? null;
const PICK = {
    V: 'violetDark', v: 'violet', u: 'violetLight', w: 'white', c: 'crimson', C: 'crimsonDark', r: 'comb',
    e: 'cream', E: 'seedShade', g: 'gold', b: 'bark', B: 'barkLight', k: 'barkDark', h: 'hay', H: 'hayDark',
    L: 'hayLight', d: 'denim', D: 'denimDark', s: 'skin', p: 'pale', z: 'zombie', a: 'haze', A: 'hazeDark',
    o: 'pumpkin', O: 'pumpkinDark', y: 'pumpkinShine', t: 'stem', G: 'grayLight',
};
/** Torso pattern from rows keyed by local y; rows missing from the map use the fallback colour. */
const rowsPattern = (map, fallback) => (i, y) => (map[y] ? pick(map[y], i) : null) ?? fallback;

const BODIES = {
    skeleton: {
        label: 'Скелет',
        skin: 'cream',
        torso: (i, y, dir) => (dir === 'side'
            ? rowsPattern({ [-9]: 'VeeeeeV', [-8]: 'VeVVVVV', [-7]: 'VeeeeVV', [-6]: 'VeeeVVV' }, 'violetDark')
            : dir === 'back'
                ? rowsPattern({ [-9]: 'VeeVeeVeeV', [-8]: 'VVVVeeVVVV', [-7]: 'VVVVeeVVVV', [-6]: 'VVeeeeeeVV' }, 'violetDark')
                : rowsPattern({ [-9]: 'VeeVeeVeeV', [-8]: 'VVVVeeVVVV', [-7]: 'VeeVeeVeeV', [-6]: 'VVVeeeeVVV' }, 'violetDark'))(i, y),
        sleeve: (i, n, dx) => (dx === 0 && i > 0 ? 'cream' : 'violetDark'),
        leg: (i) => (i === 1 ? 'cream' : 'violetDark'),
        shoe: 'cream',
        hand: 'cream',
    },
    witch: {
        label: 'Ведьма',
        skin: 'leafShine',
        torso: (i, y, dir, w) => {
            if (y === -9 && dir === 'front' && (i === 4 || i === 5)) return 'leafShine';
            if (y === -7) return dir === 'front' && (i === 4 || i === 5) ? 'gold' : 'barkDark';
            if (y === -5) return i % 2 ? 'violetDark' : 'violet';
            return 'violet';
        },
        skirt: { hem: -5, flare: [1] },
        sleeve: (i, n) => (i >= n - 1 ? 'violetDark' : 'violet'),
        leg: (i, yy, y) => (y % 2 ? 'pumpkin' : 'violetDark'),
        shoe: 'barkDark',
        toe: 2,
        hair: (c, r, dir, inHead) => {
            const strand = (c === -1 || c === 0 || c === 11 || c === 12) && r >= 2 && r <= 11;
            if (dir === 'front') return (inHead && r <= 2) || (inHead && r === 3 && (c <= 3 || c >= 8)) || strand;
            if (dir === 'side') return (inHead && (r <= 2 || c <= 4)) || ((c === -1 || c === 0 || c === 1) && r <= 12 && r >= 2);
            return inHead || ((c === -1 || c === 12) && r >= 2 && r <= 11) || (r === 11 && c >= 0 && c <= 11);
        },
        hairColor: 'pumpkinDark',
    },
    robe: {
        label: 'Балахон',
        skin: 'skin',
        torso: (i, y, dir) => {
            if (y === -7) return 'hay';
            if (dir === 'front' && (y === -6 || y === -5) && i === 3) return 'hayLight';
            if (y <= -4 && (i === 0)) return 'barkLight';
            return 'bark';
        },
        skirt: { hem: -3, flare: [1, 1, 2] },
        sleeve: (i, n) => (i >= n - 1 ? 'barkDark' : 'bark'),
        leg: () => 'barkDark',
        shoe: 'stem',
    },
    wrestler: {
        label: 'Рестлер',
        skin: 'skin',
        torso: (i, y, dir) => (dir === 'side'
            ? rowsPattern({ [-10]: 'sscsss', [-9]: 'sscssss', [-7]: 'ggggggg' }, 'crimson')
            : dir === 'back'
                ? rowsPattern({ [-10]: 'sscssssscs', [-9]: 'ssscsscsss', [-7]: 'gggggggggg' }, 'crimson')
                : rowsPattern({ [-10]: 'sscsssscss', [-9]: 'sscsssscss', [-7]: 'gggyGGyggg' }, 'crimson'))(i, y),
        sleeve: (i, n) => (i === n - 1 ? 'white' : 'skin'),
        leg: (i, yy) => (yy === 0 ? 'crimson' : yy === 1 ? 'skin' : 'white'),
        shoe: 'crimsonDark',
        hair: (c, r, dir) => {
            if (dir === 'side') return r >= -1 && r <= 1 && c >= 2 && c <= 8;
            return r >= -1 && r <= (dir === 'back' ? 4 : 1) && c >= 5 && c <= 6;
        },
        hairColor: 'crimson',
    },
    vampire: {
        label: 'Вампир',
        skin: 'pale',
        torso: (i, y, dir) => (dir === 'front'
            ? rowsPattern({ [-10]: 'VVvwwwwvVV', [-9]: 'VVvwccwvVV', [-8]: 'VVvcwwcvVV', [-7]: 'VVVcwwcVVV' }, 'violetDark')
            : dir === 'side'
                ? rowsPattern({ [-10]: 'VVVVvww', [-9]: 'VVVVvwc', [-8]: 'VVVVVcw', [-7]: 'VVVVVcw' }, 'violetDark')
                : rowsPattern({ [-10]: 'VVVVvvVVVV' }, 'violetDark'))(i, y),
        sleeve: (i, n) => (i === n - 1 ? 'white' : 'violetDark'),
        leg: () => 'violetDark',
        shoe: 'barkDark',
        hand: 'pale',
        hair: (c, r, dir, inHead) => {
            if (!inHead) return false;
            if (dir === 'front') return r <= 1 || (r === 2 && c !== 4 && c !== 7) || (r === 3 && (c <= 1 || c >= 10 || c === 5 || c === 6));
            if (dir === 'side') return r <= 1 || (c <= 4 && r <= 6) || (r === 2 && c <= 8);
            return r <= 7;
        },
        hairColor: 'violetDark',
    },
    mummy: {
        label: 'Мумия',
        skin: 'cream',
        // Bandage turns: a seam every other row, stepping down by one where the wrap overlaps.
        torso: (i, y) => ((y + (i > 4 ? 1 : 0) + 40) % 2 === 0 ? 'seedShade' : 'cream'),
        sleeve: (i) => (i % 2 ? 'seedShade' : 'cream'),
        leg: (i, yy) => ((yy + (i === 2 ? 1 : 0)) % 2 ? 'seedShade' : 'cream'),
        shoe: 'seedShade',
        // Bandage seams across the head and a loose end fluttering at the temple.
        hair: (c, r, dir, inHead) => (inHead ? (r + (c > 5 ? 1 : 0)) % 3 === 0 : dir !== 'back' && c === 12 && (r === 6 || r === 7)),
        hairColor: (c, r, inHead) => (inHead ? 'seedShade' : 'cream'),
    },
    zombie: {
        label: 'Зомби',
        skin: 'zombie',
        torso: (i, y, dir) => {
            if (y === -6 && i % 3 === 1) return 'zombie';
            if (dir === 'front' && ((y === -8 && i === 2) || (y === -9 && i === 7))) return 'zombie';
            if (y === -10 && dir === 'front' && i >= 4 && i <= 5) return 'zombie';
            return i % 4 === 0 ? 'hazeDark' : 'haze';
        },
        sleeve: (i) => (i < 2 ? 'haze' : 'zombie'),
        leg: (i, yy) => (yy === 2 && i === 0 ? 'zombie' : 'bark'),
        shoe: 'barkDark',
        hair: (c, r, dir, inHead) => {
            const tuft = r === -1 && (c === 3 || c === 6 || c === 8);
            if (dir === 'front') return (inHead && r <= 1) || tuft || (inHead && r === 2 && c % 3 === 0);
            if (dir === 'side') return (inHead && (r <= 1 || (c <= 3 && r <= 4))) || tuft;
            return (inHead && r <= 4) || tuft;
        },
        hairColor: 'stem',
    },
    scarecrow: {
        label: 'Пугало',
        skin: 'hay',
        torso: (i, y, dir, w) => {
            const bib = dir === 'front' ? i >= 2 && i <= 7 : dir === 'side' ? i >= 3 : false;
            if (y >= -8 && bib) return dir === 'front' && y === -8 && (i === 2 || i === 7) ? 'gold' : 'denim';
            if (y >= -10 && dir !== 'side' && (i === 2 || i === w - 3)) return 'denim';
            if (y === -6) return 'denim';
            return (i % 3 === 0 || y % 3 === 0) ? 'crimsonDark' : 'crimson';
        },
        sleeve: (i, n, dx) => (i % 2 === 0 && dx === 0 ? 'crimsonDark' : 'crimson'),
        leg: (i, yy) => (yy === 2 && i !== 1 ? 'hayLight' : 'denim'),
        shoe: 'barkDark',
        hand: 'hayLight',
        hair: (c, r, dir, inHead) => {
            if (inHead) return (c * 7 + r * 5) % 17 === 0;
            const tufts = (r === -1 && (c === 2 || c === 5 || c === 9)) || ((c === -1 || c === 12) && (r === 4 || r === 6));
            return dir === 'side' ? (r === -1 && c >= 2 && c % 3 === 0) || (c === -1 && (r === 4 || r === 6)) : tufts;
        },
        hairColor: (c, r, inHead) => (inHead ? 'hayDark' : 'hayLight'),
    },
};

// ---------------------------------------------------------------------------------------------------------------
// Faces: 12×11 grids over the head box, front and side. E is the eye: a placeholder key replaced by the eye colour.

const FACE_LEGEND = { K: 'outline', E: 'eye', W: 'white', p: 'blush', S: 'cream', s: 'seedShade', M: 'innerDark', R: 'crimson' };

const FACES = {
    smile: {
        label: 'Улыбка',
        front: ['', '', '', '', '', '..EE....EE..', '..EK....KE..', '.p........p.', '....K..K....', '.....KK.....'],
        side: ['', '', '', '', '', '........EE..', '........EK..', '......p.....', '..........K.', '.........K..'],
    },
    fangs: {
        label: 'Клыки',
        front: ['', '', '', '', '..K......K..', '..EE....EE..', '..KE....EK..', '............', '...KKKKKK...', '...KWKKWK...'],
        side: ['', '', '', '', '........K...', '........EE..', '........EK..', '............', '.........KKK', '.........KWK'],
    },
    stitches: {
        label: 'Шрамы',
        front: ['', '', '', '.K.K.K......', '.KKKKKK.....', '..EE....EE..', '..EK....KE.K', '.........KKK', '....KKKK...K', '..........K.'],
        side: ['', '', '', '...K.K.K....', '...KKKKKK...', '........EE..', '.....K..EK..', '....KKK.....', '.....K....KK', '............'],
    },
    skull: {
        label: 'Череп',
        front: ['', '', '..SSSSSSSS..', '.SSSSSSSSSS.', '.SSSSSSSSSS.', '.SKKSSSSKKS.', '.SKESSSSEKS.', '.SSSSKKSSSS.', '..SSSSSSSS..', '..SKSKSKSK..', '...SSSSSS...'],
        side: ['', '', '.....SSSSS..', '....SSSSSSS.', '....SSSSSSSS', '....SSSKKSSS', '....SSSKESSS', '....SSSSSSKS', '.....SSSSSSS', '.....SSKSKSK', '......SSSSS.'],
    },
    grin: {
        label: 'Ухмылка',
        front: ['', '', '', '', '.KK......KK.', '..EE....EE..', '..EE....EE..', '.K........K.', '.KWWWWWWWWK.', '..KKKKKKKK..'],
        side: ['', '', '', '', '.......KK...', '........EE..', '........EE..', '...........K', '.......KWWWW', '........KKKK'],
    },
    scared: {
        label: 'Испуг',
        front: ['', '', '', '..K......K..', '.K........K.', '..EE....EE..', '..EE....EE..', '............', '.....KK.....', '.....KK.....'],
        side: ['', '', '', '........K...', '.......K....', '........EE..', '........EE..', '............', '..........KK', '..........KK'],
    },
    cyclops: {
        label: 'Циклоп',
        front: ['', '', '', '', '....KKKK....', '....EEEE....', '....EKKE....', '.....EE.....', '............', '....KKKK....'],
        side: ['', '', '', '', '........KKK.', '.........EE.', '.........EK.', '.........E..', '............', '..........KK'],
    },
};

// ---------------------------------------------------------------------------------------------------------------
// Hats: painters over the head box at (x0, y0), one per direction.

const HAT_LEGEND = {
    o: 'pumpkin', d: 'pumpkinDark', l: 'pumpkinLight', D: 'pumpkinDeep', y: 'pumpkinShine', s: 'stem', S: 'stemDark',
    g: 'leaf', G: 'leafDark', i: 'leafLight', v: 'violet', V: 'violetDark', u: 'violetLight', c: 'crimson', C: 'crimsonDark',
    r: 'comb', k: 'barkDark', b: 'bark', B: 'barkLight', w: 'white', W: 'grayLight', Y: 'gold', h: 'hay', H: 'hayDark',
    e: 'cream', E: 'eye', K: 'outline', q: 'speculum', a: 'gray',
};

const WITCH_HAT = [
    '..............VV..',
    '............vVV...',
    '..........vvVV....',
    '.........vvvV.....',
    '........uvvvV.....',
    '.......uvvvvV.....',
    '......uvvvvvV.....',
    '.....uvvvvvvV.....',
    '....ccccyycccC....',
    '....uvvvvvvvvV....',
    '.uvvvvvvvvvvvvvvV.',
    '...VVVVVVVVVVVV...',
];

const TOP_HAT = [
    '..bkkkkkkkkk..',
    '..bkkkkkkkkk..',
    '..bkkkkkkkkk..',
    '..bkkkkkkkkk..',
    '..cccccccccC..',
    '..bkkkkkkkkk..',
    'bkkkkkkkkkkkkk',
    '.kkkkkkkkkkkk.',
];

const HORNS = {
    front: [
        'r..........r',
        'cr........rc',
        'Cc........cC',
        '.Cc......cC.',
        '.rcC....Ccr.',
        '..rcC..Ccr..',
    ],
    side: [
        '...r........',
        '..rc........',
        '..cC........',
        '..ccC.......',
        '...rcCC.....',
        '....rccC....',
    ],
};

// Cultist hood: a pointed cowl; the face shows through the opening, the outline rings it like a shadow.
function hoodPainter(g, x0, y0, dir) {
    const half = [1, 2, 3, 4, 5, 6];
    for (let row = 0; row < 15; row++) {
        const hw = half[row] ?? 7;
        // From the side the peak falls back, away from the face.
        const lean = dir === 'side' ? Math.max(0, 5 - row) : 0;
        for (let c = 0; c < 14; c++) {
            if (Math.abs(c + lean + 0.5 - 7) > hw) continue;
            let open = false;
            if (dir === 'front') open = row >= 6 && row <= 13 && c >= 2 && c <= 11 && !(row === 6 && (c === 2 || c === 11));
            if (dir === 'side') open = row >= 6 && row <= 13 && c >= 8 && !(row === 6 && c === 8);
            if (open) continue;
            let key = 'crimson';
            if (dir !== 'side' && c >= 12) key = 'crimsonDark';
            else if (row < 7 && c + lean < 6) key = 'comb';
            else if (dir === 'back' && row >= 8 && (c === 4 || c === 9)) key = 'crimsonDark';
            else if (dir === 'side' && row >= 7 && c === 3) key = 'crimsonDark';
            put(g, x0 - 1 + c, y0 - 3 + row, key);
        }
    }
}

function pumpkinHat(g, x0, y0) {
    // A dome cut from a pumpkin: ribs follow the meridians, the cut edge is zigzag like a carved lid.
    for (let y = 1; y <= 8; y++) {
        const py = y + 0.5;
        const hw = 7 * Math.sqrt(Math.max(0, 1 - ((py - 8.5) / 7.6) ** 2));
        for (let x = 0; x < 14; x++) {
            const px = x + 0.5;
            const u = (px - 7) / hw;
            if (Math.abs(u) > 1) continue;
            if (y === 8 && x % 3 === 1) continue;
            const groove = [0, -0.55, 0.55].some((k) => Math.abs(px - 7 - k * hw) < 0.55);
            let key = u > 0.6 ? 'pumpkinDark' : u < -0.55 ? 'pumpkinLight' : 'pumpkin';
            if (groove) key = u > 0.2 ? 'pumpkinDeep' : 'pumpkinDark';
            if (y === 2 && u < -0.2 && u > -0.6) key = 'pumpkinShine';
            if (y === 8) key = 'pumpkinDeep';
            put(g, x0 - 1 + x, y0 - 5 + y, key);
        }
    }
    stamp(g, x0 + 5, y0 - 6, ['.Ssi', '.s..'], HAT_LEGEND);
}

// Feathers crown the head; the painted wooden mask hides the face, only the eye holes glow through.
const WD_CROWN = [
    '.....YY.....',
    '..c..YY..c..',
    '.cc.iYYi.cc.',
    '.cciiYYiicc.',
    'q.ciiYYiic.q',
    'qq.ccYYcc.qq',
    '.qqkkkkkkqq.',
];
const WD_MASK = {
    front: [
        '...HhhhhH...',
        '..HhLhhhhH..',
        '.HhLhhhhhhH.',
        'Hhwwhhhhwwhh',
        'HhKKhhhhKKhh',
        'HhKEhcchEKhh',
        'Hhhhhcchhhhh',
        'Hchhhhhhhhch',
        '.hhKKKKKKhh.',
        '.hhKwKKwKhh.',
        '..hKKKKKKh..',
        '...HhhhhH...',
    ],
    side: [
        '..Hhh.',
        '.Hhhhh',
        '.Hhhww',
        '.HhhKK',
        '.HhhKE',
        '.Hhhhc',
        '.Hchhhh',
        '.HhhKKK',
        '.HhhKwK',
        '..HhKKK',
        '...Hhh.',
    ],
};

function witchDoctor(g, x0, y0, dir) {
    if (dir === 'front') stamp(g, x0, y0 + 1, WD_MASK.front, HAT_LEGEND);
    if (dir === 'side') {
        stamp(g, x0, y0 + 4, ['kkkkkkk'], HAT_LEGEND);
        stamp(g, x0 + 6, y0 + 1, WD_MASK.side, HAT_LEGEND);
    }
    if (dir === 'back') {
        stamp(g, x0, y0 + 4, ['kkkkkkkkkkkk'], HAT_LEGEND);
        stamp(g, x0 + 5, y0 + 4, ['bb', 'b.', 'bb'], HAT_LEGEND);
    }
    stamp(g, x0 + (dir === 'side' ? -1 : 0), y0 - 6, WD_CROWN, HAT_LEGEND);
}

const HATS = {
    none: { label: 'Нет', paint: () => {} },
    pumpkin: { label: 'Тыква', paint: (g, x0, y0) => pumpkinHat(g, x0, y0) },
    witch: {
        label: 'Ведьмина шляпа',
        paint: (g, x0, y0, dir) => stamp(g, x0 - 3, y0 - 10, WITCH_HAT, HAT_LEGEND, dir !== 'front'),
    },
    hood: { label: 'Капюшон культиста', paint: hoodPainter },
    'witch-doctor': { label: 'Вич-доктор', paint: witchDoctor },
    'top-hat': { label: 'Цилиндр', paint: (g, x0, y0) => stamp(g, x0 - 1, y0 - 7, TOP_HAT, HAT_LEGEND) },
    horns: {
        label: 'Рога',
        paint: (g, x0, y0, dir) => (dir === 'side'
            ? stamp(g, x0, y0 - 4, HORNS.side, HAT_LEGEND)
            : stamp(g, x0, y0 - 4, HORNS.front, HAT_LEGEND)),
    },
};

// ---------------------------------------------------------------------------------------------------------------
// Cloaks: { back, front } painters relative to the rig; sway (0/1) nudges the hem.

function capeRows(r, y, widen = 3) {
    const t = y - (r.top + 1);
    const grow = Math.floor(t / widen);
    return [-6 - grow + r.sx, 5 + grow + r.sx];
}

function cape(g, r, layer, { outer, inner, fold, ragged, collar }) {
    const hem = -3;
    if (r.dir === 'side') {
        if (layer !== 'back') return;
        for (let y = r.top + 1; y <= hem; y++) {
            const t = y - (r.top + 1);
            const back = -4 + r.sx - Math.floor(t * 0.75) - (y > -7 ? r.sway : 0);
            const cut = ragged ? (Math.abs(back * 3 + y) % 3 === 0 && y >= hem - 1) : false;
            for (let x = back; x <= r.sx; x++) {
                if (ragged && y >= hem - 1 && (x * 5) % 3 === 0) continue;
                if (cut && x === back) continue;
                put(g, x, y, x === back ? inner : outer);
            }
        }
        if (collar) fill(g, polygon([[-5 + r.sx, -17 + r.bob], [-1 + r.sx, -10 + r.bob], [-4 + r.sx, -10 + r.bob]]), () => inner);
        return;
    }
    const facing = r.dir === 'front';
    if (facing ? layer === 'back' : layer === 'front') {
        for (let y = r.top + 1; y <= hem; y++) {
            const [l, rr] = capeRows(r, y, 2);
            const shift = y > -7 ? (r.sway ? 1 : 0) : 0;
            for (let x = l + shift; x <= rr + shift; x++) {
                if (ragged && y >= hem - 1 && ((x + 16) * 7 + y) % 3 === 0) continue;
                if (ragged && y === hem - 3 && x === -3) continue;
                let key = outer;
                if (facing) key = x === l + shift || x === rr + shift ? outer : inner;
                else if (fold && y > r.top + 3 && (x === -3 + r.sx || x === 2 + r.sx)) key = fold;
                put(g, x, y, key);
            }
        }
        if (facing && collar) {
            const b = r.bob;
            fill(g, polygon([[-9 + r.sx, -17 + b], [-5 + r.sx, -10 + b], [-8 + r.sx, -10 + b]]), () => inner);
            fill(g, polygon([[9 + r.sx, -17 + b], [5 + r.sx, -10 + b], [8 + r.sx, -10 + b]]), () => inner);
        }
    }
    if (facing && layer === 'front' && collar) {
        put(g, -1 + r.sx, -9 + r.bob, 'gold');
        put(g, r.sx, -9 + r.bob, 'gold');
    }
}

function poncho(g, r, layer) {
    if (layer !== 'front') return;
    const stripes = ['crimson', 'crimson', 'hay', 'leafLight', 'hay', 'crimson', 'pumpkin'];
    const side = r.dir === 'side';
    const [l, rr] = side ? [-4 + r.sx, 4 + r.sx] : [-8 + r.sx, 7 + r.sx];
    for (let y = r.top + 1; y <= -4; y++) {
        const t = y - (r.top + 1);
        for (let x = l; x <= rr; x++) {
            const cx = x - r.sx + 0.5;
            const reach = side ? 4.5 : t === 0 ? 5.5 : t === 1 ? 7.5 : 8.5;
            if (Math.abs(cx - (side ? 0.5 : 0)) > reach) continue;
            const hem = side ? -6 - (cx < 0 ? 1 : 0) : -5 - Math.floor(Math.abs(cx) / 3);
            if (y > hem + 1) continue;
            if (y === hem + 1) {
                if ((x + 16) % 2 === r.sway) put(g, x, y, 'hayLight');
                continue;
            }
            put(g, x, y, stripes[t % stripes.length]);
        }
    }
}

function wings(g, r, layer) {
    const facing = r.dir === 'front';
    if (r.dir === 'side' ? layer !== 'back' : facing ? layer !== 'back' : layer !== 'front') return;
    const f = r.sway ? -2 : 0;
    const b = r.bob;
    const wing = (m) => {
        const pts = [[-3, -10], [-8, -15 + f], [-13, -17 + f], [-15, -11 + f], [-13, -9 + f], [-11, -10 + f], [-10, -7 + f / 2], [-7, -8], [-5, -6]]
            .map(([x, y]) => [m * x + r.sx, y + b]);
        fill(g, polygon(pts), (x, y) => {
            const dx = Math.abs(x - r.sx + 0.5);
            const ridge = Math.abs((y - b + 10) + (dx - 3) * 0.55) < 0.6 || Math.abs((y - b + 9) + (dx - 3) * 0.1) < 0.5;
            return ridge ? 'violet' : 'violetDark';
        });
    };
    if (r.dir === 'side') {
        const pts = [[0, -10], [-5, -16 + f], [-9, -18 + f], [-11, -12 + f], [-9, -11 + f], [-8, -12 + f], [-6, -8 + f / 2], [-4, -9], [-1, -6]]
            .map(([x, y]) => [x + r.sx, y + b]);
        fill(g, polygon(pts), () => 'violetDark');
        return;
    }
    wing(1);
    wing(-1);
}

const CLOAKS = {
    none: { label: 'Нет', paint: () => {} },
    cape: { label: 'Плащ', paint: (g, r, layer) => cape(g, r, layer, { outer: 'crimsonDark', inner: 'crimson', fold: 'pumpkinDeep', collar: true }) },
    rags: { label: 'Лохмотья', paint: (g, r, layer) => cape(g, r, layer, { outer: 'gray', inner: 'grayLight', fold: 'duckGrayDark', ragged: true }) },
    poncho: { label: 'Пончо', paint: poncho },
    wings: { label: 'Крылья', paint: wings },
};

// ---------------------------------------------------------------------------------------------------------------
// Items: per direction, rows plus the grip — the item pixel under the top-left of the 2×2 hand. The back view mirrors
// the front. muzzle — where the flash goes.

const ITEM_LEGEND = {
    w: 'white', c: 'cream', y: 'pumpkinShine', Y: 'pumpkinLight', g: 'grayLight', a: 'gray', k: 'barkDark',
    b: 'bark', B: 'barkLight', h: 'hay', H: 'hayDark', L: 'hayLight', s: 'stemLight',
};

// From the side the broom leans forward, so the stick clears the face.
const BROOM_SIDE = [
    '........B..', '........B..', '........B..', '........B..', '.......B...', '.......B...', '.......B...',
    '......B....', '......B....', '......B....', '.....B.....', '.....B.....', '.....B.....', '....B......',
    '...kkk.....', '..hLh......', '.hhLhh.....', '.hLhHh.....', 'HhHhH......',
];

const BROOM = [
    '..B..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..',
    '..B..', '..B..', '.kkk.', '.hLh.', 'hhLhh', 'hLhHh', 'HhHhH',
];

const ITEM_DEFS = {
    beer: {
        label: 'Пиво',
        front: { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [4, 3] },
        side: { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [-1, 3] },
    },
    broom: {
        label: 'Метла',
        front: { rows: BROOM, grip: [1, 11] },
        side: { rows: BROOM_SIDE, grip: [4, 12] },
    },
    pistol: {
        label: 'Пистоль',
        front: { rows: ['.a..', '.g..', '.g..', '.g..', '.ga.', 'Bbb.', '.Bb.', '..b.'], grip: [1, 5] },
        side: { rows: ['..a.', '..g.', '..g.', '..g.', '.ag.', '.bbB', '.bB.', '.b..'], grip: [1, 5] },
    },
};

// Shooting always shows the pistol, aimed: [fire, recoil] per direction.
const AIM = {
    side: [
        { rows: ['..a....', 'bbggggg', 'Bb.....', 'b......'], grip: [0, 1], muzzle: [7, 1] },
        { rows: ['......g', '....gg.', '..agg..', 'bbg....', 'Bb.....', 'b......'], grip: [0, 3], muzzle: [7, -1] },
    ],
    front: [
        { rows: ['g....', '.g...', '..ga.', '...bb', '...Bb', '....b'], grip: [3, 3], muzzle: [-1, -1] },
        { rows: ['.g...', '.g...', '..ga.', '..bb.', '..Bb.', '...b.'], grip: [2, 3], muzzle: [1, -2] },
    ],
};

function placeItem(g, spr, hand, flip) {
    const w = Math.max(...spr.rows.map((s) => s.length));
    const [gx, gy] = spr.grip;
    const x0 = flip ? hand[0] + 2 - w + gx : hand[0] - gx;
    stamp(g, x0, hand[1] - gy, spr.rows, ITEM_LEGEND, flip);
    if (!spr.muzzle) return null;
    const [mx, my] = spr.muzzle;
    return [flip ? x0 + w - 1 - mx : x0 + mx, hand[1] - gy + my];
}

// ---------------------------------------------------------------------------------------------------------------
// Composition.

function drawLegs(body, r) {
    const g = blank();
    const side = r.dir === 'side';
    for (const leg of r.legs) {
        const bottom = FOOT - leg.lift;
        for (let y = LEG_TOP; y <= bottom; y++) {
            const t = (y - LEG_TOP) / (bottom - LEG_TOP);
            const x = Math.round(leg.hip + (leg.foot - leg.hip) * t);
            for (let i = 0; i < 3; i++) {
                let key = y === bottom ? body.shoe : body.leg(i, y - LEG_TOP, y);
                if (leg.far || (!side && i === 2 && leg.hip > -1)) key = dark(key);
                put(g, x + i, y, key);
            }
            if (side && y === bottom) {
                for (let k = 0; k < (body.toe ?? 1); k++) put(g, x + 3 + k, y - (k > 0 ? 1 : 0), leg.far ? dark(body.shoe) : body.shoe);
            }
        }
    }
    return g;
}

function drawTorso(body, r) {
    const g = blank();
    const [x0, x1] = r.torso;
    const w = x1 - x0 + 1;
    for (let y = r.top; y <= TORSO_BOTTOM; y++) {
        for (let x = x0; x <= x1; x++) {
            if (y === r.top && (x === x0 || x === x1)) continue;
            const key = body.torso(x - x0, y, r.dir, w);
            put(g, x, y, x === x1 ? dark(key) : key);
        }
    }
    if (body.skirt) {
        body.skirt.flare.forEach((grow, k) => {
            const y = TORSO_BOTTOM + 1 + k;
            // Walking, the hem swings: sideways from the front, trailing back from the side.
            const swing = r.pose === 'walk' && y === body.skirt.hem ? r.sway : 0;
            const l = x0 - grow - (r.dir === 'side' ? swing : 0);
            const rr = x1 + grow + (r.dir === 'side' ? 0 : swing);
            for (let x = l; x <= rr; x++) {
                const key = body.torso(Math.max(0, Math.min(w - 1, x - x0)), y, r.dir, w);
                put(g, x, y, x === rr ? dark(key) : key);
            }
        });
    }
    return g;
}

function drawArm(g, body, arm) {
    const sleeve = (i, n, dx) => {
        const key = body.sleeve(i, n, dx);
        return arm.far || dx === 1 ? dark(key) : key;
    };
    if (arm.elbow) {
        const n1 = Math.max(Math.abs(arm.elbow[0] - arm.sh[0]), Math.abs(arm.elbow[1] - arm.sh[1]));
        stroke(g, arm.sh, arm.elbow, (i, n, dx) => sleeve(i, n1 + 3, dx));
        stroke(g, arm.elbow, arm.hand, (i, n, dx) => sleeve(n1 + i, n1 + n + 1, dx));
    } else {
        stroke(g, arm.sh, arm.hand, sleeve);
    }
    const hand = body.hand ?? body.skin;
    for (let dy = 0; dy < 2; dy++) {
        for (let dx = 0; dx < 2; dx++) {
            put(g, arm.hand[0] + dx, arm.hand[1] + dy, arm.far || dx === 1 && dy === 1 ? dark(hand) : hand);
        }
    }
}

function drawHead(body, face, r) {
    const g = blank();
    const { x: x0, y: y0 } = r.head;
    HEAD_MASK.forEach((row, rr) => [...row].forEach((ch, c) => {
        if (ch !== '#') return;
        const v = ((c - 5.5) / 6) * 0.7 + ((rr - 5) / 5.5) * 0.75;
        put(g, x0 + c, y0 + rr, v > 0.8 ? dark(body.skin) : body.skin);
    }));
    if (body.hair) {
        for (let rr = -1; rr <= 12; rr++) {
            for (let c = -1; c <= 12; c++) {
                const inHead = HEAD_MASK[rr]?.[c] === '#';
                if (!body.hair(c, rr, r.dir, inHead)) continue;
                const base = typeof body.hairColor === 'function' ? body.hairColor(c, rr, inHead) : body.hairColor;
                put(g, x0 + c, y0 + rr, c >= 10 && typeof body.hairColor !== 'function' ? dark(base) : base);
            }
        }
    }
    const rows = face[r.dir];
    if (rows) stamp(g, x0, y0, rows, FACE_LEGEND);
    return g;
}

function compose(look, dir, pose, frame, item) {
    const body = BODIES[look.body];
    const r = rig(dir, pose, frame);
    const shoot = pose === 'shoot';
    const holding = !shoot && ITEM_DEFS[item];
    const cloak = CLOAKS[look.cloak];
    const out = blank();
    const layer = (paint) => {
        const g = blank();
        paint(g);
        over(out, outlined(g));
    };

    layer((g) => cloak.paint(g, r, 'back'));
    over(out, outlined(drawLegs(body, r)));
    const free = r.arms.filter((a, i) => !((shoot || holding) && i === r.itemArm));
    layer((g) => free.filter((a) => a.far).forEach((a) => drawArm(g, body, a)));
    over(out, outlined(drawTorso(body, r)));
    over(out, outlined(drawHead(body, FACES[look.face], r)));
    layer((g) => free.filter((a) => !a.far).forEach((a) => drawArm(g, body, a)));
    layer((g) => HATS[look.hat].paint(g, r.head.x, r.head.y, dir));
    layer((g) => cloak.paint(g, r, 'front'));

    const fx = blank();
    if (shoot || holding) {
        const arm = shoot ? r.aim : r.hold;
        layer((g) => {
            const flip = dir === 'back';
            const spr = shoot ? AIM[dir === 'side' ? 'side' : 'front'][frame] : ITEM_DEFS[item][dir === 'side' ? 'side' : 'front'];
            const muzzle = placeItem(g, spr, arm.hand, flip);
            if (shoot && muzzle && frame === 0) {
                const [mx, my] = muzzle;
                [[0, 0, 'fxCore'], [1, 0, 'fxFlash'], [-1, 0, 'fxFlash'], [0, 1, 'fxFlash'], [0, -1, 'fxFlash']]
                    .forEach(([dx, dy, k]) => put(fx, mx + dx, my + dy, k));
            } else if (shoot && muzzle) {
                put(fx, muzzle[0], muzzle[1] - 1, 'fxSmoke');
                put(fx, muzzle[0] + (flip ? -1 : 1), muzzle[1] - 2, 'fxSmoke');
            }
        });
        layer((g) => drawArm(g, body, { ...arm, far: false }));
    }
    over(out, fx);
    return out;
}

// ---------------------------------------------------------------------------------------------------------------
// Public API.

const FX_COLORS = { fxCore: PALETTE.white, fxFlash: PALETTE.pumpkinShine, fxSmoke: PALETTE.grayLight };

const EYES = [
    { id: 'amber', label: 'Янтарные', key: 'eyeAmber' },
    { id: 'green', label: 'Зелёные', key: 'eyeGreen' },
    { id: 'red', label: 'Красные', key: 'eyeRed' },
    { id: 'blue', label: 'Синие', key: 'eyeBlue' },
    { id: 'violet', label: 'Фиолетовые', key: 'eyeViolet' },
    { id: 'white', label: 'Светящиеся', key: 'white' },
];

const entries = (defs) => Object.entries(defs).map(([id, def]) => ({ id, label: def.label }));

/** Parts per slot, in display order; eyes carry their colour as 0xRRGGBB. */
export const CATALOG = {
    body: entries(BODIES),
    cloak: entries(CLOAKS),
    face: entries(FACES),
    eyes: EYES.map(({ id, label, key }) => ({ id, label, color: PALETTE[key] })),
    hat: entries(HATS),
};

export const ITEMS = entries(ITEM_DEFS);

export const DEFAULT_LOOK = { body: 'skeleton', cloak: 'none', face: 'smile', eyes: 'amber', hat: 'pumpkin' };

const SLOTS = Object.keys(DEFAULT_LOOK);

/** A full look: unknown or missing ids fall back to the defaults, so parts added later never break older clients. */
export function normalizeLook(look) {
    const out = {};
    for (const slot of SLOTS) {
        const id = look?.[slot];
        out[slot] = CATALOG[slot].some((p) => p.id === id) ? id : DEFAULT_LOOK[slot];
    }
    return out;
}

export function randomLook(random = Math.random) {
    const out = {};
    for (const slot of SLOTS) {
        const parts = CATALOG[slot];
        out[slot] = parts[Math.min(parts.length - 1, Math.floor(random() * parts.length))].id;
    }
    return out;
}

export const DIRS = ['front', 'side', 'back'];

/** ms — per frame. */
export const POSES = {
    idle: { frames: 2, ms: 600 },
    fidget: { frames: 2, ms: 450 },
    walk: { frames: 4, ms: 150 },
    shoot: { frames: 2, ms: 180 },
};

/** Canvas size shared by every frame and the anchor inside it (bottom centre, between the feet). */
export const FRAME = { w: W, h: H, anchor: { x: AX, y: AY } };

function makeCanvas() {
    if (typeof document !== 'undefined') {
        const canvas = document.createElement('canvas');
        canvas.width = W;
        canvas.height = H;
        return canvas;
    }
    return new OffscreenCanvas(W, H);
}

const frameCache = new Map();

/**
 * One composed frame, cached by its arguments. Unknown dir/pose fall back to front/idle, frame wraps around the pose,
 * an unknown item is no item. anchor — canvas pixels.
 */
export function characterFrame(look, { dir, pose, frame = 0, item = null } = {}) {
    const full = normalizeLook(look);
    const d = DIRS.includes(dir) ? dir : 'front';
    const p = POSES[pose] ? pose : 'idle';
    const n = POSES[p].frames;
    const f = ((Math.floor(frame) % n) + n) % n;
    const it = ITEM_DEFS[item] ? item : null;
    const key = `${SLOTS.map((s) => full[s]).join(',')}|${d}|${p}|${f}|${it ?? ''}`;
    const cached = frameCache.get(key);
    if (cached) return cached;

    const grid = compose(full, d, p, f, it);
    const eye = PALETTE[EYES.find((e) => e.id === full.eyes).key];
    const canvas = makeCanvas();
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(W, H);
    grid.forEach((k, i) => {
        if (!k) return;
        const color = k === 'eye' ? eye : FX_COLORS[k] ?? PALETTE[k];
        if (color === undefined) return;
        img.data[i * 4] = (color >> 16) & 0xff;
        img.data[i * 4 + 1] = (color >> 8) & 0xff;
        img.data[i * 4 + 2] = color & 0xff;
        img.data[i * 4 + 3] = 255;
    });
    ctx.putImageData(img, 0, 0);
    const result = { canvas, anchor: { x: AX, y: AY } };
    frameCache.set(key, result);
    return result;
}
