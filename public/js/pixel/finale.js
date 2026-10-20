import { FLYERS } from './flyers.js';

/**
 * The finale (scenario.md, «Финал»): FrankenPHP, the bird-like silhouette it rises as, RoadRunner running in from the
 * horizon, and the effects of the ritual — the red light column, the circle flash, lightning, smoke.
 * Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as sprites.js; bake
 * figures with bakeLit() (night.js) and light frames with bake().
 */

const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const dither = (x, y) => (BAYER[y & 3][x & 3] + 0.5) / 16;

function hash(x, y, s = 0) {
    let h = Math.imul(x | 0, 374761393) ^ Math.imul(y | 0, 668265263) ^ Math.imul(s | 0, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

// Smooth value noise: dithered edges wobble in blobs rather than salt-and-pepper.
function noise(x, y, s = 0) {
    const xi = Math.floor(x);
    const yi = Math.floor(y);
    const fx = x - xi;
    const fy = y - yi;
    const u = fx * fx * (3 - 2 * fx);
    const v = fy * fy * (3 - 2 * fy);
    const a = hash(xi, yi, s);
    const b = hash(xi + 1, yi, s);
    const c = hash(xi, yi + 1, s);
    const d = hash(xi + 1, yi + 1, s);
    return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
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

const grid = (w, h) => Array.from({ length: h }, () => Array(w).fill('.'));
const toSprite = (g, legend, anchor) => {
    const rows = g.map((r) => r.join(''));
    return { w: rows[0].length, h: rows.length, rows, legend: { '.': null, ...legend }, anchor: { ...anchor } };
};

const ellipse = (cx, cy, rx, ry) => (x, y) => ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1;
const capsule = (x1, y1, x2, y2, r) => (x, y) => {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const t = Math.max(0, Math.min(1, ((x - x1) * dx + (y - y1) * dy) / (dx * dx + dy * dy || 1)));
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
const quad = (p0, p1, p2, t) => [0, 1].map((i) => (1 - t) ** 2 * p0[i] + 2 * (1 - t) * t * p1[i] + t * t * p2[i]);

/** Empty pixels touching a filled one (4-neighbourhood) become `ch`; keys in `skip` cast no outline. */
function outlined(g, ch, skip = '') {
    const out = g.map((r) => r.slice());
    const solid = (x, y) => {
        const c = g[y]?.[x];
        return c !== undefined && c !== '.' && !skip.includes(c);
    };
    for (let y = 0; y < g.length; y++) {
        for (let x = 0; x < g[0].length; x++) {
            if (g[y][x] !== '.') continue;
            if (solid(x - 1, y) || solid(x + 1, y) || solid(x, y - 1) || solid(x, y + 1)) out[y][x] = ch;
        }
    }
    return out;
}

function over(base, top) {
    for (let y = 0; y < base.length; y++) {
        for (let x = 0; x < base[0].length; x++) if (top[y][x] !== '.') base[y][x] = top[y][x];
    }
}

// ---------------------------------------------------------------------------------------------------------------
// FrankenPHP. The logo is a lime-green elePHPant head as Frankenstein's monster: a flat-top of deep-purple hair cut
// into a zigzag fringe, a stitched seam across the forehead, lavender bolts at the temples, half-closed sleepy eyes
// under flat lids, big ears, white tusks and a trunk with three wrinkles, everything outlined in the same purple.
// Here the head sits on a monster's body (purple suit, green hands, heavy boots), the head about half the height.

const FW = 72;
const FH = 96;
// Local coordinates: x from the centre, y up from the foot (the anchor, the bottom edge of the canvas).
const FX0 = FW >> 1;

const FRANKEN_LEGEND = {
    O: 'frankenInk',
    H: 'frankenInk',
    h: 'frankenInkLight',
    L: 'frankenLight',
    G: 'franken',
    g: 'frankenDark',
    D: 'frankenDeep',
    W: 'frankenBoltShine',
    B: 'frankenBolt',
    b: 'frankenBoltDark',
    T: 'white',
    t: 'grayLight',
    // The eye whites glow faintly, so the stare survives the night.
    E: 'frankenGlowCore',
    U: 'violetLight',
    S: 'violet',
    s: 'violetDark',
    k: 'crowDark',
    c: 'crow',
    M: 'innerDark',
    // Sparks off the bolts: light, not lit by the scene.
    z: 'frankenGlowHot',
    Z: 'frankenGlowCore',
    // The silhouette: a dark mass, its edge lit red by the column behind it, the one eye glowing.
    X: 'ashDark',
    r: 'rune',
    R: 'bloodMoonLight',
};

/** Paints every pixel whose centre (in local coordinates) passes the test; color may return '.' to skip. */
function paint(g, test, color) {
    for (let row = 0; row < FH; row++) {
        for (let col = 0; col < FW; col++) {
            const x = col - FX0 + 0.5;
            const y = row - FH + 0.5;
            if (!test(x, y)) continue;
            const ch = typeof color === 'function' ? color(x, y) : color;
            if (ch && ch !== '.') g[row][col] = ch;
        }
    }
}
const putL = (g, x, y, ch) => {
    const row = Math.round(y) + FH;
    const col = Math.round(x) + FX0;
    if (g[row]?.[col] !== undefined) g[row][col] = ch;
};

// Ear, viewer's right, fully spread; x is pulled toward the head by `spread`.
// Like the logo's: rising above the crown, wide and round on top, narrowing to a point below the cheek.
const EAR = [[10, -74], [13, -80], [19, -83], [25, -81], [28, -75], [28.5, -66], [27, -57], [23, -49], [17.5, -41], [14.5, -42], [11, -47]];

/**
 * p: breath 0|1 (the upper body rises a pixel), arm 'down' | 'up' (the talking gesture), mouth, trunk 0–1 (0 hangs,
 * 1 is raised straight out like a beak), ears 0–1 (folded behind the head → spread), bolts 0–1 (sideways → swept up
 * like a crest), crest 0–1 (hair spikes swept up and back), spark -1 | 0..2.
 */
function frankenGrid(p) {
    const b = p.breath ? -1 : 0;
    const out = grid(FW, FH);
    const layer = (fn, ch = 'O') => {
        const g = grid(FW, FH);
        fn(g);
        over(out, outlined(g, ch, 'zZ'));
    };
    const ears = p.ears ?? 1;
    const head = (x, y) => Math.abs(x / 14) ** 4 + Math.abs((y - (-60 + b)) / 17) ** 4 <= 1;

    // Ears, behind everything: lighter rim up top, the lower part shaded behind a wavy fold as on the logo.
    if (ears > 0.05) {
        layer((g) => {
            for (const m of [1, -1]) {
                const pts = EAR.map(([x, y]) => [m * (9 + (x - 9) * ears), y + b]);
                paint(g, polygon(pts), (x, y) => {
                    const ax = (Math.abs(x) - 9) / Math.max(0.2, ears) + 9;
                    const yy = y - b;
                    // The inner flap: a darker fold over the lower half, its edge a wave rising toward the head.
                    if (yy > -60 + (ax - 18) * 0.7 + 2 * Math.sin(ax * 0.8)) return ax > 24 || yy > -46 ? 'D' : 'g';
                    if (yy < -79 + (ax - 14) * 0.25 && ax > 13 && ax < 24) return 'L';
                    if (ax > 26.5) return 'g';
                    return 'G';
                });
            }
        });
    }

    // Legs in suit trousers and heavy boots, under the torso.
    layer((g) => {
        for (const m of [-1, 1]) {
            paint(g, (x, y) => m * x >= 2.5 && m * x <= 9.5 && y >= -19 && y < -7, (x) => (x > 6 || (x > -3 && x < 0) ? 's' : 'S'));
            paint(g, (x, y) => m * x >= 1.5 && m * x <= 11 && y >= -7 && y < -1 && !(y < -5 && m * x > 10), (x, y) => (y < -5.5 ? 'c' : 'k'));
        }
    });

    const shoulder = -40 + b;
    const tail = p.tail ?? 0;
    const arm = (m, raised) => (g) => {
        if (m < 0 && tail > 0) {
            // Swung up and back, the arm is the bird's raised tail: a long taper from the shoulder.
            const tip = [-15 - 17 * tail, shoulder + 16 - 34 * tail];
            for (let i = 0; i <= 12; i++) {
                const t = i / 12;
                const [x, y] = [-15 + (tip[0] + 15) * t, shoulder + 3 + (tip[1] - shoulder - 3) * t];
                paint(g, ellipse(x, y, 3.3 - t * 2.3, 3.3 - t * 2.3), (px) => (px < x ? 'U' : 'S'));
            }
            return;
        }
        if (raised) {
            paint(g, capsule(m * 15, shoulder + 2, m * 21, shoulder + 7, 3), (x) => (m * x > 19 ? 's' : 'S'));
            paint(g, capsule(m * 21, shoulder + 7, m * 23, shoulder - 5, 2.6), (x) => (m * x > 22 ? 's' : 'S'));
            // An open palm turned to the crowd, fingers spread.
            paint(g, ellipse(m * 23, shoulder - 9, 3.2, 3.4), (x, y) => (m * x > 24 ? 'g' : 'G'));
            for (const dx of [-3, -1, 1, 3]) putL(g, m * 23 + dx, shoulder - 13 + Math.abs(dx) * 0.4, 'G');
            putL(g, m * 23 + 4.5, shoulder - 8, 'G');
            return;
        }
        paint(g, capsule(m * 15.5, shoulder + 2, m * 17, shoulder + 15, 3.2), (x) => (m * x > 17 ? 's' : m * x < 14 ? 'U' : 'S'));
        paint(g, ellipse(m * 17.5, shoulder + 20, 3.4, 3.6), (x, y) => (m * x > 18.5 || y > shoulder + 21.5 ? 'g' : 'G'));
        // A stitched cuff where the green wrist meets the sleeve.
        putL(g, m * 17.5, shoulder + 17, 'O');
    };

    // Torso: a boxy jacket with lapels; the shoulders ride with the breath, the hem stays.
    layer((g) => {
        paint(g, polygon([[-16, shoulder - 1], [16, shoulder - 1], [17, shoulder + 3], [14, -17], [-14, -17], [-17, shoulder + 3]]), (x, y) => {
            if (y > -19) return 's';
            if (Math.abs(Math.abs(x) - (3 + (y - shoulder) * 0.25)) < 0.6 && y < shoulder + 13) return 's';
            if (x > 10) return 's';
            if (x < -12 && y < shoulder + 8) return 'U';
            return 'S';
        });
        // Buttons down the front, below the trunk's tip.
        putL(g, -5, -22, 'k');
        putL(g, -5, -26, 'k');
    });
    layer(arm(-1, false));
    layer(arm(1, p.arm === 'up'));

    // Tusks curve down and out from under the cheeks.
    layer((g) => {
        for (const m of [-1, 1]) {
            for (let i = 0; i <= 10; i++) {
                const t = i / 10;
                const [x, y] = quad([m * 6.5, -48 + b], [m * 7.5, -39 + b], [m * 12, -38 + b], t);
                paint(g, ellipse(x, y, 1.5 - t * 0.6, 1.5 - t * 0.6), (px, py) => (py > y + 0.4 ? 't' : 'T'));
            }
        }
    });

    // Head: a squarish dome lit from the upper left, the right cheek in shade.
    layer((g) => {
        paint(g, head, (x, y) => {
            const yy = y - b;
            if (x > 9.5 || (x > 6 && yy > -50)) return 'g';
            if (x < -9 && yy > -66 && yy < -54) return 'L';
            return 'G';
        });
        // Flat-top hair: a block over the crown, its fringe cut into teeth that point down.
        const crest = p.crest ?? 0;
        paint(g, (x, y) => {
            const yy = y - b;
            if (Math.abs(x) > 12.6 || yy < -80) return false;
            const u = (((x + 64) % 4) + 4) % 4 / 4;
            const zig = -71.5 + 4.5 * (1 - Math.abs(u - 0.5) * 2);
            return yy < zig && (head(x, y) || yy < -76);
        }, (x, y) => (y - b < -78.5 && x < 4 ? 'h' : 'H'));
        // Swept up, the fringe becomes three spikes leaning back: the crest the crowd takes for a road-runner's.
        if (crest > 0) {
            for (const [bx, len] of [[-8, 13], [-3, 17], [2, 14]]) {
                const tip = [bx - 10 * crest, -78 + b - len * crest];
                paint(g, polygon([[bx - 3, -76 + b], [bx + 3, -76 + b], tip]), 'H');
            }
        }
        // The seam across the forehead with its stitches.
        for (let x = -13; x <= 13; x++) putL(g, x, -65 + b, 'O');
        for (const x of [-10, -6, -2, 2, 6, 10]) {
            putL(g, x, -66 + b, 'O');
            putL(g, x, -64 + b, 'O');
        }
        // Sleepy eyes: flat heavy lids, half-moons of white, pupils pressed up against the lids.
        for (const m of [-1, 1]) {
            const cx = m * 6;
            paint(g, (x, y) => y > -60.5 + b && ellipse(cx, -60.5 + b, 3.7, 4.2)(x, y), 'E');
            for (let x = cx - 4; x <= cx + 4; x++) putL(g, x, -61 + b, 'O');
            putL(g, cx - m * 0.5 - 0.5, -59 + b, 'O');
            putL(g, cx - m * 0.5 + 0.5, -59 + b, 'O');
            putL(g, cx - m * 0.5 - 0.5, -58 + b, 'O');
            putL(g, cx - m * 0.5 + 0.5, -58 + b, 'O');
        }
        if (p.mouth) {
            paint(g, ellipse(-4.5, -45.5 + b, 2.6, 1.8), 'M');
            putL(g, -4.5, -44 + b, 'O');
        }
    });

    // Bolts: a short neck and a wide nut, lavender with a light top edge.
    const boltAngle = (m) => {
        const t = p.bolts ?? 0;
        // Sideways at rest; swept, the left one leans up and back, the right one stands up.
        return m < 0 ? Math.PI + t * (-Math.PI * 0.32) : t * (Math.PI * 0.53);
    };
    const boltEnds = [];
    layer((g) => {
        for (const m of [-1, 1]) {
            const a = boltAngle(m);
            const ux = Math.cos(a);
            const uy = -Math.sin(a);
            const bx = m * 12.5;
            const by = -65 + b;
            const local = (x, y) => [(x - bx) * ux + (y - by) * uy, -(x - bx) * uy + (y - by) * ux];
            paint(g, (x, y) => {
                const [u, v] = local(x, y);
                return (u >= 0 && u <= 3.5 && Math.abs(v) <= 1.6) || (u > 3.5 && u <= 7.5 && Math.abs(v) <= 3.6);
            }, (x, y) => {
                const [u, v] = local(x, y);
                const lower = v * (ux >= 0 ? 1 : -1) > 0.8;
                if (u > 3.5 && u < 4.8 && !lower) return 'W';
                return lower ? 'b' : 'B';
            });
            boltEnds.push([bx + ux * 8, by + uy * 8, ux, uy]);
        }
    });

    // Trunk: a tapering tube along a curve, shaded on its right, three wrinkle arcs; raised, it is a long beak.
    const tr = p.trunk ?? 0;
    const talk = p.mouth ? 1 : 0;
    const lerp = (a, c, t) => a.map((v, i) => v + (c[i] - v) * t);
    const p0 = lerp([0, -52 + b], [2, -54 + b], tr);
    const p1 = lerp(lerp([-1, -33 + b], [4, -34 + b], talk), [13, -55 + b], tr);
    const p2 = lerp(lerp([6, -24 + b], [12, -38 + b], talk), [31, -58 + b], tr);
    const samples = Array.from({ length: 41 }, (_, i) => {
        const t = i / 40;
        const [x, y] = quad(p0, p1, p2, t);
        return { t, x, y, r: (5.2 - 2.6 * t) * (1 - tr * 0.6 * t) };
    });
    layer((g) => {
        const nearest = (x, y) => {
            let best = null;
            for (const s of samples) {
                const d = Math.hypot(x - s.x, y - s.y) - s.r;
                if (!best || d < best.d) best = { ...s, d };
            }
            return best;
        };
        paint(g, (x, y) => nearest(x, y).d <= 0, (x, y) => {
            const s = nearest(x, y);
            const i = Math.min(39, Math.round(s.t * 40));
            const nx = -(samples[i + 1].y - samples[i].y);
            const ny = samples[i + 1].x - samples[i].x;
            const side = ((x - s.x) * nx + (y - s.y) * ny) / Math.hypot(nx, ny);
            const wrinkle = tr < 0.5 && [0.46, 0.6, 0.74].some((w) => Math.abs(s.t - w) < 0.016) && side > -s.r * 0.4;
            if (wrinkle) return 'O';
            if (side > s.r * 0.35) return 'g';
            if (side < -s.r * 0.6 && s.t < 0.5) return 'L';
            return 'G';
        });
        const tip = samples[40];
        putL(g, tip.x, tip.y, 'g');
    });

    if (p.spark >= 0) {
        const SPARKS = [
            [[1, -1, 'Z'], [2, -2, 'z'], [2, -3, 'z'], [3, -4, 'Z'], [4, -4, 'z']],
            [[1, 0, 'Z'], [2, 1, 'z'], [3, 0, 'z'], [4, -1, 'Z'], [2, -2, 'z'], [0, -2, 'z']],
            [[1, -2, 'z'], [3, -1, 'Z'], [5, -2, 'z'], [3, -4, 'z']],
        ][p.spark % 3];
        for (const [ex, ey, ux] of boltEnds) {
            const m = ux >= 0 ? 1 : -1;
            for (const [dx, dy, ch] of SPARKS) putL(out, ex + m * dx, ey + dy, ch);
        }
    }
    return out;
}

const FRANKEN_ANCHOR = { x: FX0, y: FH };
const frankenSprite = (p) => toSprite(frankenGrid(p), FRANKEN_LEGEND, FRANKEN_ANCHOR);

/**
 * The silhouette pass over a frank grid: pixels at `reveal` 0 are all dark (X) with a red rim where the column behind
 * lights the edge and the one eye glowing; in between, a Bayer dither lets the colours through.
 */
function silhouetteOf(g, reveal, { rim = true, eye = true } = {}) {
    const out = g.map((r) => r.slice());
    const filled = (x, y) => (g[y]?.[x] ?? '.') !== '.';
    for (let y = 0; y < FH; y++) {
        for (let x = 0; x < FW; x++) {
            const ch = g[y][x];
            if (ch === '.' || dither(x, y) < reveal) continue;
            if (eye && ch === 'E' && x > FX0) {
                out[y][x] = 'R';
                continue;
            }
            const edge = !filled(x - 1, y) || !filled(x + 1, y) || !filled(x, y - 1);
            out[y][x] = rim && edge ? 'r' : 'X';
        }
    }
    return out;
}

// The bird-like pose: trunk out like a beak, ears folded behind the head, bolts and fringe swept up into a crest.
const BIRD = { trunk: 1, ears: 0, bolts: 1, crest: 1, tail: 1, spark: -1 };

/** Cuts a figure off at the ground line, `progress` 0–1 of it risen, with a lip of smoke where it comes out. */
function risen(g, progress, legend) {
    const drop = Math.round((1 - progress) * FH * 0.8);
    const out = grid(FW, FH);
    for (let y = 0; y < FH - drop; y++) out[y + drop] = g[y].slice();
    if (progress < 1) {
        const cols = out[FH - 1].map((ch, x) => ch !== '.' || out[FH - 2][x] !== '.');
        cols.forEach((on, x) => {
            if (!on && !cols[x - 2] && !cols[x + 2]) return;
            for (let dy = 1; dy <= 4; dy++) {
                if (dither(x, dy) < 0.85 - dy * 0.18 + noise(x * 0.4, progress * 9) * 0.3) out[FH - dy][x] = dy < 3 ? 'q' : 'p';
            }
        });
    }
    return toSprite(out, { ...legend, q: 'smokeLight', p: 'smoke' }, FRANKEN_ANCHOR);
}

const frankenIdle = [0, 1].map((breath) => frankenGrid({ breath, spark: -1 }));
const birdIdle = [0, 1].map((breath) => frankenGrid({ ...BIRD, breath }));

/**
 * FrankenPHP, 72×96, anchor (36, 96) — the foot, on the circle's centre. About 82 px tall, 2.5× a player.
 * idle: 2 breathing frames (~700 ms); spark: 3 frames of the idle with sparks off the bolts, play them now and then
 * (~90 ms each) over idle[0]; talk: [closed, trunk lifted with the mouth open, the same with the palm raised] —
 * alternate 0/1 (~180 ms) while a phrase bubble is up, add 2 for emphasis; rise: 4 frames emerging from the ground
 * (¼, ½, ¾, whole) for when it climbs out without the silhouette.
 * Sparks are light (frankenGlowHot / frankenGlowCore): bake with bakeLit, those keys must stay unlit.
 */
export const FRANKEN = {
    w: FW,
    h: FH,
    anchor: { ...FRANKEN_ANCHOR },
    idle: frankenIdle.map((g) => toSprite(g, FRANKEN_LEGEND, FRANKEN_ANCHOR)),
    spark: [0, 1, 2].map((spark) => frankenSprite({ spark })),
    talk: [
        frankenSprite({ spark: -1 }),
        frankenSprite({ spark: -1, mouth: true }),
        frankenSprite({ spark: -1, mouth: true, arm: 'up', breath: 1 }),
    ],
    rise: [0.25, 0.5, 0.75, 1].map((t) => risen(frankenIdle[0], t, FRANKEN_LEGEND)),
};

/**
 * The silhouette of step 1, same canvas and anchor as FRANKEN: a dark shape with a red-lit rim and one glowing eye,
 * the trunk held out like a beak, the bolts and fringe swept up like a road-runner's crest. idle: 2 frames
 * (~500 ms, it breathes); rise: 4 frames emerging from the circle; reveal: 3 transition frames after the lightning
 * (bird pose melting into FrankenPHP while the colours dither in) — then FRANKEN.idle.
 */
export const SILHOUETTE = {
    w: FW,
    h: FH,
    anchor: { ...FRANKEN_ANCHOR },
    idle: birdIdle.map((g) => toSprite(silhouetteOf(g, 0), FRANKEN_LEGEND, FRANKEN_ANCHOR)),
    rise: [0.25, 0.5, 0.75, 1].map((t) => risen(silhouetteOf(birdIdle[0], 0), t, FRANKEN_LEGEND)),
    reveal: [0.66, 0.33, 0].map((k, i) => toSprite(
        silhouetteOf(frankenGrid({ trunk: k, ears: 1 - k, bolts: k, crest: k, tail: k, spark: i === 2 ? 1 : -1 }), [0.15, 0.5, 0.85][i], { rim: i < 2, eye: i < 1 }),
        FRANKEN_LEGEND,
        FRANKEN_ANCHOR,
    )),
};

// ---------------------------------------------------------------------------------------------------------------
// RoadRunner. The roadrunner.dev logo is a one-colour swoosh of a bird: a small round head with a long needle beak
// pointing up and forward, the eye a notch at the back of the head, and the body a single crescent that sweeps down
// from the neck and back up into a long raised tail. Here it is that crescent in the colours of the far road-runner
// of scene 0 (flyers.js): blue body, darker tail, tan throat and belly, violet crest, yellow beak and orange legs.
// Shapes live in a 52×48 reference frame and are rasterised per size, so each size is drawn at its own pixels.

const RR_LEGEND = {
    O: 'outline',
    K: 'outline',
    d: 'denim',
    D: 'denimDark',
    l: 'hayLight',
    P: 'violet',
    p: 'violetLight',
    w: 'white',
    Y: 'beak',
    o: 'pumpkin',
    M: 'innerDark',
    g: 'smokeLight',
};

const REF_H = 48;
const HIP = [27, 33];

const rotated = (test, ang, [px, py]) => {
    const c = Math.cos(ang);
    const s = Math.sin(ang);
    return (x, y) => test(px + (x - px) * c + (y - py) * s, py - (x - px) * s + (y - py) * c);
};
const part = (test, color, extra = {}) => ({ test, color: typeof color === 'function' ? color : () => color, ...extra });
const turn = (parts, ang, pivot) => parts.map((q) => ({
    ...q,
    test: rotated(q.test, ang, pivot),
    color: (x, y) => {
        const c = Math.cos(ang);
        const s = Math.sin(ang);
        const [px, py] = pivot;
        return q.color(px + (x - px) * c + (y - py) * s, py - (x - px) * s + (y - py) * c);
    },
}));

// Run: legs a blur of positions [knee, foot] per frame, near then far.
const RR_RUN = [
    [[[31, 39], [38, 45]], [[23, 40], [15, 44]]],
    [[[30, 41], [33, 47]], [[25, 40], [21, 47]]],
    [[[24, 40], [16, 44]], [[31, 39], [37, 45]]],
    [[[26, 41], [22, 47]], [[29, 41], [33, 47]]],
];

/** pose: 'run' (frame 0–3) | 'stop' | 'meep'. */
function runnerParts(pose, frame = 0) {
    const meep = pose === 'meep';
    const run = pose === 'run';
    const bob = run ? (frame % 2 ? -1 : 0) : 0;
    // The crescent: neck down to the belly and back up into the tail, thickest at the belly.
    const c0 = [37, 16];
    const c1 = [30, 40];
    const c2 = [2, 19];
    const samples = Array.from({ length: 49 }, (_, i) => {
        const t = i / 48;
        const [x, y] = quad(c0, c1, c2, t);
        const r = t < 0.38 ? 3.6 + (t / 0.38) * 4.6 : 8.2 * (1 - (t - 0.38) / 0.62) ** 0.85 + 0.7;
        return { t, x, y, r };
    });
    const nearest = (x, y) => {
        let best = null;
        for (const s of samples) {
            const d = Math.hypot(x - s.x, y - s.y) - s.r;
            if (!best || d < best.d) best = { ...s, d, dy: y - s.y, dx: x - s.x };
        }
        return best;
    };
    const body = part((x, y) => nearest(x, y).d <= 0, (x, y) => {
        const s = nearest(x, y);
        if (s.t > 0.5) return s.dy < s.r * 0.15 || s.t > 0.8 ? 'D' : 'd';
        // The pale belly and throat face forward and down; a dark wing stripe runs along the back.
        if (s.dy > s.r * 0.2 && s.t < 0.5 && s.dx > -s.r * 0.6) return 'l';
        if (s.t > 0.3 && s.dy < -s.r * 0.35) return 'D';
        return 'd';
    });
    const headTilt = meep ? -0.3 : 0;
    const head = turn([
        part(polygon([[36, 7], [27, 0], [31, 5.5], [25, 4], [30, 8.5], [26, 9], [34, 11]]), (x, y) => (y < 3 ? 'p' : 'P'), { detail: true }),
        part(ellipse(38.5, 11, 5.6, 5.4), (x, y) => (y > 12.6 && x > 37 ? 'l' : 'd')),
        part(ellipse(40.2, 9.6, 1.9, 1.9), 'w', { detail: true }),
        part(ellipse(41, 9.7, 0.85, 0.85), 'K', { detail: true }),
        ...(meep
            ? [
                part(polygon([[42.5, 8.5], [52, 2.5], [43.5, 11]]), 'Y', { detail: true }),
                part(polygon([[42.5, 12], [43.5, 11], [51, 12], [43, 14.5]]), 'Y', { detail: true }),
                part(polygon([[43.5, 11], [49, 10], [49, 11.4]]), 'M', { detail: true }),
            ]
            : [part(polygon([[42.5, 8.5], [52, 4], [43, 12.6]]), 'Y', { detail: true })]),
    ], headTilt, [37, 14]);
    const upper = [body, ...head].map((q) => ({ ...q, test: ((t) => (x, y) => t(x, y - bob))(q.test), color: ((c) => (x, y) => c(x, y - bob))(q.color) }));
    const leg = ([knee, foot], far) => {
        const ch = far ? 'o' : 'Y';
        return [
            part(capsule(HIP[0], HIP[1] + bob, knee[0], knee[1], 1.15), ch, { detail: true }),
            part(capsule(knee[0], knee[1], foot[0], foot[1], 1.1), ch, { detail: true }),
            part(capsule(foot[0], foot[1], foot[0] + 3.2, foot[1], 0.9), ch, { detail: true }),
        ];
    };
    if (run) {
        const [near, far] = RR_RUN[frame];
        // Leaning into the run: the whole upper body pitches forward around the hip.
        return [...leg(far, true), ...turn(upper, 0.12, HIP), ...leg(near, false)];
    }
    // Standing, upright like the logo's swoosh: legs straight down, the body tipped back so the tail rises.
    return [
        ...leg([[25, 40], [24, 47]], true),
        ...turn(upper, -0.22, HIP),
        ...leg([[29, 40], [29, 47]], false),
    ];
}

function rasteriseRunner(parts, height) {
    const k = height / REF_H;
    const w = Math.round(54 * k);
    const h = Math.round(REF_H * k);
    const g = grid(w, h);
    const detail = grid(w, h);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const rx = (x + 0.5) / k;
            const ry = (y + 0.5) / k;
            for (const q of parts) {
                if (q.test(rx, ry)) {
                    g[y][x] = q.color(rx, ry);
                    detail[y][x] = q.detail ? 'y' : '.';
                }
            }
        }
    }
    // Inside outline like the flyers': the silhouette's edge pixels turn dark; beak, legs and eye keep their colour so
    // they still read on the small sizes, and the smallest keeps its top edge coloured.
    const out = g.map((r) => r.slice());
    const empty = (x, y) => (g[y]?.[x] ?? '.') === '.';
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (g[y][x] === '.' || detail[y][x] === 'y') continue;
            const up = h > 14 && empty(x, y - 1);
            if (up || empty(x, y + 1) || empty(x - 1, y) || empty(x + 1, y)) out[y][x] = 'O';
        }
    }
    return toSprite(out, RR_LEGEND, { x: Math.round(HIP[0] * k), y: h });
}

