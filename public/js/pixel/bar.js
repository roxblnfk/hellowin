import { PALETTE } from './palette.js';
import { EMISSIVE, LIGHTINGS, keepHueColor, litColor } from './night.js';
import { BAR_HIDE, OWL_BAR } from './owl.js';
import { paintFlames } from './sprites.js';

/**
 * The bar (scene 0, docs/spec/frontend.md «Бар и сдвиг номеров сцен»): a Viking mead hall in pixel art — log walls
 * with posts and a tie beam, a stone hearth with antlers over it, torches, shields, banners and a chandelier, a plank
 * floor; massive tables with benches, barrels, and the counter with the keg and its tap, where the owl pours.
 *
 * Everything works in art pixels of a canvas artW × artH and takes the server's `walk.bar` layout in screen percents:
 *   { area: {x1, y1, x2, y2}, obstacles: [{cx, cy, rx, ry} (a table) | {x1, y1, x2, y2} (the counter)],
 *     counter: {x1, x2, y}, spawn: {y1, y2}, clink_reach }
 * Only `area` and `obstacles` shape the art: the floor starts a little above area.y1, every ellipse becomes a table
 * with benches, the first rectangle becomes the counter (later rectangles — barrel stacks).
 *
 * Common options `opts`:
 *   lighting — a night.js lighting name ('day' by default; an unknown name falls back to 'day'): every colour goes
 *              through litColor except emissive keys (night.js EMISSIVE), so embers and lit faces stay bright;
 *   toArt    — (p: {x, y} in percents) → {x, y} in art px; by default plain screen percents (x / 100 · artW). Pass
 *              the mapping the walkers use so the furniture lands on the obstacles they walk around;
 *   seed     — the hall's randomness (log seams, stones, what lies on the tables); the same for everyone by default.
 *
 * API (all results in art px; cache them per layout × size × lighting, they are pure):
 *   paintBar(ctx, artW, artH, layout, opts)      paints the hall without furniture and flames: roof, beam, walls,
 *                                                posts, hearth, decor, floor, contact shadows of the furniture.
 *   barProps(layout, artW, artH, opts)           → [{kind, canvas, x, y, anchor, glow?, phase?}] — furniture to sort
 *                                                with the walkers by `y` (their foot line): kind 'table', 'bench',
 *                                                'barrel', 'counter'. Put canvas pixel `anchor` at (x, y). `glow` —
 *                                                flicker frames of the same size, drawn unlit over the prop (candle on
 *                                                a table): frame ⌊now / BAR_FLICKER_MS + phase⌋ mod glow.length.
 *   barFootprints(layout, artW, artH, opts)      → [{kind, x1, y1, x2, y2}] — the floor under the tables, benches,
 *                                                barrel stacks and the counter; the furniture keeps half a walker
 *                                                (WALKER_HALF) inside its obstacle, so the router walks bodies clear of
 *                                                it. For the lab's ?boxes check.
 *   barLights(layout, artW, artH, opts)          → [{kind, x, y, frames, anchor, phase, halo}] — the wall flames
 *                                                ('hearth', 'torch', 'candle' of the chandelier) for a layer over the
 *                                                background and under everything else, drawn unlit, flickering like
 *                                                glow; halo {x, y, rx, ry} — a suggested night.js halo() centred on
 *                                                the floor below the flame (null for the chandelier's candles).
 *   barOwlSpot(layout, artW, artH, opts)         → {x, y, tap, mug} — where OWL_BAR's anchor goes (the owl is drawn
 *                                                before the counter prop, which hides its lower body); tap — the
 *                                                nozzle tip, mug — the bottom centre of the mug being poured.
 *   mugSlots(layout, artW, artH, max, opts)      → [{x, y}] up to `max` counter mugs, nearest the owl first; place
 *                                                BAR_MUG's anchor there and draw the mugs right after the counter.
 *   counterBox(layout, artW, artH, opts)         → {x1, x2, top, y} — the drawn counter (top = the keg's top, y = the
 *                                                front foot line), for the DOM button over it; null without one.
 *   BAR_MUG                                      the full counter mug sprite (sprites.js format, 9 × 8, anchor on
 *                                                the bottom centre), the walkers' beer colours plus a handle.
 *   bakeBar(sprite, lighting)                    a canvas of a sprite in a lighting with its beer and foam kept
 *                                                (night.js keepHueColor) — for BAR_MUG and the OWL_BAR frames.
 *   beerLayer(sprite)                            the same sprite with only its beer and foam pixels.
 *   DEFAULT_BAR_LAYOUT                           the lab's copy of walk.bar (app/config/hellowin.php).
 */

/** The lab's hall: a copy of walk.bar in app/config/hellowin.php (and of BAR_LAYOUT in layout.js), screen percents. */
export const DEFAULT_BAR_LAYOUT = {
    area: { x1: 3, y1: 50, x2: 94, y2: 97 },
    obstacles: [
        { x1: 62.5, y1: 46, x2: 95, y2: 60 },
        { cx: 22, cy: 64, rx: 13, ry: 7 },
        { cx: 44, cy: 78, rx: 13, ry: 7 },
        { cx: 76, cy: 80, rx: 13, ry: 7 },
    ],
    counter: { x1: 66, x2: 92, y: 62 },
    spawn: { y1: 90, y2: 94 },
    clink_reach: 10,
};

/** Flicker step of every flame in the bar, ms. */
export const BAR_FLICKER_MS = 130;

// How far the floor reaches above area.y1, so the feet of a walker on the top edge stand on planks, not the wall.
const FLOOR_LEAD = 9;
const COUNTER_H = 13;
const TABLE_H = 9;
const BENCH_H = 4;
const BENCH_D = 3;
// What the furniture keeps off the edges of its obstacle, art px: half a walker's body (characters.js: a 12 px head
// and its outline) at the sides, a gap in front and behind.
const WALKER_HALF = 7;
const FOOT_GAP = 1;

const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const dither = (x, y) => (BAYER[y & 3][x & 3] + 0.5) / 16;

