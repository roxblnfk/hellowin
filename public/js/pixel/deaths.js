import { FLYERS } from './flyers.js';

/**
 * How the zombie workers of the cursed world die (docs/spec/frontend.md, «Рапира»): the kind is DEATHS[crc32(flyer)
 * % DEATHS.length] (flyers.js, deathOf), the same for everyone. The sprites here are made from the zombie's own
 * (flyers.js, zombie: hit, headless, pieces); the player below runs one death on the scene clock.
 * Sprite = { w, h, rows, legend, anchor }, same as flyers.js.
 */

// The death plays for DEATH_MS, then everything left blinks out until GONE_MS.
export const DEATH_MS = 1800;
export const GONE_MS = 2200;

const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]];
const dither = (x, y) => (BAYER[y & 3][x & 3] + 0.5) / 16;

function hash(x, y, s = 0) {
    let h = Math.imul(x | 0, 374761393) ^ Math.imul(y | 0, 668265263) ^ Math.imul(s | 0, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

function noise(x, y, s = 0) {
    const xi = Math.floor(x);
    const yi = Math.floor(y);
    const u = (x - xi) ** 2 * (3 - 2 * (x - xi));
    const v = (y - yi) ** 2 * (3 - 2 * (y - yi));
    const a = hash(xi, yi, s);
    const b = hash(xi + 1, yi, s);
    const c = hash(xi, yi + 1, s);
    const d = hash(xi + 1, yi + 1, s);
    return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
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

// Keys of the death sprites on top of the zombie's own legend: grey dust, char and embers of the green fire, soil,
// blood and the flash.
const EXTRA = {
    s: 'grayLight', S: 'gray', u: 'duckGrayDark',
    X: 'ashDark', A: 'ash', e: 'frankenGlowHot', E: 'frankenGlowCore', f: 'frankenGlow', F: 'frankenGlowDark', j: 'frankenGlowDeep',
    k: 'soilDark', i: 'soil', I: 'soilLight',
    c: 'crimson', C: 'crimsonDark', w: 'white', h: 'eerieHot',
};

const sprite = (grid, legend, anchor) => ({ w: grid[0].length, h: grid.length, rows: grid.map((r) => r.join('')), legend: { '.': null, ...legend }, anchor: { ...anchor } });
const gridOf = (def) => def.rows.map((r) => [...r]);
const blank = (w, h) => Array.from({ length: h }, () => Array(w).fill('.'));
const legendOf = (def) => ({ ...def.legend, ...EXTRA });

// The zombie's palette keys by value: the light ones turn to ash, the dark ones to charcoal.
const LIGHT = new Set(['y', 'Y', 'z', 'G', 'w', 'l', 'n']);
const DARKER = { z: 'Z', y: 'Y', Y: 'N', G: 'g', d: 'D', w: 'G', m: 'O' };

/** A low heap on the foot of a w × h grid around column cx: `rx` wide, `hh` high, in the given tones. */
function heapOn(g, cx, rx, hh, [lit, mid, dark], seed = 0) {
    const h = g.length;
    for (let y = h - 1; y >= h - 1 - Math.ceil(hh); y--) {
        for (let x = 0; x < g[0].length; x++) {
            const dx = (x + 0.5 - cx) / rx;
            const up = (h - 0.5 - y) / Math.max(0.5, hh);
            if (Math.abs(dx) > 1 || up > (1 - dx * dx) * (0.8 + 0.4 * noise(x * 0.5, seed, 3))) continue;
            const v = dx - up * 0.6;
            g[y][x] = v < -0.3 ? lit : v < 0.35 ? mid : dark;
        }
    }
}

/** The body crumbling to dust from the top: `k` of 6 frames, a band of grey at the broken edge, a heap growing. */
function dustFrame(src, k) {
    const g = gridOf(src);
    const top = g.findIndex((r) => r.some((c) => c !== '.'));
    const f = k / 5;
    const cut = Math.round(top + (src.anchor.y - top) * f);
    for (let y = 0; y < g.length; y++) {
        for (let x = 0; x < g[0].length; x++) {
            if (g[y][x] === '.') continue;
            if (y < cut) g[y][x] = '.';
            else if (y < cut + 2 && k > 0) g[y][x] = dither(x, y) < 0.5 ? 's' : 'S';
        }
    }
    if (k > 0) heapOn(g, src.anchor.x, 3 + f * 7, 1 + f * 4, ['s', 'S', 'u'], 1);
    return sprite(g, legendOf(src), src.anchor);
}

/** The heap of dust blown away to the right: 3 frames, smaller and further each. */
function blownFrame(src, k) {
    const g = blank(src.w, src.h);
    heapOn(g, src.anchor.x + 1 + k * 2, 10 - k * 3, 5 - k * 1.5, ['s', 'S', 'u'], 1);
    return sprite(g, legendOf(src), src.anchor);
}

/** Burning: 0 — as hit, 1 — scorched, 2 — charred, 3 — charred with the green embers eating through. */
function charFrame(src, k) {
    const g = gridOf(src);
    for (let y = 0; y < g.length; y++) {
        for (let x = 0; x < g[0].length; x++) {
            const ch = g[y][x];
            if (ch === '.' || k === 0) continue;
            if (k === 1) g[y][x] = DARKER[ch] ?? ch;
            else if (ch === 'O' || ch === 'K') g[y][x] = 'X';
            else g[y][x] = k === 3 && hash(x, y, 5) < 0.12 ? 'e' : LIGHT.has(ch) ? 'A' : 'X';
        }
    }
    return sprite(g, legendOf(src), src.anchor);
}

/** The charred body falling in on itself into a heap of ash with embers: 3 frames. */
function ashFrame(src, k) {
    const g = gridOf(charFrame(src, 3));
    const top = g.findIndex((r) => r.some((c) => c !== '.'));
    const cut = Math.round(top + (src.anchor.y - top) * (k + 1) / 3);
    for (let y = 0; y < cut; y++) g[y].fill('.');
    heapOn(g, src.anchor.x, 4 + k * 2.5, 1.5 + k * 1.2, ['A', 'X', 'X'], 2);
    g.forEach((r, y) => r.forEach((c, x) => {
        if ((c === 'A' || c === 'X') && hash(x, y, 9 + k) < 0.1) r[x] = 'e';
    }));
    return sprite(g, legendOf(src), src.anchor);
}

/**
 * Green fire over a silhouette, `frame` 0–3 rising; `size` 1 — full, 0.4 — dying down. On a canvas 6 px wider and
 * 10 taller than the body, anchored like it: light, bake it as is.
 */
function fireFrame(src, frame, size = 1) {
    const pad = 3;
    const up = 10;
    const g = blank(src.w + pad * 2, src.h + up);
    const solid = (x, y) => (src.rows[y]?.[x] ?? '.') !== '.';
    for (let y = 0; y < g.length; y++) {
        for (let x = 0; x < g[0].length; x++) {
            const sx = x - pad;
            const sy = y - up;
            // Distance above the nearest body pixel in this column (within 2 columns): the flames lick up from it.
            let d = Infinity;
            for (let dx = -2; dx <= 2; dx++) {
                for (let k = 0; k <= 12; k++) {
                    if (solid(sx + dx, sy + k)) {
                        d = Math.min(d, k + Math.abs(dx) * 1.5);
                        break;
                    }
                }
            }
            if (d === Infinity) continue;
            const n = noise(x * 0.45, (y + frame * 2.5) * 0.35, 21);
            // On the body itself the fire burns in tongues, the char showing between them.
            if (d === 0) {
                if (n > 0.52) g[y][x] = n > 0.72 ? 'E' : n > 0.6 ? 'e' : 'f';
                continue;
            }
            const reach = (3 + n * 9) * size;
            if (d > reach) continue;
            const t = d / reach;
            const ch = t < 0.2 ? 'E' : t < 0.45 ? 'e' : t < 0.75 ? 'f' : 'F';
            if (t > 0.6 && dither(x, y + frame) > (1 - t) * 2.2) continue;
            g[y][x] = ch;
        }
    }
    return sprite(g, legendOf(src), { x: src.anchor.x + pad, y: src.anchor.y + up });
}

/** Sinking: the body `d` px into the ground, cut off at the ground line, a lip of soil in front of it. */
function sinkFrame(src, d) {
    const g = blank(src.w, src.h);
    const ground = src.anchor.y;
    for (let y = 0; y < src.h; y++) {
        const ny = y + d;
        if (ny >= ground) continue;
        for (let x = 0; x < src.w; x++) if (src.rows[y][x] !== '.') g[ny][x] = src.rows[y][x];
    }
    const cols = [];
    for (let x = 0; x < src.w; x++) if (g[ground - 1]?.[x] !== '.' || g[ground - 2]?.[x] !== '.') cols.push(x);
    if (cols.length && d > 0) {
        for (let x = cols[0] - 2; x <= cols[cols.length - 1] + 2; x++) {
            if (x < 0 || x >= src.w) continue;
            g[ground - 1][x] = hash(x, d, 4) < 0.5 ? 'i' : 'I';
            if (hash(x, d, 6) < 0.4 && ground - 2 >= 0) g[ground - 2][x] = 'k';
        }
    }
    return sprite(g, legendOf(src), src.anchor);
}

/** A flat ellipse rx × ry: the hole a zombie sank into, or the puddle a meat explosion leaves. */
function flatSprite(rx, ry, [core, mid, rim], seed) {
    const g = blank(2 * rx + 1, 2 * ry + 1);
    for (let y = 0; y <= 2 * ry; y++) {
        for (let x = 0; x <= 2 * rx; x++) {
            const d = Math.hypot((x - rx) / rx, (y - ry) / ry) + (noise(x * 0.6, y * 0.6, seed) - 0.5) * 0.3;
            if (d > 1) continue;
            g[y][x] = d < 0.45 ? core : d < 0.8 ? mid : rim;
        }
    }
    return sprite(g, EXTRA, { x: rx, y: ry });
}

/** The burst of a meat explosion: 2 frames, white-hot core in zombie green, light. */
function burstSprite(r, k) {
    const n = 2 * r + 1;
    const g = blank(n, n);
    for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
            const d = Math.hypot(x - r, y - r) / r;
            const ray = Math.abs(Math.sin(Math.atan2(y - r, x - r) * 4)) > 0.85;
            if (k === 0 ? d <= 0.55 || (ray && d <= 1) : d > 0.55 && d <= 1 && dither(x, y) < 0.6) {
                g[y][x] = d < 0.3 ? 'w' : d < 0.6 ? 'h' : 'z';
            }
        }
    }
    return sprite(g, { ...EXTRA, z: 'zombie' }, { x: r, y: r });
}

const bits = (rows) => sprite(rows.map((r) => [...r]), { ...EXTRA, z: 'zombie', Z: 'zombieShade', d: 'denim', D: 'denimDark', o: 'outline' }, { x: rows[0].length >> 1, y: rows.length >> 1 });

/** Every quarter turn of a sprite: [0°, 90°, 180°, 270°], for pieces tumbling through the air. */
function turns(def) {
    const r90 = (s) => {
        const rows = [];
        for (let x = 0; x < s.w; x++) {
            let row = '';
            for (let y = s.h - 1; y >= 0; y--) row += s.rows[y][x];
            rows.push(row);
        }
        return { ...s, w: s.h, h: s.w, rows, anchor: { x: s.h >> 1, y: s.w >> 1 } };
    };
    const out = [def];
    for (let i = 0; i < 3; i++) out.push(r90(out[out.length - 1]));
    return out;
}

let art = null;

/**
 * The sprites of every death, made on first use: dust (6) and blown (3); char (4), fire (4 frames, and 4 dying down),
 * ash (3); sink (frames by depth), hole; burst (2), puddle (2, growing); gibs, grains, clods, drops (small bits);
 * pieces (the zombie's head, torso, arms, legs, each in 4 quarter turns), head (the head alone, 4 turns).
 */
export function deathArt() {
    if (art) return art;
    const z = FLYERS.zombie[1];
    const walk = z.fly[0];
    const hit = z.hit;
    art = {
        dust: [0, 1, 2, 3, 4, 5].map((k) => dustFrame(walk, k)),
        blown: [0, 1, 2].map((k) => blownFrame(walk, k)),
        char: [0, 1, 2, 3].map((k) => charFrame(hit, k)),
        fire: [0, 1, 2, 3].map((f) => fireFrame(hit, f)),
        fireLow: [0, 1, 2, 3].map((f) => fireFrame(ashFrame(hit, 1), f, 0.45)),
        ash: [0, 1, 2].map((k) => ashFrame(hit, k)),
        sink: Array.from({ length: 8 }, (_, k) => sinkFrame(hit, Math.round((k * hit.h) / 7))),
        hole: flatSprite(9, 3, ['k', 'i', 'I'], 3),
        burst: [0, 1].map((k) => burstSprite(11, k)),
        puddle: [flatSprite(6, 2, ['C', 'c', 'C'], 5), flatSprite(11, 4, ['C', 'c', 'C'], 5)],
        gibs: [bits(['.cC', 'cCC', 'Cc.']), bits(['.c', 'cC']), bits(['zZ', 'Zz']), bits(['C.', 'cc', '.C']), bits(['dDd', 'Dd.']), bits(['Zz.', '.zZ']), bits(['cc', 'C.'])],
        grains: [bits(['s']), bits(['S']), bits(['u'])],
        clods: [bits(['ik', 'k.']), bits(['I']), bits(['i']), bits(['.I', 'ik'])],
        drops: [bits(['z']), bits(['Z']), bits(['C'])],
        sparks: [bits(['E']), bits(['e']), bits(['f'])],
        pieces: [z.pieces.head, z.pieces.torso, ...z.pieces.arms, ...z.pieces.legs].map(turns),
        head: turns(z.pieces.head),
        headless: z.headless,
        hit,
    };
    return art;
}

/**
 * Plays deaths on the scene. env: spawn(textures, x, y, vx, vy, gravity, life) — a particle of the scene, texture(def,
 * lit) — a lit texture, glow(def) — an unlit one, sprite('ground' | 'light') — a new sprite in that layer,
 * depthKey(x, y), reduced(), clock().
 * start(f, kind, seed) begins the death of the shot ground flyer `f` (its shotPos, its sprite); frame(f) returns the
 * body's look now — { def, lit, dx } or null while the body is gone — and sets off the moments; drop(f) clears its
 * sprites. The particles' throws come from `seed`, so every viewer sees the same death.
 */
export function createDeaths(env) {
    const bounce = (floor, rest = 0.35) => (q) => {
        if (q.y < floor) return;
        q.y = floor;
        if (Math.abs(q.vy) < 18) {
            q.vy = 0;
            q.vx *= 0.5;
            q.gravity = 0;
            q.resting = true;
        } else {
            q.vy = -q.vy * rest;
            q.vx *= 0.6;
        }
    };
    // Blinking out with everything else at the end, whatever the particle's own frames.
    const blink = (q, end) => {
        q.sprite.visible = env.clock() < end - (GONE_MS - DEATH_MS) || Math.floor(env.clock() / 100) % 2 === 0;
    };

    function extra(f, def, { x, y, z, lit = null, add = false, alpha = 1 }) {
        const s = env.sprite(add ? 'light' : 'ground');
        s.texture = lit === null ? env.glow(def) : env.texture(def, lit);
        s.anchor.set(def.anchor.x / def.w, def.anchor.y / def.h);
        s.position.set(Math.round(x), Math.round(y));
        if (z !== undefined) s.zIndex = z;
        if (add) s.blendMode = 'add';
        s.alpha = alpha;
        f.death.extras.push(s);
        return s;
    }

    function toss(f, list, { x, y, vx, vy, gravity = 260, floor, life, turn = false }) {
        const d = f.death;
        const tex = list.map((def) => env.texture(def, d.lit));
        const p = env.spawn([tex[0]], x, y, vx, vy, gravity, life);
        p.sprite.pivot.set(list[0].anchor.x, list[0].anchor.y);
        p.tick = bounce(floor);
        const end = d.at + GONE_MS;
        p.anim = (q) => {
            blink(q, end);
            let i = 0;
            if (turn && !q.resting) i = Math.floor(q.age * 12) % 4;
            // A tumbling piece comes to rest on one of its long sides.
            else if (turn) i = q.restTurn ??= Math.floor(q.age * 12) % 4 & 2;
            q.sprite.pivot.set(list[i].anchor.x, list[i].anchor.y);
            return tex[i];
        };
        return p;
    }

    const once = (f, key, fn) => {
        if (f.death.fired.has(key)) return;
        f.death.fired.add(key);
        fn();
    };

    return {
        start(f, kind, seed, lit) {
            f.death = { kind, seed, rnd: rng(seed), at: env.clock(), lit, fired: new Set(), extras: [], emitAt: 0 };
        },

        drop(f) {
            for (const s of f.death?.extras ?? []) s.destroy();
            if (f.death) f.death.extras = [];
        },

        frame(f) {
            const a = deathArt();
            const d = f.death;
            const t = env.clock() - d.at;
            const { x, y } = f.shotPos;
            const z = env.depthKey(x, y);
            const r = d.rnd;
            const life = (GONE_MS - t) / 1000;
            for (const s of d.extras) s.visible = t < DEATH_MS || Math.floor(env.clock() / 100) % 2 === 0;
            if (env.reduced()) {
                // One frame of the death, then it blinks out.
                const last = { pieces: a.headless.flat, meat: null, dust: a.blown[0], head: a.headless.flat, fire: a.ash[2], sink: null }[d.kind];
                once(f, 'still', () => {
                    if (d.kind === 'meat') extra(f, a.puddle[1], { x, y, z: z - 1, lit: d.lit });
                    if (d.kind === 'sink') extra(f, a.hole, { x, y, z: z - 1, lit: d.lit });
                });
                return last ? { def: last, lit: d.lit } : null;
            }
            switch (d.kind) {
                case 'pieces': {
                    if (t < 160) return { def: a.hit, lit: d.lit };
                    once(f, 'apart', () => {
                        // Head up and back, arms out to the sides, the torso hops, the legs fold where they stood.
                        const throws = [[0, -26, -40, -150], [0, -14, 10, -90], [6, -16, 70, -110], [-6, -16, -60, -120], [2, -5, 30, -60], [-2, -5, -25, -70]];
                        a.pieces.forEach((list, i) => {
                            const [ox, oy, vx, vy] = throws[i];
                            toss(f, list, { x: x + ox, y: y + oy, vx: vx * (0.8 + r() * 0.4), vy: vy * (0.8 + r() * 0.4), floor: y - 2 + Math.round(r() * 4), life, turn: true });
                        });
                        for (let i = 0; i < 6; i++) toss(f, a.drops, { x, y: y - 14, vx: (r() - 0.5) * 120, vy: -40 - r() * 80, floor: y + Math.round(r() * 3), life });
                    });
                    return null;
                }
                case 'meat': {
                    if (t < 120) return { def: a.hit, lit: d.lit };
                    once(f, 'burst', () => {
                        d.burst = extra(f, a.burst[0], { x, y: y - 14, z: z + 1 });
                        d.puddle = extra(f, a.puddle[0], { x, y, z: z - 1, lit: d.lit });
                        for (let i = 0; i < 16; i++) {
                            const ang = -Math.PI * (0.1 + r() * 0.8);
                            const v = 60 + r() * 110;
                            toss(f, [a.gibs[i % a.gibs.length]], { x: x + (r() - 0.5) * 6, y: y - 10 - r() * 10, vx: Math.cos(ang) * v, vy: Math.sin(ang) * v, floor: y - 3 + Math.round(r() * 7), life });
                        }
                        toss(f, turns(FLYERS.zombie[1].pieces.hat), { x, y: y - 28, vx: (r() < 0.5 ? -1 : 1) * 40, vy: -190, floor: y + 1, life, turn: true });
                    });
                    if (d.burst) {
                        d.burst.visible = t < 240;
                        if (t >= 180) d.burst.texture = env.glow(a.burst[1]);
                    }
                    if (t >= 260 && d.puddle && !d.grown) {
                        d.grown = true;
                        d.puddle.texture = env.texture(a.puddle[1], d.lit);
                        d.puddle.anchor.set(a.puddle[1].anchor.x / a.puddle[1].w, a.puddle[1].anchor.y / a.puddle[1].h);
                    }
                    return null;
                }
                case 'dust': {
                    if (t < 160) return { def: a.hit, lit: d.lit };
                    if (t < 1120) {
                        const k = Math.min(5, Math.floor((t - 160) / 160));
                        // Grains trickle off the broken edge as it comes down.
                        if (t >= d.emitAt && k < 5) {
                            d.emitAt = t + 70;
                            const top = y - 31 + Math.round(k * 5.4);
                            toss(f, [a.grains[Math.floor(r() * 3)]], { x: x + (r() - 0.5) * 10, y: top, vx: (r() - 0.5) * 16, vy: 10, gravity: 200, floor: y - 1, life });
                        }
                        return { def: a.dust[k], lit: d.lit };
                    }
                    const k = Math.min(2, Math.floor((t - 1120) / 230));
                    once(f, 'blow' + k, () => {
                        for (let i = 0; i < 4; i++) {
                            const p = env.spawn([env.texture(a.grains[i % 3], d.lit)], x + (r() - 0.3) * 12, y - 1 - r() * 3, 30 + r() * 30, -6 - r() * 10, -4, 0.6 + r() * 0.4);
                            p.drag = 0.5;
                        }
                    });
                    return t < DEATH_MS ? { def: a.blown[k], lit: d.lit } : null;
                }
                case 'head': {
                    if (t < 120) return { def: a.hit, lit: d.lit };
                    once(f, 'off', () => {
                        const side = r() < 0.5 ? -1 : 1;
                        toss(f, a.head, { x, y: y - 26, vx: side * (55 + r() * 25), vy: -170 - r() * 40, floor: y - 3, life, turn: true }).drag = 0.4;
                    });
                    // The neck spurts for a moment as the body stands.
                    if (t < 420 && t >= d.emitAt) {
                        d.emitAt = t + 60;
                        toss(f, a.drops, { x: x + 1, y: y - 20, vx: (r() - 0.5) * 50, vy: -70 - r() * 50, floor: y + Math.round(r() * 3), life });
                    }
                    const h = a.headless;
                    return { def: t < 420 ? h.hit : t < 700 ? h.kneel : h.flat, lit: d.lit };
                }
                case 'fire': {
                    if (t < 160) return { def: a.hit, lit: d.lit };
                    once(f, 'light', () => {
                        d.flame = extra(f, a.fire[0], { x, y, z: z + 0.5 });
                    });
                    const dying = t >= 1300;
                    const frames = dying ? a.fireLow : a.fire;
                    const ff = frames[Math.floor(t / 90) % 4];
                    d.flame.texture = env.glow(ff);
                    d.flame.anchor.set(ff.anchor.x / ff.w, ff.anchor.y / ff.h);
                    d.flame.visible = t < DEATH_MS - 100;
                    if (t >= d.emitAt && t < DEATH_MS - 300) {
                        d.emitAt = t + 80;
                        const p = env.spawn([env.glow(a.sparks[Math.floor(r() * 3)])], x + (r() - 0.5) * 14, y - 10 - r() * 18, (r() - 0.5) * 20, -30 - r() * 30, -20, 0.5 + r() * 0.4);
                        p.drag = 0.8;
                    }
                    if (!dying) return { def: a.char[Math.min(3, Math.floor((t - 160) / 280))], lit: d.lit };
                    return { def: a.ash[Math.min(2, Math.floor((t - 1300) / 170))], lit: d.lit };
                }
                case 'sink': {
                    if (t < 160) return { def: a.hit, lit: d.lit };
                    once(f, 'hole', () => {
                        extra(f, a.hole, { x, y, z: z - 1, lit: d.lit });
                    });
                    if (t >= d.emitAt && t < 1500) {
                        d.emitAt = t + 110;
                        toss(f, [a.clods[Math.floor(r() * 4)]], { x: x + (r() - 0.5) * 16, y: y - 1, vx: (r() - 0.5) * 60, vy: -50 - r() * 50, floor: y + Math.round(r() * 2), life });
                    }
                    if (t < 460) return { def: a.hit, lit: d.lit, dx: Math.floor(t / 40) % 2 ? 1 : -1 };
                    const k = Math.floor((t - 460) / 150);
                    return k < a.sink.length ? { def: a.sink[k], lit: d.lit, dx: k < 5 ? (Math.floor(t / 50) % 2 ? 1 : 0) : 0 } : null;
                }
                default:
                    return t < 160 ? { def: a.hit, lit: d.lit } : null;
            }
        },
    };
}