// Heights: the far horizon sprite of flyers.js is 4 px; then 12, 20, 30 and the foreground 46.
const RUNNER_SIZES = { s: 12, m: 20, l: 30, xl: 46 };

/**
 * RoadRunner, facing right (mirror for the left). far: the 7×4 horizon run of flyers.js (4 frames). s / m / l / xl —
 * 12, 20, 30 and 46 px tall, each { run: 4 frames (~70 ms; ~100 ms when small and far), stop, meep } with the anchor
 * at the feet under the hip; swap sizes as it comes nearer (the scene scales nothing). meep: beak wide open, head
 * tipped up — hold it for the «мип-мип».
 */
export const RUNNER = {
    far: FLYERS.roadrunner[0].fly,
    ...Object.fromEntries(Object.entries(RUNNER_SIZES).map(([size, height]) => [size, {
        run: [0, 1, 2, 3].map((f) => rasteriseRunner(runnerParts('run', f), height)),
        stop: rasteriseRunner(runnerParts('stop'), height),
        meep: rasteriseRunner(runnerParts('meep'), height),
    }])),
};

// ---------------------------------------------------------------------------------------------------------------
// Effects. All of them are light (bake() them, draw additive or plain) except smoke, which the scene lights.

const COLUMN_LEGEND = { w: 'columnCore', R: 'bloodMoonLight', r: 'rune', d: 'runeDark' };
const COLUMN_W = 48;

