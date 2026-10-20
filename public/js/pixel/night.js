import { PALETTE } from './palette.js';

/**
 * Light and sky for scenes 1 «Закат», 2 «Ночь» and 3 «Кровавая луна»: lighting grades that recolour existing art
 * (sunset, moonlit night, firelight near the bonfire, blood moon), the skies, the sunset sun, the moon and the blood
 * moon, stars, dithered light halos and long sunset shadows.
 * Sprite = { w, h, rows: string[], legend: { [char]: paletteKey|null }, anchor: {x, y} }, same as sprites.js.
 */

const clamp255 = (v) => Math.max(0, Math.min(255, Math.round(v)));
const rgb = (c) => [(c >> 16) & 255, (c >> 8) & 255, c & 255];
const pack = ([r, g, b]) => (clamp255(r) << 16) | (clamp255(g) << 8) | clamp255(b);
const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t);
const luma = ([r, g, b]) => (0.3 * r + 0.59 * g + 0.11 * b) / 255;

function toHsl([r, g, b]) {
    const [R, G, B] = [r / 255, g / 255, b / 255];
    const max = Math.max(R, G, B);
    const min = Math.min(R, G, B);
    const l = (max + min) / 2;
    if (max === min) return [0, 0, l];
    const d = max - min;
    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    const h = max === R ? (G - B) / d + (G < B ? 6 : 0) : max === G ? (B - R) / d + 2 : (R - G) / d + 4;
    return [h * 60, s, l];
}

function fromHsl([h, s, l]) {
    const k = (n) => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
    return [f(0) * 255, f(8) * 255, f(4) * 255];
}

// Greens lose saturation and drift (toward olive at sunset, teal at night); other hues just desaturate.
function regrade(c, { sat, greenSat, greenHue }) {
    const [h, s, l] = toHsl(c);
    const green = h >= 60 && h <= 180;
    return fromHsl([green ? h + (greenHue - h) * 0.3 : h, s * (green ? greenSat : sat), l]);
}

const SUNSET_SHADE = [72, 38, 96];
const SUNSET_WARM = [255, 168, 96];
const NIGHT_BASE = [12, 12, 32];
const MOONLIT = [150, 172, 226];
const FIRE_WARM = [255, 150, 70];
const BLOOD_BASE = [26, 6, 14];
const BLOOD_LIT = [255, 120, 100];

// Each lighting is a function of one colour, so a baked layer can be recoloured pixel by pixel and a palette stays a
// palette: the ~170 inputs give ~170 outputs.
const GRADES = {
    day: (c) => c,
    // Low warm sun: warmer and a touch darker, shadows pulled toward violet, highlights toward orange.
    sunset: (c) => {
        let v = regrade(c, { sat: 0.9, greenSat: 0.55, greenHue: 50 });
        const y = luma(v);
        v = v.map((ch, i) => ch * [1.02, 0.84, 0.74][i]);
        v = mix(v, SUNSET_SHADE, 0.4 * (1 - y) ** 1.5);
        return mix(v, SUNSET_WARM, 0.2 * y * y);
    },
    // Cold moonlight: values drop to about 40 %, hues go blue-violet, the brightest tones pick up a cold sheen. The
    // dark base keeps the outline apart from the darkest ramps.
    night: (c) => {
        let v = regrade(c, { sat: 0.55, greenSat: 0.5, greenHue: 165 });
        const y = luma(v);
        v = v.map((ch, i) => NIGHT_BASE[i] + ch * [0.36, 0.42, 0.62][i]);
        return mix(v, MOONLIT, Math.max(0, y - 0.55) * 0.6);
    },
    // The same night inside the bonfire's reach: warm, brighter, saturated toward orange — the second light source.
    firelight: (c) => {
        let v = regrade(c, { sat: 0.85, greenSat: 0.6, greenHue: 70 });
        const y = luma(v);
        v = v.map((ch, i) => 16 + ch * [0.92, 0.62, 0.42][i]);
        return mix(v, FIRE_WARM, 0.16 * y);
    },
    // Blood moon: everything sinks into maroon, highlights glow red-pink, greens go almost black.
    blood: (c) => {
        let v = regrade(c, { sat: 0.4, greenSat: 0.25, greenHue: 120 });
        const y = luma(v);
        v = v.map((ch, i) => BLOOD_BASE[i] + ch * [0.58, 0.24, 0.3][i]);
        return mix(v, BLOOD_LIT, Math.max(0, y - 0.5) * 0.5);
    },
};

/** 'day' | 'sunset' | 'night' | 'firelight' | 'blood'. */
export const LIGHTINGS = Object.keys(GRADES);

