import { PALETTE } from './palette.js';
import { pick as byLang } from '../i18n.js';

/**
 * Paper-doll Halloween characters. A look picks one part per slot (body, cloak, face, eyes, hat); a frame is composed
 * from outlined layers: cloak-back → body → face → hat → cloak-front → item → arm with the item.
 * The body owns the animation: one shared rig gives, per direction/pose/frame, the torso drop, the head box and the
 * hand points; faces, hats and items are drawn once per direction and placed at those points.
 * Plain 2D canvas, no PixiJS: the join screen draws previews with it and the scene makes textures from the canvases.
 * Side view faces right; the caller mirrors it for the left.
 */

/** A part's name on the join screen in the page's language. */
const named = (ru, en) => byLang({ ru, en });

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
    crowLight: 'crow',
    crow: 'crowDark',
    elephantLight: 'elephant',
    elephant: 'elephantDark',
    gopher: 'gopherDark',
    gopherTan: 'gopherTanShade',
    robotLight: 'robot',
    robot: 'robotDark',
    tanLight: 'tan',
    tan: 'tanShade',
    khakiLight: 'khaki',
    khaki: 'khakiDark',
    dinoLight: 'dino',
    dino: 'dinoDark',
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

// Effects (muzzle flash, smoke, the ritual's palm lights, the candle flame, the red flame) glow without an outline.
const FX_KEYS = new Set([
    'fxFlash', 'fxCore', 'fxSmoke', 'fxPalm', 'fxPalmCore', 'fxFlame', 'fxFlameHot', 'fxFlameCore', 'fxRune', 'fxRuneDark', 'fxRuneHot', 'fxRuneCore',
    'fxBlade', 'fxBladeEdge', 'fxBladeShine',
]);
const BLADE_KEYS = new Set(['fxBlade', 'fxBladeEdge']);
const isFx = (key) => FX_KEYS.has(key);

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
    } else if (pose === 'walk' || pose === 'shamble') {
        sway = frame & 1;
        // The zombie's waddle rolls the hips from side to side over every step.
        if (pose === 'shamble' && !side) sx = [-1, 0, 1, 0][frame];
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
    } else if (pose === 'ritual') {
        // Swaying from side to side over planted feet: left, centre, right, centre.
        sx = [-1, 0, 1, 0][frame];
        sway = frame & 1;
    } else if (pose === 'lurch') {
        // A standing zombie sways over planted feet, sagging on one side.
        sx = side ? 0 : frame ? 1 : -1;
        bob = frame;
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
    if (pose === 'ritual') {
        // Arms raised overhead, a little higher on the centre frames. From the front they spread in a V clear of the
        // head and the hat brims; from the side they reach up along the front of the face, past the eyes.
        const up = -23 - (frame & 1);
        r.arms = side
            ? [
                { sh: [sx, sh], elbow: [3 + sx, -13], hand: [4 + sx, up - 1], far: true },
                { sh: [-1 + sx, sh], elbow: [4 + sx, -13], hand: [6 + sx, up], far: false },
            ]
            : [
                { sh: [-7 + sx, sh], hand: [-12 + sx, up], far: false },
                { sh: [5 + sx, sh], hand: [10 + sx, up], far: false },
            ];
    }
    if (pose === 'clink' || pose === 'toast') {
        // Clinking glasses: the item hand thrusts the glass forward at chest height (frame 1 — the strike, a pixel
        // further), or raises it overhead for the toast (frame 1 a pixel higher). From the side the raised arm goes up
        // in front of the face like the ritual's, the glass clear of the head.
        const front = pose === 'clink'
            ? { sh: [-7, sh], hand: [-11 - frame, -10] }
            : { sh: [-7, sh], hand: [-12, -22 - frame] };
        if (side) {
            r.hold = pose === 'clink'
                ? { sh: [-1, sh], hand: [5 + frame, -9] }
                : { sh: [-1, sh], elbow: [4, -13], hand: [5, -22 - frame] };
        } else {
            r.hold = dir === 'back' ? Object.fromEntries(Object.entries(front).map(([k, v]) => [k, mirrorBlock(v)])) : front;
        }
    }
    if (pose === 'drink') {
        // The mug comes up to the mouth (0), tips level (1), then bottom up with the head thrown back (2). The hand
        // follows the mug: it holds DRINK's grip of the frame.
        if (frame === 2) r.head = { x: r.head.x - (side ? 1 : 0), y: r.head.y - 1 };
        if (side) {
            r.hold = [
                { sh: [-1, sh], elbow: [2, -8], hand: [4, -12] },
                { sh: [-1, sh], elbow: [3, -8], hand: [5, -12] },
                { sh: [-1, sh], elbow: [3, -9], hand: [6, -14] },
            ][frame];
        } else {
            const front = [
                { sh: [-7, sh], elbow: [-8, -8], hand: [-4, -12] },
                { sh: [-7, sh], elbow: [-9, -9], hand: [-6, -13] },
                { sh: [-7, sh], elbow: [-10, -11], hand: [-4, -15] },
            ][frame];
            r.hold = dir === 'back' ? Object.fromEntries(Object.entries(front).map(([k, v]) => [k, mirrorBlock(v)])) : front;
        }
    }
    if (pose === 'shamble' || pose === 'lurch') {
        // Both arms held out in front at shoulder height, bobbing out of step: from the side straight ahead, from the
        // front reaching in across the chest toward the camera, from the back hidden behind the body.
        const k = frame & 1;
        const front = [
            { sh: [-7 + sx, sh], elbow: [-7 + sx, -10 + bob], hand: [-5 + sx, -11 + bob + k], far: false },
            { sh: [5 + sx, sh], elbow: [5 + sx, -10 + bob], hand: [3 + sx, -11 + bob + 1 - k], far: false },
        ];
        r.arms = side
            ? [
                { sh: [sx, sh], hand: [6 + sx, -11 + bob + k], far: true },
                { sh: [-1 + sx, sh], hand: [5 + sx, -10 + bob - k], far: false },
            ]
            : dir === 'back'
                ? front.map((a) => ({ ...a, far: true }))
                : front;
    }
    if (pose === 'shrug') {
        // Empty palms turned up at the sides, the head sinking between raised shoulders on the second frame.
        r.head = { x: r.head.x, y: r.head.y + frame };
        r.arms = side
            ? [
                { sh: [sx, sh], elbow: [2, -8], hand: [4, -11 - frame], far: true },
                { sh: [-1 + sx, sh], elbow: [1, -8], hand: [3, -10 - frame], far: false },
            ]
            : [
                { sh: [-7, sh], elbow: [-9, -8], hand: [-11, -10 - frame], far: false },
                { sh: [5, sh], elbow: [7, -8], hand: [9, -10 - frame], far: false },
            ];
    }
    return r;
}

// ---------------------------------------------------------------------------------------------------------------
// Bodies. torso(i, y, dir, w): i — column inside the torso box, y — local row (patterns stay put when the torso
// drops); rows below TORSO_BOTTOM belong to the skirt. leg(i, yy, y): i — column 0..2, yy — row from the hip.
// sleeve(i, n, dx): step along the arm. hair(c, r, dir, inHead): head-box coordinates, may reach one pixel outside.
// Optional: headPaint(g, x0, y0, dir, ears) paints over the head under the face (ears — no hat on), whiskers(r)
// returns a layer laid on without an outline, tail(g, r) paints behind the body (over it from the back), snout(r)
// returns a finished layer laid over the face, zombie maps the skin's other tones for a zombie player (zombieKeys),
// bigHand rings the hands one pixel bigger, brawn bulges the upper arms and the thighs a pixel outward and caps the
// shoulders, bigShoe makes clown shoes: wider outward from the front and the back, long and round-toed from the side.

const pick = (s, i) => PICK[s[i]] ?? null;
const PICK = {
    V: 'violetDark', v: 'violet', u: 'violetLight', w: 'white', c: 'crimson', C: 'crimsonDark', r: 'comb',
    e: 'cream', E: 'seedShade', g: 'gold', b: 'bark', B: 'barkLight', k: 'barkDark', h: 'hay', H: 'hayDark',
    L: 'hayLight', d: 'denim', D: 'denimDark', s: 'skin', p: 'pale', z: 'zombie', a: 'haze', A: 'hazeDark',
    o: 'pumpkin', O: 'pumpkinDark', y: 'pumpkinShine', t: 'stem', G: 'grayLight',
};
/** Torso pattern from rows keyed by local y; rows missing from the map use the fallback colour. */
const rowsPattern = (map, fallback) => (i, y) => (map[y] ? pick(map[y], i) : null) ?? fallback;

// Cat ears over the head box, rows from three pixels above it: F — fur, f — the shaded fur, p — the pink inside. The
// body's own ears and the «Ушки» hat share them, so a cat in that hat looks the same plus the band.
const CAT_EARS = {
    front: [
        '.F........F.',
        '.FF......Ff.',
        '.FpF....Fpf.',
        '.FppF..Fppf.',
    ],
    side: [
        '..F.....f...',
        '..FF...ff...',
        '..FpF..fff..',
        '..FppF.fff..',
    ],
    back: [
        '.F........F.',
        '.fF......Ff.',
        '.fFF....FFf.',
        '.fFFF..FFFf.',
    ],
};

function catEars(g, x0, y0, dir, fur) {
    stamp(g, x0, y0 - 3, CAT_EARS[dir], { F: fur, f: dark(fur), p: 'blush' });
}

// The muzzle under the face slot: the face's mouth lands on white, so it stays readable on black fur.
const CAT_MUZZLE = {
    front: ['', '', '', '', '', '', '', '....WnnW....', '...WWWWWW...', '....WWWW....'],
    side: ['', '', '', '', '', '', '', '.........WWn', '........WWWW', '.........WWW'],
};
// Whiskers fan out past the cheeks. They go on without an outline: outlined, a one-pixel line turns into a fat bar.
const CAT_WHISKERS = {
    front: [[0, 8], [-1, 7], [-2, 7], [0, 9], [-1, 10], [-2, 10], [11, 8], [12, 7], [13, 7], [11, 9], [12, 10], [13, 10]],
    side: [[12, 7], [13, 6], [12, 9], [13, 10]],
};

function catHead(g, x0, y0, dir, ears) {
    if (ears) catEars(g, x0, y0, dir, 'crow');
    if (dir === 'back') return;
    stamp(g, x0, y0, CAT_MUZZLE[dir], { W: 'white', n: 'blush' });
}

function catWhiskers(r) {
    const g = blank();
    (CAT_WHISKERS[r.dir] ?? []).forEach(([c, rr]) => put(g, r.head.x + c, r.head.y + rr, 'grayLight'));
    return g;
}

// The tail: a polyline from the rump, local coordinates per direction; the upper part swings with the pose. From the
// back it hangs in front of the body, so it is drawn over the torso there.
const CAT_TAIL = {
    front: [[4, -6], [7, -6], [9, -8], [10, -11], [10, -13], [9, -15]],
    side: [[-3, -6], [-6, -6], [-8, -8], [-9, -11], [-9, -13], [-8, -15]],
    back: [[-1, -6], [-4, -5], [-7, -6], [-9, -8], [-10, -11], [-10, -13], [-9, -15]],
};