/**
 * The red light column standing on the circle: w × height, anchor at the bottom centre (the circle's centre). Bright
 * at the foot, streaked, fading up in Bayer steps; frame 0–3 runs the streaks upward.
 */
export function lightColumn(height = 150, frame = 0) {
    const g = grid(COLUMN_W, height);
    const cx = COLUMN_W / 2;
    for (let y = 0; y < height; y++) {
        const up = (height - 1 - y) / height;
        // A straight beam, a touch wider where it meets the circle.
        const hw = 19 + Math.max(0, 0.08 - up) * 40;
        // Full strength up to two thirds of the height, then it fades out in Bayer steps.
        const fade = up < 0.6 ? 1 : 1 - (up - 0.6) / 0.4;
        for (let x = 0; x < COLUMN_W; x++) {
            const dx = Math.abs(x + 0.5 - cx);
            if (dx > hw) continue;
            // Long vertical streaks drifting up inside straight walls, a steady hot core down the middle.
            const streak = noise(x * 0.8, y * 0.02 + frame * 0.5, 7);
            const wall = dx > hw - 2.5 ? 0.3 : 0.34 + 0.4 * streak;
            const core = dx < 3.5 ? 0.42 : dx < 6 ? 0.18 : 0;
            const v = (wall + core) * fade + (up < 0.035 ? 0.4 : 0);
            const d = dither(x, y + frame * 2);
            const ch = v > 0.95 ? 'w' : v > 0.72 ? 'R' : v > 0.5 ? 'r' : v > 0.05 && d < v * 1.7 ? 'd' : null;
            if (ch) g[y][x] = ch;
        }
    }
    return toSprite(g, COLUMN_LEGEND, { x: cx, y: height });
}