const litCache = Object.fromEntries(LIGHTINGS.map((name) => [name, new Map()]));

/** The colour as seen in that lighting. */
export function litColor(color, lighting = 'day') {
    const cache = litCache[lighting] ?? litCache.day;
    if (!cache.has(color)) cache.set(color, lighting === 'day' ? color : pack(GRADES[lighting](rgb(color))));
    return cache.get(color);
}

/** The whole palette per lighting: LIT_PALETTE.night.leaf is the moonlit leaf green. */
export const LIT_PALETTE = Object.fromEntries(LIGHTINGS.map((name) => [
    name,
    Object.fromEntries(Object.entries(PALETTE).map(([key, c]) => [key, litColor(c, name)])),
]));

// Light sources keep their colour in every lighting: flames, magic, embers, the moons, the magic circle.
export const EMISSIVE = new Set([
    'flameCore', 'magicCore', 'eerieHot', 'eerieMid', 'eyeGreen', 'eerieDark', 'eerieDeep', 'violetHot', 'eyeViolet',
    'violetGlow', 'ember', 'rune', 'runeDark', 'moonLight', 'moon', 'moonShade', 'bloodMoonLight', 'bloodMoon',
    'bloodMoonShade', 'sunsetSun', 'sunsetGlow',
    // The finale's light (finale.js, FINALE_EMISSIVE): FrankenPHP's sparks and the cursed glow, lightning, the column.
    'frankenGlowCore', 'frankenGlowHot', 'frankenGlow', 'frankenGlowDark', 'frankenGlowDeep',
    'lightningCore', 'lightning', 'lightningEdge', 'columnCore',
]);
const EMISSIVE_COLORS = new Set([...EMISSIVE].map((key) => PALETTE[key]));

const litBakes =Object.fromEntries(LIGHTINGS.map((name) => [name, new WeakMap()]));

/**
 * Bakes a sprite in a lighting, like bake() in sprites.js; emissive keys stay as they are. Light layers — a pumpkin's
 * glow, fire and aura, the bonfire's fire — are light themselves: bake them with bake(), not here.
 */
