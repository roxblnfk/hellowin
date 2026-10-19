import {
    Application,
    Container,
    RenderTexture,
    Sprite,
    Texture,
    TextureSource,
} from 'https://cdn.jsdelivr.net/npm/pixi.js@8.22.0/dist/pixi.min.mjs';
import { PALETTE } from './palette.js';
import { DECOR, PARTICLES, bake, pumpkinLayers, pumpkinSprite, seedOf, silhouette } from './sprites.js';

// Artwork exists for the scene 0 stages; later stages reuse the last drawn one (docs/spec/scenario.md).
const DRAWN_STAGES = 4;
// The root every vine grows from, in percents of the viewport like element positions.
const ROOT = { x: 50, y: 63 };
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
    const pumpkinLayer = new Container();
    const particleLayer = new Container();
    const flashLayer = new Container();
    world.addChild(skyLayer, cloudLayer, baseLayer, treeLayer, plantLayer, pumpkinLayer, particleLayer, flashLayer);

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
    let perch = { x: 0, y: 0 };
    let clock = 0;

    function toArt(element) {
        return {
            x: Math.round((element.x / 100) * layout.width / layout.cssPx),
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
        return { cx: c.x, cy: c.y, rx: artW * 0.47, ry: Math.min(artH * 0.25, c.y - horizon - 3) };
    }

    const inPatch = (shape, x, y) => ((x - shape.cx) / shape.rx) ** 2 + ((y - shape.cy) / shape.ry) ** 2;

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
        const COLORS = Object.fromEntries(['soil', 'soilDark', 'soilLight', 'leaf', 'leafDark', 'leafLight'].map((k) => [k, rgb(k)]));
        const vp = { x: shape.cx, y: horizon - (shape.cy - horizon) * 0.6 };
        const fan = (shape.cy + shape.ry - vp.y) / 10;
        for (let y = horizon; y < artH; y++) {
            const depth = (y - horizon) / (artH - horizon);
            for (let x = 0; x < artW; x++) {
                const d = inPatch(shape, x, y);
                const checker = (x + y) % 2 === 0;
                let key;
                if (d < 0.9 || (d < 1 && checker)) {
                    const lane = (((x - vp.x) / (y - vp.y)) * fan) % 1;
                    const f = lane < 0 ? lane + 1 : lane;
                    key = f < 0.2 ? 'soilDark'
                        : f < 0.3 ? 'soilLight'
                        : random() < 0.03 ? (random() < 0.5 ? 'soilDark' : 'soilLight')
                        : 'soil';
                } else if (d < 1.06) {
                    key = 'soilDark';
                } else {
                    const mow = (Math.sqrt(y - horizon + 1) * 1.7) % 1 < 0.14;
                    const n = random();
                    key = n < 0.05 * depth ? 'leafLight'
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

    // A cubic curve whose last control point sits above and beside the stem: the vine hooks down onto the stem tip
    // from above even for pumpkins above the root, and the stretch passing behind the body stays hidden. Leaves and
    // tendrils are collected, not drawn, so all vines are covered by foliage drawn in depth order.
    function drawVine(ctx, from, stem, reach, side, random, foliage) {
        const toward = Math.sign(from.x - stem.x) || side;
        const dx = stem.x - from.x;
        const dy = stem.y - from.y;
        const length = Math.hypot(dx, dy) || 1;
        const bend = side * length * 0.18;
        const c1 = { x: from.x + dx * 0.35 - (dy / length) * bend, y: from.y + dy * 0.35 + (dx / length) * bend };
        const c2 = { x: stem.x + toward * reach, y: stem.y - 12 };
        const steps = Math.ceil(length * 3);
        const points = [];
        let travelled = 0;
        for (let i = 0; i <= steps; i++) {
            const t = i / steps;
            const u = 1 - t;
            const x = u * u * u * from.x + 3 * u * u * t * c1.x + 3 * u * t * t * c2.x + t * t * t * stem.x;
            const y = u * u * u * from.y + 3 * u * u * t * c1.y + 3 * u * t * t * c2.y + t * t * t * stem.y;
            const last = points[points.length - 1];
            travelled += last ? Math.hypot(x - last.fx, y - last.fy) : 0;
            points.push({ x: Math.round(x), y: Math.round(y), fx: x, fy: y, t, d: travelled });
        }
        ctx.fillStyle = css(PALETTE.leafDark);
        for (const p of points) {
            const thick = p.t < 0.5 ? 3 : 2;
            ctx.fillRect(p.x - 1, p.y - 1, thick, thick);
        }
        ctx.fillStyle = css(PALETTE.leaf);
        for (const p of points) {
            ctx.fillRect(p.x - (p.t < 0.5 ? 0 : 1), p.y - 1, 1, 1);
        }

        // DECOR.leaf goes from the largest to the smallest: big leaves near the root, small ones toward the pumpkin.
        const leaves = DECOR.leaf;
        const tendrils = DECOR.tendril;
        let flip = side;
        let nextLeaf = 3;
        let nextTendril = 10 + random() * 10;
        for (let i = 4; i < points.length; i++) {
            const p = points[i];
            if (p.d > travelled - 10) break;
            const prev = points[i - 4];
            const tl = Math.hypot(p.fx - prev.fx, p.fy - prev.fy) || 1;
            const nx = -(p.fy - prev.fy) / tl;
            const ny = (p.fx - prev.fx) / tl;
            if (p.d >= nextLeaf) {
                nextLeaf += 6 + random() * 4;
                const pick = clamp(Math.floor(p.t * leaves.length + (random() - 0.5) * 1.5), 0, leaves.length - 1);
                const leaf = leaves[pick];
                const off = 2 + random() * 3;
                foliage.push({ def: leaf, x: p.x + nx * flip * off, y: p.y + ny * flip * off + leaf.anchor.y - (leaf.h >> 1) });
                flip = -flip;
            }
            if (p.d >= nextTendril) {
                nextTendril += 18 + random() * 14;
                const t = tendrils[Math.floor(random() * tendrils.length)];
                foliage.push({ def: t, x: p.x - nx * flip * 3, y: p.y - ny * flip * 3 + t.anchor.y - (t.h >> 1) });
            }
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
        state.elements.forEach((element, i) => {
            const def = pumpkinSprite(0, element.size ?? 'm', seedOf(element.id));
            const p = toArt(element);
            const stem = { x: p.x - def.anchor.x + stemColumn(def), y: p.y - def.anchor.y + 1 };
            drawVine(ctx, root, stem, (def.w >> 1) + 3, i % 2 === 0 ? 1 : -1, random, foliage);
        });

        // A heap of the largest leaves around the root; the root itself stays on top of it.
        const heap = Math.round(clamp(artW / 16, 14, 26));
        for (let i = 0; i < heap; i++) {
            const a = random() * Math.PI * 2;
            const r = Math.sqrt(random());
            const leaf = DECOR.leaf[Math.min(DECOR.leaf.length - 1, Math.floor(random() * 2))];
            foliage.push({ def: leaf, x: root.x + Math.cos(a) * r * 15, y: root.y + Math.sin(a) * r * 7 });
        }
        foliage.sort((a, b) => a.y - b.y).forEach((f) => stamp(ctx, f.def, f.x, f.y));
        stamp(ctx, DECOR.root, root.x, root.y);

        plantLayer.removeChildren().forEach((s) => s.destroy());
        plantLayer.addChild(new Sprite(texture(c)));
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

    function publishHitZones() {
        for (const size of SIZES) {
            const { def } = pumpkinArt(0, size);
            const px = layout.cssPx;
            rootEl.style.setProperty(`--hit-w-${size}`, `${def.w * px}px`);
            rootEl.style.setProperty(`--hit-h-${size}`, `${def.h * px}px`);
            rootEl.style.setProperty(`--hit-x-${size}`, `${-def.anchor.x * px}px`);
            rootEl.style.setProperty(`--hit-y-${size}`, `${-def.anchor.y * px}px`);
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