/** The circle flashing as the column ignites: 3 frames (blinding, burning, fading), 80×30, anchor at the centre. */
function circleFlash(f) {
    const w = 80;
    const h = 30;
    const g = grid(w, h);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const d = Math.hypot((x + 0.5 - w / 2) / (w / 2), (y + 0.5 - h / 2) / (h / 2));
            if (d > 1) continue;
            const ring = 1 - Math.abs(d - 0.8) / 0.2;
            const v = [Math.max(1.1 - d * 0.7, ring), Math.max(0.75 - d * 0.6, ring * 0.85), ring * 0.55][f];
            const dd = dither(x, y);
            const ch = v > 0.85 ? 'w' : v > 0.6 ? 'R' : v > 0.35 ? 'r' : v > 0.12 && dd < v * 2.5 ? 'd' : null;
            if (ch) g[y][x] = ch;
        }
    }
    return toSprite(g, COLUMN_LEGEND, { x: w / 2, y: h / 2 });
}

const BOLT_LEGEND = { w: 'lightningCore', l: 'lightning', e: 'lightningEdge' };
const BOLT_W = 56;

/**
 * A lightning strike from the top of a w × height canvas down to its anchor (bottom centre — aim it at the
 * silhouette). 3 frames: the full white bolt with branches and an impact splash, the bolt cooling, an afterimage.
 * seed picks the shape: three strikes are seeds 1, 2, 3.
 */