function catTail(g, r) {
    const swing = r.pose === 'walk' || r.pose === 'shamble' || r.pose === 'ritual' ? [-1, 0, 1, 0][r.frame] : r.pose === 'fidget' ? (r.frame ? 1 : -1) : 0;
    const pts = CAT_TAIL[r.dir].map(([x, y], i, all) => {
        const t = i / (all.length - 1);
        return [x + r.sx + Math.round(swing * 2 * t * t), y + (i ? r.bob : 0)];
    });
    for (let s = 0; s < pts.length - 1; s++) {
        const [[x1, y1], [x2, y2]] = [pts[s], pts[s + 1]];
        const n = Math.max(Math.abs(x2 - x1), Math.abs(y2 - y1));
        for (let k = 0; k <= n; k++) {
            const x = Math.round(x1 + ((x2 - x1) * k) / (n || 1));
            const y = Math.round(y1 + ((y2 - y1) * k) / (n || 1));
            put(g, x, y, 'crow');
            // Thick at the root, thin to the tip.
            if (s < 2) put(g, x, y - 1, 'crow');
        }
    }
}

// The elephant's ears stand out on both sides of the head, rows from the head box's second row: F — the rim, p — the
// pale inside, f — the crease against the head. The left ear; the right one is its mirror. From the side the ear lies
// flat on the head and only its rim and the part behind the head show.
const ELEPHANT_EARS = {
    front: ['.FF.', 'FppF', 'Fppf', 'Fppf', 'Fppf', 'Fppf', 'FFpf', '.FFf', '..F.'],
    side: ['...fff...', '..fFFFf..', '.fFFFFFf.', 'fFFFFFFf.', 'fFFFFFFf.', 'fFFFFFf..', '.fFFFf...', '..fff....'],
    back: ['.FF.', 'FFFF', 'FFFf', 'FFFf', 'FFFf', 'FFFf', 'FFFf', '.FFf', '..F.'],
};
const EAR_LEGEND = { F: 'elephant', p: 'elephantLight', f: 'elephantDark' };

function elephantHead(g, x0, y0, dir) {
    if (dir === 'side') {
        stamp(g, x0 - 2, y0 + 2, ELEPHANT_EARS.side, EAR_LEGEND);
        return;
    }
    const rows = ELEPHANT_EARS[dir];
    stamp(g, x0 - 4, y0 + 1, rows, EAR_LEGEND);
    stamp(g, x0 + 12, y0 + 1, rows, EAR_LEGEND, true);
}

// The trunk hangs over the mouth and below the chin, the tusks beside it; at — its top-left in head-box coordinates.
// Over the head it has no outline, so it grows out of the face instead of being cut off it, and clears the eyes.
const ELEPHANT_TRUNK = {
    front: { at: [4, 8], rows: ['.Lt.', '.Lt.', '.Lt.', 'wTtw', '..Tt'] },
    side: { at: [12, 6], rows: ['TT..', 'tTT.', '.tT.', '.tT.', '.tTT', '..tT'] },
};

/**
 * A body's own features over the face slot: { at, rows } per direction, at — the top-left in head-box coordinates.
 * Outlined only where it sticks out of the head, so it grows out of the face; outline pixels drawn on purpose stay.
 */
function faceLayer(r, defs, legend) {
    const g = blank();
    const def = defs[r.dir];
    if (!def) return g;
    const { x: x0, y: y0 } = r.head;
    stamp(g, x0 + def.at[0], y0 + def.at[1], def.rows, legend);
    return outlinedOffHead(g, x0, y0);
}

/** The layer outlined only off the head box at (x0, y0): over the head it grows out of it instead of sitting on it. */
function outlinedOffHead(g, x0, y0) {
    const out = outlined(g);
    HEAD_MASK.forEach((row, rr) => [...row].forEach((ch, c) => {
        const i = (AY + y0 + rr) * W + AX + x0 + c;
        if (ch === '#' && out[i] === 'outline' && !g[i]) out[i] = null;
    }));
    return out;
}

const elephantTrunk = (r) => faceLayer(r, ELEPHANT_TRUNK, { L: 'elephantLight', T: 'elephant', t: 'elephantDark', w: 'cream' });

// A thin tail from the rump ending in a dark tuft, local coordinates per direction; the tuft swings with the pose.
// From the front the body hides it: peeking out beside the feet, the tuft reads as a third foot.
const ELEPHANT_TAIL = {
    side: [[-3, -9], [-5, -8], [-6, -6]],
    back: [[-1, -8], [-1, -6], [-1, -5]],
};

function elephantTail(g, r) {
    if (!ELEPHANT_TAIL[r.dir]) return;
    const swing = r.pose === 'walk' || r.pose === 'shamble' || r.pose === 'ritual' ? [-1, 0, 1, 0][r.frame] : r.pose === 'fidget' ? (r.frame ? 1 : -1) : 0;
    const pts = ELEPHANT_TAIL[r.dir].map(([x, y], i) => [x + r.sx + (i === 2 ? swing : 0), y + (i ? r.bob : 0)]);
    for (let s = 0; s < pts.length - 1; s++) {
        const [[x1, y1], [x2, y2]] = [pts[s], pts[s + 1]];
        const n = Math.max(Math.abs(x2 - x1), Math.abs(y2 - y1));
        for (let k = 0; k <= n; k++) put(g, Math.round(x1 + ((x2 - x1) * k) / (n || 1)), Math.round(y1 + ((y2 - y1) * k) / (n || 1)), 'elephant');
    }
    const [tx, ty] = pts[pts.length - 1];
    [[0, 1], [-1, 1], [0, 2], [1, 2], [-1, 2]].forEach(([dx, dy]) => put(g, tx + dx, ty + dy, 'elephantDark'));
}

// The gopher's small round ears, bare-headed only like the cat's, rows from a pixel above the head box.
const GOPHER_EARS = {
    front: ['.FF......FF.', 'FFF......FFF'],
    side: ['..FF........', '.FFF........'],
    back: ['.FF......FF.', 'FFF......FFF'],
};

function gopherHead(g, x0, y0, dir, ears) {
    if (ears) stamp(g, x0, y0 - 1, GOPHER_EARS[dir], { F: 'gopher' });
}

// Its face over the face slot: the big white eyes set wide apart at the top of the head, their pupils in the eye colour
// with a dark dot like the faces' eyes, the beige muzzle with the dark nose between them and the two buck teeth under
// it. From the side the muzzle sticks out ahead.
const GOPHER_FACE = {
    front: {
        at: [0, 2],
        rows: [
            '.WWW....WWW.',
            'WWWWW..WWWWW',
            'WWEEW..WEEWW',
            'WWEKW..WEKWW',
            '.WWWtnntWWW.',
            '...tttttt...',
            '....tttt....',
            '.....wg.....',
            '.....wg.....',
        ],
    },
    side: {
        at: [5, 2],
        rows: [
            '..WWW....',
            '.WWWWW...',
            '.WWWEE...',
            '.WWWEK...',
            '..WWWttnn',
            '....ttttt',
            '.....tttt',
            '.....wg..',
            '.....wg..',
        ],
    },
};

const gopherFace = (r) => faceLayer(r, GOPHER_FACE, { W: 'white', E: 'eye', K: 'outline', t: 'gopherTan', n: 'barkDark', w: 'white', g: 'grayLight' });

// The tin robot. Its head is a square plate, not the round head box: headPaint repaints it whole — the enamel lit
// from the top left, rust only at the back, a short neck — with the round brass speakers on both sides and the two
// antennae with red knobs (bare-headed only: a hat sits where they stand). The far antenna rises by the ear.
const ROBOT_LEGEND = {
    l: 'robotLight', m: 'robot', M: 'robotDark', r: 'copper', R: 'pumpkinDeep', h: 'hay', H: 'hayDark', c: 'comb',
    C: 'crimson', L: 'eye', a: 'gray', g: 'grayLight', e: 'cream', W: 'white', K: 'outline',
};
const ROBOT_HEAD = {
    front: [
        'llllllllllll',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'MMMMMMMMMMMM',
        '....aaaa....',
    ],
    side: [
        'llllllllllll',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'MMMMMMMMMMMM',
        '...aaaa.....',
    ],
    back: [
        'llllllllllll',
        'lgmmmmmmmmgM',
        'lmmmmmmmmmmM',
        'lmmmmmmmmmmM',
        'lmmMMMMMMmmM',
        'lmmmmmmmmmmM',
        'lmmMMMMMMmmM',
        'lmrmmmmmmmmM',
        'lgRmmmmmmmgM',
        'MMMMMMMMMMMM',
        '....aaaa....',
    ],
};
// Speakers: [column, row] of the top-left in head-box coordinates and their rows; antennae: the stick's column and
// rows, the knob on top of it.
const ROBOT_SPEAKER = ['.h.', 'hcH', '.H.'];
const ROBOT_SPEAKERS = {
    front: [[-2, 4, ROBOT_SPEAKER], [11, 4, ROBOT_SPEAKER]],
    side: [[2, 3, ['.hh.', 'hMMh', 'hMch', '.hh.']]],
    back: [[-2, 4, ROBOT_SPEAKER], [11, 4, ROBOT_SPEAKER]],
};
const ROBOT_ANTENNAE = {
    front: [[3, -3, -1], [13, 1, 3]],
    side: [[4, -2, -1], [8, -3, -1]],
    back: [[8, -3, -1], [-2, 1, 3]],
};

function robotHead(g, x0, y0, dir, bare) {
    for (let rr = 0; rr < 11; rr++) {
        for (let c = 0; c < 12; c++) {
            const cx = AX + x0 + c;
            const cy = AY + y0 + rr;
            if (cx >= 0 && cx < W && cy >= 0 && cy < H) g[cy * W + cx] = null;
        }
    }
    stamp(g, x0, y0, ROBOT_HEAD[dir], ROBOT_LEGEND);
    ROBOT_SPEAKERS[dir].forEach(([c, rr, rows]) => stamp(g, x0 + c, y0 + rr, rows, ROBOT_LEGEND));
    if (!bare) return;
    ROBOT_ANTENNAE[dir].forEach(([c, from, to]) => {
        for (let rr = from; rr <= to; rr++) put(g, x0 + c, y0 + rr, 'gray');
        stamp(g, x0 + c, y0 + from - 2, ['cc', 'cC'], ROBOT_LEGEND);
    });
}

// Its face plate over the face slot, which it hides: round brass rims with lenses in the eye colour, a rivet for a nose
// and a grille of square teeth in a thin brass frame.
const ROBOT_FACE = {
    front: {
        at: [0, 1],
        rows: [
            '.mhhmmmmhhm.',
            '.hLLhmmhLLh.',
            '.hLLhmmhLLh.',
            '.mhhmmmmhhm.',
            '.mmmmmgmmmm.',
            '.hhhhhhhhhh.',
            '.hWWKWWKWWh.',
            '.hWWKWWKWWh.',
            '.hhhhhhhhhh.',
        ],
    },
    side: {
        at: [6, 1],
        rows: [
            'mmmhhm',
            'mmhLLh',
            'mmhLLh',
            'mmmhhm',
            'mmmmmmg',
            'mmhhhh',
            'mmhWKW',
            'mmhWKW',
            'mmhhhh',
        ],
    },
};

const robotFace = (r) => faceLayer(r, ROBOT_FACE, ROBOT_LEGEND);

/** Torso rows keyed by local y in ROBOT_LEGEND chars; rows missing from the map are plain enamel. */
const robotPattern = (map) => (i, y) => ROBOT_LEGEND[map[y]?.[i]] ?? 'robot';