export function bakeLit(sprite, lighting = 'day') {
    const cache = litBakes[lighting] ?? litBakes.day;
    if (cache.has(sprite)) return cache.get(sprite);
    const canvas = document.createElement('canvas');
    canvas.width = sprite.w;
    canvas.height = sprite.h;
    const ctx = canvas.getContext('2d');
    const img = ctx.createImageData(sprite.w, sprite.h);
    for (let y = 0; y < sprite.h; y++) {
        const row = sprite.rows[y];
        for (let x = 0; x < sprite.w; x++) {
            const key = sprite.legend[row[x]];
            if (key === null || key === undefined) continue;
            const color = EMISSIVE.has(key) ? PALETTE[key] : LIT_PALETTE[lighting][key];
            const i = (y * sprite.w + x) * 4;
            img.data[i] = (color >> 16) & 0xff;
            img.data[i + 1] = (color >> 8) & 0xff;
            img.data[i + 2] = color & 0xff;
            img.data[i + 3] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    cache.set(sprite, canvas);
    return canvas;
}

const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const dither = (x, y) => (BAYER[y & 3][x & 3] + 0.5) / 16;

/**
 * A recoloured copy of a baked canvas (the background, a walker's frame): every opaque pixel through litColor, except
 * emissive colours, so glowing eyes and the book's lines stay lit. With `light` = { x, y, rx, ry, near } the pixels inside that ellipse take the `near` lighting instead, Bayer-dithered toward
 * its edge — two light sources, cold moonlight around and warm firelight at the centre, without blending.
 */
export function relightCanvas(source, lighting, light = null) {
    const canvas = document.createElement('canvas');
    canvas.width = source.width;
    canvas.height = source.height;
    // Read back pixel by pixel: a CPU-backed canvas spares the slow GPU readback of getImageData.
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(source, 0, 0);
    if (lighting === 'day' && !light) return canvas;
    const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const d = img.data;
    for (let i = 0; i < d.length; i += 4) {
        if (d[i + 3] === 0) continue;
        const x = (i >> 2) % canvas.width;
        const y = Math.floor((i >> 2) / canvas.width);
        const name = light && nearLight(x, y, light) ? light.near : lighting;
        const src = (d[i] << 16) | (d[i + 1] << 8) | d[i + 2];
        if (EMISSIVE_COLORS.has(src)) continue;
        const c = litColor(src, name);
        d[i] = (c >> 16) & 255;
        d[i + 1] = (c >> 8) & 255;
        d[i + 2] = c & 255;
    }
    ctx.putImageData(img, 0, 0);
    return canvas;
}

// How much of its own colour beer keeps in a lighting — the pumpkins' and the walkers' alike: night and blood moon
// turn amber grey-blue, and a glass that does not read as beer misses the point of the cursed world.
export const BEER_KEEP = 0.65;

/** A colour BEER_KEEP of the way from its lit value back to its day one. */
export function keepHueColor(day, lighting) {
    const lit = lighting === 'day' ? day : litColor(day, lighting);
    return [16, 8, 0].reduce((acc, sh) => acc | (Math.round(((day >> sh) & 255) * BEER_KEEP + ((lit >> sh) & 255) * (1 - BEER_KEEP)) << sh), 0);
}

/** Bakes a sprite ({ w, h, rows, legend }) in keepHueColor: the beer of a pumpkin's glass. */
export function keepHueBake(def, lighting) {
    const c = document.createElement('canvas');
    c.width = def.w;
    c.height = def.h;
    const ctx = c.getContext('2d');
    const img = ctx.createImageData(def.w, def.h);
    for (let y = 0; y < def.h; y++) {
        for (let x = 0; x < def.w; x++) {
            const key = def.legend[def.rows[y][x]];
            if (!key) continue;
            const color = keepHueColor(PALETTE[key], lighting);
            const i = (y * def.w + x) * 4;
            img.data[i] = (color >> 16) & 255;
            img.data[i + 1] = (color >> 8) & 255;
            img.data[i + 2] = color & 255;
            img.data[i + 3] = 255;
        }
    }
    ctx.putImageData(img, 0, 0);
    return c;
}

/** A copy of a day canvas with every opaque pixel in keepHueColor: the beer in a walker's glass. */
export function keepHueCanvas(source, lighting) {
    const canvas = document.createElement('canvas');
    canvas.width = source.width;
    canvas.height = source.height;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(source, 0, 0);
    if (lighting === 'day') return canvas;
    const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const d = img.data;
    for (let i = 0; i < d.length; i += 4) {
        if (d[i + 3] === 0) continue;
        const c = keepHueColor((d[i] << 16) | (d[i + 1] << 8) | d[i + 2], lighting);
        d[i] = (c >> 16) & 255;
        d[i + 1] = (c >> 8) & 255;
        d[i + 2] = c & 255;
    }
    ctx.putImageData(img, 0, 0);
    return canvas;
}

/**
 * Whether the art pixel (x, y) falls in the near light of { x, y, rx, ry }: solid in the inner half, dithered out to
 * the edge. Use it per sprite (at its anchor) to pick bakeLit(sprite, 'firelight') over the far lighting.
 */
export function nearLight(x, y, light) {
    const d = Math.hypot((x - light.x) / light.rx, (y - light.y) / light.ry);
    return d < 0.5 || (d < 1 && dither(x, y) < (1 - d) * 2);
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

function sprite(rows, legend, anchor) {
    return {
        w: rows[0].length,
        h: rows.length,
        rows,
        legend: { '.': null, ...legend },
        anchor: anchor ?? { x: rows[0].length >> 1, y: rows.length >> 1 },
    };
}

const fromGrid = (grid, legend, anchor) => sprite(grid.map((r) => r.join('')), legend, anchor);
const grid = (w, h) => Array.from({ length: h }, () => Array(w).fill('.'));

/** Sky bands top → horizon per lighting. */
export const SKY = {
    day: ['sky', 'sky', 'skyLight'],
    sunset: ['sunsetTop', 'sunsetHigh', 'sunsetMid', 'sunsetLow', 'sunsetGlow'],
    night: ['nightTop', 'nightHigh', 'nightMid', 'nightLow'],
    blood: ['bloodTop', 'bloodHigh', 'bloodMid', 'bloodLow'],
};
SKY.firelight = SKY.night;

/**
 * Paints a sky w×h (h = horizon + 1) into ctx: the bands of SKY[lighting], each seam ordered-dithered over a few
 * rows, so there is no gradient and no banding. Night skies get a fixed scatter of 1-px stars, denser up high.
 */
export function paintSky(ctx, w, h, lighting = 'day', { seed = 11, stars = true } = {}) {
    const keys = SKY[lighting] ?? SKY.day;
    const color = (key) => `#${PALETTE[key].toString(16).padStart(6, '0')}`;
    // Bands grow toward the horizon: mostly the top colour, the warm or light ones hug the horizon.
    const weights = keys.map((_, i) => 1 + i * 0.35);
    const total = weights.reduce((a, b) => a + b, 0);
    const seams = [];
    let acc = 0;
    for (let i = 0; i < keys.length - 1; i++) {
        acc += weights[i];
        seams.push(Math.round((acc / total) * h));
    }
    const span = Math.max(2, Math.round(h / keys.length / 3));
    for (let y = 0; y < h; y++) {
        let band = seams.findIndex((s) => y < s);
        if (band < 0) band = keys.length - 1;
        for (let x = 0; x < w; x++) {
            let k = band;
            const next = seams[band];
            // Approaching the next seam, more and more pixels already take the next band's colour.
            if (next !== undefined && y >= next - span && dither(x, y) < (y - (next - span) + 1) / (span + 1)) k = band + 1;
            ctx.fillStyle = color(keys[k]);
            ctx.fillRect(x, y, 1, 1);
        }
    }
    if (stars && (lighting === 'night' || lighting === 'firelight' || lighting === 'blood')) {
        const rnd = mulberry32(seed);
        const n = Math.round((w * h) / (lighting === 'blood' ? 260 : 140));
        const tones = lighting === 'blood' ? ['bloodMoonLight', 'bloodLow', 'bloodMid'] : ['moonLight', 'moonShade', 'nightLow'];
        for (let i = 0; i < n; i++) {
            const x = Math.floor(rnd() * w);
            const y = Math.floor(rnd() ** 1.6 * h * 0.8);
            ctx.fillStyle = color(tones[rnd() < 0.3 ? 0 : rnd() < 0.5 ? 1 : 2]);
            ctx.fillRect(x, y, 1, 1);
        }
    }
}

const SKY_LEGEND = {
    W: 'moonLight',
    M: 'moon',
    S: 'moonShade',
    h: 'nightLow',
    H: 'nightMid',
    o: 'sunsetSun',
    y: 'sunsetGlow',
};
const BLOOD_LEGEND = { ...SKY_LEGEND, W: 'bloodMoonLight', M: 'bloodMoon', S: 'bloodMoonShade', h: 'bloodLow', H: 'bloodMid' };

// Full moon lit from the upper left: a soft terminator toward the lower right and five craters, each shaded on the
// side facing the light (the crater wall there faces away from it).
function moonGrid(r = 10) {
    const n = 2 * r + 1;
    const g = grid(n, n);
    const craters = [[-3.5, -2.5, 2.2], [2, -5, 1.5], [1.5, 2.5, 2.8], [-5, 3.5, 1.4], [5.5, -0.5, 1.3]];
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            const dx = (x - r) / (r + 0.4);
            const dy = (y - r) / (r + 0.4);
            const d = Math.hypot(dx, dy);
            if (d > 1) continue;
            const v = -(0.55 * dx + 0.45 * dy) + 0.4 * (1 - d) + (dither(x, y) - 0.5) * 0.25;
            let ch = v > 0.32 ? 'W' : v > -0.38 ? 'M' : 'S';
            for (const [cx, cy, cr] of craters) {
                const ex = x - r - cx;
                const ey = y - r - cy;
                if (Math.hypot(ex, ey) <= cr) ch = ex + ey < 0 || ch === 'S' ? 'S' : 'M';
                else if (Math.hypot(ex, ey) <= cr + 1 && ex + ey > 0.5 && ch !== 'S') ch = 'W';
            }
            g[y][x] = ch;
        }
    }
    return g;
}

// A dithered ring around the moon, lighter than the sky: it fades out in Bayer steps, no alpha.
function haloGrid(r = 10, spread = 9) {
    const R = r + spread;
    const n = 2 * R + 1;
    const g = grid(n, n);
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            const d = Math.hypot(x - R, y - R);
            if (d <= r || d > R) continue;
            const t = (d - r) / spread;
            if (dither(x, y) < (1 - t) ** 1.3 * 0.9) g[y][x] = t < 0.35 ? 'h' : 'H';
        }
    }
    return g;
}