export function lightning(height = 120, seed = 1) {
    const rnd = mulberry32(seed * 7919);
    const cx = BOLT_W >> 1;
    const main = [[cx + (rnd() - 0.5) * 28, 0]];
    while (main[main.length - 1][1] < height - 1) {
        const [x, y] = main[main.length - 1];
        const ny = Math.min(height - 1, y + 5 + rnd() * 7);
        const pull = (cx - x) * ((ny / height) ** 2) * 0.8;
        main.push([Math.max(3, Math.min(BOLT_W - 4, x + (rnd() - 0.5) * 12 + pull)), ny]);
    }
    main[main.length - 1][0] = cx;
    const branches = [];
    for (let i = 0; i < 3; i++) {
        let [x, y] = main[1 + Math.floor(rnd() * (main.length - 3))];
        const dir = rnd() < 0.5 ? -1 : 1;
        const br = [[x, y]];
        const n = 2 + Math.floor(rnd() * 3);
        for (let k = 0; k < n; k++) {
            x = Math.max(1, Math.min(BOLT_W - 2, x + dir * (2 + rnd() * 6)));
            y += 4 + rnd() * 6;
            br.push([x, Math.min(height - 3, y)]);
        }
        branches.push(br);
    }
    const draw = (g, pts, ch) => {
        for (let i = 0; i < pts.length - 1; i++) {
            const [x0, y0] = pts[i];
            const [x1, y1] = pts[i + 1];
            const n = Math.max(1, Math.ceil(Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0))));
            for (let s = 0; s <= n; s++) {
                const x = Math.round(x0 + ((x1 - x0) * s) / n);
                const y = Math.round(y0 + ((y1 - y0) * s) / n);
                if (g[y]?.[x] !== undefined) g[y][x] = ch;
            }
        }
    };
    const halo = (g, ch, density) => {
        const src = g.map((r) => r.slice());
        for (let y = 0; y < height; y++) {
            for (let x = 0; x < BOLT_W; x++) {
                if (src[y][x] !== '.') continue;
                const near = [[-1, 0], [1, 0], [0, -1], [0, 1]].some(([dx, dy]) => (src[y + dy]?.[x + dx] ?? '.') !== '.');
                if (near && dither(x, y) < density) g[y][x] = ch;
            }
        }
    };
    const frames = [0, 1, 2].map((f) => {
        const g = grid(BOLT_W, height);
        if (f < 2) {
            branches.forEach((br) => draw(g, br, f ? 'e' : 'l'));
            draw(g, main, f ? 'l' : 'w');
            halo(g, f ? 'e' : 'l', f ? 0.5 : 1);
            if (!f) halo(g, 'e', 0.6);
        } else {
            draw(g, main, 'e');
            for (let y = 0; y < height; y++) for (let x = 0; x < BOLT_W; x++) if (g[y][x] === 'e' && dither(x, y) > 0.55) g[y][x] = '.';
        }
        // The impact: a splash of light where it strikes.
        const splash = [[0, 0], [-1, -1], [1, -1], [-2, -2], [2, -2], [-3, 0], [3, 0], [-2, 1], [2, 1], [0, -3]];
        const reach = [10, 6, 0][f];
        splash.slice(0, reach).forEach(([dx, dy], i) => {
            const y = height - 1 + dy;
            if (g[y]?.[cx + dx] !== undefined) g[y][cx + dx] = i < 3 && !f ? 'w' : 'l';
        });
        return toSprite(g, BOLT_LEGEND, { x: cx, y: height });
    });
    return frames;
}