// The chest panel: a cream dial with a red needle and two red buttons under it.
const ROBOT_TORSO = {
    front: robotPattern({ [-11]: 'llllllllll', [-10]: 'lmmmmmmmrm', [-9]: 'lmmeecemmm', [-8]: 'lmmeeeemmm', [-7]: 'lmmcmmcmmm', [-6]: 'MgMMMMMMgM' }),
    side: robotPattern({ [-11]: 'lllllll', [-10]: 'lmmmmmm', [-9]: 'lmmmmme', [-8]: 'lmrmmme', [-7]: 'lmmmmmc', [-6]: 'MMMMMMM' }),
    back: robotPattern({ [-11]: 'llllllllll', [-10]: 'lgmmmmmmgm', [-9]: 'lmMMMMMMmm', [-8]: 'lmmmmmmrmm', [-7]: 'lmMMMMMMRm', [-6]: 'MgMMMMMMgM' }),
};

// The bodybuilder's red headband over the forehead, knotted at the back, with the knot's two tails fluttering behind
// (bare-headed only, like the cat's ears; a hat sits over the band). c — highlight, C — the band, d — the knot.
const BANDANA_LEGEND = { c: 'comb', C: 'crimson', d: 'crimsonDark' };
const BANDANA = {
    front: { band: ['cccccccccccC', 'CCCCCCCCCCCd'], tails: [12, 3, ['d.', 'Cd', '.C']] },
    side: { band: ['ddcccccccccc', 'ddCCCCCCCCCC'], tails: [-3, 3, ['.Cd', 'C.C', 'C.C']] },
    back: { band: ['cccccddccccC', 'CCCCCddCCCCd'], tails: [4, 4, ['.dd.', 'C..C', 'C..C', 'C...']] },
};

function bandana(g, x0, y0, dir, bare) {
    const { band, tails } = BANDANA[dir];
    stamp(g, x0, y0 + 2, band, BANDANA_LEGEND);
    if (bare) stamp(g, x0 + tails[0], y0 + tails[1], tails[2], BANDANA_LEGEND);
}

// His torso, rows keyed by local y: L — the lit muscle, t — the tan, S — the shade under the pecs and between the abs,
// K — the khaki waistband of the trousers.
const MUSCLE_LEGEND = { L: 'tanLight', t: 'tan', S: 'tanShade', K: 'khakiDark' };
const musclePattern = (map) => (i, y) => MUSCLE_LEGEND[map[y]?.[i]] ?? 'tan';
const MUSCLE_TORSO = {
    front: musclePattern({ [-10]: 'tLLLttLLLt', [-9]: 'LLLLttLLLL', [-8]: 'tSSSttSSSt', [-7]: 'tttLttLttt', [-6]: 'KKKKKKKKKK' }),
    side: musclePattern({ [-11]: 'ttLLLLt', [-10]: 'tLLLLLL', [-9]: 'ttSSSSS', [-8]: 'tttLLSt', [-7]: 'tttLSLt', [-6]: 'KKKKKKK' }),
    back: musclePattern({ [-11]: 'tLLLttLLLt', [-10]: 'LLLtSStLLL', [-9]: 'tLLtSStLLt', [-8]: 'ttLtSStLtt', [-7]: 'tttLttLttt', [-6]: 'KKKKKKKKKK' }),
};

// The dinosaur's crest: orange spikes along the top of the head (bare-headed only, like the cat's ears), rows from two
// pixels above the head box; from the back the spikes run on down the back of the head, under any hat.
const DINO_LEGEND = { L: 'dinoLight', T: 'dino', t: 'dinoDark', o: 'pumpkin', O: 'pumpkinDark', w: 'cream', K: 'outline' };
const DINO_CREST = {
    front: ['.....oO.....', '....ooOO....'],
    side: ['..o...o.....', '.oO..oO..o..'],
    back: ['.....oO.....', '....ooOO....'],
};
const DINO_NAPE = ['oO', '..', '..', 'oO', '..', '..', 'oO'];

function dinoHead(g, x0, y0, dir, bare) {
    if (bare) stamp(g, x0, y0 - 2, DINO_CREST[dir], DINO_LEGEND);
    if (dir === 'back') stamp(g, x0 + 5, y0 + 2, DINO_NAPE, DINO_LEGEND);
}

// Its snout over the face slot, which keeps the eyes: a pale muzzle with two nostrils and a toothy grin from the front,
// a long jaw sticking out ahead from the side.
const DINO_SNOUT = {
    front: {
        at: [1, 7],
        rows: [
            '..LLLLLL..',
            '.LtLLLLtL.',
            '.KwKwwKwK.',
            '..KKKKKK..',
        ],
    },
    side: {
        at: [9, 5],
        rows: [
            '..TT..',
            '.LLLLt',
            'LLLLLL',
            'KwKwKK',
            'TTTTT.',
            '.TTT..',
        ],
    },
};

const dinoSnout = (r) => faceLayer(r, DINO_SNOUT, DINO_LEGEND);

// A fat tail tapering from the rump to the tip on the ground: an outline polygon per direction in local coordinates,
// its tip point swinging with the pose, lit along the top and shaded underneath, orange spikes along its ridge.
// From the back it hangs between the legs and curls aside on the ground, over the body; from the front the body hides
// it.
const DINO_TAIL = {
    side: { pts: [[-2, -10], [-6, -8], [-9, -5], [-11, -1], [-8, -2], [-5, -4], [-2, -5]], tip: 3, spikes: [[-5, -10], [-8, -8]] },
    back: { pts: [[-3, -8], [3, -8], [2, -4], [5, -2], [8, -1], [1, -1], [-2, -3]], tip: 4, spikes: [[0, -7], [0, -5], [1, -3]] },
};

function dinoTail(g, r) {
    const def = DINO_TAIL[r.dir];
    if (!def) return;
    const swing = r.pose === 'walk' || r.pose === 'shamble' || r.pose === 'ritual' ? [-1, 0, 1, 0][r.frame] : r.pose === 'fidget' ? (r.frame ? 1 : -1) : 0;
    const inside = polygon(def.pts.map(([x, y], i) => [x + r.sx + (i === def.tip ? swing : 0), y + (y < -5 ? r.bob : 0)]));
    fill(g, inside, (x, y) => (!inside(x + 0.5, y - 0.5) ? 'dinoLight' : !inside(x + 0.5, y + 1.5) ? 'dinoDark' : 'dino'));
    def.spikes.forEach(([x, y]) => put(g, x + r.sx, y + r.bob, 'pumpkin'));
}

// The clown's greasepaint under the face slot: blue marks over and under the eyes and red lips, so every face's mouth
// lands on red.
const CLOWN_PAINT = {
    front: ['', '', '', '...d....d...', '..dd....dd..', '', '', '...d....d...', '...rrrrrr...', '....rrrr....'],
    side: ['', '', '', '.........d..', '........dd..', '', '', '.........d..', '..........rr', '.........rrr'],
};

function clownHead(g, x0, y0, dir) {
    if (CLOWN_PAINT[dir]) stamp(g, x0, y0, CLOWN_PAINT[dir], { d: 'denim', r: 'comb' });
}

// The red ball nose over the face slot.
const CLOWN_NOSE = {
    front: { at: [5, 5], rows: ['rc', 'cC'] },
    side: { at: [11, 5], rows: ['rc', 'cC'] },
};

const clownNose = (r) => faceLayer(r, CLOWN_NOSE, { r: 'comb', c: 'crimson', C: 'crimsonDark' });

// The baggy suit: violet on the character's right, orange on the left, big cream pompoms down the middle and a ruff
// under the chin.
function clownTorso(i, y, dir, w) {
    // The head's outline covers the row under the chin, so the ruff starts a row lower.
    if (y === -9) return 'cream';
    if (y === -8 && i % 2 === 0) return 'seedShade';
    if (dir === 'front' && (i === 4 || i === 5) && (y === -7 || y === -6)) return i === 4 && y === -7 ? 'cream' : 'seedShade';
    if (dir === 'side') return (y === -7 || y === -6) && i === 6 ? 'cream' : 'violet';
    const right = dir === 'front' ? i < w / 2 : i >= w / 2;
    return right ? 'violet' : 'pumpkin';
}

// The Terminator's torn face over the face slot: the character's right half shows the steel skull, its red machine eye
// glowing in the socket, a raw edge of flesh round the tear and steel teeth; from the side the tear shows round the eye.
// The other eye stays the face slot's.
const T800_LEGEND = {
    G: 'grayLight', g: 'gray', a: 'steelDark', R: 'cyborgEye', H: 'cyborgEyeHot', K: 'outline', c: 'crimson', w: 'white',
};
const T800_FACE = {
    front: {
        at: [0, 2],
        rows: [
            '.cc....',
            'cGGc...',
            'GGGgc..',
            'GKRKgc.',
            'gKHRKc.',
            'GgggGc.',
            'gwawc..',
            '.cc....',
        ],
    },
    side: {
        at: [6, 3],
        rows: [
            '.cccc.',
            'cGGGGc',
            'GgKRRK',
            'GgKHRK',
            'cGgggG',
            '.cgwaw',
            '..cccc',
        ],
    },
};

const t800Face = (r) => faceLayer(r, T800_FACE, T800_LEGEND);