function hash(x, y, s = 0) {
    let h = (Math.imul(x | 0, 374761393) + Math.imul(y | 0, 668265263) + Math.imul(s | 0, 1442695041)) | 0;
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

function rng(seed) {
    let a = seed >>> 0;
    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

// Ramps dark → light; shade() picks a step with Bayer dithering between neighbours.
const TIMBER = ['outline', 'timberDeep', 'timberDark', 'timber', 'timberLight', 'timberShine'];
const PLANK = ['outline', 'plankDeep', 'plankDark', 'plank', 'plankLight', 'timberLight'];
const STONE = ['outline', 'stoneDeep', 'stoneDark', 'stone', 'stoneLight', 'grayLight'];
const FUR = ['outline', 'barkDark', 'bark', 'barkLight', 'hayDark'];

// Furniture takes whole steps: dithered, a small prop turns to noise.
const flat = (ramp, v) => ramp[clamp(Math.round(clamp(v, 0, 1) * (ramp.length - 1)), 0, ramp.length - 1)];

function shade(ramp, v, x, y) {
    const f = clamp(v, 0, 1) * (ramp.length - 1);
    const i = Math.floor(f);
    return ramp[clamp(f - i > dither(x, y) ? i + 1 : i, 0, ramp.length - 1)];
}

// ---------------------------------------------------------------------------------------------------------------
// Pixel grids of palette keys.

class Pix {
    constructor(w, h) {
        this.w = w;
        this.h = h;
        this.k = new Array(w * h).fill(null);
    }

    set(x, y, key) {
        x = Math.round(x);
        y = Math.round(y);
        if (key && x >= 0 && y >= 0 && x < this.w && y < this.h) this.k[y * this.w + x] = key;
    }

    get(x, y) {
        return x >= 0 && y >= 0 && x < this.w && y < this.h ? this.k[y * this.w + x] : null;
    }

    rect(x0, y0, x1, y1, key) {
        for (let y = Math.round(y0); y <= Math.round(y1); y++) {
            for (let x = Math.round(x0); x <= Math.round(x1); x++) this.set(x, y, typeof key === 'function' ? key(x, y) : key);
        }
    }

    stamp(x0, y0, rows, legend) {
        rows.forEach((row, y) => [...row].forEach((ch, x) => this.set(x0 + x, y0 + y, legend[ch])));
    }

    /** Rings every filled area with the outline (light keys do not cast one). */
    outline(key = 'outline') {
        const src = this.k.slice();
        const solid = (x, y) => x >= 0 && y >= 0 && x < this.w && y < this.h && src[y * this.w + x] && !EMISSIVE.has(src[y * this.w + x]);
        for (let y = 0; y < this.h; y++) {
            for (let x = 0; x < this.w; x++) {
                if (src[y * this.w + x]) continue;
                if (solid(x - 1, y) || solid(x + 1, y) || solid(x, y - 1) || solid(x, y + 1)) this.k[y * this.w + x] = key;
            }
        }
        return this;
    }

    canvas(color) {
        const c = document.createElement('canvas');
        c.width = this.w;
        c.height = this.h;
        const ctx = c.getContext('2d');
        ctx.putImageData(this.image(ctx, color), 0, 0);
        return c;
    }

    image(ctx, color) {
        const img = ctx.createImageData(this.w, this.h);
        const cache = new Map();
        this.k.forEach((key, i) => {
            if (!key) return;
            if (!cache.has(key)) cache.set(key, color(key));
            const c = cache.get(key);
            img.data[i * 4] = (c >> 16) & 255;
            img.data[i * 4 + 1] = (c >> 8) & 255;
            img.data[i * 4 + 2] = c & 255;
            img.data[i * 4 + 3] = 255;
        });
        return img;
    }
}

const lightingOf = (opts) => (LIGHTINGS.includes(opts?.lighting) ? opts.lighting : 'day');
const BEER_KEYS = new Set(['white', 'cream', 'pumpkinShine', 'pumpkinLight']);
const litKey = (lighting, beer = false) => (key) => {
    const c = PALETTE[key];
    if (lighting === 'day' || EMISSIVE.has(key)) return c;
    return beer && BEER_KEYS.has(key) ? keepHueColor(c, lighting) : litColor(c, lighting);
};
// Flames are light: baked as they are, never relit.
const FLAME_KEYS = { w: 'flameCore', s: 'pumpkinShine', l: 'pumpkinLight', d: 'pumpkin', D: 'ember' };
const raw = (key) => PALETTE[key];

// ---------------------------------------------------------------------------------------------------------------
// Geometry: the layout in art px.

function geometry(layout, artW, artH, opts = {}) {
    const L = layout?.area ? layout : DEFAULT_BAR_LAYOUT;
    const toArt = opts.toArt ?? ((p) => ({ x: (p.x / 100) * artW, y: (p.y / 100) * artH }));
    const pt = (x, y) => toArt({ x, y });
    const a1 = pt(L.area.x1, L.area.y1);
    const a2 = pt(L.area.x2, L.area.y2);
    const floorTop = clamp(Math.round(a1.y) - FLOOR_LEAD, Math.round(artH * 0.2), Math.round(artH * 0.8));
    const tables = [];
    const rects = [];
    for (const o of L.obstacles ?? []) {
        if (o.cx !== undefined) {
            const c = pt(o.cx, o.cy);
            const e = pt(o.cx + o.rx, o.cy + o.ry);
            tables.push({ cx: c.x, cy: c.y, rx: Math.abs(e.x - c.x), ry: Math.abs(e.y - c.y) });
        } else {
            const p = pt(o.x1, o.y1);
            const q = pt(o.x2, o.y2);
            rects.push({ x1: Math.round(Math.min(p.x, q.x)), y1: Math.round(Math.min(p.y, q.y)), x2: Math.round(Math.max(p.x, q.x)), y2: Math.round(Math.max(p.y, q.y)) });
        }
    }
    let counter = null;
    let owl = null;
    if (rects.length) {
        // A walker's half off each end of the counter that has floor beside it.
        const r = { ...rects[0] };
        if (r.x1 > Math.min(a1.x, a2.x)) r.x1 += WALKER_HALF;
        if (r.x2 < Math.max(a1.x, a2.x)) r.x2 -= WALKER_HALF;
        const depth = clamp(r.y2 - r.y1, 6, 12);
        const back = r.y2 - COUNTER_H - depth;
        counter = { ...r, depth, back, face: r.y2 - COUNTER_H + 2 };
        const x = Math.round(Math.max(r.x1 + 10, r.x2 - OWL_BAR.tap.x - 16));
        owl = { x, y: back + BAR_HIDE };
        counter.keg = { x1: x + OWL_BAR.tap.x + 3, x2: x + OWL_BAR.tap.x + 14, y2: back, y1: back - 11 };
    }
    // Wall bays between posts, an odd count so a bay, not a post, stands in the middle: the hearth takes the free bay
    // nearest the middle, the counter's bay gets the shelves and the sign.
    const n = Math.max(3, 2 * Math.round((artW / 150 - 1) / 2) + 1);
    const posts = Array.from({ length: n + 1 }, (_, k) => Math.round((k * artW) / n));
    const bays = posts.slice(0, -1).map((x, k) => ({ x1: x, x2: posts[k + 1], cx: (x + posts[k + 1]) / 2 }));
    const roof = Math.max(8, Math.round(floorTop * 0.16));
    // A bay is the counter's when the counter covers a third of it: one the counter only clips stays free for the hearth.
    const hasCounter = (b) => counter && Math.min(counter.x2, b.x2) - Math.max(counter.x1, b.x1) > (b.x2 - b.x1) / 3;
    const free = bays.filter((b) => !hasCounter(b));
    const hearthBay = (free.length ? free : bays).reduce((best, b) => (Math.abs(b.cx - artW / 2) < Math.abs(best.cx - artW / 2) ? b : best));
    const hearthW = Math.round(Math.min(hearthBay.x2 - hearthBay.x1 - 22, clamp(artW * 0.15, 40, 60)));
    const hearthH = Math.round(clamp((floorTop - roof) * 0.48, 34, 54));
    const hearth = { cx: Math.round(hearthBay.cx), w: hearthW, h: hearthH, top: floorTop - hearthH, bay: hearthBay };
    hearth.ow = Math.round(hearthW * 0.56) | 1;
    hearth.oh = Math.round(hearthH * 0.56);
    hearth.mantel = floorTop - hearth.oh - 7;
    return {
        artW, artH, floorTop, roof, posts, bays, tables, rects, counter, owl, hearth, hasCounter,
        area: { x1: Math.round(Math.min(a1.x, a2.x)), y1: Math.round(a1.y), x2: Math.round(Math.max(a1.x, a2.x)), y2: Math.round(a2.y) },
        seed: opts.seed ?? 7,
    };
}

/**
 * A table ellipse → footprints (front line = y where the walkers sort) of the table and its benches. The router keeps
 * the walkers' feet out of the ellipse, so the floor under the table and its benches, widened by half a walker at each
 * end, stays inside it and no body overlaps the furniture. Of the depths that fit — the largest slab no deeper than
 * half its length and not much longer than its benches.
 */
function tableParts(t) {
    // The even floor width the ellipse leaves for the furniture `dy` from its centre, less a walker's half each end.
    const room = (dy) => 2 * Math.floor(t.rx * Math.sqrt(Math.max(0, 1 - ((dy + FOOT_GAP + 0.5) / t.ry) ** 2)) - WALKER_HALF);
    const benches = t.ry >= 8;
    let best = null;
    for (let td = benches ? 5 : 4; td < 2 * t.ry; td++) {
        const bw = benches ? Math.min(room(td / 2) - 6, room(td / 2 + 1 + BENCH_D)) : 0;
        // The slab overhangs its benches by at most 6 px at each end.
        const tw = benches ? Math.min(room(td / 2), bw + 12) : room(td / 2);
        if (tw < 14 || td > tw / 2 || (benches && bw < 10)) continue;
        if (!best || tw * td > best.tw * best.td) best = { tw, td, bw };
    }
    // An ellipse too small for any of them: the smallest table, sticking out of it.
    const { tw, td, bw } = best ?? { tw: 14, td: benches ? 5 : 4, bw: 10 };
    const x1 = Math.round(t.cx - tw / 2);
    const front = Math.round(t.cy + td / 2);
    const parts = { x1, w: tw, front, back: front - td, depth: td, benches: [] };
    if (benches) {
        const bx = Math.round(t.cx - bw / 2);
        parts.benches.push({ x1: bx, w: bw, front: front - td - 1 }, { x1: bx, w: bw, front: front + 1 + BENCH_D });
    }
    return parts;
}

// Lights that brighten the painted wall and floor (the flames themselves are barLights).
function lightSources(g) {
    const out = [{ x: g.hearth.cx, y: g.floorTop - g.hearth.oh * 0.4, r: 90, s: 0.75 }];
    for (const t of torchSpots(g)) out.push({ x: t.x, y: t.y, r: 46, s: 0.5 });
    for (const c of chandeliers(g)) out.push({ x: c.x, y: c.y, r: 60, s: 0.3 });
    return out;
}

function lightAt(lights, x, y) {
    let v = 0;
    for (const l of lights) {
        const d = Math.hypot(x - l.x, (y - l.y) * 1.15) / l.r;
        if (d < 1) v += l.s * (1 - d) ** 1.6;
    }
    return Math.min(1, v);
}

// Torches hang on the visible inner posts; the hearth's bay gets one each side when its posts are off screen.
function torchSpots(g) {
    const y = g.floorTop - 36;
    const spots = g.posts.filter((x) => x > 8 && x < g.artW - 8).map((x) => ({ x: x + 4, y, post: true }));
    if (!spots.some((s) => Math.abs(s.x - g.hearth.cx) < g.hearth.bay.x2 - g.hearth.bay.x1)) {
        spots.push({ x: g.hearth.cx - g.hearth.w / 2 - 10, y, post: false }, { x: g.hearth.cx + g.hearth.w / 2 + 10, y, post: false });
    }
    return spots;
}

// Chandeliers hang in the bays with neither the hearth nor the counter.
function chandeliers(g) {
    return g.bays
        .filter((b) => b !== g.hearth.bay && !g.hasCounter(b) && b.x2 - b.x1 > 60)
        .map((b) => ({ x: Math.round(b.cx), y: g.roof + 6 + Math.round((g.floorTop - g.roof) * 0.3), top: g.roof + 6 }));
}

// ---------------------------------------------------------------------------------------------------------------
// The background.

/** Paints the hall (no furniture, no flames) into a 2D context of an artW × artH canvas. */
export function paintBar(ctx, artW, artH, layout, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    const px = new Pix(artW, artH);
    const lights = lightSources(g);
    const L = (x, y) => lightAt(lights, x, y);
    const rand = rng(g.seed);

    paintRoof(px, g, L);
    paintWall(px, g, L, rand);
    paintFloor(px, g, L, rand);
    paintPosts(px, g, L);
    paintBeam(px, g, L);
    paintHearth(px, g, L);
    paintDecor(px, g, L, rand);
    paintShadows(px, g);

    ctx.putImageData(px.image(ctx, litKey(lightingOf(opts))), 0, 0);
}

function paintRoof(px, g, L) {
    // The dark roof space over the beam, rafters stepping across it.
    for (let y = 0; y < g.roof; y++) {
        for (let x = 0; x < g.artW; x++) {
            const rafter = (x + 6) % 22 < 5;
            const v = rafter ? 0.32 + (((x + 6) % 22) === 1 ? 0.12 : 0) : 0.1;
            px.set(x, y, shade(TIMBER, v + L(x, y) * 0.25 - (1 - y / g.roof) * 0.1, x, y));
        }
    }
}

function paintWall(px, g, L, rand) {
    const LOG = 7;
    const profile = [0.62, 0.66, 0.6, 0.52, 0.44, 0.33, 0.06];
    const seams = new Map();
    for (let y = g.roof; y < g.floorTop; y++) {
        const fromFloor = g.floorTop - 1 - y;
        const log = Math.floor(fromFloor / LOG);
        const r = LOG - 1 - (fromFloor % LOG);
        if (!seams.has(log)) {
            const xs = [];
            for (let x = Math.floor(rand() * 60); x < g.artW; x += 50 + Math.floor(rand() * 70)) xs.push(x);
            seams.set(log, xs);
        }
        const xs = seams.get(log);
        for (let x = 0; x < g.artW; x++) {
            let v = profile[r];
            // Grain: long streaks along the log.
            const streak = hash(Math.floor((x + log * 13) / 9), log * 7 + r, g.seed);
            if (r > 0 && r < LOG - 1 && streak > 0.86) v -= 0.1;
            const seam = xs.find((s) => x >= s && x <= s + 2);
            if (seam !== undefined && r < LOG - 1) v = x === seam ? 0.08 : x === seam + 1 ? v + 0.14 : v - 0.04;
            v += L(x, y) * 0.42;
            v -= Math.max(0, 1 - (y - g.roof) / 22) * 0.18;
            px.set(x, y, r === LOG - 1 ? (L(x, y) > 0.35 ? 'timberDeep' : 'outline') : shade(TIMBER, v, x, y));
        }
    }
}

function paintFloor(px, g, L, rand) {
    let y = g.floorTop;
    let row = 0;
    while (y < g.artH) {
        const t = (y - g.floorTop) / Math.max(1, g.artH - g.floorTop);
        const h = clamp(Math.round(3 + 5 * t), 3, 8);
        const ends = [];
        for (let x = -Math.floor(rand() * 80); x < g.artW; x += 34 + Math.floor(rand() * 60 + t * 30)) ends.push(x);
        for (let r = 0; r < h; r++, y++) {
            if (y >= g.artH) break;
            for (let x = 0; x < g.artW; x++) {
                let v = r === 0 ? 0.62 : r === h - 1 ? 0.12 : 0.5 - (r / h) * 0.12;
                const end = ends.find((e) => x === e || x === e + 1);
                if (end !== undefined && r < h - 1) v = x === end ? 0.1 : v + 0.12;
                if (r > 0 && r < h - 1 && hash(Math.floor((x + row * 17) / 7), row * 5 + r, g.seed + 1) > 0.88) v -= 0.1;
                // Nails by the board ends.
                if (r === 1 && ends.some((e) => x === e + 3 || x === e - 3)) v = 0.2;
                v += L(x, y) * 0.45;
                v -= Math.max(0, 1 - (y - g.floorTop) / 14) * 0.28;
                px.set(x, y, shade(PLANK, v, x, y));
            }
        }
        row++;
    }
    // The bottom log of the wall, darker, where it meets the floor.
    for (let x = 0; x < g.artW; x++) {
        px.set(x, g.floorTop - 1, 'outline');
        px.set(x, g.floorTop, shade(TIMBER, 0.2 + L(x, g.floorTop) * 0.3, x, g.floorTop));
    }
    paintRug(px, g, L);
}

// A bearskin in front of the hearth, when no table stands there.
function paintRug(px, g, L) {
    const cx = g.hearth.cx;
    const cy = g.floorTop + 13;
    const rx = 17;
    const ry = 6;
    if (cy + ry + 4 > g.artH) return;
    if (g.tables.some((t) => Math.abs(t.cx - cx) < t.rx + rx && Math.abs(t.cy - cy) < t.ry + ry + 4)) return;
    if (g.counter && g.counter.x1 < cx + rx + 6 && g.counter.y2 + 2 > cy - ry) return;
    const pts = [];
    for (let y = cy - ry - 3; y <= cy + ry + 3; y++) {
        for (let x = cx - rx - 6; x <= cx + rx + 6; x++) {
            const d = Math.hypot((x - cx) / rx, (y - cy) / ry);
            // Four paws out at the corners and the head toward the hearth.
            const paw = [[-1, -1], [1, -1], [-1, 1], [1, 1]].some(([a, b]) => Math.hypot((x - (cx + a * (rx - 1))) / 4, (y - (cy + b * (ry + 1))) / 2.2) < 1);
            const head = Math.hypot((x - cx) / 5, (y - (cy - ry - 1)) / 3) < 1;
            if (d > 1 && !paw && !head) continue;
            const edge = d > 0.82 && !head && dither(x, y) < 0.5;
            const v = 0.55 - d * 0.25 + L(x, y) * 0.3 + (hash(x >> 1, y, 3) - 0.5) * 0.2;
            pts.push([x, y, edge ? 'barkDark' : shade(FUR, v, x, y)]);
        }
    }
    const rug = new Pix(g.artW, g.artH);
    pts.forEach(([x, y, k]) => rug.set(x, y, k));
    rug.outline('plankDeep');
    rug.set(cx - 2, cy - ry - 2, 'outline');
    rug.set(cx + 2, cy - ry - 2, 'outline');
    rug.set(cx, cy - ry, 'outline');
    rug.k.forEach((k, i) => { if (k) px.k[i] = k; });
}

function paintPosts(px, g, L) {
    const profile = [0.08, 0.55, 0.68, 0.64, 0.56, 0.48, 0.4, 0.3, 0.08];
    for (const p of g.posts) {
        const x0 = p - 4;
        for (let y = g.roof; y <= g.floorTop; y++) {
            profile.forEach((v0, i) => {
                const x = x0 + i;
                let v = v0 + L(x, y) * 0.35;
                // Carved bands near the top and the foot.
                const band = (y - g.roof >= 18 && y - g.roof < 21) || (g.floorTop - y >= 8 && g.floorTop - y < 11);
                if (band && i > 0 && i < 8) v -= 0.18;
                if (i > 0 && i < 8 && hash(i, Math.floor(y / 6), p) > 0.9) v -= 0.08;
                px.set(x, y, i === 0 || i === 8 ? 'outline' : shade(TIMBER, v, x, y));
            });
        }
        // Braces from the post up to the beam, both sides.
        for (const dir of [-1, 1]) {
            for (let k = 0; k < 10; k++) {
                const bx = p + dir * (5 + k);
                const by = g.roof + 15 - k;
                for (let t = 0; t < 3; t++) {
                    const x = bx;
                    const y = by + t;
                    px.set(x, y, t === 0 ? shade(TIMBER, 0.62 + L(x, y) * 0.3, x, y) : t === 2 ? 'outline' : shade(TIMBER, 0.42 + L(x, y) * 0.3, x, y));
                }
            }
        }
    }
}

function paintBeam(px, g, L) {
    const profile = [0.08, 0.6, 0.68, 0.58, 0.46, 0.32, 0.08];
    for (let r = 0; r < profile.length; r++) {
        const y = g.roof + r;
        for (let x = 0; x < g.artW; x++) {
            let v = profile[r] + L(x, y) * 0.3;
            if (r > 0 && r < 6 && hash(Math.floor(x / 11), r, 9) > 0.85) v -= 0.1;
            px.set(x, y, r === 0 || r === 6 ? 'outline' : shade(TIMBER, v, x, y));
        }
    }
}

function paintHearth(px, g, L) {
    const h = g.hearth;
    const x1 = h.cx - Math.floor(h.w / 2);
    const x2 = x1 + h.w - 1;
    const chimney = { x1: h.cx - Math.round(h.w * 0.3), x2: h.cx + Math.round(h.w * 0.3) };
    const opening = { x1: h.cx - (h.ow >> 1), x2: h.cx + (h.ow >> 1), y2: g.floorTop - 2, y1: g.floorTop - 2 - h.oh };
    const archR = h.ow / 2;
    const inOpening = (x, y) => {
        if (x < opening.x1 || x > opening.x2 || y > opening.y2) return false;
        const top = opening.y1 + archR;
        return y >= top || Math.hypot(x - h.cx, y - top) <= archR;
    };
    // Stone blocks: rows of 5, staggered, each block its own value.
    const stone = (x, y, ox) => {
        const row = Math.floor((g.floorTop - y) / 5);
        const ry = (g.floorTop - y) % 5;
        const off = row % 2 ? 4 : 0;
        const bx = Math.floor((x - ox + off) / 8);
        const cx = (x - ox + off) % 8;
        if (ry === 4 || cx === 7) return 'stoneDeep';
        const v = 0.42 + hash(bx, row, 21) * 0.25 + (ry === 3 ? 0.1 : 0) + (cx === 0 ? 0.06 : 0) + L(x, y) * 0.35;
        return shade(STONE, v, x, y);
    };
    for (let y = g.roof; y < g.floorTop; y++) {
        for (let x = x1 - 1; x <= x2 + 1; x++) {
            const body = y >= h.top;
            const inChimney = x >= chimney.x1 && x <= chimney.x2;
            if (!body && !inChimney) continue;
            const edge = body ? x === x1 - 1 || x === x2 + 1 || y === h.top : x === chimney.x1 || x === chimney.x2;
            if (body && (x === x1 - 1 || x === x2 + 1) && y < h.top) continue;
            px.set(x, y, edge ? 'outline' : stone(x, y, x1));
        }
    }
    // The firebox: soot-dark inside, back bricks barely lit, the arch rimmed by the outline.
    for (let y = opening.y1; y <= opening.y2; y++) {
        for (let x = opening.x1; x <= opening.x2; x++) {
            if (!inOpening(x, y)) continue;
            const rim = !inOpening(x - 1, y) || !inOpening(x + 1, y) || !inOpening(x, y - 1);
            const depth = (y - opening.y1) / h.oh;
            const v = depth * 0.3 + ((x + (Math.floor(y / 3) % 2) * 2) % 5 === 0 || y % 3 === 0 ? -0.1 : 0);
            px.set(x, y, rim ? 'outline' : shade(STONE, v, x, y));
        }
    }
    // Logs in the fire with embers along them (emissive: they stay hot in any light).
    for (let k = -1; k <= 1; k += 2) {
        for (let i = 0; i < h.ow - 6; i++) {
            const x = opening.x1 + 3 + i;
            const y = opening.y2 - 1 - Math.round((k > 0 ? i : h.ow - 6 - i) * 0.18);
            px.set(x, y, 'timberDark');
            px.set(x, y + 1, 'timberDeep');
            if (hash(x, y, 4) > 0.6) px.set(x, y, 'ember');
        }
    }
    for (let x = opening.x1; x <= opening.x2; x++) px.set(x, opening.y2 + 1, hash(x, 1, 5) > 0.5 ? 'ember' : 'ashDark');
    // Mantel beam across the hearth.
    const my = h.mantel;
    for (let x = x1 - 4; x <= x2 + 4; x++) {
        px.set(x, my - 1, 'outline');
        px.set(x, my, shade(TIMBER, 0.66 + L(x, my) * 0.3, x, my));
        px.set(x, my + 1, shade(TIMBER, 0.5 + L(x, my) * 0.3, x, my + 1));
        px.set(x, my + 2, shade(TIMBER, 0.34 + L(x, my) * 0.3, x, my + 2));
        px.set(x, my + 3, 'outline');
    }
    px.set(x1 - 5, my, 'outline');
    px.set(x2 + 5, my, 'outline');
    // On the mantel: a skull, two candle stubs and a little jack-o'-lantern.
    stampOutlined(px, h.cx - 3, my - 6, ['.ccc.', 'ccccc', 'cKcKc', 'ccscc', '.sss.'], { c: 'cream', s: 'seedShade', K: 'outline' });
    for (const dx of [-Math.round(h.w * 0.32), Math.round(h.w * 0.32)]) {
        stampOutlined(px, h.cx + dx, my - 4, ['f', 'c', 'c', 'c'], { f: 'flameCore', c: 'cream' });
    }
    stampOutlined(px, h.cx + Math.round(h.w * 0.18) - 2, my - 5, ['..t..', '.ooo.', 'oEoEo', 'ooooo', '.EEE.'], { t: 'stem', o: 'pumpkin', E: 'ember' });
    // A stone apron on the floor in front.
    for (let x = x1 - 3; x <= x2 + 3; x++) {
        for (let y = g.floorTop; y <= g.floorTop + 3; y++) {
            const k = y === g.floorTop + 3 || x === x1 - 3 || x === x2 + 3 ? 'outline' : stone(x, y + 2, x1);
            px.set(x, y, k);
        }
    }
    // Antlers on the chimney: a pale skull and branching tines.
    const ay = Math.max(g.roof + 16, my - 22);
    const ax = h.cx;
    const antler = new Pix(px.w, px.h);
    const tine = (pts) => pts.forEach(([x, y]) => antler.set(ax + x, ay + y, 'cream'));
    for (const s of [-1, 1]) {
        tine([[s * 2, 0], [s * 3, -1], [s * 4, -2], [s * 5, -3], [s * 6, -4], [s * 7, -6], [s * 8, -7], [s * 9, -8], [s * 10, -10], [s * 10, -11]]);
        tine([[s * 5, -4], [s * 5, -5], [s * 4, -6], [s * 4, -7]]);
        tine([[s * 8, -8], [s * 7, -9], [s * 7, -10], [s * 6, -11]]);
        tine([[s * 9, -9], [s * 11, -8], [s * 12, -8], [s * 13, -9]]);
    }
    antler.stamp(ax - 2, ay - 1, ['.ccc.', 'ccccc', 'cKcKc', '.csc.', '..s..'], { c: 'cream', s: 'seedShade', K: 'outline' });
    antler.outline();
    antler.k.forEach((k, i) => { if (k) px.k[i] = k; });
}

function stampOutlined(px, x0, y0, rows, legend) {
    const w = Math.max(...rows.map((r) => r.length));
    const p = new Pix(w + 2, rows.length + 2);
    p.stamp(1, 1, rows, legend);
    p.outline();
    p.k.forEach((k, i) => {
        if (k) px.set(x0 - 1 + (i % p.w), y0 - 1 + Math.floor(i / p.w), k);
    });
}

const SHIELDS = [
    { a: 'crimson', b: 'gold', pattern: 'halves' },
    { a: 'shieldBlue', b: 'cream', pattern: 'quarters' },
    { a: 'shieldGreen', b: 'gold', pattern: 'cross' },
    { a: 'gold', b: 'crimsonDark', pattern: 'swirl' },
    { a: 'cream', b: 'shieldBlue', pattern: 'halves' },
];

function paintShield(px, cx, cy, r, kind, L) {
    const s = SHIELDS[kind % SHIELDS.length];
    for (let y = cy - r - 1; y <= cy + r + 1; y++) {
        for (let x = cx - r - 1; x <= cx + r + 1; x++) {
            const d = Math.hypot(x - cx, y - cy);
            if (d > r + 0.5) continue;
            const dx = x - cx;
            const dy = y - cy;
            let key;
            if (d > r - 0.6) key = 'outline';
            else if (d > r - 1.6) key = 'iron';
            else if (d < 1.8) key = d < 0.8 ? 'grayLight' : 'ironLight';
            else if (d < 2.6) key = 'outline';
            else {
                const pick = s.pattern === 'halves' ? dx < 0
                    : s.pattern === 'quarters' ? (dx < 0) !== (dy < 0)
                        : s.pattern === 'cross' ? Math.abs(dx) < 1.5 || Math.abs(dy) < 1.5
                            : Math.sin(Math.atan2(dy, dx) * 3 + d * 0.9) > 0;
                key = pick ? s.a : s.b;
                // Plank seams through the paint and a lit upper-left.
                if ((x - cx + 40) % 4 === 0) key = darker(key);
                else if (dx + dy < -r * 0.6 && dither(x, y) < 0.4 + L(x, y) * 0.4) key = lighter(key);
            }
            px.set(x, y, key);
        }
    }
}

const DARKER = { crimson: 'crimsonDark', gold: 'pumpkinGold', shieldBlue: 'denimDark', cream: 'seedShade', shieldGreen: 'leafDark', crimsonDark: 'pumpkinDeep' };
const LIGHTER = { crimson: 'comb', gold: 'pumpkinShine', shieldBlue: 'denim', cream: 'white', shieldGreen: 'leaf', crimsonDark: 'crimson' };
const darker = (k) => DARKER[k] ?? k;
const lighter = (k) => LIGHTER[k] ?? k;

// Glyphs for the sign over the counter.
const GLYPHS = {
    М: ['X...X', 'XX.XX', 'X.X.X', 'X...X', 'X...X'],
    Ё: ['X.X', '...', 'XXX', 'X..', 'XX.', 'X..', 'XXX'],
    Д: ['.XXX.', '.X.X.', '.X.X.', 'XXXXX', 'X...X'],
};

function paintSign(px, cx, y, L) {
    const word = ['М', 'Ё', 'Д'];
    const w = word.reduce((s, ch) => s + GLYPHS[ch][0].length + 1, -1) + 6;
    const h = 11;
    const x0 = Math.round(cx - w / 2);
    // The board hangs on two iron pegs.
    for (let yy = 0; yy < h; yy++) {
        for (let xx = 0; xx < w; xx++) {
            const edge = yy === 0 || yy === h - 1 || xx === 0 || xx === w - 1;
            const v = 0.66 - (yy / h) * 0.2 + L(x0 + xx, y + yy) * 0.25 + (yy % 4 === 3 ? -0.12 : 0);
            px.set(x0 + xx, y + yy, edge ? 'outline' : shade(TIMBER, v, x0 + xx, y + yy));
        }
    }
    let x = x0 + 3;
    for (const ch of word) {
        const g = GLYPHS[ch];
        const top = y + h - 2 - g.length;
        g.forEach((row, r) => [...row].forEach((c, i) => { if (c === 'X') px.set(x + i, top + r, 'timberDeep'); }));
        x += g[0].length + 1;
    }
    px.set(x0 + 2, y - 1, 'iron');
    px.set(x0 + w - 3, y - 1, 'iron');
}

function paintShelves(px, g, L) {
    const c = g.counter;
    if (!c) return;
    const x1 = c.x1 + 3;
    const x2 = c.x2 - 3;
    const levels = [g.floorTop - 30, g.floorTop - 44];
    levels.forEach((y, li) => {
        for (let x = x1 - 2; x <= x2 + 2; x++) {
            px.set(x, y, shade(TIMBER, 0.64 + L(x, y) * 0.3, x, y));
            px.set(x, y + 1, shade(TIMBER, 0.36 + L(x, y) * 0.3, x, y + 1));
            px.set(x, y + 2, 'outline');
        }
        px.set(x1 - 3, y, 'outline');
        px.set(x2 + 3, y, 'outline');
        // Brackets under the shelf.
        for (const bx of [x1 + 2, x2 - 2]) for (let k = 0; k < 3; k++) px.set(bx, y + 2 + k, 'iron');
        // Mugs, bottles and a candle along the shelf.
        let x = x1;
        let i = 0;
        while (x < x2 - 4) {
            const kind = Math.floor(hash(i, li, g.seed + 40) * 5);
            if (kind <= 1) {
                stampOutlined(px, x, y - 5, ['gggg', 'gaag', 'gaag', 'gggg'], { g: 'grayLight', a: 'gray' });
                px.set(x + 4, y - 3, 'grayLight');
                x += 7;
            } else if (kind === 2) {
                const k = hash(i, li, 9) > 0.5 ? 'shieldGreen' : 'crimsonDark';
                stampOutlined(px, x, y - 7, ['.c.', '.k.', 'kkk', 'kwk', 'kkk', 'kkk'], { c: 'cream', k, w: 'white' });
                x += 5;
            } else if (kind === 3) {
                stampOutlined(px, x, y - 6, ['..s..', '.ooo.', 'oEoEo', 'ooooo', '.EEE.'], { s: 'stem', o: 'pumpkinDark', E: 'ember' });
                x += 7;
            } else {
                stampOutlined(px, x, y - 5, ['.cc.', 'cKKc', 'cccc', '.cc.'], { c: 'cream', K: 'outline' });
                x += 6;
            }
            i++;
        }
    });
    paintSign(px, (c.x1 + c.x2) / 2, g.floorTop - 62, L);
    // A big barrel stands against the wall at the counter's far end, its foot hidden by the counter.
    const bx = c.x2 - 10;
    paintStandingBarrel(px, bx, g.floorTop + 4, 14, 19, L);
}

function paintStandingBarrel(px, cx, foot, w, h, L) {
    const p = barrelPix(w, h, (x, y) => L(cx - (w >> 1) + x, foot - h + y));
    p.k.forEach((k, i) => { if (k) px.set(cx - (p.w >> 1) + (i % p.w), foot - p.h + 1 + Math.floor(i / p.w), k); });
}

/** A standing barrel w × h: bulging staves, two iron hoops, a dark lid. light(x, y) in its own pixels. */
function barrelPix(w, h, light = () => 0) {
    const p = new Pix(w + 2, h + 2);
    const cx = (w + 1) / 2;
    for (let y = 1; y <= h; y++) {
        const t = (y - 1) / (h - 1);
        const bulge = Math.sin(t * Math.PI) * 1.2;
        const half = w / 2 - 1.2 + bulge;
        for (let x = 1; x <= w; x++) {
            const dx = (x + 0.5 - cx - 0.5) / half;
            if (Math.abs(dx) > 1) continue;
            let key;
            if (y <= 2) key = y === 1 ? 'timberDark' : 'timberDeep';
            else if (Math.abs(y - 1 - Math.round(h * 0.22)) < 1 || Math.abs(y - 1 - Math.round(h * 0.78)) < 1) key = dx < -0.4 ? 'ironLight' : 'iron';
            else {
                const stave = Math.round(dx * 3.2) !== Math.round((dx + 0.08) * 3.2);
                const v = 0.62 - dx * 0.28 - Math.abs(dx) ** 3 * 0.25 + light(x, y) * 0.3 - (stave ? 0.16 : 0);
                key = flat(TIMBER, v);
            }
            p.set(x, y, key);
        }
    }
    return p.outline();
}

function paintBanner(px, cx, top, h, color, L) {
    const w = 11;
    const x0 = cx - (w >> 1);
    // The rod and the cloth with a swallowtail hem and a gold rune.
    for (let x = x0 - 2; x <= x0 + w + 1; x++) {
        px.set(x, top - 1, 'outline');
        px.set(x, top, 'timberDark');
        px.set(x, top + 1, 'outline');
    }
    for (let y = top + 2; y < top + h; y++) {
        const fromEnd = top + h - 1 - y;
        for (let x = x0; x < x0 + w; x++) {
            const i = x - x0;
            if (fromEnd < 3 && Math.abs(i - (w >> 1)) < 3 - fromEnd) continue;
            const edge = i === 0 || i === w - 1;
            const fold = i % 4 === 1 ? darker(color) : color;
            px.set(x, y, edge ? 'outline' : dither(x, y) < L(x, y) * 0.5 ? lighter(fold) : fold);
        }
        px.set(x0, y, 'outline');
        px.set(x0 + w - 1, y, 'outline');
    }
    // The hem's last cloth row darkens into an edge.
    for (let x = x0; x < x0 + w; x++) {
        const y = top + h - 1 - Math.max(0, 2 - Math.abs(x - x0 - (w >> 1)));
        if (px.get(x, y)) px.set(x, y, 'outline');
    }
    // A rune: ᛉ (algiz), the elk-sedge of protection.
    const ry = top + 5;
    const rune = [[0, 0], [0, 1], [0, 2], [0, 3], [0, 4], [0, 5], [-1, 1], [-2, 0], [1, 1], [2, 0]];
    rune.forEach(([dx, dy]) => px.set(cx + dx, ry + dy, 'gold'));
}

function paintChandelier(px, c) {
    // Chain from the beam, an iron ring seen a little from below, candles on it (their flames are barLights).
    for (let y = c.top; y < c.y - 4; y++) px.set(c.x, y, (y - c.top) % 2 ? 'ironLight' : 'iron');
    for (const s of [-1, 1]) for (let k = 0; k < 4; k++) px.set(c.x + s * (3 + k * 3), c.y - 4 + k, 'iron');
    for (let a = 0; a < 64; a++) {
        const t = (a / 64) * Math.PI * 2;
        const x = Math.round(c.x + Math.cos(t) * 13);
        const y = Math.round(c.y + Math.sin(t) * 2.5);
        px.set(x, y, Math.sin(t) > 0 ? 'iron' : 'ironLight');
        px.set(x, y + 1, 'outline');
    }
    for (const dx of CHANDELIER_CANDLES) {
        for (let k = 1; k <= 3; k++) px.set(c.x + dx, c.y - k, 'cream');
        px.set(c.x + dx - 1, c.y - 2, 'outline');
        px.set(c.x + dx + 1, c.y - 2, 'outline');
    }
}
const CHANDELIER_CANDLES = [-12, -6, 0, 6, 12];

function paintTorchSconce(px, t) {
    // An iron bracket and a short tarred stick; the flame is a barLight over the head.
    const p = new Pix(px.w, px.h);
    for (let k = 0; k < 4; k++) p.set(t.x - 1 + k, t.y + 6 - Math.floor(k / 2), 'iron');
    for (let k = 0; k < 7; k++) {
        p.set(t.x, t.y + k, k < 2 ? 'hayDark' : 'timberDark');
        p.set(t.x + 1, t.y + k, k < 2 ? 'hayDark' : 'timber');
    }
    p.set(t.x - 1, t.y + 1, 'iron');
    p.set(t.x + 2, t.y + 1, 'iron');
    p.outline();
    p.k.forEach((k, i) => { if (k) px.k[i] = k; });
}

function paintDecor(px, g, L, rand) {
    paintShelves(px, g, L);
    const chand = chandeliers(g);
    const torches = torchSpots(g);
    for (const t of torches) paintTorchSconce(px, t);
    // Shields along the eye band of every free bay, banners higher up; garlands of tiny pumpkins under the beam.
    let kind = Math.floor(rand() * SHIELDS.length);
    const band = g.floorTop - 25;
    for (const b of g.bays) {
        if (g.hasCounter(b)) continue;
        const hearth = b === g.hearth.bay;
        const blocked = (x) => torches.some((t) => Math.abs(t.x - x) < 12)
            || (hearth && Math.abs(x - g.hearth.cx) < g.hearth.w / 2 + 12)
            || (g.counter && x > g.counter.x1 - 12 && x < g.counter.x2 + 12);
        for (let x = b.x1 + 16; x <= b.x2 - 16; x += 24) {
            if (blocked(x)) continue;
            paintShield(px, x, band + ((x / 24) % 2 ? -3 : 0), 8, kind++, L);
        }
        const upper = { y1: g.roof + 18, y2: band - 16 };
        if (!hearth && upper.y2 - upper.y1 > 20) {
            const hasChand = chand.some((c) => c.x > b.x1 && c.x < b.x2);
            const xs = hasChand ? [b.cx - 34, b.cx + 34] : [b.cx - 20, b.cx + 20];
            xs.forEach((x, i) => {
                if (x < 8 || x > g.artW - 8) return;
                paintBanner(px, Math.round(x), upper.y1, Math.min(48, upper.y2 - upper.y1), i ? 'shieldBlue' : 'crimson', L);
            });
        }
        if (hearth && upper.y2 - upper.y1 > 24) {
            // Two shields flank the chimney.
            const dx = Math.round(g.hearth.w * 0.3) + 13;
            const y = Math.round((upper.y1 + upper.y2) / 2);
            [g.hearth.cx - dx, g.hearth.cx + dx].forEach((x) => {
                if (x > b.x1 + 10 && x < b.x2 - 10) paintShield(px, x, y, 8, kind++, L);
            });
        }
        if (!hearth) paintGarland(px, b, g);
    }
    // Over the counter's sign, on a tall wall: banners too.
    for (const b of g.bays.filter((bay) => g.hasCounter(bay))) {
        const y1 = g.roof + 18;
        const y2 = g.floorTop - 68;
        if (y2 - y1 <= 30) continue;
        [b.cx - 20, b.cx + 20].forEach((x, i) => {
            if (x >= 8 && x <= g.artW - 8) paintBanner(px, Math.round(x), y1, Math.min(48, y2 - y1), i ? 'shieldGreen' : 'crimson', L);
        });
    }
    for (const c of chand) paintChandelier(px, c);
    paintCobwebs(px, g);
    paintBats(px, g, rand);
}

function paintGarland(px, b, g) {
    const x1 = b.x1 + 14;
    const x2 = b.x2 - 14;
    if (x2 - x1 < 30) return;
    const y0 = g.roof + 8;
    const sag = Math.min(9, (x2 - x1) / 10);
    const at = (x) => y0 + Math.round(sag * Math.sin(((x - x1) / (x2 - x1)) * Math.PI));
    for (let x = x1; x <= x2; x++) px.set(x, at(x), 'hayDark');
    for (let x = x1 + 6; x <= x2 - 4; x += 10) {
        const y = at(x) + 1;
        stampOutlined(px, x - 1, y, ['.t.', 'ooo', 'ooo'], { t: 'stem', o: (x / 10) % 2 ? 'pumpkin' : 'pumpkinLight' });
    }
}

function paintCobwebs(px, g) {
    // Webs in the corners where the braces meet the beam: radial threads and two sagging rings.
    for (const p of g.posts) {
        for (const s of [-1, 1]) {
            const ox = p + s * 15;
            const oy = g.roof + 7;
            if (ox < 0 || ox > g.artW) continue;
            for (let k = 0; k < 9; k++) {
                px.set(ox - s * k, oy, k % 2 ? null : 'grayLight');
            }
            for (let r = 3; r <= 7; r += 4) {
                for (let k = 0; k <= r; k++) {
                    if (k % 2) continue;
                    px.set(ox - s * (r - k), oy + Math.round(k * 0.7), 'gray');
                }
            }
        }
    }
}

function paintBats(px, g, rand) {
    // Bats asleep under the beam, hanging upside down.
    const n = Math.max(2, Math.round(g.artW / 160));
    for (let i = 0; i < n; i++) {
        const x = Math.round(g.artW * ((i + 0.3 + rand() * 0.4) / n));
        if (g.posts.some((p) => Math.abs(p - x) < 16)) continue;
        stampOutlined(px, x - 2, g.roof + 7, ['.b.b.', 'bbbbb', '.bBb.', '.bbb.', '..b..'], { b: 'bat', B: 'eyeRed' });
    }
}

function paintShadows(px, g) {
    // Contact shadows of the furniture on the floor: dithered, one ramp step down.
    const DOWN = { plankLight: 'plank', plank: 'plankDark', plankDark: 'plankDeep', plankDeep: 'outline', timberLight: 'plankLight' };
    const darken = (x, y, density) => {
        const k = px.get(x, y);
        if (k && DOWN[k] && dither(x, y) < density) px.set(x, y, DOWN[k]);
    };
    for (const t of g.tables) {
        const p = tableParts(t);
        const cy = p.front - p.depth / 2;
        const rx = p.w / 2 + 3;
        const ry = p.depth / 2 + 3;
        for (let y = Math.floor(cy - ry); y <= cy + ry + 2; y++) {
            for (let x = Math.floor(t.cx - rx); x <= t.cx + rx; x++) {
                const d = Math.hypot((x - t.cx) / rx, (y - cy - 1) / ry);
                if (d < 1) darken(x, y, 0.9 - d * 0.5);
            }
        }
    }
    if (g.counter) {
        for (let y = g.counter.y2; y <= g.counter.y2 + 3; y++) {
            for (let x = g.counter.x1 - 1; x <= g.counter.x2 + 1; x++) darken(x, y, 0.9 - (y - g.counter.y2) * 0.22);
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Props: furniture sorted with the walkers.

/** Furniture as depth-sorted sprites: [{kind, canvas, x, y, anchor, glow?, phase?}], see the module doc. */
export function barProps(layout, artW, artH, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    const color = litKey(lightingOf(opts));
    const out = [];
    g.tables.forEach((t, i) => {
        const p = tableParts(t);
        for (const b of p.benches) out.push(benchProp(b, color));
        out.push(tableProp(t, p, i, g, color));
    });
    for (const r of g.rects.slice(1)) out.push(...barrelStack(r, color));
    out.push(...wallBarrels(g, color));
    if (g.counter) out.push(counterProp(g, color));
    return out;
}

/** The floor under the furniture standing on the walkers' floor: [{kind, x1, y1, x2, y2}], see the module doc. */
export function barFootprints(layout, artW, artH, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    const out = [];
    for (const t of g.tables) {
        const p = tableParts(t);
        out.push({ kind: 'table', x1: p.x1, y1: p.back, x2: p.x1 + p.w, y2: p.front });
        for (const b of p.benches) out.push({ kind: 'bench', x1: b.x1, y1: b.front - BENCH_D, x2: b.x1 + b.w, y2: b.front });
    }
    for (const r of g.rects.slice(1)) {
        for (const b of barrelSpots(r)) out.push({ kind: 'barrel', x1: b.x - 5, y1: b.y - 4, x2: b.x + 6, y2: b.y });
    }
    if (g.counter) out.push({ kind: 'counter', x1: g.counter.x1, y1: g.counter.y2 - g.counter.depth, x2: g.counter.x2, y2: g.counter.y2 });
    return out;
}

function propOf(kind, pix, ox, oy, x, y, color, extra = {}) {
    return { kind, canvas: pix.canvas(color), x, y, anchor: { x: x - ox, y: y - oy }, ...extra };
}

function benchProp(b, color) {
    const ox = b.x1 - 1;
    const oy = b.front - BENCH_H - BENCH_D - 1;
    const p = new Pix(b.w + 2, BENCH_H + BENCH_D + 2);
    const set = (x, y, k) => p.set(x - ox, y - oy, k);
    for (let y = b.front - BENCH_H - BENCH_D; y < b.front - BENCH_H; y++) {
        for (let x = b.x1; x < b.x1 + b.w; x++) set(x, y, y === b.front - BENCH_H - BENCH_D ? 'timberLight' : 'timber');
    }
    for (let x = b.x1; x < b.x1 + b.w; x++) set(x, b.front - BENCH_H, 'timberDark');
    for (const lx of [b.x1 + 1, b.x1 + b.w - 3]) {
        for (let y = b.front - BENCH_H + 1; y < b.front; y++) {
            set(lx, y, 'timber');
            set(lx + 1, y, 'timberDeep');
        }
    }
    p.outline();
    return propOf('bench', p, ox, oy, Math.round(b.x1 + b.w / 2), b.front, color);
}

function tableProp(t, parts, index, g, color) {
    const { x1, w, front, depth } = parts;
    const topY = front - TABLE_H - depth;
    const ox = x1 - 1;
    const oy = topY - 9;
    const p = new Pix(w + 2, depth + TABLE_H + 10);
    const set = (x, y, k) => p.set(x - ox, y - oy, k);
    // Back legs show under the slab only when the table is shallow.
    for (const lx of [x1 + 3, x1 + w - 5]) for (let y = front - TABLE_H + 2; y < front - depth; y++) set(lx, y, 'timberDeep');
    // The slab: planks along its length, the corners rounded.
    for (let y = topY; y < front - TABLE_H; y++) {
        const r = y - topY;
        for (let x = x1; x < x1 + w; x++) {
            if ((r === 0 || y === front - TABLE_H - 1) && (x === x1 || x === x1 + w - 1)) continue;
            const seam = (depth - 1 - r) % 4 === 3;
            const knot = hash(Math.floor((x + r * 7) / 5), r, index + 50) > 0.93;
            set(x, y, seam ? 'timberDark' : r === 0 || (depth - 1 - r) % 4 === 2 ? 'timberLight' : knot ? 'timberDark' : 'timber');
        }
    }
    for (let x = x1; x < x1 + w; x++) {
        set(x, front - TABLE_H, x === x1 || x === x1 + w - 1 ? 'timberDark' : 'timberLight');
        set(x, front - TABLE_H + 1, 'timberDark');
    }
    for (const lx of [x1 + 1, x1 + w - 4]) {
        for (let y = front - TABLE_H + 2; y < front; y++) {
            set(lx, y, 'timber');
            set(lx + 1, y, 'timberDark');
            set(lx + 2, y, 'timberDeep');
        }
    }
    for (let x = x1 + 4; x < x1 + w - 4; x++) set(x, front - 3, 'timberDeep');
    p.outline();
    // What stands on the table: a candle or a jack-o'-lantern in the middle, mugs, a plate, now and then a skull.
    const midY = topY + Math.floor(depth / 2) + 1;
    const cx = Math.round(t.cx);
    const pick = hash(index, 3, g.seed + 70);
    const items = new Pix(p.w, p.h);
    const put = (x0, y0, rows, legend) => {
        const q = new Pix(rows[0].length + 2, rows.length + 2);
        q.stamp(1, 1, rows, legend);
        q.outline();
        q.k.forEach((k, i) => { if (k) items.set(x0 - 1 - ox + (i % q.w), y0 - 1 - oy + Math.floor(i / q.w), k); });
    };
    let flame = null;
    if (pick < 0.5) {
        put(cx - 1, midY - 4, ['cc', 'cc', 'cs', 'gg'], { c: 'cream', s: 'seedShade', g: 'gray' });
        flame = { x: cx, y: midY - 5 };
    } else {
        put(cx - 3, midY - 6, ['...t...', '.ooooo.', 'ooEoEoo', 'ooooooo', 'oEEEEEo', '.ooooo.'], { t: 'stem', o: 'pumpkin', E: 'ember' });
    }
    const side = hash(index, 5, g.seed) > 0.5 ? 1 : -1;
    put(cx + side * Math.round(w * 0.28) - 1, midY - 3, ['wcw', 'ggg', 'gYg', 'ggg'], { w: 'white', c: 'cream', g: 'grayLight', Y: 'pumpkinLight' });
    put(cx - side * Math.round(w * 0.3) - 2, midY - 1, ['.ccc.', 'gBBbg'], { c: 'cream', B: 'barkLight', b: 'bark', g: 'grayLight' });
    if (pick > 0.75 && w > 30) put(cx + side * Math.round(w * 0.12), midY - 4, ['.ccc.', 'ccccc', 'cKcKc', '.csc.'], { c: 'cream', s: 'seedShade', K: 'outline' });
    items.k.forEach((k, i) => { if (k) p.k[i] = k; });
    const extra = {};
    if (flame) {
        extra.glow = CANDLE_FLAMES.map((rows) => {
            const f = new Pix(p.w, p.h);
            f.stamp(flame.x - ox - 1, flame.y - oy - rows.length + 1, rows, { Y: 'pumpkinLight', y: 'pumpkinShine', w: 'flameCore' });
            return f.canvas(raw);
        });
        extra.phase = index * 1.7;
    }
    return propOf('table', p, ox, oy, cx, front, color, extra);
}
// The candle flame of the walkers' candle (characters.js): steady, stretched tall, bent.
const CANDLE_FLAMES = [
    ['...', '.Y.', 'YwY', '.y.'],
    ['.Y.', '.Y.', 'YwY', '.y.'],
    ['...', 'Y..', 'ywY', '.y.'],
];

function barrelProp(cx, foot, w, h, color, kind = 'barrel') {
    const p = barrelPix(w, h);
    const ox = cx - (p.w >> 1);
    const oy = foot - p.h + 1;
    return propOf(kind, p, ox, oy, cx, foot, color);
}

// The feet of a barrel stack in a rectangle, kept off its sides like the tables so walkers round it don't overlap them.
function barrelSpots(r) {
    const out = [];
    for (let x = r.x1 + WALKER_HALF + 6; x <= r.x2 - WALKER_HALF - 5; x += 12) out.push({ x, y: r.y2 - FOOT_GAP });
    return out;
}

function barrelStack(r, color) {
    return barrelSpots(r).map((b) => barrelProp(b.x, b.y, 11, 14, color));
}

// Barrels against the back wall where nothing else stands: the corner by the left wall, and between the hearth and
// the counter when there is room.
function wallBarrels(g, color) {
    const foot = g.floorTop + 6;
    if (foot > g.area.y1 + 2) return [];
    const out = [];
    const taken = [[g.hearth.cx - g.hearth.w / 2 - 6, g.hearth.cx + g.hearth.w / 2 + 6]];
    if (g.counter) taken.push([g.counter.x1 - 4, g.counter.x2 + 4]);
    for (const p of torchSpots(g)) taken.push([p.x - 3, p.x + 3]);
    const freeAt = (x1, x2) => x1 > 2 && x2 < g.artW - 2 && !taken.some(([a, b]) => x1 < b && x2 > a);
    const left = Math.max(4, g.area.x1 - 4);
    if (freeAt(left, left + 26)) {
        out.push(barrelProp(left + 7, foot, 12, 15, color), barrelProp(left + 20, foot + 1, 12, 15, color));
        taken.push([left, left + 26]);
    }
    if (g.counter) {
        const mid = Math.round((g.hearth.cx + g.hearth.w / 2 + g.counter.x1) / 2);
        if (freeAt(mid - 8, mid + 8)) out.push(barrelProp(mid, foot, 12, 15, color));
    }
    return out;
}

function counterProp(g, color) {
    const c = g.counter;
    const keg = c.keg;
    const top = Math.min(keg.y1 - 8, c.back - 2);
    const ox = c.x1 - 1;
    const oy = top - 1;
    const p = new Pix(Math.max(c.x2, keg.x2 + 1) - c.x1 + 3, c.y2 - top + 2);
    const set = (x, y, k) => p.set(x - ox, y - oy, k);
    // Top: polished planks along the counter, a light lip at the front.
    for (let y = c.back; y < c.y2 - COUNTER_H; y++) {
        const r = y - c.back;
        for (let x = c.x1; x <= c.x2; x++) {
            const seam = (c.depth - 1 - r) % 4 === 3;
            const shine = (x + y * 2) % 23 < 3 && r > 0 && !seam;
            set(x, y, seam ? 'timberDark' : shine ? 'timberShine' : (c.depth - 1 - r) % 4 === 2 ? 'timberLight' : 'timber');
        }
    }
    for (let x = c.x1; x <= c.x2; x++) {
        set(x, c.y2 - COUNTER_H, 'timberShine');
        set(x, c.y2 - COUNTER_H + 1, 'timberDark');
    }
    // Front: upright planks with two iron bands, riveted, and a dark plinth.
    for (let y = c.face; y <= c.y2; y++) {
        for (let x = c.x1; x <= c.x2; x++) {
            const i = (x - c.x1) % 5;
            const band = y === c.face + 2 || y === c.y2 - 3;
            let k;
            if (y >= c.y2 - 1) k = 'timberDeep';
            else if (band) k = i === 2 ? 'ironLight' : 'iron';
            else if (i === 4) k = 'timberDeep';
            else k = i === 0 ? 'timberLight' : i === 3 ? 'timberDark' : 'timber';
            set(x, y, k);
        }
    }
    // A carved knot in the middle of the front.
    const kx = Math.round((c.x1 + c.x2) / 2);
    const ky = Math.round((c.face + 2 + c.y2 - 3) / 2);
    [[-2, 0], [-1, -1], [0, -1], [1, 0], [0, 1], [-1, 1], [2, 0], [3, -1], [3, 1]].forEach(([dx, dy]) => set(kx + dx, ky + dy, 'gold'));
    // The keg lying on its cradle on the counter, hoops across, the tap's spout and lever on its left end.
    const kw = keg.x2 - keg.x1 + 1;
    const kh = keg.y2 - keg.y1;
    for (let y = keg.y1; y < keg.y2; y++) {
        for (let x = keg.x1; x <= keg.x2; x++) {
            const u = (x - keg.x1) / (kw - 1);
            const bulge = Math.sin(u * Math.PI) * 1.4;
            const half = kh / 2 - 1.4 + bulge;
            const dy = (y + 0.5 - (keg.y1 + kh / 2)) / half;
            if (Math.abs(dy) > 1) continue;
            const hoop = Math.abs(u - 0.2) < 0.06 || Math.abs(u - 0.8) < 0.06;
            const stave = Math.round(dy * 2.5) !== Math.round((dy + 0.1) * 2.5);
            const k = hoop ? (dy < -0.3 ? 'ironLight' : 'iron') : flat(TIMBER, 0.66 - dy * 0.3 - (stave ? 0.2 : 0) - (u < 0.06 || u > 0.94 ? 0.2 : 0));
            set(x, y, k);
        }
    }
    for (let x = keg.x1 + 1; x <= keg.x2 - 1; x++) set(x, keg.y2, 'timberDark');
    const tap = { x: g.owl.x + OWL_BAR.tap.x, y: g.owl.y + OWL_BAR.tap.y };
    set(tap.x, tap.y, 'gray');
    set(tap.x, tap.y - 1, 'grayLight');
    set(tap.x, tap.y - 2, 'grayLight');
    for (let x = tap.x + 1; x < keg.x1; x++) set(x, tap.y - 2, x === keg.x1 - 1 ? 'gold' : 'grayLight');
    for (let y = tap.y - 6; y <= tap.y - 3; y++) set(tap.x + 1, y, y === tap.y - 6 ? 'crimson' : 'timberDark');
    // A jack-o'-lantern on the counter's free end.
    const jx = c.x1 + 3;
    const jack = ['...t...', '.ooooo.', 'ooEoEoo', 'ooooooo', 'oEEEEEo', '.ooooo.'];
    jack.forEach((row, r) => [...row].forEach((ch, i) => set(jx + i, c.back - jack.length + 2 + r, { t: 'stem', o: 'pumpkinDark', E: 'ember' }[ch])));
    p.outline();
    return propOf('counter', p, ox, oy, Math.round((c.x1 + c.x2) / 2), c.y2, color);
}

// ---------------------------------------------------------------------------------------------------------------
// Lights.

const flameCache = new Map();

/** Flame frames for a w × h box, the tongues standing on its bottom centre. */
function flameFrames(kind, w, h) {
    const key = `${kind}:${w}:${h}`;
    if (flameCache.has(key)) return flameCache.get(key);
    const tongues = kind === 'hearth'
        ? [-0.32, -0.12, 0.1, 0.3].map((u, i) => ({ x: w / 2 + u * w, y: h - 1, len: h * (0.62 + (i % 2) * 0.3), wid: w * 0.32, ang: u * 0.4, phase: i * 1.9, sway: 1 }))
        : [{ x: w / 2, y: h - 1, len: h * 0.9, wid: w * 0.7, ang: 0, phase: 0.7, sway: 1.1 }];
    const frames = [0, 1, 2, 3].map((f) => {
        const grid = Array.from({ length: h }, () => Array(w).fill('.'));
        paintFlames(grid, tongues, f);
        const p = new Pix(w, h);
        grid.forEach((row, y) => row.forEach((ch, x) => p.set(x, y, FLAME_KEYS[ch])));
        return p.canvas(raw);
    });
    flameCache.set(key, frames);
    return frames;
}

/** The wall flames: [{kind, x, y, frames, anchor, phase, halo}], see the module doc. */
export function barLights(layout, artW, artH, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    const out = [];
    const hw = g.hearth.ow - 2;
    const hh = Math.round(g.hearth.oh * 0.8);
    out.push({
        kind: 'hearth',
        x: g.hearth.cx,
        y: g.floorTop - 3,
        frames: flameFrames('hearth', hw, hh),
        anchor: { x: hw >> 1, y: hh - 1 },
        phase: 0,
        halo: { x: g.hearth.cx, y: g.floorTop + 6, rx: Math.round(g.hearth.w * 0.9), ry: 9 },
    });
    torchSpots(g).forEach((t, i) => out.push({
        kind: 'torch',
        x: t.x + 1,
        y: t.y,
        frames: flameFrames('torch', 7, 11),
        anchor: { x: 3, y: 10 },
        phase: 1.3 + i * 2.1,
        halo: { x: t.x + 1, y: g.floorTop + 3, rx: 18, ry: 5 },
    }));
    for (const c of chandeliers(g)) {
        CHANDELIER_CANDLES.forEach((dx, i) => out.push({
            kind: 'candle',
            x: c.x + dx,
            y: c.y - 4,
            frames: CANDLE_FLAMES.map((rows) => {
                const p = new Pix(3, 4);
                p.stamp(0, 0, rows, { Y: 'pumpkinLight', y: 'pumpkinShine', w: 'flameCore' });
                return p.canvas(raw);
            }),
            anchor: { x: 1, y: 3 },
            phase: i * 0.9,
            halo: null,
        }));
    }
    return out;
}

// ---------------------------------------------------------------------------------------------------------------
// The counter: the owl's place, the mugs.

/** Where OWL_BAR's anchor goes: {x, y, tap, mug}, or null without a counter. */
export function barOwlSpot(layout, artW, artH, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    if (!g.owl) return null;
    const { x, y } = g.owl;
    return { x, y, tap: { x: x + OWL_BAR.tap.x, y: y + OWL_BAR.tap.y }, mug: { x: x + OWL_BAR.mug.x, y: y + OWL_BAR.mug.y } };
}

/** Up to `max` counter mug spots (BAR_MUG's anchor), nearest the owl first: a front row, then a row behind it. */
export function mugSlots(layout, artW, artH, max, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    const c = g.counter;
    if (!c || max <= 0) return [];
    const right = g.owl.x - 10;
    const left = c.x1 + 14;
    const step = 6;
    const perRow = Math.max(1, Math.floor((right - left) / step) + 1);
    const rows = [c.y2 - COUNTER_H - 1];
    if (c.depth >= 8) rows.push(c.y2 - COUNTER_H - 5);
    const out = [];
    for (let r = 0; r < rows.length && out.length < max; r++) {
        for (let i = 0; i < perRow && out.length < max; i++) out.push({ x: right - i * step - r * 3, y: rows[r] });
    }
    return out;
}

/** The drawn counter in art px: {x1, x2, top, y} (y — the front foot line), or null. */
export function counterBox(layout, artW, artH, opts = {}) {
    const g = geometry(layout, artW, artH, opts);
    const c = g.counter;
    if (!c) return null;
    return { x1: c.x1, x2: Math.max(c.x2, c.keg.x2), top: c.keg.y1 - 6, y: c.y2 };
}

// ---------------------------------------------------------------------------------------------------------------
// The counter mug.

const MUG_LEGEND = { '.': null, O: 'outline', w: 'white', c: 'cream', y: 'pumpkinShine', Y: 'pumpkinLight', g: 'grayLight' };

/** A full mug for the counter, 9 × 8, anchor (3, 7): the bottom centre under the outline. */
export const BAR_MUG = {
    w: 9,
    h: 8,
    rows: [
        '.OOOOO...',
        'OwwcwwO..',
        'OwwwwwOO.',
        'OyYYYgOgO',
        'OyYYYgO.O',
        'OyYYYgOgO',
        'OgggggOO.',
        '.OOOOO...',
    ],
    legend: MUG_LEGEND,
    anchor: { x: 3, y: 7 },
};

/** The sprite with only its beer and foam pixels. */
export function beerLayer(sprite) {
    const keep = new Set(Object.entries(sprite.legend).filter(([, k]) => BEER_KEYS.has(k)).map(([ch]) => ch));
    return { ...sprite, rows: sprite.rows.map((r) => [...r].map((ch) => (keep.has(ch) ? ch : '.')).join('')) };
}

/** A canvas of the sprite in the lighting, its beer and foam keeping their hue (night.js keepHueColor). */
export function bakeBar(sprite, lighting = 'day') {
    const L = LIGHTINGS.includes(lighting) ? lighting : 'day';
    const p = new Pix(sprite.w, sprite.h);
    sprite.rows.forEach((row, y) => [...row].forEach((ch, x) => p.set(x, y, sprite.legend[ch])));
    return p.canvas(litKey(L, true));
}