// Smoke: three tones lit by the scene, light up and to the left, dense in the middle and dithered out to the edge.
const SMOKE_LEGEND = { q: 'smokeLight', p: 'smoke', s: 'smokeDark' };

function smokeBlob(g, cx, cy, rx, ry, seed, density = 1) {
    for (let y = Math.floor(cy - ry - 2); y <= cy + ry + 2; y++) {
        for (let x = Math.floor(cx - rx - 2); x <= cx + rx + 2; x++) {
            if (g[y]?.[x] === undefined) continue;
            const n = noise(x * 0.22, y * 0.3, seed) - 0.5;
            const d = Math.hypot((x + 0.5 - cx) / rx, (y + 0.5 - cy) / ry) + n * 0.5;
            if (d > 1) continue;
            if (dither(x, y) > (1 - d) * 2.4 * density) continue;
            const lit = (x - cx) / rx + (y - cy) / ry + n;
            const ch = lit < -0.5 ? 'q' : lit < 0.45 ? 'p' : 's';
            // Lighter puffs in front win over the darker ones behind.
            if (g[y][x] === '.' || 'spq'.indexOf(ch) >= 'spq'.indexOf(g[y][x])) g[y][x] = ch;
        }
    }
}

/** A puff of smoke r px across, 4 frames billowing (the scene moves and fades it). */
function puff(r, frame) {
    const w = r * 2 + 6;
    const h = Math.round(r * 1.6) + 6;
    const g = grid(w, h);
    const cx = w / 2;
    const cy = h / 2;
    const lobes = 3;
    for (let i = 0; i < lobes; i++) {
        const a = (i / lobes) * Math.PI * 2 + frame * 0.5;
        smokeBlob(g, cx + Math.cos(a) * r * 0.35, cy + Math.sin(a) * r * 0.25, r * (0.62 + 0.06 * Math.sin(frame + i)), r * 0.5, 31 + i + frame * 3);
    }
    return toSprite(g, SMOKE_LEGEND, { x: Math.round(cx), y: Math.round(cy) });
}