// The low sunset sun: a big disk in two warm tones whose lower half is sliced by sky gaps, the retro horizon sun.
// The gaps are transparent, so the sky behind shows through.
function sunsetSunSprite(r = 13) {
    const n = 2 * r + 1;
    const g = grid(n, n);
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            if (Math.hypot(x - r, y - r) > r + 0.3) continue;
            const below = y - r;
            if (below > 0 && below % 4 >= 4 - Math.min(3, 1 + Math.floor(below / 5))) continue;
            g[y][x] = y < r * 0.6 + (dither(x, y) - 0.5) * 3 ? 'y' : 'o';
        }
    }
    return fromGrid(g, SKY_LEGEND);
}

const HALO_TONES = {
    warm: { a: 'pumpkin', b: 'pumpkinDeep' },
    candle: { a: 'pumpkinLight', b: 'pumpkinDark' },
    green: { a: 'eyeGreen', b: 'eerieDark' },
    violet: { a: 'violetGlow', b: 'violet' },
    franken: { a: 'frankenGlowHot', b: 'frankenGlowDark' },
    red: { a: 'rune', b: 'runeDark' },
};
const haloCache = new Map();

/**
 * A dithered ground light rx × ry (flattened like the ground): two tones in Bayer steps, dense in the middle and
 * sparse at the edge. Meant for an additive blend at alpha ~0.5–0.7 over the night ground. Tones: 'warm' (the
 * bonfire), 'candle' (a walker's candle, lighter), 'green' (scene 2 magic), 'violet' (blood moon), 'franken' (the
 * cursed world's lime: a firefly's lantern), 'red' (the magic circle's red: a walker's red flame).
 */