const BODIES = {
    skeleton: {
        label: named('Скелет', 'Skeleton'),
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
        label: named('Ведьма', 'Witch'),
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
        label: named('Балахон', 'Robe'),
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
        label: named('Рестлер', 'Wrestler'),
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
        label: named('Вампир', 'Vampire'),
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
        label: named('Мумия', 'Mummy'),
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
        label: named('Зомби', 'Zombie'),
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
        label: named('Пугало', 'Scarecrow'),
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
    // A black tuxedo cat: the crow ramp stays a step above the outline at night, the white bib, muzzle and paws carry
    // the shape. Ears, muzzle and whiskers come from headPaint, the tail from tail.
    cat: {
        label: named('Кот', 'Cat'),
        skin: 'crow',
        torso: (i, y, dir) => {
            if (dir === 'front' && y >= -10 && y <= -7 && i >= (y === -10 || y === -7 ? 4 : 3) && i <= (y === -10 || y === -7 ? 5 : 6)) return 'white';
            if (dir === 'side' && y >= -10 && y <= -8 && i >= 4) return 'white';
            return 'crow';
        },
        sleeve: () => 'crow',
        leg: () => 'crow',
        shoe: 'white',
        hand: 'white',
        headPaint: catHead,
        whiskers: catWhiskers,
        tail: catTail,
    },
    // The elePHPant in the PHP blue: the ears and the trunk carry the shape, so they stay on under every hat and face.
    elephant: {
        label: named('PHP-слоник', 'PHP elephant'),
        skin: 'elephant',
        torso: (i, y, dir) => {
            if (dir === 'front' && y >= -9 && y <= -6 && i >= (y === -9 || y === -6 ? 4 : 3) && i <= (y === -9 || y === -6 ? 5 : 6)) return 'elephantLight';
            if (dir === 'side' && y >= -9 && y <= -7 && i >= 4) return 'elephantLight';
            return 'elephant';
        },
        sleeve: () => 'elephant',
        leg: () => 'elephant',
        shoe: 'elephantLight',
        headPaint: elephantHead,
        snout: elephantTrunk,
        tail: elephantTail,
        zombie: { elephantLight: 'hazeLight' },
    },
    // The Go gopher: a pudgy cyan blob whose eyes, muzzle and buck teeth carry the shape, so they stay on under every
    // face (the face slot shows only round them); its pupils take the eye colour.
    gopher: {
        label: named('Go-гофер', 'Go gopher'),
        skin: 'gopher',
        torso: () => 'gopher',
        skirt: { hem: -5, flare: [1] },
        sleeve: () => 'gopher',
        leg: () => 'gopher',
        shoe: 'gopherTan',
        hand: 'gopherTan',
        headPaint: gopherHead,
        snout: gopherFace,
    },
    // A priest in a floor-length black cassock (the crow ramp, a step above the outline) with a white clerical collar
    // and a golden pectoral cross on a chain; the hem hides the legs, only the shoes step out from under it.
    priest: {
        label: named('Священник', 'Priest'),
        skin: 'pale',
        torso: (i, y, dir) => {
            if (dir === 'front') {
                if (y === -9 && (i === 4 || i === 5)) return 'white';
                if (y === -8 && (i === 3 || i === 5)) return 'gray';
                if ((y === -7 || y === -5 || y === -4) && i === 4) return 'gold';
                if (y === -6 && i >= 3 && i <= 5) return 'gold';
            }
            if (dir === 'side' && y === -9 && i >= 5) return 'white';
            if (dir === 'side' && y === -6 && i === 6) return 'gold';
            if (y >= -5 && i === 0) return 'crowLight';
            return 'crow';
        },
        skirt: { hem: -3, flare: [1, 1, 1] },
        sleeve: (i, n) => (i >= n - 1 ? 'crowDark' : 'crow'),
        leg: () => 'crowDark',
        shoe: 'barkDark',
        hand: 'pale',
        hair: (c, r, dir, inHead) => {
            if (!inHead) return false;
            if (dir === 'front') return r <= 1 || (r === 2 && (c <= 2 || c >= 9));
            if (dir === 'side') return r <= 1 || (c <= 3 && r <= 5);
            return r <= 6;
        },
        hairColor: 'gray',
    },
    // The tin robot: a square head with its own face, speakers and antennae (see robotHead), a chest panel, ribbed
    // arms and big round hands.
    robot: {
        label: named('Робот', 'Robot'),
        skin: 'robot',
        torso: (i, y, dir) => ROBOT_TORSO[dir](i, y),
        sleeve: (i) => (i % 2 ? 'robotDark' : 'robot'),
        leg: (i, yy) => (yy === 1 ? 'robotDark' : 'robot'),
        shoe: 'gray',
        hand: 'grayLight',
        bigHand: true,
        headPaint: robotHead,
        snout: robotFace,
        zombie: { robotLight: 'hazeLight' },
    },
    // A Rambo-style bodybuilder: a bare sun-tanned torso with pecs and abs, bulging arms and thighs (brawn), khaki army
    // trousers with darker blotches, black shaggy hair under the red headband.
    bodybuilder: {
        label: named('Качок', 'Muscleman'),
        skin: 'tan',
        torso: (i, y, dir) => MUSCLE_TORSO[dir](i, y),
        sleeve: (i, n) => (i < n * 0.6 ? 'tanLight' : 'tan'),
        leg: (i, yy, y) => ((i * 2 + y * 3 + 40) % 5 === 0 ? 'khakiDark' : 'khaki'),
        shoe: 'barkDark',
        brawn: true,
        hair: (c, r, dir, inHead) => {
            if (dir === 'front') return (inHead && r <= 1) || ((c === -1 || c === 0 || c === 11 || c === 12) && r >= 3 && r <= 7 && (inHead || r <= 6));
            if (dir === 'side') return (inHead && (r <= 1 || (c <= 3 && r <= 7))) || (c === -1 && r >= 3 && r <= 8);
            return (inHead && r <= 8) || ((c === -1 || c === 12) && r >= 3 && r <= 8);
        },
        hairColor: 'barkDark',
        headPaint: bandana,
        zombie: { tanLight: 'zombie' },
    },
    // A cute lime dinosaur kid: its own snout and teeth (the face slot keeps the eyes), a pale belly, an orange crest
    // and spikes down the back and the tail.
    dino: {
        label: named('Динозавр', 'Dinosaur'),
        skin: 'dino',
        torso: (i, y, dir) => {
            if (dir === 'front' && y >= -10 && y <= -6 && i >= (y === -10 || y === -6 ? 4 : 3) && i <= (y === -10 || y === -6 ? 5 : 6)) return 'hayLight';
            if (dir === 'side' && y >= -10 && y <= -7 && i >= 5) return 'hayLight';
            if (dir === 'side' && i === 0 && (y === -10 || y === -8)) return 'pumpkin';
            if (dir === 'back' && (i === 4 || i === 5) && (y === -10 || y === -7)) return i === 4 ? 'pumpkin' : 'pumpkinDark';
            return 'dino';
        },
        sleeve: () => 'dino',
        leg: () => 'dino',
        shoe: 'dinoDark',
        hand: 'dinoLight',
        toe: 2,
        headPaint: dinoHead,
        snout: dinoSnout,
        tail: dinoTail,
        zombie: { dinoLight: 'zombie' },
    },
    // A creepy-fun clown: a white greasepaint face with blue marks and red lips, a red ball nose, frizzy orange tufts
    // over the ears, a baggy two-tone suit with pompoms and a ruff, white gloves and big red shoes.
    clown: {
        label: named('Клоун', 'Clown'),
        skin: 'white',
        torso: clownTorso,
        skirt: { hem: -5, flare: [1] },
        sleeve: (i, n) => (i >= n - 1 ? 'cream' : i % 2 ? 'pumpkin' : 'violet'),
        leg: (i, yy) => (yy % 2 ? 'pumpkin' : 'violet'),
        shoe: 'crimson',
        bigShoe: true,
        // Bald on top: the tufts puff out a pixel past the head at the sides.
        hair: (c, r, dir, inHead) => {
            const puff = r >= 3 && r <= 6;
            if (dir === 'front') return inHead ? (c <= 1 || c >= 10) && r >= 2 && r <= 7 : (c === -1 || c === 12) && puff;
            if (dir === 'side') return inHead ? c <= 3 && r >= 2 && r <= 7 : c === -1 && puff;
            return inHead ? r >= 2 && r <= 8 && (c <= 1 || c >= 10 || r >= 5) : (c === -1 || c === 12) && puff;
        },
        // Little 2×2 curls, every other row of them shifted: each lit at the top left and shaded at the bottom right.
        hairColor: (c, r) => {
            const x = (c + 1 + ((r >> 1) & 1)) & 1;
            const y = r & 1;
            return !x && !y ? 'pumpkinLight' : x && y ? 'pumpkinDark' : 'pumpkin';
        },
        headPaint: clownHead,
        snout: clownNose,
    },
    // The T-800: a black leather jacket over a grey shirt, a dark crew cut, half the face torn off to the steel skull
    // with a glowing red machine eye (see T800_FACE).
    terminator: {
        label: named('Терминатор', 'Terminator'),
        skin: 'skin',
        torso: (i, y, dir) => {
            if (y === -6) return dir !== 'back' && (i === 4 || i === 5) ? 'gray' : 'barkDark';
            if (dir === 'front') {
                if (i >= 3 && i <= 6 && y >= -10) return y === -10 && (i === 3 || i === 6) ? 'crowLight' : 'gray';
                if ((i === 2 || i === 7) && y <= -8) return 'crowLight';
            }
            if (dir === 'side' && y >= -10 && i === 6) return 'gray';
            if (dir === 'side' && y >= -10 && y <= -8 && i === 5) return 'crowLight';
            if (dir === 'back' && y === -11) return 'crowLight';
            return 'crow';
        },
        sleeve: (i, n) => (i >= n - 1 ? 'crowDark' : 'crow'),
        leg: (i, yy) => (yy === 0 ? 'crowDark' : 'crow'),
        shoe: 'barkDark',
        hand: 'skin',
        hair: (c, r, dir, inHead) => {
            if (!inHead) return false;
            if (dir === 'front') return r <= 1 || (r === 2 && (c <= 1 || c >= 10));
            if (dir === 'side') return r <= 1 || (c <= 3 && r <= 4);
            return r <= 5;
        },
        hairColor: 'barkDark',
        snout: t800Face,
    },
};

// ---------------------------------------------------------------------------------------------------------------
// Faces: 12×11 grids over the head box, front and side. E is the eye: a placeholder key replaced by the eye colour.

const FACE_LEGEND = { K: 'outline', E: 'eye', W: 'white', p: 'blush', S: 'cream', s: 'seedShade', M: 'innerDark', R: 'crimson' };

const FACES = {
    smile: {
        label: named('Улыбка', 'Smile'),
        front: ['', '', '', '', '', '..EE....EE..', '..EK....KE..', '.p........p.', '....K..K....', '.....KK.....'],
        side: ['', '', '', '', '', '........EE..', '........EK..', '......p.....', '..........K.', '.........K..'],
    },
    fangs: {
        label: named('Клыки', 'Fangs'),
        front: ['', '', '', '', '..K......K..', '..EE....EE..', '..KE....EK..', '............', '...KKKKKK...', '...KWKKWK...'],
        side: ['', '', '', '', '........K...', '........EE..', '........EK..', '............', '.........KKK', '.........KWK'],
    },
    stitches: {
        label: named('Шрамы', 'Scars'),
        front: ['', '', '', '.K.K.K......', '.KKKKKK.....', '..EE....EE..', '..EK....KE.K', '.........KKK', '....KKKK...K', '..........K.'],
        side: ['', '', '', '...K.K.K....', '...KKKKKK...', '........EE..', '.....K..EK..', '....KKK.....', '.....K....KK', '............'],
    },
    skull: {
        label: named('Череп', 'Skull'),
        front: ['', '', '..SSSSSSSS..', '.SSSSSSSSSS.', '.SSSSSSSSSS.', '.SKKSSSSKKS.', '.SKESSSSEKS.', '.SSSSKKSSSS.', '..SSSSSSSS..', '..SKSKSKSK..', '...SSSSSS...'],
        side: ['', '', '.....SSSSS..', '....SSSSSSS.', '....SSSSSSSS', '....SSSKKSSS', '....SSSKESSS', '....SSSSSSKS', '.....SSSSSSS', '.....SSKSKSK', '......SSSSS.'],
    },
    grin: {
        label: named('Ухмылка', 'Smirk'),
        front: ['', '', '', '', '.KK......KK.', '..EE....EE..', '..EE....EE..', '.K........K.', '.KWWWWWWWWK.', '..KKKKKKKK..'],
        side: ['', '', '', '', '.......KK...', '........EE..', '........EE..', '...........K', '.......KWWWW', '........KKKK'],
    },
    scared: {
        label: named('Испуг', 'Scared'),
        front: ['', '', '', '..K......K..', '.K........K.', '..EE....EE..', '..EE....EE..', '............', '.....KK.....', '.....KK.....'],
        side: ['', '', '', '........K...', '.......K....', '........EE..', '........EE..', '............', '..........KK', '..........KK'],
    },
    cyclops: {
        label: named('Циклоп', 'Cyclops'),
        front: ['', '', '', '', '....KKKK....', '....EEEE....', '....EKKE....', '.....EE.....', '............', '....KKKK....'],
        side: ['', '', '', '', '........KKK.', '.........EE.', '.........EK.', '.........E..', '............', '..........KK'],
    },
    // A pirate's patch over one eye with its strap across the forehead and round the back of the head, the other eye
    // as usual, a crooked grin. From the side the patch covers the eye in view.
    eyepatch: {
        label: named('Повязка на глаз', 'Eye patch'),
        front: ['', '', '.KK.........', '...KK.......', '.....KKKKKK.', '..EE...KKKKK', '..EK...KKKK.', '........KK..', '.........K..', '....KKKK....'],
        side: ['', '', 'KKK.........', '...KKK......', '......KKKK..', '.......KKKK.', '.......KKKK.', '........KK..', '...........K', '.........KK.'],
        back: ['', '', '..........KK', '.......KKK..', '...KKKK.....', 'KKK.........'],
        // The patch and its strap alone are worn: they go on over a body's own face too (the snout layer), on its own
        // eye where it has one (wornOn), { at, rows } in head-box coordinates.
        worn: {
            front: { at: [0, 2], rows: ['.KK.........', '...KK.......', '.....KKKKKK.', '.......KKKKK', '.......KKKK.', '........KK..', '.........K..'] },
            side: { at: [0, 2], rows: ['KKK.........', '...KKK......', '......KKKK..', '.......KKKK.', '.......KKKK.', '........KK..'] },
        },
        wornOn: {
            gopher: {
                front: { at: [0, 0], rows: ['..KKK.......', '.....KKK....', '........KKK.', '.......KKKKK', '.......KKKKK', '.......KKKKK', '........KKK.'] },
                side: { at: [0, 1], rows: ['.KKK........', '....KKKKKK..', '......KKKKK.', '......KKKKK.', '......KKKKK.', '.......KKK..'] },
            },
            robot: {
                front: { at: [0, 0], rows: ['..KKK.......', '.....KKKKKK.', '.......KKKK.', '.......KKKK.', '.......KKKK.', '........KK..'] },
                side: { at: [0, 1], rows: ['....KKKKKKKK', 'KKKK....KKKK', '........KKKK', '........KKKK', '.........KK.'] },
            },
        },
    },
};

/** A face's worn part over the head at its { at, rows } for the direction, as is: it lies inside the head. */
function wornLayer(r, defs) {
    const g = blank();
    const def = defs[r.dir];
    if (def) stamp(g, r.head.x + def.at[0], r.head.y + def.at[1], def.rows, FACE_LEGEND);
    return g;
}

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

// The pirate's tricorn: a black crown in a brim turned up into three corners, the brim's edge in gold, a small white
// skull on the front. From the front and the back two corners point up at the sides and the third dips at the middle.
const TRICORN = {
    front: [
        '.....bkkkkk.....',
        'Y...bkkwwwkk...Y',
        'kY..bkwKwKwk..Yk',
        'kkY.bkkwwwkk.Ykk',
        '.kkYYkkkkkkYYkk.',
        '..kkkYYYYYYkkk..',
        '....kkkkkkkk....',
    ],
    side: [
        '....bkkkkk....',
        'Y..bkkkkkkk...',
        'kY.bkkkkkkk..Y',
        'kkYbkkkkkkk.Yk',
        '.kkYYkkkkkkYkk',
        '..kkkYYYYYYkk.',
        '...kkkkkkkkk..',
    ],
    back: [
        '.....kkkkkb.....',
        'Y...kkkkkkkb...Y',
        'kY..kkkkkkkb..Yk',
        'kkY.kkkkkkkb.Ykk',
        '.kkYYkkkkkkYYkk.',
        '..kkkYYYYYYkkk..',
        '....kkkkkkkk....',
    ],
};

function tricorn(g, x0, y0, dir) {
    stamp(g, x0 - (dir === 'side' ? 1 : 2), y0 - 5, TRICORN[dir], HAT_LEGEND);
}

// The punk mohawk: a tall crest of spiky strands from the forehead to the nape, the sides shaved (the head under it
// shows as the body has it). Its colour runs from the root outward: R — the hot pink root, M — halfway to the eyes,
// T — the strand tips in the eye colour (crestMid and crestTip, filled in per look), S — the root's shade and the
// partings between strands. From the front it is a narrow strip with tips poking out on both sides, from the side a
// fin of spikes fanning out over the head; from the back it also runs down the back of the head. It grows out of the
// head, so it has no outline over it (grown).
const MOHAWK_LEGEND = { R: 'mohawk', S: 'mohawkShade', M: 'crestMid', T: 'crestTip' };
const MOHAWK = {
    front: [
        '.T..T.',
        '.TTTT.',
        'TTMMTT',
        '.MMMM.',
        'TMMMMT',
        '.RRSS.',
        'TRRSST',
        '.RRSS.',
        '.RRSS.',
        '.RRSS.',
    ],
    side: [
        '.......T....',
        '...T...T....',
        '...TT.TT....',
        '...TT.MT...T',
        'T...MMMMM.TT',
        'MM..MMMMMMMM',
        '.MSRRSRRRSM.',
        '.RRRRSRRRSR.',
        '..RRRRRRRR..',
        '..SSSSSSSS..',
        '.RS.........',
        'RS..........',
        'RS..........',
    ],
    back: [
        '.T..T.',
        '.TTTT.',
        'TTMMTT',
        '.MMMM.',
        'TMMMMT',
        '.RRSS.',
        'TRRSST',
        '.RRSS.',
        '.RRSS.',
        '.RRSS.',
        '..RS..',
        '..RS..',
        '..RS..',
        '..RS..',
        '..RS..',
    ],
};

function mohawk(g, x0, y0, dir) {
    stamp(g, x0 + (dir === 'side' ? 0 : 3), y0 - 9, MOHAWK[dir], MOHAWK_LEGEND);
}

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

// «Ушки»: the cat's ears on a thin band over the crown, black like the cat's so the pink inside carries the shape.
const EAR_BAND = { front: [1, 10], side: [3, 8], back: [1, 10] };

function catEarsHat(g, x0, y0, dir) {
    const [a, b] = EAR_BAND[dir];
    for (let c = a; c <= b; c++) put(g, x0 + c, y0 + 1, 'crowLight');
    catEars(g, x0, y0, dir, 'crow');
}

const HATS = {
    none: { label: named('Нет', 'None'), paint: () => {} },
    pumpkin: { label: named('Тыква', 'Pumpkin'), paint: (g, x0, y0) => pumpkinHat(g, x0, y0) },
    witch: {
        label: named('Ведьмина шляпа', 'Witch hat'),
        paint: (g, x0, y0, dir) => stamp(g, x0 - 3, y0 - 10, WITCH_HAT, HAT_LEGEND, dir !== 'front'),
    },
    hood: { label: named('Капюшон культиста', 'Cultist hood'), paint: hoodPainter },
    'witch-doctor': { label: named('Вич-доктор', 'Witch doctor'), paint: witchDoctor },
    'top-hat': { label: named('Цилиндр', 'Top hat'), paint: (g, x0, y0) => stamp(g, x0 - 1, y0 - 7, TOP_HAT, HAT_LEGEND) },
    horns: {
        label: named('Рога', 'Horns'),
        paint: (g, x0, y0, dir) => (dir === 'side'
            ? stamp(g, x0, y0 - 4, HORNS.side, HAT_LEGEND)
            : stamp(g, x0, y0 - 4, HORNS.front, HAT_LEGEND)),
    },
    'cat-ears': { label: named('Ушки', 'Cat ears'), paint: catEarsHat },
    tricorn: { label: named('Треуголка', 'Tricorn'), paint: tricorn },
    mohawk: { label: named('Ирокез', 'Mohawk'), paint: mohawk, grown: true },
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
    none: { label: named('Нет', 'None'), paint: () => {} },
    cape: { label: named('Плащ', 'Cape'), paint: (g, r, layer) => cape(g, r, layer, { outer: 'crimsonDark', inner: 'crimson', fold: 'pumpkinDeep', collar: true }) },
    rags: { label: named('Лохмотья', 'Rags'), paint: (g, r, layer) => cape(g, r, layer, { outer: 'gray', inner: 'grayLight', fold: 'duckGrayDark', ragged: true }) },
    poncho: { label: named('Пончо', 'Poncho'), paint: poncho },
    wings: { label: named('Крылья', 'Wings'), paint: wings },
};

// ---------------------------------------------------------------------------------------------------------------
// Items: per direction, rows plus the grip — the item pixel under the top-left of the 2×2 hand. The back view mirrors
// the front. muzzle — where the flash goes, barrel — which way it fires; flame — the item pixel the flame stands on;
// fx — where the item's own shot effect starts when it is aimed (shoot pose); flames — which flicker frames stand on
// the flame pixel (FLAMES, the candle's by default); keep — legend chars that keep their hue at night (the beer and its
// foam, not the glass), returned as their own layer.

const ITEM_LEGEND = {
    w: 'white', c: 'cream', y: 'pumpkinShine', Y: 'pumpkinLight', g: 'grayLight', a: 'gray', k: 'barkDark',
    b: 'bark', B: 'barkLight', h: 'hay', H: 'hayDark', L: 'hayLight', s: 'stemLight',
    // The magic book: green lines on cream pages in a violet binding with a dark spine.
    e: 'eyeGreen', E: 'eerieHot', v: 'violetDark', V: 'violet', m: 'eerieMid',
    // The rapier: a golden cup hilt, its blade light (fx keys: no outline, drawn unlit in the frame's glow).
    o: 'gold', O: 'pumpkinGoldDark', Q: 'fxBlade', q: 'fxBladeEdge',
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
        label: named('Пиво', 'Beer'),
        front: { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [4, 3], fx: [2, 0], keep: 'wcyY' },
        side: { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [-1, 3], fx: [2, 0], keep: 'wcyY' },
    },
    broom: {
        label: named('Метла', 'Broom'),
        front: { rows: BROOM, grip: [1, 11] },
        side: { rows: BROOM_SIDE, grip: [4, 12] },
    },
    pistol: {
        label: named('Пистоль', 'Pistol'),
        front: { rows: ['.a..', '.g..', '.g..', '.g..', '.ga.', 'Bbb.', '.Bb.', '..b.'], grip: [1, 5] },
        side: { rows: ['..a.', '..g.', '..g.', '..g.', '.ag.', '.bbB', '.bB.', '.b..'], grip: [1, 5] },
    },
    // A wax candle on a tin dish, carried at dusk. The flame is not in the rows: it is light, drawn over the frame in
    // its own flicker frames (CANDLE_FLAME), standing on the pixel above the wax.
    candle: {
        label: named('Свеча', 'Candle'),
        front: { rows: ['.....', '.....', '.....', '.ccc.', '.wcc.', '.ccc.', '.ccc.', 'ggggg', '.aaa.'], grip: [2, 7], flame: [2, 2], fx: [2, 1] },
        side: { rows: ['.....', '.....', '.....', '.ccc.', '.wcc.', '.ccc.', '.ccc.', 'ggggg', '.aaa.'], grip: [2, 7], flame: [2, 2], fx: [2, 1] },
    },
    // An open spell book held up by its spine: the violet cover round the edges and below, the dark spine down the
    // middle, two cream pages with green lines of text. From the side it turns three quarters to the camera, the near
    // page wider.
    book: {
        label: named('Магическая книга', 'Spellbook'),
        front: {
            rows: ['.ccc.ccc.', 'VmmmvmmeV', 'VcccvcccV', 'VmmcvmmmV', 'VcccvcccV', 'VmmmvmccV', 'VVVVvVVVV'],
            grip: [4, 6],
            fx: [4, 0],
        },
        side: { rows: ['.ccc.c.', 'VcmmvmV', 'VcccvcV', 'VcmevcV', 'VcccvcV', 'VcmmvmV', 'VVVVvVV'], grip: [-1, 3], fx: [4, 0] },
    },
    // Items have no frames of their own, so the flame is static: a white-hot core in a lopsided tongue of fire over
    // a rag wrapping, so it reads as a flame and not as a lollipop. Gripped low, the flame clears the head.
    torch: {
        label: named('Факел', 'Torch'),
        front: {
            rows: ['..Y..', '.YY..', '.YyY.', 'YywyY', 'YwwyY', '.ywy.', 'HhHhH', '.HhH.', '..B..', '..b..', '..b..', '..b..', '..b..', '..b..', '..k..'],
            grip: [1, 12],
            fx: [2, 0],
        },
        side: {
            rows: ['...Y.', '..YY.', '.YyY.', 'YywyY', 'YwwyY', '.ywy.', 'HhHhH', '.HhH.', '..B..', '..b..', '..b..', '..b..', '..b..', '..b..', '..k..'],
            grip: [1, 12],
            fx: [3, 0],
        },
    },
    // Three grey tines on a long shaft; from the side it leans forward like the broom, so it clears the face.
    pitchfork: {
        label: named('Вилы', 'Pitchfork'),
        front: {
            rows: [
                'g.g.g', 'g.g.g', 'g.g.g', 'aggga', '..g..', '..B..', '..B..', '..B..', '..B..', '..B..', '..B..',
                '..B..', '..B..', '..B..', '..B..', '..B..', '..b..', '..b..', '..b..',
            ],
            grip: [1, 11],
        },
        side: {
            rows: [
                '......g.g.g', '......g.g.g', '......aggga', '........g..', '........B..', '.......B...', '.......B...',
                '.......B...', '......B....', '......B....', '......B....', '.....B.....', '.....B.....', '.....B.....',
                '....B......', '....b......', '...b.......',
            ],
            grip: [4, 12],
        },
    },
    // A small red flame floating over the open palm, in the magic circle's red: no rows, only light (RED_FLAME), so
    // the night never dims it. It hovers a pixel above the hand; from the side ahead of the fingers, clear of the face.
    'red-flame': {
        label: named('Красный огонёк', 'Red flame'),
        front: { rows: ['.', '.', '.'], grip: [0, 2], flame: [0, 0], fx: [0, -1], flames: 'red' },
        side: { rows: ['......', '......'], grip: [0, 1], flame: [5, 0], fx: [5, -1], flames: 'red' },
    },
    // The rapier of finale steps 6–7: a long thin blade of light over a golden cup hilt. Upright from the front; from
    // the side it points forward and up, so the blade clears the face. `blade` — its light shimmers (frame `flames`).
    rapier: {
        label: named('Рапира', 'Rapier'),
        front: {
            rows: ['..q..', '..Q..', '..Q..', '..Q..', '..Q..', '..Q..', '..Q..', '..Q..', '..Q..', '..Q..', '..Q..', 'o.Q.o', 'oOOOo', '.OoO.', '..k..', '..k..', '..o..'],
            grip: [2, 14],
            fx: [2, 0],
            blade: true,
        },
        side: {
            rows: ['.........q', '........Q.', '.......Q..', '......Q...', '.....Q....', '....Q.....', '..oQ......', '..OOo.....', '.k.o......', 'k.........'],
            grip: [0, 8],
            fx: [9, 0],
            blade: true,
        },
    },
};

