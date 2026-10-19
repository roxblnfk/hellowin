import {
    Application,
    Container,
    RenderTexture,
    Sprite,
    Texture,
    TextureSource,
} from 'https://cdn.jsdelivr.net/npm/pixi.js@8.22.0/dist/pixi.min.mjs';
import { ROOT, spreadLeft, spreadX } from '../layout.js';
import { PALETTE } from './palette.js';
import { DECOR, PARTICLES, bake, pumpkinLayers, pumpkinSprite, seedOf, silhouette } from './sprites.js';

// Artwork exists for the scene 0 stages; later stages reuse the last drawn one (docs/spec/scenario.md).
const DRAWN_STAGES = 4;
const SIZES = ['s', 'm', 'l'];
// Back to front; glow sits above back and under body and stays hidden by day.
const LAYERS = ['back', 'glow', 'body', 'rim', 'stem'];

const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
const css = (color) => '#' + color.toString(16).padStart(6, '0');

function rng(seed) {
    return () => {
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function canvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    return c;
}

// Middle of the topmost drawn row: the stem tip of a whole pumpkin.
function stemColumn(def) {
    const row = def.rows.find((r) => [...r].some((ch) => def.legend[ch])) ?? '';
    const xs = [...row].flatMap((ch, x) => (def.legend[ch] ? [x] : []));
    return xs.length ? Math.round((xs[0] + xs[xs.length - 1]) / 2) : def.anchor.x;
}

function texture(source) {
    const t = Texture.from(source);
    t.source.scaleMode = 'nearest';
    return t;
}

/**
 * Integer art-pixel grid for the current viewport: every art pixel is `scale` device pixels.
 */
function measure(rootEl) {
    const dpr = window.devicePixelRatio || 1;
    const width = rootEl.clientWidth || window.innerWidth;
    const height = rootEl.clientHeight || window.innerHeight;
    const scale = clamp(Math.floor(Math.min(width * dpr, height * dpr * 1.3) / 300), 2, 5);
    const artW = Math.ceil((width * dpr) / scale);
    const artH = Math.ceil((height * dpr) / scale);
    return {
        dpr,
        width,
        height,
        scale,
        cssPx: scale / dpr,
        artW,
        artH,
        // A low camera: the horizon sits well above the middle, leaving the upper part to the sky and far fields.
        horizon: Math.round(artH * 0.37),
    };
}

/**
 * Pixi scene of docs/spec/frontend.md. The scene keeps no game state of its own beyond what the calls pass in.
 *
 * @returns {Promise<null|{render: Function, click: Function, pumpkin: Function, setScene: Function, perch: Function, step: Function}>}
 *          null when no renderer can start.
 */
export async function createScene(rootEl, snapshot) {
    TextureSource.defaultOptions.scaleMode = 'nearest';

    let layout = measure(rootEl);
    const app = new Application();
    try {
        await app.init({
            width: layout.width,
            height: layout.height,
            resolution: layout.dpr,
            autoDensity: true,
            antialias: false,
            roundPixels: true,
            background: PALETTE.soil,
            preference: 'webgl',
        });
    } catch (error) {
        console.warn('pixel scene is unavailable', error);
        return null;
    }
    // Pixi falls back to its 2D canvas renderer, which does not draw the art-resolution render texture here; the
    // page then keeps its emoji buttons instead (docs/spec/frontend.md).
    if (app.renderer.name !== 'webgl' && app.renderer.name !== 'webgpu') {
        app.destroy(true);
        return null;
    }

    app.canvas.classList.add('scene-canvas');
    app.canvas.setAttribute('aria-hidden', 'true');
    rootEl.prepend(app.canvas);

    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let reduced = motion.matches;
    motion.addEventListener?.('change', (e) => (reduced = e.matches));

    // The world is drawn at art resolution into a texture and blown up by an integer factor: whatever moves or
    // squashes inside it still lands on the shared art-pixel grid.
    const world = new Container();
    const skyLayer = new Container();
    const cloudLayer = new Container();
    const baseLayer = new Container();
    const treeLayer = new Container();
    const plantLayer = new Container();
    const bugLayer = new Container();
    const pumpkinLayer = new Container();
    const particleLayer = new Container();
    const flashLayer = new Container();
    world.addChild(skyLayer, cloudLayer, baseLayer, treeLayer, plantLayer, bugLayer, pumpkinLayer, particleLayer, flashLayer);

    let target = null;
    const display = new Sprite();
    app.stage.addChild(display);

    const art = new Map();
    const artTexture = (key, make) => {
        if (!art.has(key)) {
            art.set(key, make());
        }
        return art.get(key);
    };

    function pumpkinArt(stage, size, seed = 0) {
        const drawn = clamp(stage, 0, DRAWN_STAGES);
        return artTexture(`pumpkin:${drawn}:${size}:${seed}`, () => {
            // The composite gives the outline and the flash; the scene draws the layers separately.
            const def = pumpkinSprite(drawn, size, seed);
            const { layers } = pumpkinLayers(drawn, size, seed);
            const edge = bake(silhouette(def, 'pumpkinShine'));
            const ring = canvas(def.w + 2, def.h + 2);
            const ctx = ring.getContext('2d');
            for (const [dx, dy] of [[0, 1], [2, 1], [1, 0], [1, 2]]) {
                ctx.drawImage(edge, dx, dy);
            }
            return {
                def,
                layers: Object.fromEntries(LAYERS.map((name) => [name, layers[name] ? texture(bake(layers[name])) : null])),
                ring: texture(ring),
                flash: texture(bake(silhouette(def, 'white'))),
            };
        });
    }

    const particleArt = (kind) => artTexture(`particle:${kind}`, () => PARTICLES[kind].map((p) => texture(bake(p))));

    let state = {
        scene: snapshot.scene ?? 0,
        stageMax: snapshot.stageMax ?? DRAWN_STAGES,
        threshold: snapshot.threshold ?? 1,
        elements: snapshot.elements ?? [],
    };
    const pumpkins = new Map();
    const particles = [];
    let clouds = [];
    let bugs = [];
    let perch = { x: 0, y: 0 };
    let clock = 0;

    function toArt(element) {
        return {
            x: Math.round((spreadLeft(element.x, layout.width) / 100) * layout.width / layout.cssPx),
            y: Math.round((element.y / 100) * layout.height / layout.cssPx),
        };
    }

    function drawSky() {
        const { artW, horizon } = layout;
        const c = canvas(artW, horizon + 1);
        const ctx = c.getContext('2d');
        ctx.fillStyle = css(PALETTE.sky);
        ctx.fillRect(0, 0, artW, horizon + 1);
        // A dithered band toward the horizon instead of a gradient: two palette colors, no blending.
        const band = farPlan() + 6;
        ctx.fillStyle = css(PALETTE.skyLight);
        for (let y = horizon - band; y <= horizon; y++) {
            const density = (y - (horizon - band)) / band;
            for (let x = 0; x < artW; x++) {
                const checker = (x + y) % 2 === 0;
                if (density > 0.66 || (density > 0.33 && checker) || (checker && x % 4 === 0)) {
                    ctx.fillRect(x, y, 1, 1);
                }
            }
        }
        skyLayer.removeChildren().forEach((s) => s.destroy());
        skyLayer.addChild(new Sprite(texture(c)));

        const sunDef = DECOR.sun;
        const sun = new Sprite(artTexture('sun', () => texture(bake(sunDef))));
        sun.position.set(Math.round(artW * 0.82) - (sunDef.w >> 1), Math.max(2, Math.round((horizon - farPlan() - sunDef.h) * 0.3)));
        skyLayer.addChild(sun);

        cloudLayer.removeChildren().forEach((s) => s.destroy());
        const random = rng(7);
        const count = Math.max(2, Math.round(artW / 90));
        clouds = Array.from({ length: count }, (_, i) => {
            const def = DECOR.cloud[i % DECOR.cloud.length];
            const sprite = new Sprite(artTexture(`cloud:${i % DECOR.cloud.length}`, () => texture(bake(def))));
            cloudLayer.addChild(sprite);
            return {
                sprite,
                w: def.w,
                x: random() * artW,
                y: 1 + Math.floor(random() * Math.max(1, horizon - farPlan() - def.h - 3)),
                speed: 1.5 + random() * 2.5,
            };
        });
    }

    function stamp(ctx, def, x, y) {
        ctx.drawImage(bake(def), Math.round(x - def.anchor.x), Math.round(y - def.anchor.y));
    }

    /**
     * The patch seen at an angle: an ellipse around the root, wider than tall, in art pixels.
     */
    function patchShape() {
        const { artW, artH, horizon } = layout;
        const c = toArt(ROOT);
        // A compact patch with lawn around it, widened on narrow screens together with the pumpkins (layout.js).
        const spread = spreadX(layout.width);
        return {
            cx: c.x,
            cy: c.y,
            rx: artW * Math.min(0.47, 0.29 * spread),
            ry: Math.min(artH * 0.24, c.y - horizon - 3),
        };
    }

    const inPatch = (shape, x, y) => ((x - shape.cx) / shape.rx) ** 2 + ((y - shape.cy) / shape.ry) ** 2;

    // The same test with a lumpy rim: the radius wobbles with the angle, so the edge is not a clean ellipse.
    function inRagged(shape, x, y) {
        const a = Math.atan2((y - shape.cy) / shape.ry, (x - shape.cx) / shape.rx);
        const wobble = 1 + 0.05 * Math.sin(5 * a + 1) + 0.035 * Math.sin(11 * a + 2) + 0.02 * Math.sin(23 * a);
        return inPatch(shape, x, y) / (wobble * wobble);
    }

    /** A point on the patch rim at screen angle `deg` (−90 is the far side), `r` times the radius. */
    function onRim(shape, deg, r) {
        const a = (deg * Math.PI) / 180;
        return { x: shape.cx + Math.cos(a) * shape.rx * r, y: shape.cy + Math.sin(a) * shape.ry * r };
    }

    // Props around the patch: a low wattle fence along the far rim, hay, a basket, a watering can and stones at the
    // near rim. Only decoration: kept outside the patch, so they never cover a pumpkin's hit zone, and away from the
    // screen sides where the duck bushes sit.
    function drawProps(ctx, shape, near) {
        const fences = DECOR.fence;
        const fence = fences[0];
        const end = DECOR.fenceEnd;
        const span = spreadX(layout.width) > 1.3 ? 40 : 60;
        const arc = [];
        for (let deg = -90 - span; deg <= -90 + span; deg += 0.5) arc.push(onRim(shape, deg, 1.06));
        const posts = [arc[0]];
        let run = 0;
        for (let i = 1; i < arc.length; i++) {
            run += Math.hypot(arc[i].x - arc[i - 1].x, arc[i].y - arc[i - 1].y);
            if (run >= fence.w - 1) {
                posts.push(arc[i]);
                run = 0;
            }
        }
        if (!near) {
            for (let i = 1; i < posts.length; i++) {
                const a = posts[i - 1];
                const b = posts[i];
                stamp(ctx, fences[i % fences.length], (a.x + b.x) / 2, Math.max(a.y, b.y));
            }
            stamp(ctx, end, posts[0].x, posts[0].y);
            stamp(ctx, end, posts[posts.length - 1].x, posts[posts.length - 1].y);
            return;
        }
        const stones = DECOR.stone;
        const items = [
            { def: DECOR.hay, at: onRim(shape, 18, 1.16) },
            { def: DECOR.basket, at: onRim(shape, 158, 1.12) },
            { def: DECOR.can, at: onRim(shape, 52, 1.08) },
            { def: stones[0], at: onRim(shape, 104, 1.07) },
            { def: stones[stones.length - 1], at: onRim(shape, 206, 1.06) },
        ];
        // On a phone the patch reaches the screen sides: pull props back in, or drop one that would sit far from its spot.
        for (const { def, at } of items.sort((a, b) => a.at.y - b.at.y)) {
            const x = clamp(at.x, def.w / 2 + 2, layout.artW - def.w / 2 - 2);
            if (Math.abs(x - at.x) < def.w * 0.75) stamp(ctx, def, x, at.y);
        }
    }

    // Height of the far plan above the horizon; clouds and the sun stay above it.
    const farPlan = () => Math.max(DECOR.hills.h, ...DECOR.farTree.map((t) => t.h));

    // Far plan above the horizon, then the lawn and the dug patch seen from a low camera: furrows converge toward a
    // vanishing point above the horizon, mowing lines crowd together into the distance, weeds shrink and thin out.
    function drawBase() {
        const { artW, artH, horizon } = layout;
        const c = canvas(artW, artH);
        const ctx = c.getContext('2d');
        const random = rng(42);
        const shape = patchShape();

        // Far plan: the hills strip tiled along the horizon, sparse far trees in front of it.
        const hills = bake(DECOR.hills);
        for (let x = 0; x < artW; x += DECOR.hills.w) {
            ctx.drawImage(hills, x - DECOR.hills.anchor.x, horizon - DECOR.hills.anchor.y);
        }
        const farTrees = [];
        for (let x = 4 + random() * 20; x < artW; x += 18 + random() * 40) {
            farTrees.push({ def: DECOR.farTree[Math.floor(random() * DECOR.farTree.length)], x, y: horizon - Math.floor(random() * 2) });
        }
        farTrees.sort((a, b) => a.y - b.y).forEach((t) => stamp(ctx, t.def, t.x, t.y));

        const image = ctx.createImageData(artW, artH - horizon);
        const rgb = (key) => [(PALETTE[key] >> 16) & 255, (PALETTE[key] >> 8) & 255, PALETTE[key] & 255];
        const COLORS = Object.fromEntries(['soil', 'soilDark', 'soilLight', 'leaf', 'leafDark', 'leafLight', 'leafShine'].map((k) => [k, rgb(k)]));
        const vp = { x: shape.cx, y: horizon - (shape.cy - horizon) * 0.6 };
        const fan = (shape.cy + shape.ry - vp.y) / 10;
        for (let y = horizon; y < artH; y++) {
            const depth = (y - horizon) / (artH - horizon);
            for (let x = 0; x < artW; x++) {
                const d = inRagged(shape, x, y);
                const checker = (x + y) % 2 === 0;
                let key;
                if (d < 0.9 || (d < 1 && checker)) {
                    const lane = (((x - vp.x) / (y - vp.y)) * fan) % 1;
                    const f = lane < 0 ? lane + 1 : lane;
                    key = f < 0.2 ? 'soilDark'
                        : f < 0.3 ? 'soilLight'
                        : random() < 0.03 ? (random() < 0.5 ? 'soilDark' : 'soilLight')
                        : 'soil';
                } else if (d < 1.1 && random() < (1.1 - d) * 6) {
                    // Clods of dug soil scattered over the rim.
                    key = random() < 0.6 ? 'soilDark' : 'soilLight';
                } else {
                    const mow = (Math.sqrt(y - horizon + 1) * 1.7) % 1 < 0.14;
                    const n = random();
                    // Warmer, sunlit grass right around the patch.
                    const warm = Math.max(0, 1.6 - d) * 0.12;
                    key = n < warm * 0.5 ? 'leafShine'
                        : n < 0.05 * depth + warm ? 'leafLight'
                        : mow || n < 0.04 + 0.12 * depth || (depth > 0.4 && y % 4 === 0 && checker) ? 'leafDark'
                        : 'leaf';
                }
                const [r, g, b] = COLORS[key];
                const i = ((y - horizon) * artW + x) * 4;
                image.data[i] = r;
                image.data[i + 1] = g;
                image.data[i + 2] = b;
                image.data[i + 3] = 255;
            }
        }
        ctx.putImageData(image, 0, horizon);

        // Grass blades leaning in over the soil along the rim.
        for (let i = 0; i < shape.rx * 6; i++) {
            const p = onRim(shape, random() * 360, 0.9 + random() * 0.08);
            const x = Math.round(p.x);
            const y = Math.round(p.y);
            const tall = 2 + Math.floor(random() * 2);
            ctx.fillStyle = css(PALETTE[random() < 0.5 ? 'leaf' : 'leafDark']);
            ctx.fillRect(x, y - tall + 1, 1, tall);
            if (random() < 0.4) ctx.fillRect(x + 1, y - 1, 1, 1);
        }
        drawProps(ctx, shape, false);

        // Rejection sampling: sparse inside the patch, denser outside and toward the edges, rarer and smaller far off.
        const weeds = DECOR.weed;
        const smallest = DECOR.grass.reduce((a, b) => (a.w * a.h <= b.w * b.h ? a : b));
        const tries = Math.round((artW * (artH - horizon)) / 16);
        const picks = [];
        for (let i = 0; i < tries; i++) {
            const x = random() * artW;
            const y = horizon + 2 + random() * (artH - horizon - 2);
            const depth = (y - horizon) / (artH - horizon);
            const d = inPatch(shape, x, y);
            const edge = Math.max(1 - x / (artW * 0.18), 1 - (artW - x) / (artW * 0.18), 1 - (artH - y) / (artH * 0.15), 0);
            const chance = (d < 1 ? 0.012 : Math.min(0.9, 0.05 + (d - 1) * 0.5 + edge * 0.6)) * (0.15 + 0.85 * depth);
            if (random() >= chance) continue;
            let def;
            if (depth < 0.25) {
                def = smallest;
            } else {
                const list = d < 1 ? DECOR.grass : random() < 0.08 && DECOR.flower?.length ? DECOR.flower : random() < 0.55 ? weeds : DECOR.grass;
                def = list[Math.floor(random() * list.length)];
            }
            picks.push({ def, x, y });
        }
        picks.sort((a, b) => a.y - b.y).forEach((p) => stamp(ctx, p.def, p.x, p.y));
        drawProps(ctx, shape, true);

        baseLayer.removeChildren().forEach((s) => s.destroy());
        baseLayer.addChild(new Sprite(texture(c)));
    }

    // At the edge of the far plan, behind the patch in depth: vines and pumpkins cover its foot.
    function drawTree() {
        const def = DECOR.tree;
        const { artW, horizon } = layout;
        const [px, py] = def.perch ?? [def.w - 1, def.h >> 1];
        // The branch reaches toward the middle; on a narrow screen the crown slides past the left edge instead.
        const foot = {
            x: Math.round(Math.max(artW * 0.07, artW * 0.32 - (px - def.anchor.x))),
            y: horizon + 3,
        };
        treeLayer.removeChildren().forEach((s) => s.destroy());
        const sprite = new Sprite(artTexture('tree', () => texture(bake(def))));
        sprite.position.set(foot.x - def.anchor.x, foot.y - def.anchor.y);
        treeLayer.addChild(sprite);
        perch = { x: (foot.x - def.anchor.x + px) * layout.cssPx, y: (foot.y - def.anchor.y + py) * layout.cssPx };
    }

    /**
     * Samples a cubic curve with a sideways wave on top of it, about 3 points per art pixel. The wave fades out at
     * both ends, so the vine still starts at `from` and lands exactly on `to`.
     */
    function wavyCurve(from, c1, c2, to, wave) {
        const length = Math.hypot(to.x - from.x, to.y - from.y) || 1;
        const steps = Math.ceil(length * 3);
        const raw = [];
        for (let i = 0; i <= steps; i++) {
            const t = i / steps;
            const u = 1 - t;
            raw.push({
                t,
                x: u * u * u * from.x + 3 * u * u * t * c1.x + 3 * u * t * t * c2.x + t * t * t * to.x,
                y: u * u * u * from.y + 3 * u * u * t * c1.y + 3 * u * t * t * c2.y + t * t * t * to.y,
            });
        }
        const points = [];
        let travelled = 0;
        raw.forEach((p, i) => {
            const a = raw[Math.max(0, i - 1)];
            const b = raw[Math.min(raw.length - 1, i + 1)];
            const tl = Math.hypot(b.x - a.x, b.y - a.y) || 1;
            const nx = -(b.y - a.y) / tl;
            const ny = (b.x - a.x) / tl;
            const off = wave.amp * Math.sin(Math.PI * p.t) * Math.sin(2 * Math.PI * wave.turns * p.t + wave.phase);
            const fx = p.x + nx * off;
            const fy = p.y + ny * off;
            const last = points[points.length - 1];
            travelled += last ? Math.hypot(fx - last.fx, fy - last.fy) : 0;
            points.push({ x: Math.round(fx), y: Math.round(fy), fx, fy, t: p.t, d: travelled, nx, ny });
        });
        return points;
    }

    // Shadow first, one pixel lower, then the body and a lit top edge: where vines cross, the upper one reads as
    // lying on top. The body is a lighter green than the leaves, so the runners stay visible through the foliage.
    function strokeVine(ctx, points, width) {
        const pass = (key, grow, dy) => {
            ctx.fillStyle = css(PALETTE[key]);
            for (const p of points) {
                const w = Math.max(1, Math.round(width(p.t)) + grow);
                ctx.fillRect(p.x - (w >> 1), p.y - (w >> 1) + dy, w, w);
            }
        };
        pass('leafDark', 0, 1);
        pass('leafLight', -1, 0);
        ctx.fillStyle = css(PALETTE.leafShine);
        for (const p of points) {
            if (width(p.t) >= 3) ctx.fillRect(p.x - 1, p.y - (Math.round(width(p.t)) >> 1), 1, 1);
        }
    }

    function sprinkleLeaves(points, random, foliage, { gap, from = 0, until, sizeAt, offset }) {
        const leaves = DECOR.leaf;
        let flip = random() < 0.5 ? 1 : -1;
        let next = from + gap[0] * random();
        for (const p of points) {
            if (p.d > until) break;
            if (p.d < next) continue;
            next = p.d + gap[0] + random() * (gap[1] - gap[0]);
            const leaf = leaves[clamp(Math.floor(sizeAt(p.t) * leaves.length + (random() - 0.5) * 1.5), 0, leaves.length - 1)];
            const off = offset + random() * 3;
            foliage.push({ def: leaf, x: p.x + p.nx * flip * off, y: p.y + p.ny * flip * off + leaf.anchor.y - (leaf.h >> 1) });
            flip = -flip;
        }
    }

    /**
     * One pumpkin vine: a winding S-shaped runner from the root to the stem tip, thinning from 4 to 2 art pixels,
     * with 1–3 short side shoots that end in a tendril and a leaf. The last control point sits above and beside the
     * stem, so the runner hooks down onto it from above even for pumpkins above the root. Shape comes from the
     * pumpkin's seed: the same pumpkin grows the same vine on every screen. Leaves and tendrils are only collected,
     * so foliage of all vines is drawn afterwards in depth order.
     */
    function drawVine(ctx, from, stem, reach, seed, foliage) {
        const random = rng(seed);
        const side = random() < 0.5 ? 1 : -1;
        const toward = Math.sign(from.x - stem.x) || side;
        const dx = stem.x - from.x;
        const dy = stem.y - from.y;
        const length = Math.hypot(dx, dy) || 1;
        const bend = side * length * (0.1 + random() * 0.15);
        const c1 = { x: from.x + dx * 0.35 - (dy / length) * bend, y: from.y + dy * 0.35 + (dx / length) * bend };
        const c2 = { x: stem.x + toward * reach, y: stem.y - 12 };
        const wave = { amp: clamp(length * 0.06, 2, 7), turns: 1.5 + random(), phase: random() * Math.PI * 2 };
        const points = wavyCurve(from, c1, c2, stem, wave);
        const travelled = points[points.length - 1].d;

        const shoots = 1 + Math.floor(random() * 2);
        for (let i = 0; i < shoots; i++) {
            const base = points[Math.floor((0.2 + random() * 0.55) * (points.length - 1))];
            const out = random() < 0.5 ? 1 : -1;
            const len = 10 + random() * 12;
            const tip = { x: base.fx + base.nx * out * len, y: base.fy + base.ny * out * len * 0.7 };
            const mid = { x: (base.fx + tip.x) / 2, y: (base.fy + tip.y) / 2 };
            const shoot = wavyCurve(base, mid, mid, tip, { amp: 1.5, turns: 1, phase: random() * Math.PI });
            strokeVine(ctx, shoot, (t) => 2 - t);
            // Leaves gather at the fork and at the tip; the shoot itself stays bare so it reads as a branch.
            const forkLeaf = DECOR.leaf[2];
            if (random() < 0.5) foliage.push({ def: forkLeaf, x: base.fx - base.nx * out * 4, y: base.fy - base.ny * out * 4 + forkLeaf.anchor.y - (forkLeaf.h >> 1) });
            const tendril = DECOR.tendril[Math.floor(random() * DECOR.tendril.length)];
            foliage.push({ def: tendril, x: tip.x + out * 3, y: tip.y + tendril.anchor.y - (tendril.h >> 1) });
            for (const [k, dx] of [[1, -out * 3]]) {
                const leaf = DECOR.leaf[k];
                foliage.push({ def: leaf, x: tip.x + dx, y: tip.y - k + leaf.anchor.y - (leaf.h >> 1) });
            }
        }
        strokeVine(ctx, points, (t) => 4 - 2 * t);

        // Leaves only on the outer half: the inner half shows bare runners fanning out of the root heap. None on
        // the hook over the stem.
        sprinkleLeaves(points, random, foliage, { gap: [14, 20], from: Math.max(22, travelled * 0.45), until: travelled - 12, sizeAt: (t) => 0.3 + t * 0.6, offset: 6 });
        let nextTendril = 10 + random() * 10;
        for (const p of points) {
            if (p.d > travelled - 10) break;
            if (p.d < nextTendril) continue;
            nextTendril = p.d + 22 + random() * 14;
            const t = DECOR.tendril[Math.floor(random() * DECOR.tendril.length)];
            foliage.push({ def: t, x: p.x - p.nx * side * 3, y: p.y - p.ny * side * 3 + t.anchor.y - (t.h >> 1) });
        }
    }

    function drawPlants() {
        const { artW, artH } = layout;
        const c = canvas(artW, artH);
        const ctx = c.getContext('2d');
        const random = rng(9);
        const fill = (key, x, y, w = 1, h = 1) => {
            ctx.fillStyle = css(PALETTE[key]);
            ctx.fillRect(x, y, w, h);
        };

        for (const element of state.elements) {
            const { def } = pumpkinArt(element.stage ?? 0, element.size ?? 'm', seedOf(element.id));
            const p = toArt(element);
            const w = Math.max(4, Math.round(def.w * 0.8));
            fill('soilDark', p.x - (w >> 1), p.y - 1, w, 2);
            fill('soilDark', p.x - (w >> 1) + 1, p.y + 1, w - 2, 1);
        }

        const root = toArt(ROOT);
        const foliage = [];
        for (const element of state.elements) {
            const seed = seedOf(element.id);
            const def = pumpkinSprite(0, element.size ?? 'm', seed);
            const p = toArt(element);
            const stem = { x: p.x - def.anchor.x + stemColumn(def), y: p.y - def.anchor.y + 1 };
            drawVine(ctx, root, stem, (def.w >> 1) + 3, seed, foliage);
        }

        // A heap of the largest leaves around the root; the root itself stays on top of it.
        const heapLeaves = DECOR.bigLeaf ? [DECOR.bigLeaf].flat() : DECOR.leaf.slice(0, 2);
        const heap = Math.round(clamp(artW / 30, 10, 16));
        for (let i = 0; i < heap; i++) {
            const a = random() * Math.PI * 2;
            const r = Math.sqrt(random());
            const leaf = heapLeaves[Math.floor(random() * heapLeaves.length)];
            foliage.push({ def: leaf, x: root.x + Math.cos(a) * r * 11, y: root.y + Math.sin(a) * r * 5 });
        }
        foliage.sort((a, b) => a.y - b.y).forEach((f) => stamp(ctx, f.def, f.x, f.y));
        stamp(ctx, DECOR.root, root.x, root.y);

        plantLayer.removeChildren().forEach((s) => s.destroy());
        plantLayer.addChild(new Sprite(texture(c)));
    }

    // Two butterflies drifting lazily over the lawn on either side of the patch: a 3×1 open and a 1×2 closed frame.
    function placeBugs() {
        bugLayer.removeChildren().forEach((c) => c.destroy());
        const shape = patchShape();
        const frame = (w, h, key) => {
            const c = canvas(w, h);
            const ctx = c.getContext('2d');
            ctx.fillStyle = css(PALETTE[key]);
            ctx.fillRect(0, 0, w, h);
            return texture(c);
        };
        bugs = [
            { key: 'white', at: onRim(shape, 200, 1.35), phase: 0 },
            { key: 'sun', at: onRim(shape, -20, 1.3), phase: 7 },
        ].map(({ key, at, phase }) => {
            const open = artTexture(`bug:open:${key}`, () => frame(3, 1, key));
            const closed = artTexture(`bug:closed:${key}`, () => frame(1, 2, key));
            const sprite = new Sprite(open);
            bugLayer.addChild(sprite);
            return { sprite, open, closed, x: at.x, y: at.y - 6, phase };
        });
    }

    function pumpkinMode(p) {
        if (p.stage >= state.stageMax) return 'done';
        if (p.clicks >= state.threshold) return 'ready';
        return 'idle';
    }

    function applyArt(p) {
        const a = pumpkinArt(p.stage, p.size, p.seed);
        p.art = a;
        for (const name of LAYERS) {
            const sprite = p.layers[name];
            sprite.texture = a.layers[name] ?? Texture.EMPTY;
            sprite.visible = Boolean(a.layers[name]) && name !== 'glow';
            sprite.position.set(-a.def.anchor.x, -a.def.anchor.y);
        }
        p.ring.texture = a.ring;
        p.ring.position.set(-a.def.anchor.x - 1, -a.def.anchor.y - 1);
    }

    function buildPumpkins() {
        pumpkinLayer.removeChildren().forEach((c) => c.destroy({ children: true }));
        flashLayer.removeChildren().forEach((c) => c.destroy());
        pumpkins.clear();
        const random = rng(3);
        const sorted = [...state.elements].sort((a, b) => a.y - b.y);
        for (const element of sorted) {
            const node = new Container();
            const ring = new Sprite();
            // The figure's origin is the anchor point, so squash and sway pivot on the pumpkin's base.
            const body = new Container();
            const layers = Object.fromEntries(LAYERS.map((name) => [name, new Sprite()]));
            body.addChild(...LAYERS.map((name) => layers[name]));
            node.addChild(ring, body);
            pumpkinLayer.addChild(node);
            const p = {
                id: element.id,
                size: element.size ?? 'm',
                stage: element.stage ?? 0,
                clicks: element.clicks ?? 0,
                home: toArt(element),
                node,
                seed: seedOf(element.id),
                ring,
                body,
                layers,
                phase: random() * 1000,
                hit: -1e9,
                bounce: -1e9,
                flash: null,
            };
            applyArt(p);
            pumpkins.set(p.id, p);
        }
    }

    // Hit zones are the core of the body, not the whole sprite: in a tight patch near pumpkins overlap far ones,
    // and only the cores have to stay apart (app/config/hellowin.php is laid out for that).
    function publishHitZones() {
        for (const size of SIZES) {
            const { def } = pumpkinArt(0, size);
            const px = layout.cssPx;
            const w = Math.round(def.w * 0.72);
            const h = Math.round(def.h * 0.72);
            const lift = Math.round(def.h * 0.06);
            rootEl.style.setProperty(`--hit-w-${size}`, `${w * px}px`);
            rootEl.style.setProperty(`--hit-h-${size}`, `${h * px}px`);
            rootEl.style.setProperty(`--hit-x-${size}`, `${-(w >> 1) * px}px`);
            rootEl.style.setProperty(`--hit-y-${size}`, `${(def.h - def.anchor.y - lift - h) * px}px`);
        }
        rootEl.style.setProperty('--px', `${layout.cssPx}px`);
    }

    function rebuild() {
        layout = measure(rootEl);
        app.renderer.resize(layout.width, layout.height);
        target?.destroy(true);
        target = RenderTexture.create({ width: layout.artW, height: layout.artH, resolution: 1, scaleMode: 'nearest' });
        display.texture = target;
        display.scale.set(layout.cssPx);
        drawSky();
        drawBase();
        drawTree();
        drawPlants();
        placeBugs();
        buildPumpkins();
        for (const particle of particles.splice(0)) particle.sprite.destroy();
        publishHitZones();
        tick({ deltaMS: 0 });
        app.render();
    }

    function spawn(kind, x, y, vx, vy, gravity, life) {
        const textures = particleArt(kind);
        const sprite = new Sprite(textures[Math.floor(Math.random() * textures.length)]);
        particleLayer.addChild(sprite);
        particles.push({ sprite, x, y, vx, vy, gravity, life, age: 0 });
    }

    function emitPoint(p, key) {
        const { def } = p.art;
        const [ex, ey] = def.emit?.[key] ?? [def.anchor.x, def.h >> 1];
        return { x: p.home.x - def.anchor.x + ex, y: p.home.y - def.anchor.y + ey };
    }

    const between = (a, b) => a + Math.random() * (b - a);
    const sign = () => (Math.random() < 0.5 ? -1 : 1);

    function burst(p, stage) {
        if (stage >= state.stageMax) {
            const at = emitPoint(p, 'top');
            for (let i = 0; i < 2; i++) {
                spawn('spark', at.x, at.y - 1, sign() * between(10, 30), -between(20, 45), 60, 0.4);
            }
        } else if (stage >= 3) {
            const at = emitPoint(p, 'top');
            for (let i = 0; i < 8; i++) {
                spawn(i % 3 === 0 ? 'pulp' : 'seed', at.x, at.y, sign() * between(4, 24), -between(60, 100), 260, between(0.8, 1.1));
            }
        } else {
            const at = emitPoint(p, 'cut');
            const count = 4 + Math.floor(Math.random() * 3);
            for (let i = 0; i < count; i++) {
                spawn('chunk', at.x, at.y, sign() * between(20, 60), -between(40, 90), 220, between(0.6, 1));
            }
        }
    }

    // Squash-and-shake frames after a click: [scaleX, scaleY, offsetX], 50 ms each.
    const HIT = [[1.15, 0.85, 1], [0.94, 1.08, -1], [1.03, 0.97, 0]];
    // Bounce frames after a stage change: [scaleX, scaleY, offsetY].
    const BOUNCE = [[0.88, 1.15, -3], [0.95, 1.06, -2], [1, 1, -1], [1.12, 0.9, 0], [1.04, 0.96, 0]];

    function animatePumpkin(p) {
        const mode = pumpkinMode(p);
        let sx = 1;
        let sy = 1;
        let ox = 0;
        let oy = 0;
        let skew = 0;
        const hit = Math.floor((clock - p.hit) / 50);
        const bounce = Math.floor((clock - p.bounce) / 60);
        if (hit >= 0 && hit < HIT.length) {
            [sx, sy, ox] = HIT[hit];
        } else if (bounce >= 0 && bounce < BOUNCE.length) {
            [sx, sy, oy] = BOUNCE[bounce];
        } else if (!reduced && mode === 'ready' && (clock + p.phase) % 1400 < 120) {
            oy = -1;
        } else if (!reduced && mode === 'done') {
            skew = Math.sin((clock + p.phase) / 650) * 0.07;
        }
        p.ring.visible = mode === 'ready' && (reduced || Math.floor((clock + p.phase) / 220) % 2 === 0);
        p.body.scale.set(sx, sy);
        p.body.skew.x = skew;
        p.node.position.set(p.home.x + ox, p.home.y + oy);

        if (p.flash && clock - p.flash.born > 70) {
            p.flash.sprite.destroy();
            p.flash = null;
        }
    }

    function tick(ticker) {
        const dt = Math.min(ticker.deltaMS, 50);
        clock += dt;
        const seconds = dt / 1000;

        if (!reduced) {
            for (const cloud of clouds) {
                cloud.x += cloud.speed * seconds;
                if (cloud.x > layout.artW) cloud.x = -cloud.w;
                cloud.sprite.position.set(Math.round(cloud.x), cloud.y);
            }
        } else {
            for (const cloud of clouds) cloud.sprite.position.set(Math.round(cloud.x), cloud.y);
        }

        pumpkins.forEach(animatePumpkin);
        for (const bug of bugs) {
            bug.sprite.visible = !reduced;
            const t = clock / 1000 + bug.phase;
            const x = bug.x + Math.sin(t * 0.31) * 16 + Math.sin(t * 0.9) * 3;
            const y = bug.y + Math.sin(t * 0.47) * 5 - Math.abs(Math.sin(t * 2.3)) * 2;
            bug.sprite.texture = Math.floor(clock / 160 + bug.phase) % 2 ? bug.open : bug.closed;
            bug.sprite.position.set(Math.round(x), Math.round(y));
        }

        for (let i = particles.length - 1; i >= 0; i--) {
            const particle = particles[i];
            particle.age += seconds;
            if (particle.age >= particle.life) {
                particle.sprite.destroy();
                particles.splice(i, 1);
                continue;
            }
            particle.vy += particle.gravity * seconds;
            particle.x += particle.vx * seconds;
            particle.y += particle.vy * seconds;
            particle.sprite.position.set(Math.round(particle.x), Math.round(particle.y));
            // Blink out instead of fading: alpha blending would add colors outside the palette.
            particle.sprite.visible = particle.age < particle.life * 0.7 || Math.floor(particle.age * 20) % 2 === 0;
        }

        app.renderer.render({ container: world, target, clear: true });
    }

    rebuild();
    app.ticker.add(tick);

    function layoutChanged() {
        const next = measure(rootEl);
        return next.width !== layout.width || next.height !== layout.height || next.dpr !== layout.dpr;
    }

    // The first change rebuilds at once, before the next paint, so no strip of the old size shows; a drag-resize
    // then settles with one trailing rebuild.
    let resizeTimer = 0;
    let lastRebuild = 0;
    new ResizeObserver(() => {
        clearTimeout(resizeTimer);
        if (!layoutChanged()) return;
        if (performance.now() - lastRebuild > 200) {
            lastRebuild = performance.now();
            rebuild();
        } else {
            resizeTimer = setTimeout(() => layoutChanged() && rebuild(), 200);
        }
    }).observe(rootEl);

    return {
        render(next) {
            state = {
                scene: next.scene ?? 0,
                stageMax: next.stageMax ?? state.stageMax,
                threshold: next.threshold ?? state.threshold,
                elements: next.elements ?? [],
            };
            rebuild();
        },

        click(id, { stage, clicks, threshold }) {
            const p = pumpkins.get(id);
            if (!p) return;
            state.threshold = threshold ?? state.threshold;
            p.clicks = clicks ?? p.clicks;
            if (stage !== undefined && stage !== p.stage) {
                p.stage = stage;
                applyArt(p);
            }
            if (reduced) return;
            p.hit = clock;
            burst(p, p.stage);
        },

        pumpkin(id, stage) {
            const p = pumpkins.get(id);
            if (!p) return;
            p.stage = stage;
            p.clicks = 0;
            applyArt(p);
            if (reduced) return;
            p.flash?.sprite.destroy();
            const sprite = new Sprite(p.art.flash);
            sprite.position.set(p.home.x - p.art.def.anchor.x, p.home.y - p.art.def.anchor.y);
            flashLayer.addChild(sprite);
            p.flash = { sprite, born: clock };
            p.bounce = clock + 70;
        },

        setScene(n, stageMax) {
            state.scene = n;
            state.stageMax = stageMax ?? state.stageMax;
        },

        /** Point on the tree branch where a bird can land, in CSS pixels of the scene root; follows resizes. */
        perch() {
            return { ...perch };
        },

        /**
         * Stops the frame loop and advances the scene by `ms` by hand. Headless screenshots get no animation
         * frames, so public/dev/lab.html drives time itself.
         */
        step(ms) {
            app.ticker.stop();
            if (layoutChanged()) rebuild();
            tick({ deltaMS: ms });
            app.render();
        },
    };
}