export function halo(rx, ry, tone = 'warm') {
    const key = `${rx}:${ry}:${tone}`;
    if (haloCache.has(key)) return haloCache.get(key);
    const g = grid(2 * rx + 1, 2 * ry + 1);
    for (let y = 0; y <= 2 * ry; y++) {
        for (let x = 0; x <= 2 * rx; x++) {
            const d = Math.hypot((x - rx) / rx, (y - ry) / ry);
            if (d > 1) continue;
            const density = (1 - d) ** 0.8 * 1.15;
            if (dither(x, y) < density) g[y][x] = d < 0.45 && dither(x, y) < density - 0.35 ? 'a' : 'b';
        }
    }
    const result = fromGrid(g, HALO_TONES[tone] ?? HALO_TONES.warm, { x: rx, y: ry });
    haloCache.set(key, result);
    return result;
}

/**
 * A long sunset shadow of a sprite: its silhouette laid on the ground, squashed to `squash` of its height and sheared
 * away from the sun (dir -1 = sun on the right, shadow to the left). Solid 'outline'; draw it at alpha ~0.35. The
 * anchor matches the source's foot.
 */
export function longShadow(src, { dir = -1, len = 1.8, squash = 0.28 } = {}) {
    const reach = Math.ceil(src.h * len);
    const sh = Math.max(2, Math.ceil(src.h * squash) + 1);
    const w = src.w + reach;
    const ox = dir < 0 ? reach : 0;
    const g = grid(w, sh);
    for (let y = 0; y < src.h; y++) {
        const up = src.anchor.y - y;
        for (let x = 0; x < src.w; x++) {
            const k = src.legend[src.rows[y][x]];
            if (k === null || k === undefined) continue;
            const gx = Math.round(ox + x + dir * up * len);
            const gy = sh - 1 - Math.round(up * squash);
            for (let s = 0; s <= Math.ceil(len); s++) {
                const xx = gx - dir * s;
                if (gy >= 0 && gy < sh && xx >= 0 && xx < w) g[gy][xx] = 'O';
            }
        }
    }
    return fromGrid(g, { O: 'outline' }, { x: ox + src.anchor.x, y: sh - 1 });
}

const MOON = moonGrid();
const MOON_HALO = haloGrid();

export const NIGHT = {
    // The moon (21×21) and its halo (39×39, centred on the moon: draw it first); the blood moon of scene 3 is the same
    // drawing in red, its halo maroon.
    moon: fromGrid(MOON, SKY_LEGEND),
    moonHalo: fromGrid(MOON_HALO, SKY_LEGEND),
    bloodMoon: fromGrid(MOON, BLOOD_LEGEND),
    bloodMoonHalo: fromGrid(MOON_HALO, BLOOD_LEGEND),
    // The low striped sun of scene 1 (27×27), set it half behind the hills.
    sunsetSun: sunsetSunSprite(),
    // Stars: a bright and a dim dot, a cross and a sparkle; twinkle swaps cross ↔ sparkle every ~400 ms.
    stars: [
        sprite(['W'], SKY_LEGEND),
        sprite(['S'], SKY_LEGEND),
        sprite(['.S.', 'SWS', '.S.'], SKY_LEGEND),
        sprite(['..S..', '..S..', 'SSWSS', '..S..', '..S..'], SKY_LEGEND),
    ],
    // The small warm circle around a walker holding a candle at dusk (anchor: centre, put it at the feet).
    candleLight: halo(11, 5, 'candle'),
};