// Shooting always shows the pistol, aimed: [fire, recoil] per direction; barrel — the firing direction, a vector.
const AIM = {
    side: [
        { rows: ['..a....', 'bbggggg', 'Bb.....', 'b......'], grip: [0, 1], muzzle: [7, 1], barrel: [1, 0] },
        { rows: ['......g', '....gg.', '..agg..', 'bbg....', 'Bb.....', 'b......'], grip: [0, 3], muzzle: [7, -1], barrel: [2, -1] },
    ],
    front: [
        { rows: ['g....', '.g...', '..ga.', '...bb', '...Bb', '....b'], grip: [3, 3], muzzle: [-1, -1], barrel: [-1, -1] },
        { rows: ['.g...', '.g...', '..ga.', '..bb.', '..Bb.', '...b.'], grip: [2, 3], muzzle: [1, -2], barrel: [-1, -3] },
    ],
};

// Drinking always shows the beer, tipped further each frame: [at the mouth, level, bottom up]. The side view faces
// right with the foam toward the mouth; the front view is its mirror, the mouth right of the item hand.
const DRINK_SIDE = [
    { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [-1, 3], keep: 'wcyY' },
    { rows: ['.wgggg', 'wwyyyg', 'cwYYYg', 'wwYYYg', '.wgggg'], grip: [1, 3], keep: 'wcyY' },
    { rows: ['....gg', '...gYg', '..gYYg', '.wYYg.', 'wcwg..', '.ww...'], grip: [3, 4], keep: 'wcyY' },
];
const mirrorRows = (rows) => {
    const w = Math.max(...rows.map((r) => r.length));
    return rows.map((r) => [...r.padEnd(w, '.')].reverse().join(''));
};
const DRINK = {
    side: DRINK_SIDE,
    front: [
        DRINK_SIDE[0],
        { ...DRINK_SIDE[1], rows: mirrorRows(DRINK_SIDE[1].rows), grip: [-1, 2] },
        { ...DRINK_SIDE[2], rows: mirrorRows(DRINK_SIDE[2].rows), grip: [0, 3] },
    ],
};