/**
 * The cloud the silhouette rises in, 84×44, anchor at the bottom centre (the circle's centre): 4 frames of billows
 * rolling up and out.
 */
function smokeCloud(frame) {
    const w = 84;
    const h = 44;
    const g = grid(w, h);
    const rnd = mulberry32(5);
    const puffs = Array.from({ length: 13 }, () => [rnd(), rnd(), rnd()]);
    puffs.forEach(([a, b, c], i) => {
        const phase = (b + frame / 4) % 1;
        const side = a * 2 - 1;
        const x = w / 2 + side * (14 + phase * 20);
        const y = h - 8 - phase * 26 - c * 4;
        const r = 7 + phase * 6 + c * 3;
        smokeBlob(g, x, y, r, r * 0.7, 40 + i, 1.1 - phase * 0.7);
    });
    // The base hugs the ground across the whole circle.
    smokeBlob(g, w / 2, h - 6, 38, 6, 12 + frame);
    return toSprite(g, SMOKE_LEGEND, { x: w / 2, y: h });
}

/**
 * Low smoke creeping over the ground after the lightning: 128×14, anchor at the bottom centre, 4 frames drifting
 * sideways; ends and top thin out, so several overlapping copies make a carpet.
 */
function creepingSmoke(frame) {
    const w = 128;
    const h = 14;
    const g = grid(w, h);
    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const n = noise((x + frame * 3) * 0.09, y * 0.35, 3);
            const top = 3 + n * 9;
            const up = h - 1 - y;
            if (up > top) continue;
            const fade = Math.min(1, x / 20, (w - 1 - x) / 20);
            const v = (1 - up / (top + 1)) ** 0.6 * fade * (0.75 + n * 0.7);
            if (dither(x, y) > v) continue;
            g[y][x] = up > top - 2.5 || n > 0.6 ? 'q' : n < 0.3 ? 's' : 'p';
        }
    }
    return toSprite(g, SMOKE_LEGEND, { x: w / 2, y: h });
}

