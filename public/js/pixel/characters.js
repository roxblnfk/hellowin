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
    crowLight: 'crow',
    crow: 'crowDark',
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
// returns a layer laid on without an outline, tail(g, r) paints behind the body (over it from the back).

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
    // A black tuxedo cat: the crow ramp stays a step above the outline at night, the white bib, muzzle and paws carry
    // the shape. Ears, muzzle and whiskers come from headPaint, the tail from tail.
    cat: {
        label: 'Кот',
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

// «Ушки»: the cat's ears on a thin band over the crown, black like the cat's so the pink inside carries the shape.
const EAR_BAND = { front: [1, 10], side: [3, 8], back: [1, 10] };

function catEarsHat(g, x0, y0, dir) {
    const [a, b] = EAR_BAND[dir];
    for (let c = a; c <= b; c++) put(g, x0 + c, y0 + 1, 'crowLight');
    catEars(g, x0, y0, dir, 'crow');
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
    'cat-ears': { label: 'Ушки', paint: catEarsHat },
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
        label: 'Пиво',
        front: { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [4, 3], fx: [2, 0], keep: 'wcyY' },
        side: { rows: ['.wcw.', 'wwwww', 'yYYYg', 'yYYYg', 'yYYYg', 'ggggg'], grip: [-1, 3], fx: [2, 0], keep: 'wcyY' },
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
    // A wax candle on a tin dish, carried at dusk. The flame is not in the rows: it is light, drawn over the frame in
    // its own flicker frames (CANDLE_FLAME), standing on the pixel above the wax.
    candle: {
        label: 'Свеча',
        front: { rows: ['.....', '.....', '.....', '.ccc.', '.wcc.', '.ccc.', '.ccc.', 'ggggg', '.aaa.'], grip: [2, 7], flame: [2, 2], fx: [2, 1] },
        side: { rows: ['.....', '.....', '.....', '.ccc.', '.wcc.', '.ccc.', '.ccc.', 'ggggg', '.aaa.'], grip: [2, 7], flame: [2, 2], fx: [2, 1] },
    },
    // An open spell book held up by its spine: the violet cover round the edges and below, the dark spine down the
    // middle, two cream pages with green lines of text. From the side it turns three quarters to the camera, the near
    // page wider.
    book: {
        label: 'Магическая книга',
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
        label: 'Факел',
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
        label: 'Вилы',
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
        label: 'Красный огонёк',
        front: { rows: ['.', '.', '.'], grip: [0, 2], flame: [0, 0], fx: [0, -1], flames: 'red' },
        side: { rows: ['......', '......'], grip: [0, 1], flame: [5, 0], fx: [5, -1], flames: 'red' },
    },
    // The rapier of finale steps 6–7: a long thin blade of light over a golden cup hilt. Upright from the front; from
    // the side it points forward and up, so the blade clears the face. `blade` — its light shimmers (frame `flames`).
    rapier: {
        label: 'Рапира',
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
    over(out, outlined(drawHead(body, FACES[look.face], r, look.hat === 'none')));
    if (body.whiskers) over(out, body.whiskers(r));
    layer((g) => free.filter((a) => !a.far).forEach((a) => drawArm(g, body, a)));
    layer((g) => HATS[look.hat].paint(g, r.head.x, r.head.y, dir));
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
 * has more tones than its ramp step lists the rest in `zombie`.
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
 * skin and hands green and blanks the eyes; the zombie's own poses — `shamble` (4 walk frames) and `lurch` (2 standing) — hold the hands
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
    // A zombie's eyes are dead white, whatever their colour was.
    const eye = zombie ? PALETTE.ghost : PALETTE[EYES.find((e) => e.id === full.eyes).key];
    const palm = { fxPalm: eye, fxPalmCore: mixWhite(eye, 0.6) };
    const skin = zombie ? zombieKeys(BODIES[full.body]) : {};
    const colorOf = (k) => (k === 'eye' ? eye : palm[k] ?? FX_COLORS[k] ?? PALETTE[skin[k] ?? k]);
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