// The candle flame's flicker: steady, stretched tall, bent and low. The last row stands on the item's flame pixel.
const FLAME_LEGEND = { Y: 'fxFlame', y: 'fxFlameHot', w: 'fxFlameCore' };
const CANDLE_FLAME = [
    ['...', '.Y.', 'YwY', '.y.'],
    ['.Y.', '.Y.', 'YwY', '.y.'],
    ['...', 'Y..', 'ywY', '.y.'],
];
// The red flame: a rounded wisp with a white-hot heart and a dark-red tip; steady, stretched tall, bent.
const RED_LEGEND = { R: 'fxRune', r: 'fxRuneDark', h: 'fxRuneHot', w: 'fxRuneCore' };
const RED_FLAME = [
    ['.r.', '.R.', 'RhR', 'RwR', '.R.'],
    ['.r.', '.R.', '.R.', 'RhR', 'RwR', '.R.'],
    ['r..', '.R.', 'RRh', 'RwR', '.R.'],
];
const FLAMES = { candle: [CANDLE_FLAME, FLAME_LEGEND], red: [RED_FLAME, RED_LEGEND] };

// ---------------------------------------------------------------------------------------------------------------
// Shot effects the scene plays over a shooting walker (docs/spec/frontend.md, «Выстрел»). Sprites in the sprites.js
// format ({ w, h, rows, legend, anchor }); the flash is light — bake it as is, the smoke takes the scene's lighting.

const FX_BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const fxDither = (x, y) => (FX_BAYER[y & 3][x & 3] + 0.5) / 16;
const FLASH_LEGEND = { '.': null, W: 'flameCore', s: 'pumpkinShine', Y: 'pumpkinLight', o: 'pumpkin', r: 'ember' };
const FLASH_R = 7;

/**
 * A muzzle flash along the barrel (dx, dy), anchored on the muzzle: 0 — the bang, a white-hot tongue with a star of
 * short rays; 1 — the tongue torn off the muzzle, sparks flying on; 2 — the last orange wisps.
 */
function flashSprite(dx, dy, frame) {
    const len = Math.hypot(dx, dy) || 1;
    const [ux, uy] = [dx / len, dy / len];
    const n = 2 * FLASH_R + 1;
    const rows = [];
    for (let y = -FLASH_R; y <= FLASH_R; y++) {
        let row = '';
        for (let x = -FLASH_R; x <= FLASH_R; x++) {
            const a = x * ux + y * uy;
            const c = Math.abs(-x * uy + y * ux);
            const ang = Math.atan2(c, a);
            const d = Math.hypot(x, y);
            let k = '.';
            if (frame === 0) {
                const w = a >= -0.5 && a <= 6.5 ? 0.7 + 1.9 * Math.sin((Math.PI * (a + 0.5)) / 7.5) : -1;
                if (c <= w) k = a < 2.5 && c < 1.1 ? 'W' : c > w - 0.8 ? (a > 4.5 ? 'o' : 'Y') : 's';
                else if (d <= 1.5) k = 'Y';
                // Four rays fanning out of the muzzle, two forward and two thrown back.
                else if ([1.0, 2.1].some((r) => Math.abs(ang - r) < 0.2) && d < (ang < 1.5 ? 4.6 : 3.6)) k = d < 2.6 ? 's' : 'Y';
            } else if (frame === 1) {
                const w = a >= 2 && a <= 7 ? 0.5 + 1.3 * Math.sin((Math.PI * (a - 2)) / 5) : -1;
                if (c <= w) k = c < 0.7 && a < 5 ? 's' : a > 5.5 ? 'o' : 'Y';
                else if (Math.abs(ang - 0.95) < 0.13 && Math.abs(d - 5.5) < 0.6) k = 's';
                else if (Math.abs(ang - 2.2) < 0.15 && Math.abs(d - 4) < 0.6) k = 'Y';
            } else {
                const w = a >= 4 && a <= 8 ? 0.4 + 1.1 * Math.sin((Math.PI * (a - 4)) / 4) : -1;
                if (c <= w && fxDither(x + 8, y + 8) < 0.55) k = a > 6.5 ? 'r' : 'o';
                else if (Math.abs(ang - 0.9) < 0.12 && Math.abs(d - 6.5) < 0.6) k = 'Y';
            }
            row += k;
        }
        rows.push(row);
    }
    return { w: n, h: n, rows, legend: FLASH_LEGEND, anchor: { x: FLASH_R, y: FLASH_R } };
}

const SMOKE_LEGEND = { '.': null, w: 'white', g: 'grayLight', a: 'gray' };

// Each puff is a lumpy cloud: a main ball and two smaller ones, mirrored for the second variant.
const PUFF_LUMPS = [[0, 0, 1], [-0.75, 0.35, 0.6], [0.7, 0.45, 0.55]];

/**
 * A smoke puff of radius r, variant v: a lumpy cloud lit from above — pale crown, grey body, a shade underneath — its
 * rim dithered half and half, the whole thinned to `density` by a Bayer step (1 — solid).
 */
function puffSprite(r, density, v) {
    const n = 2 * r + 3;
    const c = r + 1;
    const lumps = PUFF_LUMPS.map(([lx, ly, k]) => [c + (v ? -lx : lx) * r, c + ly * r, Math.max(0.6, k * r)]);
    const rows = [];
    for (let y = 0; y < n; y++) {
        let row = '';
        for (let x = 0; x < n; x++) {
            const depth = Math.max(...lumps.map(([lx, ly, lr]) => lr + 0.35 - Math.hypot(x - lx, y - ly)));
            const rim = depth < 0.9 && r > 1 && (x + y) % 2 === 1;
            if (depth < 0 || rim || fxDither(x, y) >= density) {
                row += '.';
                continue;
            }
            const dy = (y - c) / r;
            row += dy > 0.7 ? 'a' : dy < -0.45 && r > 1 && depth > 0.9 ? 'w' : 'g';
        }
        rows.push(row);
    }
    return { w: n, h: n, rows, legend: SMOKE_LEGEND, anchor: { x: c, y: c } };
}

const SPELL_LEGEND = { '.': null, M: 'magicCore', h: 'eerieHot', n: 'eyeGreen' };
// The red flame's shot: the flash recoloured in the magic circle's reds, and a spark of the same light.
const RED_FX_LEGEND = { '.': null, W: 'columnCore', s: 'bloodMoonLight', Y: 'rune', o: 'runeDark', r: 'runeDark', R: 'rune', h: 'bloodMoonLight', w: 'columnCore' };
const redFlashCache = new Map();
const BUBBLE_LEGEND = { '.': null, w: 'white', W: 'hazeLight' };
const fxSprite = (rows, legend) => ({ w: rows[0].length, h: rows.length, rows, legend, anchor: { x: rows[0].length >> 1, y: rows.length >> 1 } });
const flashCache = new Map();
const PUFF_SIZES = [1, 2, 3, 4];
const PUFF_DENSITY = [1, 0.62, 0.38, 0.18];