const GLOW_TONES = { a: 'frankenGlow', b: 'frankenGlowDark' };

/** A dithered ground light rx × ry in FrankenPHP's lime, like halo() in night.js: additive, alpha ~0.35–0.6. */
export function frankenHalo(rx, ry) {
    const g = grid(2 * rx + 1, 2 * ry + 1);
    for (let y = 0; y <= 2 * ry; y++) {
        for (let x = 0; x <= 2 * rx; x++) {
            const d = Math.hypot((x - rx) / rx, (y - ry) / ry);
            if (d > 1) continue;
            const density = (1 - d) ** 0.8 * 1.15;
            if (dither(x, y) < density) g[y][x] = d < 0.45 && dither(x, y) < density - 0.35 ? 'a' : 'b';
        }
    }
    return toSprite(g, GLOW_TONES, { x: rx, y: ry });
}

/**
 * column: 4 frames of lightColumn() at 150 px (~90 ms); flash: 3 frames of the circle igniting (~80 ms);
 * lightning: three strikes × 3 frames at 120 px (~60 ms each, a beat of darkness between strikes); smoke.puff:
 * sizes 5, 8, 12 × 4 frames (~200 ms); smoke.cloud: 4 frames (~220 ms); smoke.creep: 4 frames (~300 ms);
 * motes: FrankenPHP-green specks for the cursed world.
 */
export const FX = {
    column: [0, 1, 2, 3].map((f) => lightColumn(150, f)),
    flash: [0, 1, 2].map(circleFlash),
    lightning: [1, 2, 3].map((seed) => lightning(120, seed)),
    smoke: {
        puff: [5, 8, 12].map((r) => [0, 1, 2, 3].map((f) => puff(r, f))),
        cloud: [0, 1, 2, 3].map(smokeCloud),
        creep: [0, 1, 2, 3].map(creepingSmoke),
    },
    motes: [
        toSprite(['.z.', 'zZz', '.z.'].map((r) => [...r]), { z: 'frankenGlow', Z: 'frankenGlowCore' }, { x: 1, y: 1 }),
        toSprite(['Z', 'z'].map((r) => [...r]), { z: 'frankenGlowDark', Z: 'frankenGlowHot' }, { x: 0, y: 0 }),
        toSprite(['..z..', '..h..', 'zhZhz', '..h..', '..z..'].map((r) => [...r]), { z: 'frankenGlowDark', h: 'frankenGlow', Z: 'frankenGlowCore' }, { x: 2, y: 2 }),
    ],
};

/**
 * Palette keys of this module that are light sources: add them to EMISSIVE in night.js, so bakeLit and relightCanvas
 * keep them unlit (sparks, the cursed glow, lightning, the column's core).
 */
export const FINALE_EMISSIVE = [
    'frankenGlowCore', 'frankenGlowHot', 'frankenGlow', 'frankenGlowDark', 'frankenGlowDeep',
    'lightningCore', 'lightning', 'lightningEdge', 'columnCore',
];