export const SHOT_FX = {
    /** Flash frames (3) for a barrel direction, as characterFrame returns it; cached per direction. */
    flash([dx, dy]) {
        const key = `${dx},${dy}`;
        if (!flashCache.has(key)) flashCache.set(key, [0, 1, 2].map((f) => flashSprite(dx, dy, f)));
        return flashCache.get(key);
    },
    // Smoke puffs: [size][fade][variant], radius 1..4, each thinner in four Bayer steps, two mirrored variants.
    puffs: PUFF_SIZES.map((r) => PUFF_DENSITY.map((d) => [0, 1].map((v) => puffSprite(r, d, v)))),
    // The candle's fireball: a white-hot ball in two flickering shapes, light like the flash.
    fireball: [
        fxSprite(['..YsY..', '.YsWsY.', 'YsWWWsY', 'sWWWWWs', 'YsWWWsY', '.YsWsY.', '..YsY..'], FLASH_LEGEND),
        fxSprite(['...Y...', '.YssY..', 'YsWWsY.', 'sWWWWsY', '.sWWWs.', '.YssYo.', '..o....'], FLASH_LEGEND),
    ],
    // The spell book's flash at the pages: a green four-point star, light.
    spark: fxSprite(['...n...', '...h...', '..nhn..', 'nhhMhhn', '..nhn..', '...h...', '...n...'], SPELL_LEGEND),
    // Beer bubbles: a ring with a shine in two sizes; the pop of one — four dots flying apart.
    bubbles: [
        fxSprite(['.w.', 'w.W', '.W.'], BUBBLE_LEGEND),
        fxSprite(['.ww.', 'w..W', 'w..W', '.WW.'], BUBBLE_LEGEND),
    ],
    pop: fxSprite(['w...w', '.....', '.....', '.....', 'W...W'], BUBBLE_LEGEND),
    /** The red flame's flare: the flash frames for a direction in the magic circle's reds, light; cached. */
    redFlash(barrel) {
        const key = barrel.join(',');
        if (!redFlashCache.has(key)) redFlashCache.set(key, SHOT_FX.flash(barrel).map((def) => ({ ...def, legend: RED_FX_LEGEND })));
        return redFlashCache.get(key);
    },
    // The red flame's spark streaking at the target: a four-point star turning between two shapes, light.
    redSpark: [
        fxSprite(['..R..', '.RhR.', 'RhwhR', '.RhR.', '..R..'], RED_FX_LEGEND),
        fxSprite(['r...r', '.hRh.', '.RwR.', '.hRh.', 'r...r'], RED_FX_LEGEND),
    ],
};

/** Stamps the item; returns a mapper from item pixels to local coordinates (the flip mirrors the columns). */
function placeItem(g, spr, hand, flip) {
    const w = Math.max(...spr.rows.map((s) => s.length));
    const [gx, gy] = spr.grip;
    const x0 = flip ? hand[0] + 2 - w + gx : hand[0] - gx;
    stamp(g, x0, hand[1] - gy, spr.rows, ITEM_LEGEND, flip);
    return ([x, y]) => [flip ? x0 + w - 1 - x : x0 + x, hand[1] - gy + y];
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
            // Brawn: the thigh bulges a pixel over the top two rows, outward from the front and the back, forward
            // from the side.
            if (body.brawn && y < LEG_TOP + 2 && y < bottom) {
                const outer = side || leg.hip > -1 ? 3 : -1;
                const key = body.leg(outer < 0 ? 0 : 2, y - LEG_TOP, y);
                put(g, x + outer, y, leg.far || outer > 0 ? dark(key) : key);
            }
            if (side && y === bottom) {
                for (let k = 0; k < (body.toe ?? 1); k++) put(g, x + 3 + k, y - (k > 0 ? 1 : 0), leg.far ? dark(body.shoe) : body.shoe);
            }
            if (body.bigShoe && y === bottom) {
                const shoe = leg.far ? dark(body.shoe) : body.shoe;
                if (side) {
                    [[-1, 0], [4, 0], [5, 0], [3, -1], [4, -1]].forEach(([dx, dy]) => put(g, x + dx, y + dy, shoe));
                } else {
                    const outer = leg.hip > -1 ? 3 : -1;
                    put(g, x + outer, y, outer > 0 ? dark(shoe) : shoe);
                    put(g, x + outer, y - 1, outer > 0 ? dark(shoe) : shoe);
                }
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
            const swing = (r.pose === 'walk' || r.pose === 'shamble') && y === body.skirt.hem ? r.sway : 0;
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
    if (body.brawn) brawnArm(g, body, arm);
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
    // A big round hand (the robot's) rings the 2×2 one, shaded on the right and below.
    if (body.bigHand) {
        BIG_HAND.forEach(([dx, dy]) => put(g, arm.hand[0] + dx, arm.hand[1] + dy, arm.far || dx > 1 || dy > 1 ? dark(hand) : hand));
    }
}

const BIG_HAND = [[0, -1], [1, -1], [-1, 0], [2, 0], [-1, 1], [2, 1], [0, 2], [1, 2]];

/**
 * Brawn under the sleeve: the deltoid caps the shoulder and the biceps bulges a pixel off the upper arm, outward from
 * the front and the back, forward from the side; lit on the left, shaded on the right.
 */
function brawnArm(g, body, arm) {
    const [sx, sy] = arm.sh;
    const out = sx <= -4 ? -1 : 1;
    const col = out < 0 ? -1 : 2;
    const tone = (key) => {
        const k = out < 0 ? key : dark(key);
        return arm.far ? dark(k) : k;
    };
    const upper = body.sleeve(0, 4, 0);
    const to = arm.elbow ?? arm.hand;
    const n = Math.max(Math.abs(to[0] - sx), Math.abs(to[1] - sy));
    const reach = arm.elbow ? n : Math.ceil(n * 0.5);
    for (let i = 0; i <= reach; i++) {
        const t = n ? i / n : 0;
        const bx = Math.round(sx + (to[0] - sx) * t);
        const by = Math.round(sy + (to[1] - sy) * t);
        put(g, bx + col, by, tone(upper));
        put(g, bx + col, by + 1, tone(upper));
    }
    put(g, sx, sy - 1, arm.far ? dark(upper) : upper);
    put(g, sx + 1, sy - 1, tone(upper));
}

function drawHead(body, face, r, ears) {
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
    if (body.headPaint) body.headPaint(g, x0, y0, r.dir, ears);
    const rows = face[r.dir];
    if (rows) stamp(g, x0, y0, rows, FACE_LEGEND);
    return g;
}

function compose(look, dir, pose, frame, item) {
    const body = BODIES[look.body];
    const r = rig(dir, pose, frame);
    const shoot = pose === 'shoot';
    const ritual = pose === 'ritual';
    // Shooting aims what the hand holds: the pistol has its own aimed sprites, any other item is held out on the aimed
    // arm, an empty hand points.
    const gun = shoot && item === 'pistol';
    // Drinking always tips the beer to the mouth; the shrug shows empty hands whatever the item.
    const drink = pose === 'drink';
    // A zombie's hands are held out empty in front.
    const zombie = pose === 'shamble' || pose === 'lurch';
    const held = !ritual && !gun && pose !== 'shrug' && !zombie && (drink || ITEM_DEFS[item]);
    // Clinking and the toast raise the item hand even when it is empty.
    const raised = pose === 'clink' || pose === 'toast';
    const holding = !shoot && (held || raised);
    const cloak = CLOAKS[look.cloak];
    const out = blank();
    const layer = (paint) => {
        const g = blank();
        paint(g);
        over(out, outlined(g));
    };

    layer((g) => cloak.paint(g, r, 'back'));
    if (body.tail && dir !== 'back') layer((g) => body.tail(g, r));
    over(out, outlined(drawLegs(body, r)));
    const free = r.arms.filter((a, i) => !((shoot || holding) && i === r.itemArm));
    layer((g) => free.filter((a) => a.far).forEach((a) => drawArm(g, body, a)));
    over(out, outlined(drawTorso(body, r)));
    if (body.tail && dir === 'back') layer((g) => body.tail(g, r));
    const fx = blank();
    const result = { out };
    let keep = null;
    const holdLayer = () => {
        const arm = shoot ? r.aim : r.hold;
        layer((g) => {
            const flip = dir === 'back';
            const spr = gun
                ? AIM[dir === 'side' ? 'side' : 'front'][frame]
                : drink ? DRINK[dir === 'side' ? 'side' : 'front'][frame]
                    : held ? ITEM_DEFS[item][dir === 'side' ? 'side' : 'front'] : null;
            if (!spr) {
                // The fingertip past the pointing hand.
                if (shoot) result.muzzle = [dir === 'front' ? arm.hand[0] - 1 : arm.hand[0] + 2, arm.hand[1]];
                return;
            }
            const at = placeItem(g, spr, arm.hand, flip);
            if (spr.keep) {
                const keys = new Set([...spr.keep].map((ch) => ITEM_LEGEND[ch]));
                keep = g.map((k) => (keys.has(k) ? k : null));
            }
            const muzzle = spr.muzzle ? at(spr.muzzle) : (shoot || raised) && spr.fx ? at(spr.fx) : null;
            if (muzzle) result.muzzle = muzzle;
            if (spr.barrel) result.barrel = flip ? [-spr.barrel[0], spr.barrel[1]] : spr.barrel;
            if (spr.flame) {
                // Flicker frames of the flame; the frame itself carries none, so relighting the frame never dims it.
                const [bx, by] = at(spr.flame);
                const [frames, legend] = FLAMES[spr.flames ?? 'candle'];
                result.flames = frames.map((rows) => {
                    const fl = blank();
                    stamp(fl, bx - 1, by - rows.length + 1, rows, legend, flip);
                    return fl;
                });
                result.flame = [bx, by - 1];
            }
            if (spr.blade) {
                // The blade alone, top to bottom, in three shimmer frames: still, and a glint near the tip or halfway.
                const cells = [];
                g.forEach((k, i) => BLADE_KEYS.has(k) && cells.push(i));
                const base = blank();
                cells.forEach((i) => (base[i] = g[i]));
                result.flames = [-1, 0.2, 0.6].map((at) => {
                    const fl = base.slice();
                    if (at >= 0 && cells.length) fl[cells[Math.round(at * (cells.length - 1))]] = 'fxBladeShine';
                    return fl;
                });
                const mid = cells[cells.length >> 1] ?? 0;
                result.flame = [(mid % W) - AX, Math.floor(mid / W) - AY];
            }
            if (gun && muzzle && frame === 0) {
                const [mx, my] = muzzle;
                [[0, 0, 'fxCore'], [1, 0, 'fxFlash'], [-1, 0, 'fxFlash'], [0, 1, 'fxFlash'], [0, -1, 'fxFlash']]
                    .forEach(([dx, dy, k]) => put(fx, mx + dx, my + dy, k));
            } else if (gun && muzzle) {
                put(fx, muzzle[0], muzzle[1] - 1, 'fxSmoke');
                put(fx, muzzle[0] + (flip ? -1 : 1), muzzle[1] - 2, 'fxSmoke');
            }
        });
        const hand = blank();
        drawArm(hand, body, { ...arm, far: false });
        const handOut = outlined(hand);
        over(out, handOut);
        // What the hand covers is not beer any more.
        if (keep) keep = keep.map((k, i) => (handOut[i] ? null : k));
    };
    // Drinking seen from the back: the head hides the mug at the mouth, only the raised elbow shows past it.
    const behindHead = drink && dir === 'back';
    if ((shoot || holding) && behindHead) holdLayer();
    // A body's own ears (the cat) show only bare-headed: every hat sits where they stand, the «Ушки» hat brings its own.
    const face = FACES[look.face];
    over(out, outlined(drawHead(body, face, r, look.hat === 'none')));
    if (body.whiskers) over(out, body.whiskers(r));
    if (body.snout) {
        over(out, body.snout(r));
        // The snout hides the face slot under it; what the face puts on (the eyepatch) still goes on top.
        if (face.worn) over(out, wornLayer(r, face.wornOn?.[look.body] ?? face.worn));
    }
    layer((g) => free.filter((a) => !a.far).forEach((a) => drawArm(g, body, a)));
    const hat = HATS[look.hat];
    if (hat.grown) {
        // Hair grows out of the head: no outline where it lies on it.
        const g = blank();
        hat.paint(g, r.head.x, r.head.y, dir);
        over(out, outlinedOffHead(g, r.head.x, r.head.y));
    } else {
        layer((g) => hat.paint(g, r.head.x, r.head.y, dir));
    }
    layer((g) => cloak.paint(g, r, 'front'));

    if ((shoot || holding) && !behindHead) holdLayer();
    if (ritual) {
        // A small light over each palm, in the eye colour; on odd frames a spark flickers off it.
        r.arms.forEach(({ hand: [hx, hy] }, i) => {
            put(fx, hx, hy - 2, 'fxPalmCore');
            put(fx, hx + 1, hy - 2, 'fxPalmCore');
            [[-1, -2], [2, -2], [0, -3], [1, -3], [0, -1], [1, -1]].forEach(([dx, dy]) => put(fx, hx + dx, hy + dy, 'fxPalm'));
            if (frame & 1) put(fx, i ? hx + 2 : hx - 1, hy - 4, 'fxPalm');
        });
    }
    over(out, fx);
    if (ritual || gun) result.glow = fx;
    // Drinking, the head or the hat may cover the mug: only the beer still on top stays.
    if (drink && keep) keep = keep.map((k, i) => (out[i] === k ? k : null));
    if (keep?.some(Boolean)) result.keep = keep.map((k, i) => (fx[i] ? null : k));
    return result;
}

// ---------------------------------------------------------------------------------------------------------------
// Public API.

const FX_COLORS = {
    fxCore: PALETTE.white,
    fxFlash: PALETTE.pumpkinShine,
    fxSmoke: PALETTE.grayLight,
    fxFlame: PALETTE.pumpkinLight,
    fxFlameHot: PALETTE.pumpkinShine,
    fxFlameCore: PALETTE.flameCore,
    // The red flame in the magic circle's colours, all of them emissive (night.js, EMISSIVE).
    fxRune: PALETTE.rune,
    fxRuneDark: PALETTE.runeDark,
    fxRuneHot: PALETTE.bloodMoonLight,
    fxRuneCore: PALETTE.columnCore,
    // The rapier's blade in its gold, emissive (night.js, EMISSIVE): a hot core, a deeper tip, a white glint running up.
    fxBlade: PALETTE.goldHot,
    fxBladeEdge: PALETTE.goldGlow,
    fxBladeShine: PALETTE.goldCore,
};

const EYES = [
    { id: 'amber', label: named('Янтарные', 'Amber'), key: 'eyeAmber' },
    { id: 'green', label: named('Зелёные', 'Green'), key: 'eyeGreen' },
    { id: 'red', label: named('Красные', 'Red'), key: 'eyeRed' },
    { id: 'blue', label: named('Синие', 'Blue'), key: 'eyeBlue' },
    { id: 'violet', label: named('Фиолетовые', 'Violet'), key: 'eyeViolet' },
    { id: 'white', label: named('Светящиеся', 'Glowing'), key: 'white' },
    // Black eyes would melt into the outline round them: a white glint in each carries them, and the ritual's palm
    // lights, which take the eye colour, glow a pale ghostly blue instead of black. The mohawk's tips, black too, would
    // melt into its outline: they take the cat's fur, a step above it.
    { id: 'black', label: named('Чёрные', 'Black'), key: 'eyeBlack', glint: true, palm: 'ghostShade', crest: 'crow' },
];

/**
 * Puts the glint in the top-left pixel of every eye of three pixels or more (4-connected `eye` pixels); one-pixel
 * eyes, like the skull's, stay as they are.
 */
function withGlint(grid) {
    const out = grid.slice();
    const seen = new Uint8Array(grid.length);
    grid.forEach((k, start) => {
        if (k !== 'eye' || seen[start]) return;
        const cells = [start];
        seen[start] = 1;
        for (let n = 0; n < cells.length; n++) {
            const i = cells[n];
            const x = i % W;
            [x > 0 ? i - 1 : -1, x < W - 1 ? i + 1 : -1, i - W, i + W].forEach((j) => {
                if (j >= 0 && j < grid.length && !seen[j] && grid[j] === 'eye') {
                    seen[j] = 1;
                    cells.push(j);
                }
            });
        }
        if (cells.length >= 3) out[start] = 'eyeGlint';
    });
    return out;
}

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
    ritual: { frames: 4, ms: 380 },
    clink: { frames: 2, ms: 350 },
    toast: { frames: 2, ms: 450 },
    drink: { frames: 3, ms: 400 },
    shrug: { frames: 2, ms: 400 },
    // A zombie player (finale step 6): the waddling walk with the arms held out, and the sway standing.
    shamble: { frames: 4, ms: 150 },
    lurch: { frames: 2, ms: 700 },
};

/**
 * The body's skin and hands, their shades and the blush, in the zombie green: a zombie player's look. A body whose skin
 * has more tones than its ramp step (the elephant's pale ear insides) lists the rest in `zombie`.
 */
function zombieKeys(body) {
    const map = { blush: 'zombieShade' };
    for (const key of new Set([body.skin, body.hand ?? body.skin])) {
        map[key] = 'zombie';
        map[dark(key)] = 'zombieShade';
    }
    return { ...map, ...body.zombie };
}

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

const mixWhite = (c, t) => [16, 8, 0].reduce((acc, sh) => {
    const v = (c >> sh) & 0xff;
    return acc | (Math.round(v + (255 - v) * t) << sh);
}, 0);

const toHsl = (c) => {
    const [r, g, b] = [16, 8, 0].map((sh) => ((c >> sh) & 0xff) / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    const d = max - min;
    if (!d) return [0, 0, l];
    const h = max === r ? (g - b) / d + (g < b ? 6 : 0) : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
    return [h * 60, d / (1 - Math.abs(2 * l - 1)), l];
};

const fromHsl = ([h, s, l]) => {
    const k = (n) => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    return [0, 8, 4].reduce((acc, n, i) => acc | (Math.round(255 * (l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)))) << (16 - 8 * i)), 0);
};

/**
 * The colour halfway from a to b along the hue circle (the short way round), so a pink and a green meet in orange,
 * not in grey-brown; a grey end takes the other end's hue.
 */
function mixHue(a, b) {
    const [ha, sa, la] = toHsl(a);
    const [hb, sb, lb] = toHsl(b);
    const h1 = sa ? ha : hb;
    const h2 = sb ? hb : ha;
    const dh = ((h2 - h1 + 540) % 360) - 180;
    return fromHsl([(h1 + dh / 2 + 360) % 360, (sa + sb) / 2, (la + lb) / 2]);
}

function paintGrid(grid, colorOf) {
    const canvas = makeCanvas();
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(W, H);
    grid.forEach((k, i) => {
        if (!k) return;
        const color = colorOf(k);
        if (color === undefined) return;
        img.data[i * 4] = (color >> 16) & 0xff;
        img.data[i * 4 + 1] = (color >> 8) & 0xff;
        img.data[i * 4 + 2] = color & 0xff;
        img.data[i * 4 + 3] = 255;
    });
    ctx.putImageData(img, 0, 0);
    return canvas;
}

/**
 * One composed frame, cached by its arguments. Unknown dir/pose fall back to front/idle, frame wraps around the pose,
 * an unknown item is no item. anchor and the points below — canvas pixels. Light comes as `glow`: a canvas of the
 * same size to draw unlit over the relit frame. The ritual pose ignores the item; its glow is the palm lights. The
 * shoot pose aims the item: the pistol's own aimed sprites (glow — its flash or smoke pixels, `barrel` — the firing
 * direction, a vector), any other item held out on the aimed arm, an empty hand pointing; `muzzle` is where the
 * scene's shot effect starts (the muzzle, the item's `fx` pixel, the fingertip). Both poses have their lights in
 * `canvas` too. A held candle (and the red flame) has its flame only in the
 * glow: `flames` — its flicker frames (`glow` is the first), `flame` — the flame's core. A held beer brings `beer`:
 * the beer and foam pixels of `canvas` alone, for the scene to keep their hue at night over the relit frame. Clinking
 * (`clink`) and the toast (`toast`) raise the item hand; their `muzzle` is the glass's top. Drinking (`drink`, 3 frames, play
 * once) always tips the beer to the mouth whatever the item, its `beer` the part not hidden by the head; the shrug
 * (`shrug`, 2 frames) shows empty palms whatever the item. A held rapier has its blade in the glow: `flames` — its
 * shimmer frames, `flame` — the middle of the blade, `muzzle` (aimed or raised) — its tip. `zombie` recolours the
 * skin and hands green and blanks the eyes (and the mohawk's tips); the zombie's own poses — `shamble` (4 walk frames) and `lurch` (2 standing) — hold the hands
 * out empty whatever the item.
 */
export function characterFrame(look, { dir, pose, frame = 0, item = null, zombie = false } = {}) {
    const full = normalizeLook(look);
    const d = DIRS.includes(dir) ? dir : 'front';
    const p = POSES[pose] ? pose : 'idle';
    const n = POSES[p].frames;
    const f = ((Math.floor(frame) % n) + n) % n;
    const it = ITEM_DEFS[item] ? item : null;
    const key = `${SLOTS.map((s) => full[s]).join(',')}|${d}|${p}|${f}|${it ?? ''}|${zombie ? 'z' : ''}`;
    const cached = frameCache.get(key);
    if (cached) return cached;

    const composed = compose(full, d, p, f, it);
    const eyes = EYES.find((e) => e.id === full.eyes);
    // A zombie's eyes are dead white, whatever their colour was.
    const eye = zombie ? PALETTE.ghost : PALETTE[eyes.key];
    if (eyes.glint && !zombie) composed.out = withGlint(composed.out);
    const light = zombie || !eyes.palm ? eye : PALETTE[eyes.palm];
    const palm = { fxPalm: light, fxPalmCore: mixWhite(light, 0.6) };
    const skin = zombie ? zombieKeys(BODIES[full.body]) : {};
    // The mohawk's strand tips take the eye colour, its middle the hue halfway from the pink root.
    const tip = zombie || !eyes.crest ? eye : PALETTE[eyes.crest];
    const crest = { crestTip: tip, crestMid: mixHue(PALETTE.mohawk, tip) };
    const colorOf = (k) => (k === 'eye' ? eye : k === 'eyeGlint' ? PALETTE.white : palm[k] ?? crest[k] ?? FX_COLORS[k] ?? PALETTE[skin[k] ?? k]);
    const result = { canvas: paintGrid(composed.out, colorOf), anchor: { x: AX, y: AY } };
    const toCanvas = ([x, y]) => [AX + x, AY + y];
    if (composed.glow) result.glow = paintGrid(composed.glow, colorOf);
    if (composed.muzzle) {
        result.muzzle = toCanvas(composed.muzzle);
        result.barrel = composed.barrel;
    }
    if (composed.keep) result.beer = paintGrid(composed.keep, colorOf);
    if (composed.flames) {
        result.flames = composed.flames.map((g) => paintGrid(g, colorOf));
        result.glow = result.flames[0];
        result.flame = toCanvas(composed.flame);
    }
    frameCache.set(key, result);
    return result;
}
