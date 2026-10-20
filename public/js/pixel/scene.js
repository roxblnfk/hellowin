import {
    Application,
    Container,
    Graphics,
    RenderTexture,
    Sprite,
    Text,
    Texture,
    TextureSource,
} from 'https://cdn.jsdelivr.net/npm/pixi.js@8.22.0/dist/pixi.min.mjs';
import { ROOT, floats, isPumpkin, jumpOut, jumpedOut, spreadLeft, spreadX } from '../layout.js';
import { ASH, BONFIRE, BONFIRE_STAGES, EXPLOSION, magicCircle } from './bonfire.js';
import { FRAME, POSES, SHOT_FX, characterFrame } from './characters.js';
import { createBubbles } from './bubble.js';
import { CLINK, createClinks } from './clink.js';
import { T as FINALE_T, createFinaleStage, lightsOut, nightOf, ritualOf, rumbleAt } from './finale-stage.js';
import { BUSH, FLYERS, bake as flyerBake, digitsSprite, feathersOf } from './flyers.js';
import { NIGHT, bakeLit, halo, keepHueBake, keepHueCanvas, keepHueColor, litColor, paintSky, relightCanvas } from './night.js';
import { OWL, OWL_FLIGHT, glowing } from './owl.js';
import { PALETTE } from './palette.js';
import { DECOR, PARTICLES, PUMPKIN_SHADOW, PUMPKIN_STAGES, bake, pumpkinArms, pumpkinLayers, pumpkinSprite, seedOf, silhouette } from './sprites.js';

const SIZES = ['s', 'm', 'l'];
// Back to front: the aura under everything, the glow above back and under body (hidden by day), the fire on top.
const LAYERS = ['aura', 'back', 'glow', 'body', 'rim', 'stem', 'fire'];
// Light layers: drawn unlit in every lighting and animated by their frames (sprites.js, pumpkinLayers).
const LIGHT_LAYERS = new Set(['aura', 'glow', 'fire']);
// The first stage whose glow layer is the inner light; below it the glow is only the mask of the cuts.
const LIT_STAGE = 5;
// Frame periods of the light layers, ms.
const FRAME_MS = { glow: 120, fire: 110, aura: 160 };
// The jump out of scene 1: hop length and height (art px); app.css moves the buttons over the same time.
const JUMP_MS = 600;
const JUMP_H = 12;
// Floating pumpkins hover this high (art px) and bob ±2 around it.
const FLOAT_LIFT = 5;
// Explosion frame times (bonfire.js, EXPLOSION), the screen shake, and how long the pit's embers keep dying.
const BLAST_MS = [70, 70, 70, 120, 120, 120, 120, 120];
const SHAKE_MS = 700;
const EMBERS_MS = 9000;
// Sunset: how long the palette slides from day and the sun sinks behind the hills; later scene changes are quicker.
const SUNSET_MS = 4000;
const FADE_MS = 3000;
const FADE_STEPS = 10;

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

// Integer hash of two numbers: a walker's seed and a time slot give the same idle move on every client.
function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

// The cursed world's pumpkins now and then raise their beer: in about a third of the slots, for RAISE_MS at a moment
// the hash picks, on the server clock, so the whole room sees the same pumpkin raise it.
const RAISE_SLOT_MS = 5000;
const RAISE_MS = 1400;
const RAISE_EASE_MS = 140;

/** The arms frame of a pumpkin at server time `now`: 0 holds the glass, 1 halfway, 2 raised (sprites.js, pumpkinArms). */
function raiseFrame(seed, now) {
    const slot = Math.floor(now / RAISE_SLOT_MS);
    const h = mix(seed ^ 0x6265, slot);
    if (h % 100 >= 33) return 0;
    const t = now - slot * RAISE_SLOT_MS - ((h >>> 8) % (RAISE_SLOT_MS - RAISE_MS));
    if (t < 0 || t >= RAISE_MS) return 0;
    return t < RAISE_EASE_MS || t >= RAISE_MS - RAISE_EASE_MS ? 1 : 2;
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
 * @returns {Promise<null|{render: Function, click: Function, pumpkin: Function, setScene: Function, setServerClock: Function, flyer: Function, shot: Function, flyerAt: Function, walker: Function, move: Function, leave: Function, setMe: Function, groundAt: Function, perch: Function, circle: Function, setFinale: Function, step: Function}>}
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
    const plantLayer = new Container();
    // Ground light under everything standing: the bonfire's and pumpkins' halos (additive), candles, float shadows.
    const lightLayer = new Container();
    const bugLayer = new Container();
    // The bonfire's explosion, over the ground objects around it.
    const blastLayer = new Container();
    const blastSprite = new Sprite();
    blastSprite.visible = false;
    blastLayer.addChild(blastSprite);
    // Flyers never go behind the far plan: depth 0 flies between the hills and everything standing on the ground,
    // depth 1 over the ground objects, depth 2 over depth 1.
    const farFlyerLayer = new Container();
    const midFlyerLayer = new Container();
    const nearFlyerLayer = new Container();
    // Everything that stands on the ground and has height: grass and weeds, bushes, props, fence, the tree, foliage,
    // the root and the pumpkins. One container sorted by the y of each object's foot, so nearer things cover farther.
    const groundLayer = new Container();
    groundLayer.sortableChildren = true;
    const particleLayer = new Container();
    const flashLayer = new Container();
    // The finale's lightning, over everything but the veil.
    const fxLayer = new Container();
    // Full-screen veil for the new-round dim-and-flash.
    const veil = new Sprite(Texture.WHITE);
    veil.visible = false;
    world.addChild(skyLayer, cloudLayer, baseLayer, plantLayer, lightLayer, farFlyerLayer, groundLayer, blastLayer, bugLayer, midFlyerLayer, nearFlyerLayer, particleLayer, flashLayer, fxLayer, veil);
    // Debug outline of the flyers' hit boxes (public/dev/lab.html ?hits=1).
    const hitOverlay = new Graphics();
    world.addChild(hitOverlay);

    let target = null;
    const display = new Sprite();
    // Name labels are drawn over the blown-up world at screen resolution: text rasterised into the art texture
    // would turn into blocky smudges, and a pixel font of our own would not cover Cyrillic names.
    const labelLayer = new Container();
    // A frozen copy of the world in the old lighting, fading out over the relit one when the scene changes.
    const fadeDisplay = new Sprite();
    fadeDisplay.visible = false;
    app.stage.addChild(display, fadeDisplay, labelLayer);

    const art = new Map();
    const artTexture = (key, make) => {
        if (!art.has(key)) {
            art.set(key, make());
        }
        return art.get(key);
    };

    // A sprite in a lighting: day art is baked as is, the rest goes through night.js (cached there per sprite).
    const litBake = (def, lit) => (lit === 'day' ? bake(def) : bakeLit(def, lit));

    /**
     * Textures of a pumpkin stage in a lighting: the lit layers through bakeLit, the light layers (aura, glow, fire)
     * unlit with their flicker frames. Stages past the last drawn one look like it (docs/spec/scenario.md).
     */
    function pumpkinArt(stage, size, seed = 0, lit = 'day', tone = 'green') {
        const drawn = clamp(stage, 0, PUMPKIN_STAGES);
        return artTexture(`pumpkin:${drawn}:${size}:${seed}:${lit}:${drawn >= 9 ? tone : ''}`, () => {
            // The composite gives the outline and the flash; the scene draws the layers separately.
            const def = pumpkinSprite(drawn, size, seed);
            const L = pumpkinLayers(drawn, size, seed, tone);
            const outline = artTexture(`pumpkin-edge:${drawn}:${size}:${seed}`, () => {
                const edge = bake(silhouette(def, 'pumpkinShine'));
                const ring = canvas(def.w + 2, def.h + 2);
                const ctx = ring.getContext('2d');
                for (const [dx, dy] of [[0, 1], [2, 1], [1, 0], [1, 2]]) {
                    ctx.drawImage(edge, dx, dy);
                }
                return { ring: texture(ring), flash: texture(bake(silhouette(def, 'white'))) };
            });
            const unlit = (list) => list?.map((sprite) => texture(bake(sprite))) ?? null;
            return {
                def,
                layers: Object.fromEntries(LAYERS.map((name) => {
                    const layer = L.layers[name];
                    if (!layer) return [name, null];
                    return [name, texture(LIGHT_LAYERS.has(name) ? bake(layer) : litBake(layer, lit))];
                })),
                frames: { glow: unlit(L.glowFrames), fire: unlit(L.fireFrames), aura: unlit(L.auraFrames) },
                ...outline,
            };
        });
    }

    const particleArt = (kind) => artTexture(`particle:${kind}`, () => PARTICLES[kind].map((p) => texture(bake(p))));

    let state = {
        round: snapshot.round,
        scene: snapshot.scene ?? 0,
        sceneStages: snapshot.sceneStages ?? 3,
        stageMax: snapshot.stageMax ?? 4,
        threshold: snapshot.threshold ?? 1,
        elements: snapshot.elements ?? [],
        bushes: snapshot.bushes ?? [],
    };

    // The finale (docs/spec/scenario.md, «Финал»): the step (null outside the final scene), whether the circle is
    // closed, and when the step and step 1 began on the scene clock. -1e9 is a step settled long ago: a reload, a
    // resync and reduced motion show the end of the step, never its animation.
    const finale = { step: null, ready: false, at: -1e9, pullAt: -1e9 };
    const sinceStep = () => clock - finale.at;
    const inFinale = () => state.scene >= state.sceneStages && finale.step !== null;

    // Lighting per scene (docs/spec/scenario.md, «Сцены 1–3»): day, sunset, night; the final scene is the blood moon
    // until the lightning of finale step 2, then the night again.
    const lightingOf = (scene) => {
        if (scene < state.sceneStages) return ['day', 'sunset', 'night'][scene] ?? 'night';
        return finale.step !== null && nightOf(finale.step, sinceStep()) ? 'night' : 'blood';
    };
    let lighting = 'day';
    // The bonfire's warm reach at night, { x, y, rx, ry, near } in art px; null without a burning bonfire.
    let fireLight = null;
    /**
     * The lighting of a sprite standing at an art point: the firelight near the bonfire at night, else the scene's. A
     * sprite is lit as a whole, so it takes a clean cut at the middle of the background's dithered edge (nearLight)
     * rather than a coin flip of the dither at its foot.
     */
    const lightAt = (x, y) => (fireLight && Math.hypot((x - fireLight.x) / fireLight.rx, (y - fireLight.y) / fireLight.ry) < 0.75 ? 'firelight' : lighting);
    /** A baked full-screen layer (lawn, vines) recoloured for the scene's lighting and the bonfire's reach. */
    const relight = (c) => (lighting === 'day' && !fireLight ? c : relightCanvas(c, lighting, fireLight));
    // The floating pumpkins' lights go out with the lightning and come back green at step 5.
    const darkPumpkins = () => inFinale() && lightsOut(finale.step, sinceStep());
    let darkShown = false;
    // Magic of the stages 9+ turns violet under the blood moon and FrankenPHP's lime in the cursed world; spent while
    // the lights are out.
    const toneOf = () => (darkPumpkins() ? 'spent' : inFinale() && finale.step >= 5 ? 'franken' : lighting === 'blood' ? 'violet' : 'green');
    const bonfireElement = () => state.elements.find((e) => e.type === 'bonfire') ?? null;
    const pumpkinElements = () => state.elements.filter(isPumpkin);
    // The magic circle is drawn on the bonfire's pit: its clicks shake the pit like the bonfire's shook the fire.
    const onPit = (id) => bonfire?.ash && state.elements.some((e) => e.id === id && e.type === 'circle');
    // The bonfire takes the root's place once the foliage is freed in scene 1; scene 0 keeps the root.
    const hasBonfire = () => state.scene > 0 && Boolean(bonfireElement());
    // Whether the pumpkins stand jumped out (layout.js), and when the hop started (it is over after JUMP_MS).
    let out = false;
    let jumpAt = -1e9;
    const pumpkins = new Map();
    // Players' characters by player id: the Walker of protocol.md plus the sprite and label drawn for it.
    const walkers = new Map();
    let meId = null;
    const particles = [];
    let clouds = [];
    const flyers = new Map();
    // Server time in ms; the client model supplies it with the clock offset (setServerClock).
    let serverNow = () => Date.now();
    const bushes = new Map();
    const flyerTextures = new Map();
    const hazyTextures = new Map();
    let veilStart = -1e9;
    const VEIL = [['outline', 0.6], ['outline', 0.6], ['outline', 0.45], ['white', 0.55], ['white', 0.3]];
    // The explosion's flash: white-green, then white, fading in steps.
    const BLAST_VEIL = [['magicCore', 0.85], ['white', 0.8], ['white', 0.6], ['eyeGreen', 0.4], ['white', 0.25], ['white', 0.12]];
    let veilFrames = VEIL;
    let bugs = [];
    let perch = { x: 0, y: 0 };
    // The owl on the branch: its sprite (made again with groundLayer on every rebuild) and the line it is saying.
    let owlSprite = null;
    let owlAt = { x: 0, y: 0 };
    let owlTalk = { at: -1e9, until: -1e9, hoot: false };
    let clock = 0;
    // The bonfire in the middle from scene 1 (its sprites, stage and click shake), the pit in the final scene.
    let bonfire = null;
    // The explosion 2 → 3 starts here; the pit's embers die out after it. A frozen frame fades out over the relit
    // world after a scene change.
    let blastAt = -1e9;
    let fade = null;
    // The sinking sunset sun: its sprite, where it rests and since when it sinks.
    let sun = null;
    let sunSetAt = -1e9;
    let twinkles = [];
    // The magic circle on the pit, 0–1 (scene.circle); nothing is drawn at 0.
    let circleProgress = 0;

    function toArt(element) {
        return {
            x: Math.round((spreadLeft(element.x, layout.width) / 100) * layout.width / layout.cssPx),
            y: Math.round((element.y / 100) * layout.height / layout.cssPx),
        };
    }

    function drawSky() {
        const { artW, horizon } = layout;
        const c = canvas(artW, horizon + 1);
        // CPU-backed: the night skies are painted pixel by pixel, which a GPU canvas would queue up until a readback.
        const ctx = c.getContext('2d', { willReadFrequently: true });
        if (lighting === 'day') {
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
        } else {
            // Dithered bands of the lighting's sky, 1-px stars at night (night.js).
            paintSky(ctx, artW, horizon + 1, lighting, { seed: 5 });
        }
        skyLayer.removeChildren().forEach((s) => s.destroy());
        skyLayer.addChild(new Sprite(texture(c)));

        const skyX = Math.round(artW * 0.82);
        const top = horizon - farPlan();
        sun = null;
        twinkles = [];
        if (lighting === 'day') {
            const sunDef = DECOR.sun;
            const daySun = new Sprite(artTexture('sun', () => texture(bake(sunDef))));
            daySun.position.set(skyX - (sunDef.w >> 1), Math.max(2, Math.round((top - sunDef.h) * 0.3)));
            skyLayer.addChild(daySun);
        } else if (lighting === 'sunset') {
            // The striped sun rests half behind the hills (the base layer covers it); after the day it sinks there.
            const def = NIGHT.sunsetSun;
            const sprite = new Sprite(defTexture(def));
            sprite.anchor.set(0.5);
            sun = { sprite, x: skyX, rest: top - 3, high: Math.max(def.h >> 1, Math.round(top * 0.3)) };
            sprite.position.set(sun.x, sun.rest);
            skyLayer.addChild(sprite);
        } else {
            const [ring, moon] = lighting === 'blood' ? [NIGHT.bloodMoonHalo, NIGHT.bloodMoon] : [NIGHT.moonHalo, NIGHT.moon];
            const y = Math.max(moon.h >> 1, Math.round(top * 0.32));
            for (const def of [ring, moon]) {
                const sprite = new Sprite(defTexture(def));
                sprite.anchor.set(0.5);
                sprite.position.set(skyX, y);
                skyLayer.addChild(sprite);
            }
            // A few bigger stars twinkle (cross ↔ sparkle) over the painted 1-px ones, clear of the moon.
            if (lighting === 'night') {
                const random = rng(21);
                for (let i = 0; i < Math.max(4, Math.round(artW / 70)); i++) {
                    const x = Math.round(4 + random() * (artW - 8));
                    const sy = Math.round(3 + random() ** 1.4 * Math.max(4, top - 10));
                    if (Math.hypot(x - skyX, sy - y) < ring.w / 2 + 3) continue;
                    const sprite = new Sprite();
                    sprite.anchor.set(0.5);
                    sprite.position.set(x, sy);
                    skyLayer.addChild(sprite);
                    twinkles.push({ sprite, phase: Math.floor(random() * 4) });
                }
            }
        }

        cloudLayer.removeChildren().forEach((s) => s.destroy());
        const random = rng(7);
        // The night sky keeps a couple of moonlit clouds; the day and the sunset more.
        const count = Math.max(lighting === 'day' || lighting === 'sunset' ? 2 : 1, Math.round(artW / (lighting === 'day' || lighting === 'sunset' ? 90 : 200)));
        clouds = Array.from({ length: count }, (_, i) => {
            const def = DECOR.cloud[i % DECOR.cloud.length];
            const sprite = new Sprite(defTexture(def, lighting));
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

    // Texture of a sprites.js-style sprite per lighting; 'day' (the default) also serves light sprites like halos.
    // `baker` replaces the lighting for a sprite of its own rules (FrankenPHP's half-lit lime).
    const defTextures = new Map();
    const defTexture = (def, lit = 'day', baker = litBake) => {
        if (!defTextures.has(lit)) defTextures.set(lit, new WeakMap());
        const cache = defTextures.get(lit);
        if (!cache.has(def)) cache.set(def, texture(baker(def, lit)));
        return cache.get(def);
    };

    // Sort key: the foot's y, then x for a stable order between objects on the same row.
    const depthKey = (x, y) => Math.round(y) * 8192 + Math.round(x) + 4096;

    /** Puts a standing object on the ground with its anchor at (x, y), lit where it stands; `sortY` overrides the depth row. */
    function addGround(def, x, y, { tex = defTexture(def, lightAt(x, y)), sortY = y } = {}) {
        const sprite = new Sprite(tex);
        sprite.anchor.set(def.anchor.x / def.w, def.anchor.y / def.h);
        sprite.position.set(Math.round(x), Math.round(y));
        sprite.zIndex = depthKey(x, sortY);
        groundLayer.addChild(sprite);
        return sprite;
    }

    // Tufts around the foot of a bush, prop, trunk or fence post: a few in front cover its base, one or two behind,
    // so the object grows out of the grass instead of sitting on top of it.
    function rootIn(x, y, halfW, random) {
        const tufts = [...DECOR.grass, ...DECOR.weed];
        const front = 3 + Math.floor(random() * 3);
        for (let i = 0; i < front + 2; i++) {
            const dy = i < front ? 1 + random() * 2 : -(1 + random() * 2);
            addGround(tufts[Math.floor(random() * tufts.length)], x + (random() - 0.5) * 2 * halfW, y + dy);
        }
    }

    // Props around the patch: a low wattle fence along the far rim, hay, a basket, a watering can and stones at the
    // near rim. Only decoration: kept outside the patch, so they never cover a pumpkin's hit zone, and away from the
    // screen sides where the duck bushes sit.
    function placeProps(shape, random) {
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
        for (let i = 1; i < posts.length; i++) {
            const a = posts[i - 1];
            const b = posts[i];
            const y = Math.max(a.y, b.y);
            addGround(fences[i % fences.length], (a.x + b.x) / 2, y);
            rootIn(b.x, y, 2, random);
        }
        for (const post of [posts[0], posts[posts.length - 1]]) {
            addGround(end, post.x, post.y);
            rootIn(post.x, post.y, 2, random);
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
            if (Math.abs(x - at.x) >= def.w * 0.75) continue;
            addGround(def, x, at.y);
            rootIn(x, at.y, def.w / 2, random);
        }
    }

    // Height of the far plan above the horizon; clouds and the sun stay above it.
    const farPlan = () => Math.max(DECOR.hills.h, ...DECOR.farTree.map((t) => t.h));

    // Far plan above the horizon, then the lawn and the dug patch seen from a low camera: furrows converge toward a
    // vanishing point above the horizon, mowing lines crowd together into the distance, weeds shrink and thin out.
    let ridge = [];
    function drawBase() {
        const { artW, artH, horizon } = layout;
        const c = canvas(artW, artH);
        // CPU-backed: the ridge scan and the relighting (night.js) read this canvas back.
        const ctx = c.getContext('2d', { willReadFrequently: true });
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
        // Top of the far plan per column: a shot far bird drops behind the hills there instead of over the patch.
        const far = ctx.getImageData(0, 0, artW, horizon).data;
        ridge = Array.from({ length: artW }, (_, x) => {
            let y = 0;
            while (y < horizon && far[(y * artW + x) * 4 + 3] === 0) y++;
            return y;
        });

        const image = ctx.createImageData(artW, artH - horizon);
        const rgb = (key) => [(PALETTE[key] >> 16) & 255, (PALETTE[key] >> 8) & 255, PALETTE[key] & 255];
        const COLORS = Object.fromEntries(['soil', 'soilDark', 'soilLight', 'leaf', 'leafDark', 'leafLight', 'leafShine', 'ash', 'ashDark'].map((k) => [k, rgb(k)]));
        // From the blast on the patch is scorched and bare (bareOf): charcoal furrows, ash specks, no grass.
        const bare = bareOf();
        const SCORCH = { soilLight: 'soil', soil: 'soilDark', soilDark: 'ashDark' };
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
                    if (bare) key = (x * 7 + y * 13) % 23 === 0 ? 'ash' : SCORCH[key];
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
            const tuft = random() < 0.4;
            if (bare) continue;
            ctx.fillRect(x, y - tall + 1, 1, tall);
            if (tuft) ctx.fillRect(x + 1, y - 1, 1, 1);
        }

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
            // No undergrowth on the scorched patch.
            if (!(bare && d < 1)) picks.push({ def, x, y });
        }
        picks.forEach((p) => addGround(p.def, p.x, p.y));
        placeProps(shape, random);

        baseLayer.removeChildren().forEach((s) => s.destroy());
        baseLayer.addChild(new Sprite(texture(relight(c))));
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
        const tree = addGround(def, foot.x, foot.y);
        rootIn(foot.x, foot.y, 6, rng(5));
        owlAt = { x: foot.x - def.anchor.x + px, y: foot.y - def.anchor.y + py };
        perch = { x: owlAt.x * layout.cssPx, y: owlAt.y * layout.cssPx };
        // Sorted with the tree, just in front of it: the talons grip the branch, nearer things still cover both.
        owlSprite = new Sprite();
        owlSprite.zIndex = tree.zIndex + 1;
        groundLayer.addChild(owlSprite);
        animateOwl();
    }

    // At finale step 5 the owl flies off the branch once its last line is over, and is gone; lines after that are
    // shown over FrankenPHP (perch()).
    const OWL_TAKEOFF_MS = 150;
    const OWL_FLY_MS = 2500;
    const OWL_H = OWL.idle[0].h;
    // FrankenPHP's head above the circle's centre, in art pixels: where his own phrase bubbles hang (finale-stage.js).
    const FRANKEN_HEAD = 84;
    // The owl does not leave before this long into step 5: the farewell line may still wait for the bubble (owl.js).
    const OWL_STAY_MS = 3000;
    let owlFlight = { mode: 'perched', at: 0, leave: false };
    // Off the top of the screen, up and to the left of the branch.
    const owlAway = () => ({ x: owlAt.x - layout.artW * 0.35, y: -OWL_FLIGHT.fly[0].h - 4 });
    const owlGone = () => owlFlight.mode === 'away' || owlFlight.mode === 'leaving';

    // Idle on the server clock, so the whole room sees the same blink and the same swivel: 5 s slots, a blink in
    // each, a swivel to one side in some of them and, rarely, all the way round.
    const OWL_SLOT_MS = 5000;
    const OWL_HOOT_MS = 900;
    const owlNight = new WeakMap();
    function idleOwl(now) {
        const slot = Math.floor(now / OWL_SLOT_MS);
        const into = now - slot * OWL_SLOT_MS;
        const h = mix(0x0ff1ce, slot);
        const breath = OWL.idle[Math.floor(now / 700) % 2];
        const blinkAt = 300 + (h % 700);
        if (into >= blinkAt && into < blinkAt + 120) return OWL.blink;
        const turn = (h >>> 10) % 9;
        if (turn > 3 || into < 1500) return breath;
        const side = turn % 2 ? OWL.turnRight : OWL.turnLeft;
        // half, full, (back,) full, half: 150 ms per in-between frame.
        const swivel = turn === 3
            ? [[150, side[0]], [150, side[1]], [900, OWL.back], [150, side[1]], [150, side[0]]]
            : [[150, side[0]], [1400, side[1]], [150, side[0]]];
        let t = into - 1500;
        for (const [ms, frame] of swivel) {
            if (t < ms) return frame;
            t -= ms;
        }
        return breath;
    }

    function owlFrame() {
        if (clock >= owlTalk.at && clock < owlTalk.until) {
            const t = clock - owlTalk.at;
            if (owlTalk.hoot && t < OWL_HOOT_MS) return OWL.hoot;
            if (reduced) return OWL.talk[0];
            // Beak open and shut, and now and then the wing comes up for emphasis.
            const beat = Math.floor(t / 160);
            return beat % 10 >= 7 ? OWL.talk[2] : OWL.talk[beat % 2];
        }
        return reduced ? OWL.idle[0] : idleOwl(serverNow());
    }

    // The owl's frame and point: on the branch, or taking off and flying away (owlFlight).
    function owlPose() {
        const f = owlFlight;
        if (f.mode === 'perched' && f.leave && clock >= owlTalk.until && sinceStep() > OWL_STAY_MS) {
            owlFlight = { mode: reduced ? 'away' : 'leaving', at: clock, leave: false };
        }
        const t = clock - owlFlight.at;
        const wing = OWL_FLIGHT.fly[Math.floor(clock / 90) % OWL_FLIGHT.fly.length];
        if (owlFlight.mode === 'leaving') {
            if (t < OWL_TAKEOFF_MS) return { def: OWL_FLIGHT.takeOff, at: owlAt };
            const k = (t - OWL_TAKEOFF_MS) / OWL_FLY_MS;
            if (k >= 1) owlFlight = { mode: 'away', at: clock, leave: false };
            else {
                const away = owlAway();
                return { def: wing, at: { x: owlAt.x + (away.x - owlAt.x) * k * k, y: owlAt.y + (away.y - owlAt.y) * k } };
            }
        }
        if (owlFlight.mode === 'away') return null;
        return { def: owlFrame(), at: owlAt };
    }

    function animateOwl() {
        if (!owlSprite) return;
        const pose = owlPose();
        owlSprite.visible = Boolean(pose);
        if (!pose) return;
        let { def } = pose;
        // From the night scene on, the eyes glow.
        if (state.scene >= 2) {
            if (!owlNight.has(def)) owlNight.set(def, glowing(def));
            def = owlNight.get(def);
        }
        const art = flyerArt(def, false, lightAt(owlAt.x, owlAt.y));
        owlSprite.texture = art.texture;
        owlSprite.anchor.set(art.ax, art.ay);
        owlSprite.position.set(Math.round(pose.at.x), Math.round(pose.at.y));
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
        burn = null;
        if (bareOf()) {
            plantLayer.removeChildren().forEach((s) => s.destroy());
            if (!burningOf()) return;
        }
        const { artW, artH } = layout;
        const c = canvas(artW, artH);
        const ctx = c.getContext('2d', { willReadFrequently: true });
        const random = rng(9);
        const fill = (key, x, y, w = 1, h = 1) => {
            ctx.fillStyle = css(PALETTE[key]);
            ctx.fillRect(x, y, w, h);
        };

        for (const element of pumpkinElements()) {
            const def = pumpkinSprite(0, element.size ?? 'm', seedOf(element.id));
            const p = toArt(element);
            const w = Math.max(4, Math.round(def.w * 0.8));
            fill('soilDark', p.x - (w >> 1), p.y - 1, w, 2);
            fill('soilDark', p.x - (w >> 1) + 1, p.y + 1, w - 2, 1);
        }

        const root = toArt(ROOT);
        const foliage = [];
        for (const element of pumpkinElements()) {
            const seed = seedOf(element.id);
            const def = pumpkinSprite(0, element.size ?? 'm', seed);
            const p = toArt(element);
            const stem = { x: p.x - def.anchor.x + stemColumn(def), y: p.y - def.anchor.y + 1 };
            drawVine(ctx, root, stem, (def.w >> 1) + 3, seed, foliage);
        }

        plantLayer.removeChildren().forEach((s) => s.destroy());
        if (bareOf()) {
            startBurn(relight(c), foliage);
            return;
        }
        plantLayer.addChild(new Sprite(texture(relight(c))));
        // From scene 1 the freed tops are the bonfire's heap (buildBonfire): no root and no leaf heap in the middle.
        foliage.forEach((leaf) => addGround(leaf.def, leaf.x, leaf.y));
        if (hasBonfire()) return;
        // A heap of the largest leaves around the root; the root itself stays on top of it.
        const heapLeaves = DECOR.bigLeaf ? [DECOR.bigLeaf].flat() : DECOR.leaf.slice(0, 2);
        const heap = Math.round(clamp(artW / 30, 10, 16));
        for (let i = 0; i < heap; i++) {
            const a = random() * Math.PI * 2;
            const r = Math.sqrt(random());
            const leaf = heapLeaves[Math.floor(random() * heapLeaves.length)];
            addGround(leaf, root.x + Math.cos(a) * r * 11, root.y + Math.sin(a) * r * 5);
        }
        // The root sorts in front of its heap, so the leaves frame it instead of burying it.
        addGround(DECOR.root, root.x, root.y, { sortY: root.y + 20 });
    }

    // The blast of 2 → 3 burns the foliage away (docs/spec/frontend.md, «Взрыв 2 → 3»): vines, leaves and tendrils char
    // and smoulder, crumble to ash in BURN_FRAMES steps and are gone; from then on the patch is bare. A reload, a resync
    // or reduced motion shows the bare patch at once.
    const BURN_START = 250;
    const BURN_STEP = 380;
    const BURN_FRAMES = 6;
    const BURN_END = BURN_START + BURN_STEP * BURN_FRAMES;
    const bareOf = () => state.scene >= state.sceneStages;
    const burningOf = () => !reduced && clock - blastAt < BURN_END;
    let burn = null;

    /**
     * Charred frames of a baked canvas: the first darkened with ember specks, then charcoal and ash crumbling away in
     * blobs (2-px value noise over a Bayer step) with glowing embers along the crumbling edge.
     */
    const charCache = new WeakMap();
    function charFrames(src) {
        if (charCache.has(src)) return charCache.get(src);
        const { width: w, height: h } = src;
        const data = src.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, w, h).data;
        const rgbOf = (c) => [(c >> 16) & 255, (c >> 8) & 255, c & 255];
        const ash = rgbOf(litColor(PALETTE.ash, lighting));
        const coal = rgbOf(litColor(PALETTE.ashDark, lighting));
        const ember = rgbOf(PALETTE.ember);
        const frames = Array.from({ length: BURN_FRAMES }, (_, k) => {
            const out = canvas(w, h);
            const octx = out.getContext('2d');
            const img = octx.createImageData(w, h);
            const gone = [0, 0.08, 0.28, 0.5, 0.7, 0.88][k];
            for (let i = 0; i < data.length; i += 4) {
                if (data[i + 3] === 0) continue;
                const x = (i >> 2) % w;
                const y = Math.floor((i >> 2) / w);
                const n = (mix((x >> 1) * 73856093, (y >> 1) + 0xb07) / 4294967296) * 0.75 + (((x & 1) * 2 + (y & 1)) / 4) * 0.25;
                if (n < gone) continue;
                let c;
                if (k === 0) {
                    c = mix(x, y * 31 + 7) % 100 < 5 ? ember : [data[i] * 0.45, data[i + 1] * 0.4, data[i + 2] * 0.45];
                } else {
                    const lum = (data[i] * 3 + data[i + 1] * 6 + data[i + 2]) / 2550;
                    c = n < gone + 0.07 && k < BURN_FRAMES - 1 ? ember : lum > 0.3 ? ash : coal;
                }
                img.data[i] = c[0];
                img.data[i + 1] = c[1];
                img.data[i + 2] = c[2];
                img.data[i + 3] = 255;
            }
            octx.putImageData(img, 0, 0);
            return texture(out);
        });
        charCache.set(src, frames);
        return frames;
    }

    // The vines' canvas and the foliage as sprites of their own, with their charred frames; points along them for the
    // embers.
    function startBurn(vines, foliage) {
        const sprite = new Sprite(texture(vines));
        plantLayer.addChild(sprite);
        const leaves = foliage.map((leaf) => {
            const lit = lightAt(leaf.x, leaf.y);
            const s = addGround(leaf.def, leaf.x, leaf.y);
            return { sprite: s, frames: charFrames(litBake(leaf.def, lit)), x: leaf.x, y: leaf.y - (leaf.def.h >> 1) };
        });
        burn = { vines: { sprite, frames: charFrames(vines) }, leaves, step: -1 };
    }

    function animateBurn() {
        if (!burn) return;
        const t = clock - blastAt;
        if (t >= BURN_END) {
            burn.vines.sprite.destroy();
            burn.leaves.forEach((l) => l.sprite.destroy());
            burn = null;
            return;
        }
        const step = t < BURN_START ? -1 : Math.floor((t - BURN_START) / BURN_STEP);
        if (step !== burn.step && step >= 0) {
            burn.step = step;
            burn.vines.sprite.texture = burn.vines.frames[step];
            burn.leaves.forEach((l) => (l.sprite.texture = l.frames[step]));
        }
        // Embers and cinders lift off the smouldering foliage while it burns.
        if (step >= 0 && step < BURN_FRAMES - 1 && burn.leaves.length) {
            for (let i = 0; i < 2; i++) {
                const l = burn.leaves[Math.floor(Math.random() * burn.leaves.length)];
                spawn(i ? 'cinder' : 'ember', l.x + between(-3, 3), l.y, sign() * between(2, 12), -between(15, 40), i ? 30 : -15, between(0.5, 0.9));
            }
        }
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

    // Halo radii per size for the lit pumpkins' ground light.
    const HALO = { l: [16, 5], m: [13, 5], s: [10, 4] };

    const showArms = (p, frame) => {
        const f = p.armsArt.frames[frame];
        p.arms.children[0].texture = f.lit;
        p.arms.children[1].texture = f.beer;
    };

    function applyArt(p) {
        // Lit where it will stand: after the jump out a pumpkin may leave the firelight.
        const at = out ? p.out : p.home;
        const a = pumpkinArt(p.stage, p.size, p.seed, lightAt(at.x, at.y), toneOf());
        p.art = a;
        const dark = darkPumpkins();
        darkShown = dark;
        for (const name of LAYERS) {
            const sprite = p.layers[name];
            sprite.texture = a.layers[name] ?? Texture.EMPTY;
            // The glow is the inner light from stage 5 on, and only after dark; below it, a mask of the cuts. The
            // finale puts every light out for a while (darkPumpkins).
            sprite.visible = Boolean(a.layers[name]) && (name !== 'glow' || (p.stage >= LIT_STAGE && lighting !== 'day')) && !(dark && LIGHT_LAYERS.has(name));
            sprite.position.set(-a.def.anchor.x, -a.def.anchor.y);
        }
        // In the cursed world the pumpkins hold beer: arms and glass lit like the body, the beer keeping its amber.
        p.arms.visible = toneOf() === 'franken';
        if (p.arms.visible) {
            const lit = lightAt(at.x, at.y);
            p.armsArt = artTexture(`pumpkin-arms:${clamp(p.stage, 0, PUMPKIN_STAGES)}:${p.size}:${p.seed}:${lit}`, () => {
                const { anchor, frames } = pumpkinArms(p.stage, p.size, p.seed);
                return { anchor, frames: frames.map((f) => ({ lit: texture(litBake(f.lit, lit)), beer: texture(keepHueBake(f.beer, lit)) })) };
            });
            showArms(p, 0);
            p.arms.position.set(-p.armsArt.anchor.x, -p.armsArt.anchor.y);
        }
        p.ring.texture = a.ring;
        p.ring.position.set(-a.def.anchor.x - 1, -a.def.anchor.y - 1);
        // A burning or charmed pumpkin lights the ground around it; in the cursed world only its cut-outs glow.
        const lit = p.stage > LIT_STAGE && lighting !== 'day' && !dark && toneOf() !== 'franken';
        p.halo.visible = lit;
        if (lit) {
            const [rx, ry] = HALO[p.size] ?? HALO.m;
            p.halo.texture = defTexture(halo(rx, ry, lighting === 'sunset' ? 'warm' : lighting === 'blood' ? 'violet' : 'green'));
        }
    }

    function buildPumpkins() {
        flashLayer.removeChildren().forEach((c) => c.destroy());
        pumpkins.clear();
        const random = rng(3);
        const sorted = pumpkinElements().sort((a, b) => a.y - b.y);
        const k = jumpOut(layout.width);
        for (const element of sorted) {
            const node = new Container();
            const ring = new Sprite();
            // The figure's origin is the anchor point, so squash and sway pivot on the pumpkin's base.
            const body = new Container();
            const layers = Object.fromEntries(LAYERS.map((name) => [name, new Sprite()]));
            const arms = new Container();
            arms.addChild(new Sprite(), new Sprite());
            body.addChild(...LAYERS.map((name) => layers[name]), arms);
            node.addChild(ring, body);
            groundLayer.addChild(node);
            // Ground light and the shadow of a floating pumpkin lie flat under everything standing.
            const glow = new Sprite();
            glow.anchor.set(0.5);
            glow.blendMode = 'add';
            glow.alpha = 0.35;
            const shadow = new Sprite();
            shadow.anchor.set(0.5);
            shadow.alpha = 0.5;
            lightLayer.addChild(glow, shadow);
            const home = toArt(element);
            const p = {
                id: element.id,
                size: element.size ?? 'm',
                stage: element.stage ?? 0,
                clicks: element.clicks ?? 0,
                home,
                // Where it lands after the jump out: the offset from the root grows by jumpOut (layout.js), like the button.
                out: toArt({ x: ROOT.x + (element.x - ROOT.x) * k, y: ROOT.y + (element.y - ROOT.y) * k }),
                pos: home,
                lift: 0,
                node,
                seed: seedOf(element.id),
                ring,
                body,
                layers,
                arms,
                halo: glow,
                shadow,
                phase: random() * 1000,
                // Pumpkins leave in a ragged volley, not all at once.
                delay: random() * 250,
                hit: -1e9,
                bounce: -1e9,
                flash: null,
            };
            applyArt(p);
            pumpkins.set(p.id, p);
        }
        // Places in the round dance of finale step 1: evenly around the circle, in the order the pumpkins stand
        // around it, so each one only slides over to its neighbour's side.
        const c = centre();
        const shape = patchShape();
        const around = (p) => {
            const rest = out ? p.out : p.home;
            return Math.atan2((rest.y - c.y) / shape.ry, (rest.x - c.x) / shape.rx);
        };
        const ring = [...pumpkins.values()].sort((a, b) => around(a) - around(b));
        const first = ring.length ? around(ring[0]) : 0;
        ring.forEach((p, i) => (p.dance = first + (i / ring.length) * Math.PI * 2));
    }

    // The circle's foot in art px: the bonfire's spot, where FrankenPHP will stand.
    const centre = () => toArt(bonfireElement() ?? ROOT);

    // The round dance of finale step 1 at scene time `c`: pulled from its place onto a ring around the circle over
    // FINALE_T.pull, turning slowly. Returns the ground point and how far the pull has come.
    function danceAt(p, c) {
        const shape = patchShape();
        const mid = centre();
        const k = clamp((c - finale.pullAt) / FINALE_T.pull, 0, 1);
        const e = k * k * (3 - 2 * k);
        const a = p.dance + (reduced ? 0 : c / 1700);
        // Wide enough for the whole patch to hold hands around FrankenPHP without hiding him.
        // On a phone, inside the screen.
        const rx = Math.min(shape.rx * 0.92, layout.artW / 2 - 22);
        const target = { x: mid.x + Math.cos(a) * rx, y: mid.y + 4 + Math.sin(a) * shape.ry * 0.72 };
        const rest = out ? p.out : p.home;
        return { at: { x: rest.x + (target.x - rest.x) * e, y: rest.y + (target.y - rest.y) * e }, k: e };
    }

    /**
     * A pumpkin in the finale: { at, lift, squash? } or null outside it. Step 1 dances, step 2 flings them back to
     * their places as the lights go out and they drop, steps 3–4 they lie there, step 5 they rise and float again.
     */
    function finalePumpkin(p) {
        if (!inFinale() || finale.step < 1) return null;
        const t = sinceStep();
        const rest = out ? p.out : p.home;
        const bob = reduced ? 0 : Math.round(2 * Math.sin((clock + p.phase) / 520));
        if (finale.step === 1 || (finale.step === 2 && t < FINALE_T.reveal)) {
            const { at, k } = danceAt(p, clock);
            return { at, lift: FLOAT_LIFT + Math.round(6 * k) + (reduced ? 0 : Math.round(2 * Math.sin((clock + p.phase) / 260))) };
        }
        if (finale.step === 2) {
            const k = (t - FINALE_T.reveal) / FINALE_T.fling;
            if (k < 1) {
                const from = danceAt(p, finale.at + FINALE_T.reveal);
                const e = 1 - (1 - k) * (1 - k);
                return {
                    at: { x: from.at.x + (rest.x - from.at.x) * e, y: from.at.y + (rest.y - from.at.y) * e },
                    lift: Math.max(0, Math.round((1 - k * k) * (FLOAT_LIFT + 6) + Math.sin(Math.PI * k) * 8)),
                };
            }
            const b = Math.floor((t - FINALE_T.reveal - FINALE_T.fling) / 60);
            return { at: rest, lift: 0, squash: b < BOUNCE.length ? BOUNCE[b] : null };
        }
        if (finale.step === 5) {
            const k = clamp(t / FINALE_T.rise5, 0, 1);
            return { at: rest, lift: Math.round(FLOAT_LIFT * (1 - (1 - k) * (1 - k))) + (k >= 1 ? bob : 0) };
        }
        return { at: rest, lift: 0 };
    }

    /**
     * The bonfire from scene 1 on (bonfire.js): the heap and its flames in place of the root, the ground light under
     * it; in the final scene the ash pit with the magic circle. Stage and frames are set per tick (animateBonfire).
     */
    function buildBonfire() {
        bonfire = null;
        const element = bonfireElement();
        if (!element || !hasBonfire()) return;
        const home = toArt(element);
        const node = new Container();
        const base = new Sprite();
        const fire = new Sprite();
        const circle = new Sprite();
        circle.anchor.set(ASH.pit.anchor.x / ASH.pit.w, ASH.pit.anchor.y / ASH.pit.h);
        node.addChild(base, fire, circle);
        node.position.set(home.x, home.y);
        node.zIndex = depthKey(home.x, home.y);
        groundLayer.addChild(node);
        const light = new Sprite();
        light.anchor.set(0.5);
        light.blendMode = 'add';
        lightLayer.addChild(light);
        bonfire = { id: element.id, stage: element.stage ?? 0, home, node, base, fire, circle, light, hit: -1e9, flare: -1e9, ash: state.scene >= state.sceneStages };
        animateBonfire();
    }

    function animateBonfire() {
        if (!bonfire) return;
        const { base, fire, circle, light, node } = bonfire;
        const lit = lightAt(bonfire.home.x, bonfire.home.y);
        const shake = Math.floor((clock - bonfire.hit) / 50);
        node.position.set(bonfire.home.x + (shake >= 0 && shake < HIT.length ? HIT[shake][2] : 0), bonfire.home.y);
        circle.visible = bonfire.ash && circleProgress > 0;
        if (bonfire.ash) {
            // The pit, with its embers dying out for a while after the blast; a reload shows the cold pit.
            base.texture = defTexture(ASH.pit, lit);
            base.anchor.set(ASH.pit.anchor.x / ASH.pit.w, ASH.pit.anchor.y / ASH.pit.h);
            const since = clock - blastAt;
            fire.texture = defTexture(ASH.embers);
            fire.anchor.set(ASH.embers.anchor.x / ASH.embers.w, ASH.embers.anchor.y / ASH.embers.h);
            const dying = since / EMBERS_MS;
            fire.visible = !reduced && dying < 1 && Math.floor(clock / 180 + dying * 3) % (dying < 0.5 ? 4 : 2) !== 0;
            light.visible = false;
            if (circle.visible) {
                const step = Math.round(clamp(circleProgress, 0, 1) * 48);
                circle.texture = artTexture(`circle:${step}`, () => texture(bake(magicCircle(step / 48))));
            }
            return;
        }
        const stage = clamp(bonfire.stage, 0, BONFIRE_STAGES);
        const frames = BONFIRE.stages[stage];
        const fr = frames[reduced ? 0 : Math.floor(clock / 110) % frames.length];
        base.texture = defTexture(fr.base, lit);
        base.anchor.set(fr.base.anchor.x / fr.base.w, fr.base.anchor.y / fr.base.h);
        fire.visible = Boolean(fr.fire);
        if (fr.fire) {
            fire.texture = defTexture(fr.fire);
            fire.anchor.set(fr.fire.anchor.x / fr.fire.w, fr.fire.anchor.y / fr.fire.h);
        }
        const glow = BONFIRE.light[stage];
        light.visible = Boolean(glow) && lighting !== 'day';
        if (light.visible) {
            light.texture = defTexture(glow);
            light.position.set(bonfire.home.x, bonfire.home.y);
            // The firelight breathes in two steps, and flares up for a moment on a new stage.
            light.alpha = clock - bonfire.flare < 300 ? 0.8 : reduced || Math.floor(clock / 220) % 3 ? 0.55 : 0.48;
        }
    }

    // Hit zones are the core of the body, not the whole sprite: in a tight patch near pumpkins overlap far ones,
    // and only the cores have to stay apart (app/config/hellowin.php is laid out for that). Padded stages keep the
    // body where it was, so the zones of stage 0 hold for every stage.
    function publishHitZones() {
        const px = layout.cssPx;
        for (const size of SIZES) {
            const def = pumpkinSprite(0, size);
            const w = Math.round(def.w * 0.72);
            const h = Math.round(def.h * 0.72);
            const lift = Math.round(def.h * 0.06);
            rootEl.style.setProperty(`--hit-w-${size}`, `${w * px}px`);
            rootEl.style.setProperty(`--hit-h-${size}`, `${h * px}px`);
            rootEl.style.setProperty(`--hit-x-${size}`, `${-(w >> 1) * px}px`);
            rootEl.style.setProperty(`--hit-y-${size}`, `${(def.h - def.anchor.y - lift - h) * px}px`);
        }
        // The bonfire's clickable core (BONFIRE.hit) around its foot; the frames share one anchor.
        const { hit } = BONFIRE;
        const { anchor } = BONFIRE.stages[0][0].base;
        rootEl.style.setProperty('--hit-w-bonfire', `${hit.w * px}px`);
        rootEl.style.setProperty('--hit-h-bonfire', `${hit.h * px}px`);
        rootEl.style.setProperty('--hit-x-bonfire', `${(hit.x - anchor.x) * px}px`);
        rootEl.style.setProperty('--hit-y-bonfire', `${(hit.y - anchor.y) * px}px`);
        // The circle's: the pit around the same foot.
        const pit = { w: ASH.pit.w - 8, h: ASH.pit.h - 4 };
        rootEl.style.setProperty('--hit-w-circle', `${pit.w * px}px`);
        rootEl.style.setProperty('--hit-h-circle', `${pit.h * px}px`);
        rootEl.style.setProperty('--hit-x-circle', `${-(pit.w >> 1) * px}px`);
        rootEl.style.setProperty('--hit-y-circle', `${-(pit.h >> 1) * px}px`);
        rootEl.style.setProperty('--float-lift', `${FLOAT_LIFT * px}px`);
        rootEl.style.setProperty('--px', `${px}px`);
    }

    function rebuild() {
        layout = measure(rootEl);
        app.renderer.resize(layout.width, layout.height);
        target?.destroy(true);
        target = RenderTexture.create({ width: layout.artW, height: layout.artH, resolution: 1, scaleMode: 'nearest' });
        display.texture = target;
        display.scale.set(layout.cssPx);
        // A fade frozen at the old size would not line up any more.
        if (fade && (fade.texture.width !== layout.artW || fade.texture.height !== layout.artH)) endFade();
        fadeDisplay.scale.set(layout.cssPx);
        groundLayer.removeChildren().forEach((c) => c.destroy({ children: true }));
        lightLayer.removeChildren().forEach((c) => c.destroy());
        lighting = lightingOf(state.scene);
        out = jumpedOut(state.scene, state.stageMax, pumpkinElements());
        // Two light sources at night: the bonfire's warm reach around the centre, cold moonlight beyond it.
        const burning = bonfireElement();
        if (lighting === 'night' && state.scene < state.sceneStages && burning && BONFIRE.light[clamp(burning.stage ?? 0, 0, BONFIRE_STAGES)]) {
            const shape = patchShape();
            // Centred a little toward the viewer: it covers the whole patch and its near rim, not the hills behind it.
            fireLight = { x: Math.round(shape.cx), y: Math.round(shape.cy + shape.ry * 0.35), rx: Math.round(shape.rx * 1.45), ry: Math.round(shape.ry * 1.65), near: 'firelight' };
        } else {
            fireLight = null;
        }
        drawSky();
        drawBase();
        drawTree();
        drawPlants();
        placeBugs();
        placeBushes();
        buildPumpkins();
        buildBonfire();
        stage.build();
        buildWalkers();
        // Ground targets outlive a resize like walkers: their sprites went with groundLayer.
        for (const f of flyers.values()) {
            if (!f.ground) continue;
            f.sprite = new Sprite();
            groundLayer.addChild(f.sprite);
        }
        for (const particle of particles.splice(0)) {
            particle.sprite.destroy();
            particle.done?.();
        }
        publishHitZones();
        veil.width = layout.artW;
        veil.height = layout.artH;
        tick({ deltaMS: 0 });
        app.render();
    }

    /** Freezes the world as it is now; tick() fades the copy out in steps over the relit world for `ms`. */
    function startFade(ms) {
        endFade();
        const frozen = RenderTexture.create({ width: layout.artW, height: layout.artH, resolution: 1, scaleMode: 'nearest' });
        app.renderer.render({ container: world, target: frozen, clear: true });
        fadeDisplay.texture = frozen;
        fadeDisplay.visible = true;
        fade = { texture: frozen, at: clock, ms };
    }

    function endFade() {
        if (!fade) return;
        fadeDisplay.visible = false;
        fadeDisplay.texture = Texture.EMPTY;
        fade.texture.destroy(true);
        fade = null;
    }

    /** The bonfire blows up (2 → 3): flash, shake, the fireball over the fresh pit. */
    function startBlast() {
        blastAt = clock;
        veilStart = clock;
        veilFrames = BLAST_VEIL;
        if (!bonfire) return;
        blastSprite.position.set(bonfire.home.x, bonfire.home.y);
    }

    // Bushes and flyers use plain screen percents, without the patch spread of layout.js: they live at the screen
    // edges, and a spread would push them off a phone screen.
    const toScreenArt = (p) => ({
        x: (p.x / 100) * layout.width / layout.cssPx,
        y: (p.y / 100) * layout.height / layout.cssPx,
    });

    function placeBushes() {
        bushes.clear();
        const random = rng(11);
        for (const b of [...(state.bushes ?? [])].sort((a, c) => a.y - c.y)) {
            const at = toScreenArt(b);
            const lit = lightAt(at.x, at.y);
            const idle = flyerArt(BUSH.idle[seedOf(b.id) % BUSH.idle.length], false, lit);
            const sprite = addGround(idle.def, at.x, at.y, { tex: idle.texture });
            rootIn(at.x, at.y, idle.def.w / 2 - 2, random);
            bushes.set(b.id, { sprite, idle, lit, rustleUntil: -1e9 });
        }
    }

    /**
     * Texture plus anchor fractions of a flyers.js sprite, cached per sprite object. `hazy` mixes every pixel 28%
     * toward hazeLight for the farthest flyers: the pixels stay solid, only their colours move toward the sky.
     */
    function flyerArt(def, hazy = false, lit = 'day') {
        if (!hazy && !flyerTextures.has(lit)) flyerTextures.set(lit, new Map());
        const cache = hazy ? hazyTextures : flyerTextures.get(lit);
        if (!cache.has(def)) {
            // On the ground and in the tree (owl, bushes, zombies) things take the scene's light; flyers stay as drawn.
            const c = lit === 'day' ? flyerBake(def) : relightCanvas(flyerBake(def), lit);
            if (hazy) {
                const ctx = c.getContext('2d');
                const img = ctx.getImageData(0, 0, c.width, c.height);
                const haze = [(PALETTE.hazeLight >> 16) & 255, (PALETTE.hazeLight >> 8) & 255, PALETTE.hazeLight & 255];
                for (let i = 0; i < img.data.length; i += 4) {
                    for (let k = 0; k < 3; k++) img.data[i + k] = Math.round(img.data[i + k] * 0.72 + haze[k] * 0.28);
                }
                ctx.putImageData(img, 0, 0);
            }
            cache.set(def, { texture: texture(c), ax: def.anchor.x / def.w, ay: def.anchor.y / def.h, def });
        }
        return cache.get(def);
    }

    // The base path in config percents: a straight line, or a quadratic curve through `via`; with its tangent.
    function basePath(f, t) {
        const u = 1 - t;
        if (!f.via) {
            return { x: f.from.x + (f.to.x - f.from.x) * t, y: f.from.y + (f.to.y - f.from.y) * t, dx: f.to.x - f.from.x, dy: f.to.y - f.from.y };
        }
        return {
            x: u * u * f.from.x + 2 * u * t * f.via.x + t * t * f.to.x,
            y: u * u * f.from.y + 2 * u * t * f.via.y + t * t * f.to.y,
            dx: 2 * u * (f.via.x - f.from.x) + 2 * t * (f.to.x - f.via.x),
            dy: 2 * u * (f.via.y - f.from.y) + 2 * t * (f.to.y - f.via.y),
        };
    }

    function pathAt(f, t) {
        const p = basePath(f, t);
        // The road-runner keeps the server's x but runs on the scene's horizon line, along the foot of the hills: the
        // config's horizon (38 %) lands a little in front of ours (37 % of the art height) and would put it on the lawn.
        if (f.kind === 'roadrunner') return { x: toScreenArt(p).x, y: layout.horizon - 1 };
        // `wobble` (protocol.md, bats): a sideways sway across the path, amp · sin(2π · waves · t), in screen percents.
        if (f.wobble) {
            const len = Math.hypot(p.dx, p.dy) || 1;
            const off = f.wobble.amp * Math.sin(2 * Math.PI * f.wobble.waves * t);
            return toScreenArt({ x: p.x - (p.dy / len) * off, y: p.y + (p.dx / len) * off });
        }
        return toScreenArt(p);
    }

    // Wingbeat period per depth, far to near (public/dev/flyers.html); the ground runners step at their own pace.
    const FLAP = [260, 130, 90];
    const STEP_MS = { roadrunner: 70, zombie: 150, worm: 150 };
    const RUSTLE_MS = 600;
    // Kinds that walk on the lawn: in groundLayer, sorted by their feet, on the walkers' coordinates.
    const GROUND = new Set(['zombie', 'worm']);
    // A shot ground target goes down where it stands: `hit`, then fall[0] until `fall`, fall[1], blinking from `lie`
    // until `gone`. A zombie kneels and lies flat; a worm writhes side to side, then sinks into the ground.
    const COLLAPSE = { zombie: { hit: 160, fall: 420, lie: 1600, gone: 2000 }, worm: { hit: 450, fall: 850, lie: 1300, gone: 1700 } };
    // A worm comes out of the ground at `from` (3 emerge frames) and goes back in at `to` (2 burrow frames).
    const EMERGE_MS = 130;
    const BURROW_MS = 150;
    // Single-pixel feathers of a shot far flyer.
    const SPECK = { chicken: 'white', crow: 'crowDark', bat: 'bat', ghost: 'ghost', witch: 'violetDark', zombie: 'zombie', firefly: 'frankenGlowHot' };
    // A firefly's lantern blinks every LANTERN_MS; its halo per depth and its alpha per glow level (dim, mid, bright).
    const LANTERN_MS = 1700;
    const LANTERN_HALO = [halo(2, 2, 'franken'), halo(4, 3, 'franken'), halo(6, 5, 'franken')];
    const LANTERN_ALPHA = [0.12, 0.35, 0.6];

    // Zombie and worm paths are on the lawn in config coordinates (the walk zone), so they take the patch spread like
    // walkers. A worm meanders: `wobble` sways it across the path, in config percents.
    function groundPathAt(f, t) {
        const dx = f.to.x - f.from.x;
        const dy = f.to.y - f.from.y;
        const p = { x: f.from.x + dx * t, y: f.from.y + dy * t };
        if (!f.wobble) return walkerArt(p);
        const len = Math.hypot(dx, dy) || 1;
        const off = f.wobble.amp * Math.sin(2 * Math.PI * f.wobble.waves * t);
        return walkerArt({ x: p.x - (dy / len) * off, y: p.y + (dx / len) * off });
    }

    /**
     * A crawling worm at `local` ms into its flight: it stays on `from` while it emerges and on `to` while it burrows,
     * and crawls the whole path in the time between. Returns its point and frame.
     */
    function wormAt(f, set, local) {
        const out = set.emerge.length * EMERGE_MS;
        const back = set.burrow.length * BURROW_MS;
        const t = clamp((local - out) / Math.max(1, f.duration - out - back), 0, 1);
        const into = local - (f.duration - back);
        const frame = local < out ? set.emerge[Math.floor(local / EMERGE_MS)]
            : into >= 0 ? set.burrow[Math.min(set.burrow.length - 1, Math.floor(into / BURROW_MS))]
            : set.fly[Math.floor((local - out) / STEP_MS.worm) % set.fly.length];
        return { pos: groundPathAt(f, t), frame };
    }

    function animateFlyers() {
        for (const [id, f] of flyers) {
            const set = FLYERS[f.kind]?.[f.depth] ?? FLYERS.duck[0];
            let art;
            let flip = f.flip;
            if (f.shotAt === null) {
                const now = serverNow();
                // A flyer stamped a few ms ahead of our clock estimate must not index frames with a negative time.
                const local = Math.max(0, now - f.launchedAt);
                if (now >= f.expiresAt) {
                    dropFlyer(f);
                    flyers.delete(id);
                    continue;
                }
                const t = clamp(local / f.duration, 0, 1);
                let frame;
                if (set.emerge) ({ pos: f.pos, frame } = wormAt(f, set, local));
                else {
                    f.pos = f.ground ? groundPathAt(f, t) : pathAt(f, t);
                    frame = set.fly[Math.floor(local / (STEP_MS[f.kind] ?? FLAP[clamp(f.depth, 0, 2)])) % set.fly.length];
                }
                art = f.ground ? flyerArt(frame, false, lightAt(f.pos.x, f.pos.y)) : flyerArt(frame, f.depth === 0);
            } else if (f.ground) {
                const since = clock - f.shotAt;
                const collapse = COLLAPSE[f.kind] ?? COLLAPSE.zombie;
                if (since >= collapse.gone) {
                    dropFlyer(f);
                    flyers.delete(id);
                    continue;
                }
                f.pos = f.shotPos;
                art = flyerArt(since < collapse.hit ? set.hit : set.fall[since < collapse.fall ? 0 : 1], false, lightAt(f.pos.x, f.pos.y));
                // A hit worm writhes: thrown from side to side every 90 ms.
                if (f.kind === 'worm' && since < collapse.hit && !reduced) flip = Math.floor(since / 90) % 2 ? !f.flip : f.flip;
                // Blinks out instead of fading, like the particles: alpha would add colours outside the palette.
                f.sprite.visible = since < collapse.lie || Math.floor(since / 100) % 2 === 0;
            } else {
                // Hit frame, then a tumble down: far birds fall behind the hills, the nearer ones off the screen.
                const fall = (clock - f.shotAt) / 1000;
                f.pos = { x: f.shotPos.x + f.drift * fall, y: f.shotPos.y + 130 * fall * fall };
                const floor = f.depth === 0
                    ? Math.max(ridge[clamp(Math.round(f.pos.x), 0, ridge.length - 1)] ?? layout.horizon, f.shotPos.y + 2)
                    : layout.artH + 30;
                if (f.pos.y > floor) {
                    dropFlyer(f);
                    flyers.delete(id);
                    continue;
                }
                art = flyerArt(fall < 0.16 ? set.hit : set.fall[Math.floor(fall / 0.12) % set.fall.length], f.depth === 0);
            }
            f.sprite.texture = art.texture;
            f.sprite.anchor.set(art.ax, art.ay);
            f.sprite.scale.x = flip ? -1 : 1;
            f.sprite.position.set(Math.round(f.pos.x), Math.round(f.pos.y));
            if (f.ground) f.sprite.zIndex = depthKey(f.pos.x, f.pos.y);
            f.art = art;
            if (f.glow) lantern(f, set, art, flip);
        }
        for (const bush of bushes.values()) {
            const art = clock < bush.rustleUntil ? flyerArt(BUSH.rustle[Math.floor(clock / 90) % BUSH.rustle.length], false, bush.lit) : bush.idle;
            bush.sprite.texture = art.texture;
        }
    }

    function dropFlyer(f) {
        f.sprite.destroy();
        f.glow?.destroy();
        f.halo?.destroy();
    }

    /**
     * A firefly's lantern (flyers.js, `glow`): unlit over the body, with a soft additive halo, pulsing on its own beat
     * — a slow blink, bright for a moment, flickering while bright, then dim; out for good once the firefly is shot.
     */
    function lantern(f, set, art, flip) {
        const lit = f.shotAt === null;
        f.glow.visible = lit;
        f.halo.visible = lit;
        if (!lit) return;
        const beat = (clock + f.phase) % LANTERN_MS;
        const level = reduced ? 1 : beat < 120 || (beat >= 600 && beat < 720) ? 1 : beat < 600 ? (mix(f.phase, Math.floor(clock / 90)) % 5 ? 2 : 1) : 0;
        const glow = flyerArt(set.glow[level]);
        f.glow.texture = glow.texture;
        f.glow.anchor.set(glow.ax, glow.ay);
        f.glow.scale.x = flip ? -1 : 1;
        f.glow.position.copyFrom(f.sprite.position);
        const { def } = art;
        const [lx, ly] = set.lantern;
        const x = Math.round(f.pos.x) + (flip ? def.anchor.x - lx - 1 : lx - def.anchor.x);
        const y = Math.round(f.pos.y) + ly - def.anchor.y;
        const h = LANTERN_HALO[clamp(f.depth, 0, 2)];
        f.halo.texture = defTexture(h);
        f.halo.alpha = LANTERN_ALPHA[level];
        f.halo.position.set(x - h.anchor.x, y - h.anchor.y);
    }

    // Flights run on server time, so every player sees a bird at the same spot and a late event picks the flight
    // up midway; a bird past `expiresAt` (say, events held back in a background tab) is never drawn.
    function launch(event) {
        if (flyers.has(event.flyer)) return;
        const now = serverNow();
        const launchedAt = event.launchedAt ?? now;
        const expiresAt = event.expiresAt ?? launchedAt + event.duration;
        if (now >= expiresAt) return;
        const sprite = new Sprite();
        const ground = GROUND.has(event.kind);
        const layer = ground ? groundLayer : [farFlyerLayer, midFlyerLayer, nearFlyerLayer][clamp(event.depth ?? 0, 0, 2)];
        // A firefly's halo goes under its body, the lantern's light over it.
        const lit = !ground && FLYERS[event.kind]?.[0]?.glow;
        const halo = lit ? new Sprite() : null;
        if (halo) {
            halo.blendMode = 'add';
            layer.addChild(halo);
        }
        layer.addChild(sprite);
        const glow = lit ? new Sprite() : null;
        if (glow) layer.addChild(glow);
        flyers.set(event.flyer, {
            ground,
            kind: event.kind,
            depth: event.depth ?? 0,
            from: event.from,
            via: event.via ?? null,
            to: event.to,
            wobble: Number.isFinite(event.wobble?.amp) && Number.isFinite(event.wobble?.waves) ? event.wobble : null,
            duration: Math.max(1, event.duration),
            launchedAt,
            expiresAt,
            flip: event.to.x < event.from.x,
            sprite,
            glow,
            halo,
            phase: seedOf(event.flyer) % LANTERN_MS,
            shotAt: null,
            pos: ground ? walkerArt(event.from) : toScreenArt(event.from),
        });
        const bush = event.bush && bushes.get(event.bush);
        if (bush && now - launchedAt < RUSTLE_MS) bush.rustleUntil = clock + RUSTLE_MS - (now - launchedAt);
        animateFlyers();
    }

    function down(event) {
        const f = flyers.get(event.flyer);
        if (!f || f.shotAt !== null) return;
        f.shotAt = clock;
        f.shotPos = { ...f.pos };
        f.drift = (f.flip ? -1 : 1) * 12;
        // The body's middle: a ground target stands on its anchor, a flyer is centred on it.
        const mid = f.ground ? { x: f.pos.x, y: f.pos.y - f.art.def.h / 2 } : f.pos;
        if (!reduced && f.depth === 0) {
            // A bird a few pixels big loses a couple of single-pixel feathers.
            const key = SPECK[f.kind] ?? 'duckGrayDark';
            const speck = artTexture(`speck:${key}`, () => {
                const c = canvas(1, 1);
                const ctx = c.getContext('2d');
                ctx.fillStyle = css(PALETTE[key]);
                ctx.fillRect(0, 0, 1, 1);
                return texture(c);
            });
            spawn([speck], f.pos.x, f.pos.y, -6, -8, 20, 1);
            spawn([speck], f.pos.x, f.pos.y, 7, -5, 20, 1.1);
        } else if (!reduced) {
            // Bits of a ground target take the light it stood in, like the target itself.
            const feathers = feathersOf(f.kind).map((def) => flyerArt(def, false, f.ground ? lightAt(mid.x, mid.y) : 'day').texture);
            for (let i = 0; i < 6; i++) {
                const a = (i / 6) * Math.PI * 2 + 0.4;
                spawn(feathers, mid.x, mid.y, Math.cos(a) * 24, Math.sin(a) * 14 - 10, 30, 1.2 + Math.random() * 0.3);
            }
        }
        const score = flyerArt(digitsSprite(`+${event.points}`));
        spawn([score.texture], f.pos.x - score.def.w / 2, f.ground ? f.pos.y - f.art.def.h - 4 : f.pos.y - 14, 0, -10, 0, 1.2);
    }

    /**
     * Hit box of a flyer in art pixels: the body only, 60% of the sprite's width and height around the anchor, so
     * wings, tail and legs do not count. The tiny far birds have no box: only their own opaque pixels hit.
     */
    function hitBox(f) {
        const { def } = f.art;
        const left = Math.round(f.pos.x) - (f.flip ? def.w - def.anchor.x : def.anchor.x);
        const top = Math.round(f.pos.y) - def.anchor.y;
        if (f.depth === 0) return { left, top, w: def.w, h: def.h, pixels: true };
        if (def.box) {
            // A worm is long and low: its opaque bounds (mirrored with it) and 2 px around them, or it would be a
            // sliver to hit.
            const { box } = def;
            const x = f.flip ? def.w - box.x - box.w : box.x;
            return { left: left + x - 2, top: top + box.y - 2, w: box.w + 4, h: box.h + 3, pixels: false };
        }
        if (f.ground) {
            // The anchor is at the feet: the box covers the body above them, without the outstretched arms.
            const w = Math.max(2, Math.round(def.w * 0.5));
            const h = Math.max(2, Math.round(def.h * 0.85));
            return { left: Math.round(f.pos.x - w / 2), top: Math.round(f.pos.y) - h, w, h, pixels: false };
        }
        const w = Math.max(2, Math.round(def.w * 0.6));
        const h = Math.max(2, Math.round(def.h * 0.6));
        return { left: Math.round(f.pos.x - w / 2), top: Math.round(f.pos.y - h / 2), w, h, pixels: false };
    }

    function hits(f, x, y) {
        const box = hitBox(f);
        if (x < box.left || x >= box.left + box.w || y < box.top || y >= box.top + box.h) return false;
        if (!box.pixels) return true;
        const { def } = f.art;
        const col = Math.floor(x - box.left);
        const row = def.rows[Math.floor(y - box.top)] ?? '';
        return Boolean(def.legend[row[f.flip ? def.w - 1 - col : col]]);
    }

    /** Id of the flyer under the pointer, nearest depth first; null when none. */
    function flyerAt(clientX, clientY) {
        const rect = app.canvas.getBoundingClientRect();
        const x = (clientX - rect.left) / layout.cssPx;
        const y = (clientY - rect.top) / layout.cssPx;
        const live = [...flyers].filter(([, f]) => f.shotAt === null && f.art).sort(([, a], [, b]) => b.depth - a.depth);
        for (const [id, f] of live) {
            if (hits(f, x, y)) return id;
        }
        return null;
    }

    let showHits = false;
    function drawHits() {
        hitOverlay.clear();
        if (!showHits) return;
        for (const f of flyers.values()) {
            if (f.shotAt !== null || !f.art) continue;
            const b = hitBox(f);
            hitOverlay.rect(b.left + 0.5, b.top + 0.5, b.w - 1, b.h - 1).stroke({ width: 1, color: 0xff00ff });
        }
    }

    /**
     * One particle; `kind` names a PARTICLES set or is a list of textures to pick from. Returns it, so a caller can
     * add `anim(p)` (the texture for its age; such a particle fades by its own frames instead of blinking out),
     * `drag` (1/s, slows it down), `wind` (sideways acceleration, px/s²), `tick(p)` (every frame, after the move) and
     * `done()` (called when it dies).
     */
    function spawn(kind, x, y, vx, vy, gravity, life) {
        const textures = Array.isArray(kind) ? kind : particleArt(kind);
        const sprite = new Sprite(textures[Math.floor(Math.random() * textures.length)]);
        particleLayer.addChild(sprite);
        const particle = { sprite, x, y, vx, vy, gravity, life, age: 0 };
        particles.push(particle);
        return particle;
    }

    // Where a pumpkin's sprite point is now: it moves with the jump and floats up with its lift.
    function emitPoint(p, key) {
        const { def } = p.art;
        const [ex, ey] = def.emit?.[key] ?? [def.anchor.x, def.h >> 1];
        return { x: p.pos.x - def.anchor.x + ex, y: p.pos.y - p.lift - def.anchor.y + ey };
    }

    const between = (a, b) => a + Math.random() * (b - a);
    const sign = () => (Math.random() < 0.5 ? -1 : 1);

    // Click particles follow the scene: carving bits by day, embers and cinders at sunset, green motes at night, violet
    // hex sparks under the blood moon. A burnt click (the pumpkin is done) only gives off a couple of grey sparks.
    function burst(p, stage) {
        const final = state.scene >= state.sceneStages;
        if (final) {
            // Green motes in the cursed world, where the pumpkins glow FrankenPHP's lime.
            const at = emitPoint(p, 'top');
            const kind = finale.step >= 5 ? 'magic' : 'hex';
            for (let i = 0; i < 4; i++) spawn(kind, at.x, at.y, sign() * between(6, 22), -between(20, 50), -10, between(0.6, 1));
        } else if (stage >= state.stageMax) {
            const at = emitPoint(p, 'top');
            for (let i = 0; i < 2; i++) {
                spawn('spark', at.x, at.y - 1, sign() * between(10, 30), -between(20, 45), 60, 0.4);
            }
        } else if (state.scene === 1) {
            const at = emitPoint(p, 'top');
            for (let i = 0; i < 6; i++) {
                spawn(i % 3 === 0 ? 'cinder' : 'ember', at.x, at.y, sign() * between(4, 22), -between(30, 70), i % 3 === 0 ? 40 : -20, between(0.6, 1));
            }
        } else if (state.scene >= 2) {
            const at = emitPoint(p, 'top');
            for (let i = 0; i < 5; i++) spawn('magic', at.x, at.y, sign() * between(4, 20), -between(15, 45), -15, between(0.7, 1.1));
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

    // Embers and cinders off the bonfire's heap (green motes once the spell has turned it); `n` of them.
    function bonfireBurst(n) {
        if (!bonfire) return;
        if (bonfire.ash) {
            // Violet hex sparks off the circle on the pit.
            for (let i = 0; i < n; i++) spawn('hex', bonfire.home.x + between(-24, 24), bonfire.home.y + between(-6, 6), sign() * between(4, 18), -between(20, 50), -10, between(0.6, 1));
            return;
        }
        const green = bonfire.stage >= 5;
        const top = bonfire.home.y - 10 - Math.min(30, bonfire.stage * 4);
        for (let i = 0; i < n; i++) {
            const kind = green ? 'magic' : i % 3 === 0 ? 'cinder' : 'ember';
            spawn(kind, bonfire.home.x + between(-10, 10), top + between(-4, 6), sign() * between(4, 26), -between(30, 80), kind === 'cinder' ? 30 : -20, between(0.6, 1.1));
        }
    }

    // Squash-and-shake frames after a click: [scaleX, scaleY, offsetX], 50 ms each.
    const HIT = [[1.15, 0.85, 1], [0.94, 1.08, -1], [1.03, 0.97, 0]];
    // Bounce frames after a stage change: [scaleX, scaleY, offsetY].
    const BOUNCE = [[0.88, 1.15, -3], [0.95, 1.06, -2], [1, 1, -1], [1.12, 0.9, 0], [1.04, 0.96, 0]];
    // The springy hop of the jumped-out pumpkins (scenes 1–2): period, airborne share, height.
    const SPRING = { ms: 1100, air: 0.3, h: 3 };

    function animatePumpkin(p) {
        const mode = pumpkinMode(p);
        let sx = 1;
        let sy = 1;
        let ox = 0;
        let oy = 0;
        let skew = 0;

        // Ground point: home, out after the jump, or a hop on the way there.
        let at = out ? p.out : p.home;
        let hopping = false;
        if (out && !reduced) {
            const t = (clock - jumpAt - p.delay) / JUMP_MS;
            if (t < 1) {
                hopping = true;
                const k = clamp(t, 0, 1);
                const e = 1 - (1 - k) * (1 - k);
                at = { x: p.home.x + (p.out.x - p.home.x) * e, y: p.home.y + (p.out.y - p.home.y) * e };
                oy = -Math.round(Math.sin(Math.PI * k) * JUMP_H);
                if (t > 0) [sx, sy] = [0.92, 1.1];
            } else if (t < 1 + BOUNCE.length * 60 / JUMP_MS) {
                // Landing: the same squash as a stage change.
                [sx, sy, oy] = BOUNCE[Math.min(BOUNCE.length - 1, Math.floor(((t - 1) * JUMP_MS) / 60))];
                hopping = true;
            }
        }
        const final = state.scene >= state.sceneStages;
        // The finale moves the pumpkins itself: the round dance, the drop, the rise of the cursed world.
        const staged = finalePumpkin(p);
        if (staged) at = staged.at;
        p.pos = at;
        const floating = staged ? staged.lift > 0 : floats(p.stage, state.scene, state.sceneStages);
        // Floats bob like a float on water; under the blood moon slower, with a slight tilt.
        p.lift = staged ? staged.lift : floating ? FLOAT_LIFT + (reduced ? 0 : Math.round(2 * Math.sin((clock + p.phase) / (final ? 520 : 650)))) : 0;

        const hit = Math.floor((clock - p.hit) / 50);
        const bounce = Math.floor((clock - p.bounce) / 60);
        if (hopping) {
            // The jump wins over everything else.
        } else if (staged?.squash && !reduced) {
            // Landing after the drop of finale step 2.
            [sx, sy, oy] = staged.squash;
        } else if (staged && !floating) {
            // Lying on the ground after the drop: still, apart from a click's shake.
            if (hit >= 0 && hit < HIT.length) [sx, sy, ox] = HIT[hit];
        } else if (hit >= 0 && hit < HIT.length) {
            [sx, sy, ox] = HIT[hit];
        } else if (bounce >= 0 && bounce < BOUNCE.length) {
            [sx, sy, oy] = BOUNCE[bounce];
        } else if (!reduced && floating) {
            skew = Math.sin((clock + p.phase) / 900) * (final ? 0.06 : 0.03);
        } else if (!reduced && out) {
            // Jumped out: a springy hop in place, stretched in the air and squashed on landing.
            const u = ((clock + p.phase * 3) % SPRING.ms) / SPRING.ms;
            if (u < SPRING.air) {
                oy = -Math.round(Math.sin((Math.PI * u) / SPRING.air) * SPRING.h);
                [sx, sy] = [0.94, 1.07];
            } else if (u < SPRING.air + 0.08) {
                [sx, sy] = [1.1, 0.9];
            }
        } else if (!reduced && mode === 'ready' && (clock + p.phase) % 1400 < 120) {
            oy = -1;
        } else if (!reduced && mode === 'done') {
            skew = Math.sin((clock + p.phase) / 650) * 0.07;
        }
        p.ring.visible = mode === 'ready' && (reduced || Math.floor((clock + p.phase) / 220) % 2 === 0);
        p.body.scale.set(sx, sy);
        p.body.skew.x = skew;
        p.node.position.set(Math.round(at.x + ox), Math.round(at.y + oy - p.lift));
        p.node.zIndex = depthKey(at.x, at.y);

        // The light layers flicker through their frames, each pumpkin on its own beat.
        for (const name of LIGHT_LAYERS) {
            const frames = p.art.frames[name];
            if (frames && !reduced) p.layers[name].texture = frames[Math.floor((clock + p.phase) / FRAME_MS[name]) % frames.length];
        }
        if (p.arms.visible && !reduced) showArms(p, raiseFrame(p.seed, serverNow()));
        p.halo.position.set(Math.round(at.x), Math.round(at.y));
        p.shadow.visible = p.lift > 0;
        if (p.shadow.visible) {
            const shadows = PUMPKIN_SHADOW[p.size] ?? PUMPKIN_SHADOW.m;
            p.shadow.texture = defTexture(shadows[clamp(Math.floor((p.lift - 3) / 1.5), 0, shadows.length - 1)]);
            p.shadow.position.set(Math.round(at.x), Math.round(at.y));
        }

        if (p.flash && clock - p.flash.born > 70) {
            p.flash.sprite.destroy();
            p.flash = null;
        }
    }

    // Characters (docs/spec/frontend.md, «Экран входа и персонажи»). Walk coordinates are config percents like the
    // pumpkins, so they take the same patch spread; a phone screen cuts the lawn's far left and right off, and
    // walkers there are held at the screen edge instead of vanishing.
    const WALKER_MARGIN = 10;
    const IDLE_SLOT_MS = 3500;
    // Facing per idle slot: mostly toward the camera, sometimes sideways or away.
    const IDLE_FACING = [['front', false], ['front', false], ['side', false], ['side', true], ['back', false], ['front', false]];
    const poseOf = (pose) => POSES[pose] ?? { frames: 1, ms: 500 };
    // Character frames per lighting: each canvas is recoloured once per lighting it shows up in (night.js).
    const frameTextures = new Map();
    // A held beer (`beer` of the frame) keeps its amber over the relit frame, like the pumpkins' (night.js, BEER_KEEP).
    const frameTexture = (c, lit = 'day', beer = null) => {
        if (!frameTextures.has(lit)) frameTextures.set(lit, new WeakMap());
        const cache = frameTextures.get(lit);
        if (!cache.has(c)) {
            const relit = lit === 'day' ? c : relightCanvas(c, lit);
            if (beer && lit !== 'day') relit.getContext('2d').drawImage(keepHueCanvas(beer, lit), 0, 0);
            cache.set(c, texture(relit));
        }
        return cache.get(c);
    };
    // A candle casts a small warm circle at dusk and at night; the spell book a faint green one after dark.
    const BOOK_LIGHT = halo(8, 3, 'green');
    // A shot (docs/spec/frontend.md, «Выстрел»), ms from the event: the flash frames end at FLASH_MS, the recoil frame
    // comes in at RECOIL_MS and jolts the figure until KICK_MS, the smoking pistol is held up until SHOT_MS. Smoke
    // puffs: [ms, off the raised barrel] — the first ones are blown out of the muzzle. After dark the flash lights the
    // ground and the air around the muzzle for its first frames.
    const FLASH_MS = [50, 110, 170];
    const RECOIL_MS = 110;
    const KICK_MS = 230;
    const SHOT_MS = 1200;
    const PUFFS = [[110, false], [120, false], [140, false], [170, false], [260, true], [430, true], [650, true], [900, true]];
    const SHOT_GROUND = halo(16, 6, 'warm');
    const SHOT_GROUND_ALPHA = [0.9, 0.6, 0.3];
    const SHOT_AIR = halo(6, 5, 'warm');
    const SHOT_AIR_ALPHA = [0.8, 0.45];
    // Other items: how long the aimed pose holds, the book's bolt and flash, the beer's bubbles (ms after the shot).
    const ITEM_SHOT_MS = 900;
    const BOLT_MS = 260;
    const BOOK_FLASH = halo(6, 5, 'green');
    const BUBBLES = [80, 150, 230, 320, 420, 540];
    // A held candle: its flicker frame changes on FLAME_MS slots (mostly the steady one), the ground light and the halo
    // round the flame breathe with it. Its trail: an ember every EMBER_MS walking, every EMBER_IDLE_MS standing, at
    // most EMBER_CAP alive per walker.
    const FLAME_MS = 90;
    const FLICKER = [0, 0, 1, 0, 2, 1];
    const CANDLE_ALPHA = [0.55, 0.62, 0.48];
    const FLAME_HALO = halo(5, 4, 'candle');
    const HALO_ALPHA = [0.34, 0.42, 0.26];
    const EMBER_MS = [110, 170];
    const EMBER_IDLE_MS = [1200, 3200];
    const EMBER_CAP = 7;
    const EMBER_COLORS = ['flameCore', 'pumpkinShine', 'pumpkinLight', 'pumpkin', 'ember'];
    // The red flame (scene 3, finale step 0): a slower, gentler flicker than the candle's, a red ground light and halo
    // after dark, no trail. Its shot — a red flare and a spark streaking at the target, red embers in its wake.
    const RED_FLAME_MS = 140;
    const RED_LIGHT = halo(9, 4, 'red');
    const RED_ALPHA = [0.42, 0.48, 0.36];
    const RED_HALO = halo(5, 4, 'red');
    const RED_EMBERS = ['columnCore', 'bloodMoonLight', 'rune', 'runeDark'];
    const RED_SPARK_SPEED = 640;

    // Topmost opaque row of a character frame: frames keep headroom for tall hats, the label goes over the real head.
    const frameTops = new WeakMap();
    function frameTop(c) {
        if (!frameTops.has(c)) {
            const { data } = c.getContext('2d').getImageData(0, 0, c.width, c.height);
            let top = 0;
            while (top < c.height && !data.slice(top * c.width * 4, (top + 1) * c.width * 4).some((v, i) => i % 4 === 3 && v > 0)) top++;
            frameTops.set(c, top);
        }
        return frameTops.get(c);
    }

    function walkerArt(p) {
        const at = toArt(p);
        return { x: clamp(at.x, WALKER_MARGIN, layout.artW - WALKER_MARGIN), y: at.y };
    }

    // Front, back or side (mirrored for left) by the screen direction of a step.
    function facing(from, to) {
        const a = walkerArt(from);
        const b = walkerArt(to);
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        if (Math.abs(dx) >= Math.abs(dy) * 0.8) return ['side', dx < 0];
        return dy > 0 ? ['front', false] : ['back', false];
    }

    /**
     * Where a walk is at server time `now`: constant speed along the polyline, measured in config units like the
     * server's HELLOWIN_WALK_SPEED. Returns the point, the segment it is on and whether it is still moving.
     */
    function walkAt(walk, now) {
        const path = walk?.path?.length ? walk.path : [{ x: ROOT.x, y: 94 }];
        const lengths = path.slice(1).map((p, i) => Math.hypot(p.x - path[i].x, p.y - path[i].y));
        const total = lengths.reduce((a, b) => a + b, 0);
        const span = (walk?.arrivesAt ?? 0) - (walk?.startedAt ?? 0);
        const t = total > 0 && span > 0 ? clamp((now - walk.startedAt) / span, 0, 1) : 1;
        let left = t * total;
        for (let i = 0; i < lengths.length; i++) {
            if (left <= lengths[i] || i === lengths.length - 1) {
                const k = lengths[i] > 0 ? clamp(left / lengths[i], 0, 1) : 1;
                const a = path[i];
                const b = path[i + 1];
                return { at: { x: a.x + (b.x - a.x) * k, y: a.y + (b.y - a.y) * k }, from: a, to: b, moving: t < 1, index: i };
            }
            left -= lengths[i];
        }
        return { at: path[0], from: path[0], to: path[0], moving: false, index: 0 };
    }

    /*
     * Walking never jumps (docs/spec/frontend.md, «Ходьба»). A walker is drawn along its own track: a path in config
     * coordinates run on the scene clock. A new walk (a `move`, a changed `walker`, a snapshot) continues from where
     * the walker is drawn now, through the server path's points still ahead of it, at the server's speed — so it may
     * arrive a little after `arrivesAt`, which nobody can see. Latency and the clock offset only shift the timing, never
     * the position. A walker drawn too far from where the server has it (a long tab sleep) snaps, and so does one that
     * appears for the first time — it starts where the server has it now.
     */
    const SNAP_DISTANCE = 15;
    // Config units per ms when a walk does not tell (a standing walker): the last speed seen, HELLOWIN_WALK_SPEED-like.
    let walkSpeed = 12 / 1000;
    const pathLength = (path) => path.slice(1).reduce((sum, p, i) => sum + Math.hypot(p.x - path[i].x, p.y - path[i].y), 0);

    function trackFrom(start, rest) {
        const path = [start, ...rest].filter((p, i, all) => i === 0 || Math.hypot(p.x - all[i - 1].x, p.y - all[i - 1].y) > 0.01);
        return { path, startedAt: clock, arrivesAt: clock + pathLength(path) / walkSpeed };
    }

    /** A new walk for walker `w`: continue from the drawn point, or start where the server has it now. */
    function retrack(w, walk) {
        const path = walk?.path?.length ? walk.path : [{ x: ROOT.x, y: 94 }];
        const span = (walk?.arrivesAt ?? 0) - (walk?.startedAt ?? 0);
        const total = pathLength(path);
        if (span > 0 && total > 0) walkSpeed = total / span;
        const server = walkAt(walk, serverNow());
        if (!w.drawn || Math.hypot(w.drawn.x - server.at.x, w.drawn.y - server.at.y) > SNAP_DISTANCE) {
            w.track = trackFrom(server.at, path.slice(server.index + 1));
            return;
        }
        // The segment of the server path nearest to the drawn point: the points before its end are behind the walker.
        let best = { d: Infinity, i: 0 };
        for (let i = 0; i < path.length - 1; i++) {
            const a = path[i];
            const b = path[i + 1];
            const len2 = (b.x - a.x) ** 2 + (b.y - a.y) ** 2 || 1;
            const k = clamp(((w.drawn.x - a.x) * (b.x - a.x) + (w.drawn.y - a.y) * (b.y - a.y)) / len2, 0, 1);
            const d = Math.hypot(w.drawn.x - (a.x + (b.x - a.x) * k), w.drawn.y - (a.y + (b.y - a.y) * k));
            if (d < best.d) best = { d, i };
        }
        w.track = trackFrom({ ...w.drawn }, path.length > 1 ? path.slice(best.i + 1) : path);
    }

    function walkerLabel(w) {
        const label = new Text({
            text: w.name || '???',
            style: {
                fontFamily: 'ui-monospace, "Cascadia Mono", Consolas, "Courier New", monospace',
                fontSize: 12,
                fontWeight: '700',
                fill: w.id === meId ? PALETTE.sun : PALETTE.cream,
                stroke: { color: PALETTE.outline, width: 3, join: 'round' },
            },
        });
        label.anchor.set(0.5, 1);
        labelLayer.addChild(label);
        return label;
    }

    function addWalkerSprites(w) {
        w.sprite = new Sprite();
        groundLayer.addChild(w.sprite);
        w.glow = new Sprite();
        w.glow.anchor.set(0.5);
        w.glow.blendMode = 'add';
        lightLayer.addChild(w.glow);
        // The frame's own light — the ritual's palm lights, the candle flame — drawn unlit over the relit frame
        // (characters.js, `glow`); a soft additive halo around it; the muzzle flash of a shot.
        w.lights = new Sprite();
        w.halo = new Sprite();
        w.halo.blendMode = 'add';
        w.flash = new Sprite();
        groundLayer.addChild(w.lights, w.halo, w.flash);
        w.label?.destroy();
        w.label = walkerLabel(w);
    }

    function dropWalkerSprites(w) {
        w.sprite?.destroy();
        w.glow?.destroy();
        w.glow = null;
        for (const key of ['lights', 'halo', 'flash']) {
            w[key]?.destroy();
            w[key] = null;
        }
        w.label?.destroy();
        w.sprite = null;
        w.label = null;
    }

    function buildWalkers() {
        // Sprites went with groundLayer; labels live outside it and are made again with the new layout.
        for (const w of walkers.values()) addWalkerSprites(w);
        animateWalkers();
    }

    function setWalker(data) {
        const old = walkers.get(data.id);
        const w = { shoot: null, ...old, ...data, seed: seedOf(data.id) };
        if (!old || data.walk !== old.walk) retrack(w, w.walk);
        if (!old) {
            walkers.set(w.id, w);
            addWalkerSprites(w);
        } else {
            walkers.set(w.id, w);
            if (old.name !== w.name) {
                w.label?.destroy();
                w.label = walkerLabel(w);
            }
        }
    }

    /**
     * Pose of a standing walker. Time runs in slots of a few seconds, shifted per walker by crc32(id); each slot
     * hashes to a facing and maybe a fidget. Both inputs are shared, so every client shows the same move.
     */
    function idleOf(w, s, now) {
        const slotMs = IDLE_SLOT_MS + (w.seed % 2000);
        const shifted = now + (w.seed % slotMs);
        const slot = Math.floor(shifted / slotMs);
        const into = shifted - slot * slotMs;
        const frame = (pose) => Math.floor((now + w.seed) / poseOf(pose).ms) % poseOf(pose).frames;
        // Until the first slot that starts after arrival the walker keeps facing the way it came.
        // The track runs on the scene clock: its arrival on the server clock is as long ago as on ours.
        const arrived = now - (clock - (w.track?.arrivesAt ?? -1e12));
        if (now - into < arrived) {
            const [dir, flip] = s.from === s.to ? ['front', false] : facing(s.from, s.to);
            return { dir, flip, pose: 'idle', frame: frame('idle') };
        }
        const h = mix(w.seed, slot);
        const [dir, flip] = IDLE_FACING[h % IDLE_FACING.length];
        const fidget = poseOf('fidget');
        if ((h >>> 8) % 3 === 0 && into < fidget.frames * fidget.ms * 2) {
            return { dir, flip, pose: 'fidget', frame: Math.floor(into / fidget.ms) % fidget.frames };
        }
        return { dir, flip, pose: 'idle', frame: frame('idle') };
    }

    // Facing the circle from an art point: sideways when it is more to the side, else toward or away from the camera.
    function towardCentre(at) {
        const c = centre();
        const dx = c.x - at.x;
        const dy = c.y - at.y;
        if (Math.abs(dx) >= Math.abs(dy) * 0.8) return ['side', dx < 0];
        return dy < 0 ? ['back', false] : ['front', false];
    }

    // A canvas point of a character frame drawn with its anchor at (x, y), mirrored or not, in art px.
    const framePoint = ([cx, cy], x, y, flip) => ({
        x: flip ? x + FRAME.anchor.x - cx - 1 : x - FRAME.anchor.x + cx,
        y: y - FRAME.anchor.y + cy,
    });

    function animateWalkers() {
        const now = serverNow();
        const ritual = inFinale() && ritualOf(finale.step, finale.ready, sinceStep());
        const dark = lighting !== 'day';
        const clinking = clinksNow(now);
        for (const w of walkers.values()) {
            if (!w.sprite) continue;
            const s = walkAt(w.track, clock);
            w.drawn = s.at;
            const at = walkerArt(s.at);
            const clink = clinking.of.get(w.id);
            let view;
            let lean = 0;
            if (s.moving) {
                const [dir, flip] = facing(s.from, s.to);
                const walk = poseOf('walk');
                view = { dir, flip, pose: 'walk', frame: Math.floor((clock - w.track.startedAt) / walk.ms) % walk.frames, item: w.item ?? null };
            } else if (w.shoot && clock < w.shoot.until) {
                // One shot with what the hand holds: the aimed frame, then the follow-through (the pistol's recoil).
                view = { dir: w.shoot.dir, flip: w.shoot.flip, pose: 'shoot', frame: clock - w.shoot.at < RECOIL_MS ? 0 : 1, item: w.shoot.item };
            } else if (ritual) {
                // Hands up toward the circle, swaying on their own beat; a walker on the way walks normally and joins in
                // once it stands.
                w.shoot = null;
                const [dir, flip] = towardCentre(at);
                const pose = poseOf('ritual');
                view = { dir, flip, pose: 'ritual', frame: Math.floor((now + w.seed) / pose.ms) % pose.frames, item: null };
            } else if (clink) {
                w.shoot = null;
                ({ view, lean } = clinkView(w, clink, at, now));
            } else {
                w.shoot = null;
                view = { ...idleOf(w, s, now), item: w.item ?? null };
            }
            if (reduced && !s.moving && view.pose !== 'shoot') view.frame = 0;
            const f = characterFrame(w.look, view);
            const x = Math.round(at.x + lean);
            const y = Math.round(at.y);
            if (clink) clinkFoam(w, clink, f, view, x, y, now);
            w.sprite.texture = frameTexture(f.canvas, lightAt(at.x, at.y), f.beer);
            w.sprite.anchor.set(f.anchor.x / f.canvas.width, f.anchor.y / f.canvas.height);
            w.sprite.scale.x = view.flip ? -1 : 1;
            w.sprite.position.set(x, y);
            w.sprite.zIndex = depthKey(at.x, at.y);
            w.flash.visible = false;
            w.halo.visible = false;
            const red = view.item === 'red-flame';
            const light = !dark ? null : view.item === 'candle' ? NIGHT.candleLight : red ? RED_LIGHT : view.item === 'book' && lighting !== 'sunset' ? BOOK_LIGHT : null;
            w.glow.visible = Boolean(light);
            if (light) {
                w.glow.texture = defTexture(light);
                w.glow.alpha = light === BOOK_LIGHT ? 0.4 : 0.55;
                w.glow.position.set(x, y);
            }
            let glow = f.glow;
            if (f.flames) {
                // The candle: a flicker frame by a hash of short slots, the same on every client; the ground light
                // and the halo breathe with it and stand under and around the flame, wherever the hand carries it.
                const k = reduced ? 0 : FLICKER[mix(w.seed, Math.floor(clock / (red ? RED_FLAME_MS : FLAME_MS))) % FLICKER.length];
                glow = f.flames[k];
                const flame = framePoint(f.flame, x, y, view.flip);
                if (light) {
                    w.glow.alpha = (red ? RED_ALPHA : CANDLE_ALPHA)[k];
                    w.glow.position.set(flame.x, y);
                }
                if (dark) placeHalo(w, red ? RED_HALO : FLAME_HALO, flame, HALO_ALPHA[k] * (lighting === 'sunset' ? 1 : 1.4));
                if (!red) candleTrail(w, flame, s.moving, view);
            }
            if (view.pose === 'shoot') shotFx(w, view, x, y);
            w.lights.visible = Boolean(glow);
            if (glow) {
                w.lights.texture = frameTexture(glow);
                w.lights.anchor.copyFrom(w.sprite.anchor);
                w.lights.scale.x = w.sprite.scale.x;
                w.lights.position.copyFrom(w.sprite.position);
                w.lights.zIndex = w.sprite.zIndex + 0.5;
            }
            // The label stays whole on screen even when its walker is held at the edge.
            const half = w.label.width / 2 + 2;
            w.label.position.set(Math.round(clamp(x * layout.cssPx, half, layout.width - half)), Math.round((y - f.anchor.y + frameTop(f.canvas) - 1) * layout.cssPx));
        }
        toastBubbles(clinking.groups, now);
    }

    /*
     * Clinking glasses in the cursed world (clink.js; docs/spec/frontend.md, «Финал»): the groups of the current slot
     * by the server clock, each with its centre in art px where the walkers are drawn. A walker on the way walks on and
     * joins in once it stands, if the clink still runs; a shot is more important for a moment.
     */
    const clinkPlan = createClinks();
    let toastSpeech = null;
    const FOAM = ['white', 'white', 'cream', 'hazeLight'];
    // How far a walker leans toward the group's centre to reach the glasses (art px), and the glass's reach from the
    // anchor it leans by.
    const LEAN_MAX = 6;
    const GLASS_REACH = 10;

    function clinksNow(now) {
        const of = new Map();
        if (!inFinale() || finale.step < 5) return { of, groups: [] };
        const groups = clinkPlan.at(now, walkers.values()).map((g) => {
            const members = g.ids.map((id) => walkers.get(id)).filter((w) => w?.sprite && w.track);
            const points = members.map((w) => walkerArt(walkAt(w.track, clock).at));
            const centre = points.length ? { x: points.reduce((a, p) => a + p.x, 0) / points.length, y: Math.min(...points.map((p) => p.y)) } : null;
            return { ...g, members, points, centre };
        }).filter((g) => g.centre);
        for (const g of groups) {
            if (now - g.at < CLINK.end) g.members.forEach((w, i) => of.set(w.id, { group: g, index: i }));
        }
        return { of, groups };
    }

    // Facing the group's centre from the side (by the order in the group when straight above or below it); turning,
    // reaching out, the strike, back, then the glass raised for the toast. The lean toward the centre eases in with
    // the reach and out after the strike.
    function clinkView(w, { group, index }, at, now) {
        const t = now - group.at;
        const dx = group.centre.x - at.x;
        const flip = Math.abs(dx) >= 1 ? dx < 0 : index % 2 === 1;
        const item = w.item ?? null;
        const idle = poseOf('idle');
        const base = { dir: 'side', flip, item };
        const reach = clamp(Math.abs(dx) - GLASS_REACH, 0, LEAN_MAX) * (flip ? -1 : 1);
        const ease = (k) => (reduced ? (k > 0 ? 1 : 0) : clamp(k, 0, 1));
        let k = 0;
        let view;
        if (t < CLINK.reach) {
            view = { ...base, pose: 'idle', frame: Math.floor((now + w.seed) / idle.ms) % idle.frames };
        } else if (t < CLINK.back) {
            k = ease((t - CLINK.reach) / (CLINK.strike - CLINK.reach));
            view = { ...base, pose: 'clink', frame: t < CLINK.strike ? 0 : 1 };
        } else if (t < CLINK.raise) {
            k = 1 - ease((t - CLINK.back) / (CLINK.raise - CLINK.back));
            view = { ...base, pose: 'idle', frame: 0 };
        } else {
            const toast = poseOf('toast');
            view = { ...base, pose: 'toast', frame: Math.floor((t - CLINK.raise) / toast.ms) % toast.frames };
        }
        return { view, lean: Math.round(reach * k) };
    }

    // The strike: a splash of foam off the glass and a glint where the glasses meet, once per walker and clink.
    function clinkFoam(w, { group }, f, view, x, y, now) {
        if (view.pose !== 'clink' || now - group.at < CLINK.strike || !f.muzzle) return;
        // Once per clink; a clock that went back (a replayed lab run) splashes again.
        if (w.foam && w.foam.of === group.at && now >= w.foam.now) return;
        w.foam = { of: group.at, now };
        if (now - group.at > CLINK.back) return;
        const top = framePoint(f.muzzle, x, y, view.flip);
        const toward = view.flip ? -1 : 1;
        // The foam keeps its hue like the beer in the glass.
        const lit = lightAt(top.x, top.y);
        const foam = FOAM.map((key) => artTexture(`foam-px:${key}:${lit}`, () => {
            const c = canvas(1, 1);
            const ctx = c.getContext('2d');
            ctx.fillStyle = css(keepHueColor(PALETTE[key], lit));
            ctx.fillRect(0, 0, 1, 1);
            return texture(c);
        }));
        const glint = spawn([defTexture(SHOT_FX.pop)], top.x + toward * 2, top.y - 1, 0, 0, 0, 0.16);
        glint.sprite.pivot.set(2, 2);
        if (reduced) return;
        for (let i = 0; i < 6; i++) {
            const a = -Math.PI / 2 + toward * between(-0.2, 0.9);
            const v = between(18, 42);
            ember(top.x + toward, top.y, Math.cos(a) * v, Math.sin(a) * v, 70, between(0.35, 0.6), 0, FOAM).frames = foam;
        }
    }

    // The toast over the group's heads from CLINK.toast; a toast of two voices puts the line over the first walker and
    // the reply over the second, one after the other.
    function toastBubbles(groups, now) {
        if (!groups.length && !toastSpeech) return;
        toastSpeech ??= createBubbles({ labels: labelLayer, layout: () => layout });
        for (const g of groups) {
            const t = now - g.at;
            if (t < CLINK.toast || now >= g.until) continue;
            const [line, reply] = g.toast;
            const head = (p) => ({ x: p.x, y: p.y - 34 });
            if (!reply) toastSpeech.say('toast:' + g.key, line, head(g.centre));
            else if (t < CLINK.reply || g.points.length < 2) toastSpeech.say('toast:' + g.key, line, head(g.points[0]));
            else toastSpeech.say('reply:' + g.key, reply, head(g.points[1]));
        }
        toastSpeech.sweep();
    }

    /** An additive halo of a halo() sprite centred on an art point, over the walker. */
    function placeHalo(w, def, at, alpha) {
        w.halo.visible = true;
        w.halo.texture = defTexture(def);
        w.halo.alpha = alpha;
        w.halo.position.set(at.x - def.anchor.x, at.y - def.anchor.y);
        w.halo.zIndex = w.sprite.zIndex + 0.6;
    }

    // Embers: 1-px light that cools from white-hot to red as it lives, a step cooler on some frames — a flicker.
    const emberTextures = (colors = EMBER_COLORS) => colors.map((key) => artTexture(`ember-px:${key}`, () => {
        const c = canvas(1, 1);
        const ctx = c.getContext('2d');
        ctx.fillStyle = css(PALETTE[key]);
        ctx.fillRect(0, 0, 1, 1);
        return texture(c);
    }));
    function emberAnim(p) {
        const t = p.age / p.life;
        const flick = Math.floor(p.age * 24 + p.phase) % 4 === 0 ? 1 : 0;
        p.sprite.visible = t < 0.75 || Math.floor(p.age * 20) % 2 === 0;
        return p.frames[Math.min(p.frames.length - 1, Math.floor(p.heat + t * (p.frames.length - 1)) + flick)];
    }
    function ember(x, y, vx, vy, gravity, life, heat, colors = EMBER_COLORS) {
        const frames = emberTextures(colors);
        const p = spawn([frames[0]], x, y, vx, vy, gravity, life);
        Object.assign(p, { frames, heat, phase: Math.random() * 4, anim: emberAnim });
        return p;
    }

    /**
     * Smouldering bits off a walking candle: one every EMBER_MS, drifting up and back from the flame; standing, one
     * now and then. At most EMBER_CAP alive per walker; none with reduced motion.
     */
    function candleTrail(w, flame, moving, view) {
        if (reduced) return;
        const [min, max] = moving ? EMBER_MS : EMBER_IDLE_MS;
        // Setting off cuts the long wait of a standing candle short.
        w.emberAt = Math.min(w.emberAt ?? clock + between(min, max), clock + max);
        if (clock < w.emberAt) return;
        w.emberAt = clock + between(min, max);
        if ((w.embers ?? 0) >= EMBER_CAP) return;
        const back = moving && view.dir === 'side' ? (view.flip ? 1 : -1) : 0;
        w.embers = (w.embers ?? 0) + 1;
        const p = ember(flame.x + Math.round(between(-1, 1)), flame.y - 2, back * between(5, 12) + between(-3, 3), -between(6, 14), -4, between(0.5, 0.95), Math.random() * 1.5);
        p.drag = 0.6;
        p.done = () => w.embers--;
    }

    /**
     * The shot's effect by the item in hand (docs/spec/frontend.md, «Выстрел»), from the item's point toward where the
     * target was at the shot: the pistol fires, the candle (and a torch) throws a fireball, the spell book strikes with
     * green lightning, the red flame shoots a red spark, the beer mug burps bubbles. Anything else — an empty hand
     * pointing, a broom — has no effect.
     */
    function shotFx(w, view, x, y) {
        const item = w.shoot.item;
        if (item === 'pistol') {
            gunFx(w, view, x, y);
            return;
        }
        const t = clock - w.shoot.at;
        const from = framePoint(characterFrame(w.look, { ...view, frame: 0 }).muzzle, x, y, view.flip);
        const to = w.shoot.target ?? { x: from.x + (view.flip ? -60 : 60), y: from.y - 60 };
        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const len = Math.hypot(dx, dy) || 1;
        const dark = lighting !== 'day';
        if (item === 'candle' || item === 'torch') {
            // A flare off the flame toward the target, then the fireball.
            const k = FLASH_MS.findIndex((ms) => t < ms);
            if (k >= 0) {
                const a = Math.round((Math.atan2(dy, dx) / Math.PI) * 8) * (Math.PI / 8);
                showFlash(w, SHOT_FX.flash([Math.round(Math.cos(a) * 8), Math.round(Math.sin(a) * 8)])[k], from);
                if (dark && k < 2) placeHalo(w, SHOT_AIR, from, SHOT_AIR_ALPHA[k]);
            }
            if (!w.shoot.fired && t >= 40) {
                w.shoot.fired = 1;
                fireball(from, to, len);
            }
        } else if (item === 'book') {
            if (t < 120) {
                showFlash(w, SHOT_FX.spark, from);
                if (dark) placeHalo(w, BOOK_FLASH, from, t < 60 ? 0.8 : 0.45);
            }
            if (!w.shoot.fired) {
                w.shoot.fired = 1;
                bolt(from, to);
            }
        } else if (item === 'red-flame') {
            // A red flare off the flame toward the target, then the spark.
            const k = FLASH_MS.findIndex((ms) => t < ms);
            if (k >= 0) {
                const a = Math.round((Math.atan2(dy, dx) / Math.PI) * 8) * (Math.PI / 8);
                showFlash(w, SHOT_FX.redFlash([Math.round(Math.cos(a) * 8), Math.round(Math.sin(a) * 8)])[k], from);
                if (dark && k < 2) placeHalo(w, RED_HALO, from, SHOT_AIR_ALPHA[k]);
            }
            if (!w.shoot.fired && t >= 30) {
                w.shoot.fired = 1;
                redSpark(from, to, len);
            }
        } else if (item === 'beer') {
            const bubbles = reduced ? BUBBLES.slice(0, 1) : BUBBLES;
            while (w.shoot.fired < bubbles.length && t >= bubbles[w.shoot.fired]) {
                w.shoot.fired++;
                bubble(from, to, len);
            }
        }
    }

    function showFlash(w, def, at) {
        w.flash.visible = true;
        w.flash.texture = defTexture(def);
        w.flash.position.set(at.x - def.anchor.x, at.y - def.anchor.y);
        w.flash.zIndex = w.sprite.zIndex + 0.7;
    }

    // A ball of the candle's fire flying at the target with embers trailing off it; it bursts into embers where it
    // ends. With reduced motion only the flare at the candle stays.
    function fireball(from, to, len) {
        if (reduced) return;
        const speed = 520;
        const frames = SHOT_FX.fireball.map((def) => defTexture(def));
        const p = spawn([frames[0]], from.x, from.y, ((to.x - from.x) / len) * speed, ((to.y - from.y) / len) * speed, 0, Math.min(1.2, len / speed));
        p.sprite.pivot.set(3, 3);
        p.anim = (q) => frames[Math.floor(q.age * 20) % frames.length];
        let trail = 0;
        p.tick = (q) => {
            if (q.age < trail) return;
            trail = q.age + 0.012;
            ember(Math.round(q.x - q.vx * 0.012), Math.round(q.y - q.vy * 0.012), between(-6, 6), between(-10, 2), -8, between(0.25, 0.45), between(0.5, 1.5));
        };
        p.done = () => {
            for (let i = 0; i < 5; i++) {
                const a = (i / 5) * Math.PI * 2 + between(0, 1);
                ember(Math.round(p.x), Math.round(p.y), Math.cos(a) * between(20, 40), Math.sin(a) * between(20, 40) - 10, 40, between(0.3, 0.5), 0);
            }
        };
    }

    // The red flame's spark: a star of the circle's light streaking at the target, a dense wake of red embers behind it
    // that reads as a streak; it bursts into embers where it ends. With reduced motion only the flare stays.
    function redSpark(from, to, len) {
        if (reduced) return;
        const frames = SHOT_FX.redSpark.map((def) => defTexture(def));
        const p = spawn([frames[0]], from.x, from.y, ((to.x - from.x) / len) * RED_SPARK_SPEED, ((to.y - from.y) / len) * RED_SPARK_SPEED, 0, Math.min(1, len / RED_SPARK_SPEED));
        p.sprite.pivot.set(2, 2);
        p.anim = (q) => frames[Math.floor(q.age * 25) % frames.length];
        let trail = 0;
        p.tick = (q) => {
            if (q.age < trail) return;
            trail = q.age + 0.008;
            ember(Math.round(q.x - q.vx * 0.008), Math.round(q.y - q.vy * 0.008), between(-3, 3), between(-4, 4), 0, between(0.15, 0.3), between(0.5, 1.5), RED_EMBERS);
        };
        p.done = () => {
            for (let i = 0; i < 6; i++) {
                const a = (i / 6) * Math.PI * 2 + between(0, 0.8);
                ember(Math.round(p.x), Math.round(p.y), Math.cos(a) * between(25, 45), Math.sin(a) * between(25, 45) - 8, 30, between(0.3, 0.5), 0, RED_EMBERS);
            }
        };
    }

    /**
     * Green lightning from the book to the target: three jagged variants of the bolt, 50 ms each, then a thinner one as
     * it dies; a few motes of magic where it strikes. Art pixels drawn into a Graphics that lives as a particle.
     */
    function bolt(from, to) {
        const paths = [0, 1, 2].map(() => boltPixels(from, to));
        const g = new Graphics();
        particleLayer.addChild(g);
        let shown = -1;
        const tick = (q) => {
            const k = reduced ? 0 : Math.min(3, Math.floor(q.age / 0.05));
            if (k === shown) return;
            shown = k;
            g.clear();
            const { core, glow } = paths[Math.min(2, k)];
            if (k < 3) {
                for (const [x, y] of glow) g.rect(x, y, 1, 1);
                g.fill(PALETTE.eyeGreen);
            }
            core.forEach(([x, y], i) => (k < 3 || i % 2 === 0) && g.rect(x, y, 1, 1));
            g.fill(k < 3 ? PALETTE.magicCore : PALETTE.eerieHot);
        };
        particles.push({ sprite: g, x: 0, y: 0, vx: 0, vy: 0, gravity: 0, life: reduced ? 0.15 : BOLT_MS / 1000, age: 0, tick });
        if (!reduced) for (let i = 0; i < 4; i++) spawn('magic', to.x, to.y, sign() * between(6, 22), -between(10, 40), -10, between(0.5, 0.9));
    }

    // Pixels of one bolt: a polyline jittered across its way every ~7 px plus a short fork, and glow beside the core.
    function boltPixels(from, to) {
        const dx = to.x - from.x;
        const dy = to.y - from.y;
        const len = Math.hypot(dx, dy) || 1;
        const n = Math.max(3, Math.round(len / 7));
        const jag = Math.min(4, len * 0.08);
        const pts = Array.from({ length: n + 1 }, (_, i) => {
            const t = i / n;
            const off = i === 0 || i === n ? 0 : between(-jag, jag);
            return [Math.round(from.x + dx * t - (dy / len) * off), Math.round(from.y + dy * t + (dx / len) * off)];
        });
        const core = new Map();
        const line = ([x0, y0], [x1, y1]) => {
            const steps = Math.max(Math.abs(x1 - x0), Math.abs(y1 - y0), 1);
            for (let i = 0; i <= steps; i++) {
                const x = Math.round(x0 + ((x1 - x0) * i) / steps);
                const y = Math.round(y0 + ((y1 - y0) * i) / steps);
                core.set(x * 8192 + y, [x, y]);
            }
        };
        pts.slice(1).forEach((p, i) => line(pts[i], p));
        const at = pts[1 + Math.floor(Math.random() * Math.max(1, n - 2))];
        const a = Math.atan2(dy, dx) + sign() * between(0.5, 1);
        const fork = between(4, 9);
        line(at, [Math.round(at[0] + Math.cos(a) * fork), Math.round(at[1] + Math.sin(a) * fork)]);
        const glow = [];
        for (const [x, y] of core.values()) {
            for (const [gx, gy] of [[x + 1, y], [x, y + 1]]) {
                if (!core.has(gx * 8192 + gy) && (gx + gy) % 2 === 0) glow.push([gx, gy]);
            }
        }
        return { core: [...core.values()], glow };
    }

    // A burp bubble: floats from the mug at the target, wobbling, slower than a shot, and pops where it ends. Drawn as
    // is — a shiny film catches whatever light there is, and a relit one would vanish at night; with reduced motion one
    // pop at the mug.
    function bubble(from, to, len) {
        const pop = defTexture(SHOT_FX.pop);
        if (reduced) {
            spawn([pop], from.x - 2, from.y - 4, 0, 0, 0, 0.2);
            return;
        }
        const def = SHOT_FX.bubbles[Math.random() < 0.5 ? 0 : 1];
        const speed = between(70, 110);
        const a = Math.atan2(to.y - from.y, to.x - from.x) + between(-0.25, 0.25);
        const p = spawn([defTexture(def)], from.x, from.y - 1, Math.cos(a) * speed, Math.sin(a) * speed, 0, Math.min(1.6, len / speed) * between(0.6, 1));
        p.sprite.pivot.set(def.anchor.x, def.anchor.y);
        const phase = Math.random() * 6;
        p.tick = (q) => {
            q.y += Math.sin(q.age * 12 + phase) * 0.35;
        };
        p.anim = (q) => q.sprite.texture;
        p.done = () => {
            const q = spawn([pop], Math.round(p.x), Math.round(p.y), 0, 0, 0, 0.12);
            q.sprite.pivot.set(2, 2);
        };
    }

    /**
     * The pistol's effects: the muzzle flash in three frames along the barrel, sparks, a warm light on the ground and
     * in the air after dark, the recoil kick, and smoke puffs — first blown out of the muzzle, then wisps off the
     * raised barrel — that rise, drift, grow and thin out.
     */
    function gunFx(w, view, x, y) {
        const t = clock - w.shoot.at;
        const flip = view.flip;
        const fire = characterFrame(w.look, { ...view, frame: 0 });
        const muzzle = framePoint(fire.muzzle, x, y, flip);
        const barrel = [flip ? -fire.barrel[0] : fire.barrel[0], fire.barrel[1]];
        const len = Math.hypot(barrel[0], barrel[1]);
        const [ux, uy] = [barrel[0] / len, barrel[1] / len];
        const k = FLASH_MS.findIndex((ms) => t < ms);
        if (k >= 0) {
            showFlash(w, SHOT_FX.flash(barrel)[k], muzzle);
            if (lighting !== 'day') {
                w.glow.visible = true;
                w.glow.texture = defTexture(SHOT_GROUND);
                w.glow.alpha = SHOT_GROUND_ALPHA[k];
                w.glow.position.set(Math.round((x + muzzle.x) / 2), y);
                if (k < 2) placeHalo(w, SHOT_AIR, muzzle, SHOT_AIR_ALPHA[k]);
            }
        }
        // The kick: the whole figure jolts one art pixel back from the shot as the recoil frame comes in.
        if (t >= RECOIL_MS && t < KICK_MS) w.sprite.x -= Math.sign(ux) || 0;
        if (!w.shoot.sparked) {
            w.shoot.sparked = true;
            if (!reduced) {
                for (let i = 0; i < 4; i++) {
                    const a = Math.atan2(uy, ux) + between(-0.5, 0.5);
                    const v = between(50, 95);
                    const p = ember(muzzle.x + Math.round(ux * 3), muzzle.y + Math.round(uy * 3), Math.cos(a) * v, Math.sin(a) * v, 90, between(0.2, 0.35), 0);
                    p.drag = 2;
                }
            }
        }
        const puffs = reduced ? PUFFS.slice(0, 1) : PUFFS;
        while ((w.shoot.puffs ?? 0) < puffs.length && t >= puffs[w.shoot.puffs ?? 0][0]) {
            const [, raised] = puffs[w.shoot.puffs ?? 0];
            w.shoot.puffs = (w.shoot.puffs ?? 0) + 1;
            const from = raised ? framePoint(characterFrame(w.look, { ...view, frame: 1 }).muzzle, x, y, flip) : muzzle;
            puff(from, [ux, uy], !raised);
        }
    }

    // One smoke puff in the light where it starts: it grows to its size over the first part of its life, then thins
    // out in Bayer steps; a blown one flies out along the barrel and slows down, every one rises and drifts.
    function puff(at, [ux, uy], blown) {
        const lit = lightAt(at.x, at.y);
        const size = blown ? 2 + Math.floor(Math.random() * 2) : 1;
        const variant = Math.random() < 0.5 ? 0 : 1;
        const first = SHOT_FX.puffs[0][0][variant];
        let p;
        if (reduced) {
            p = spawn([defTexture(first, lit)], at.x, at.y - 1, 0, 0, 0, 0.9);
        } else if (blown) {
            const v = between(10, 18);
            p = spawn([defTexture(first, lit)], at.x + ux * 2, at.y + uy * 2, ux * v + between(-3, 3), uy * v - between(7, 11), -14, between(2, 3));
            p.drag = 1;
        } else {
            p = spawn([defTexture(first, lit)], at.x, at.y - 1, ux * 3 + between(-2, 2), -between(4, 8), -12, between(1.5, 2.4));
            p.drag = 0.9;
        }
        p.wind = reduced ? 0 : 1.5;
        p.anim = (q) => {
            const t = q.age / q.life;
            const grown = Math.min(size, (blown ? 1 : 0) + Math.floor(Math.min(1, t / 0.6) * size));
            const fade = t < 0.35 ? 0 : Math.min(3, 1 + Math.floor(((t - 0.35) / 0.65) * 3));
            const def = SHOT_FX.puffs[reduced ? 1 : grown][fade][variant];
            q.sprite.pivot.set(def.anchor.x, def.anchor.y);
            return defTexture(def, lit);
        };
    }

    // A walker whose player shot a flyer turns toward it and shoots with what it holds; one already walking does not
    // stop for it.
    function walkerShot(event) {
        const w = walkers.get(event.player?.id);
        if (!w || !w.sprite) return;
        if (walkAt(w.track, clock).moving) return;
        const f = flyers.get(event.flyer);
        const me = walkerArt(walkAt(w.track, clock).at);
        const dx = f ? f.pos.x - me.x : 0;
        // The sky is beyond the patch: a bird straight ahead means turning away from the camera.
        const [dir, flip] = Math.abs(dx) > 24 ? ['side', dx < 0] : ['back', false];
        const item = w.item ?? null;
        const until = clock + (item === 'pistol' ? SHOT_MS : ITEM_SHOT_MS);
        w.shoot = { dir, flip, item, target: f ? { ...f.pos } : null, at: clock, until, sparked: false, puffs: 0, fired: 0 };
    }

    /** Config coordinates of a screen point on the ground (inverse patch spread); null over the sky. */
    function groundAt(clientX, clientY) {
        const rect = app.canvas.getBoundingClientRect();
        const x = clientX - rect.left;
        const y = clientY - rect.top;
        if (x < 0 || y < 0 || x > rect.width || y > rect.height || y / layout.cssPx < layout.horizon + 2) return null;
        const left = (x / rect.width) * 100;
        return {
            x: Math.round((ROOT.x + (left - ROOT.x) / spreadX(layout.width)) * 10) / 10,
            y: Math.round((y / rect.height) * 1000) / 10,
        };
    }

    function tick(ticker) {
        const dt = Math.min(ticker.deltaMS, 50);
        clock += dt;
        const seconds = dt / 1000;
        // The moon turns normal under the third strike of finale step 2: a cut, the lightning's flash covers it.
        if (inFinale() && lightingOf(state.scene) !== lighting) {
            rebuild();
            return;
        }
        if (darkPumpkins() !== darkShown) pumpkins.forEach(applyArt);

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
        animateBonfire();
        animateSky();
        animateBlast();
        animateBurn();
        // A new round: the patch dims for a moment, then a short white flash. Stepped alpha, no smooth fade.
        const since = clock - veilStart;
        veil.visible = since < veilFrames.length * 60;
        if (veil.visible) {
            const [color, alpha] = veilFrames[Math.floor(since / 60)];
            veil.tint = PALETTE[color];
            veil.alpha = alpha;
        }

        for (const bug of bugs) {
            // Butterflies are a daytime thing.
            bug.sprite.visible = !reduced && lighting === 'day';
            const t = clock / 1000 + bug.phase;
            const x = bug.x + Math.sin(t * 0.31) * 16 + Math.sin(t * 0.9) * 3;
            const y = bug.y + Math.sin(t * 0.47) * 5 - Math.abs(Math.sin(t * 2.3)) * 2;
            bug.sprite.texture = Math.floor(clock / 160 + bug.phase) % 2 ? bug.open : bug.closed;
            bug.sprite.position.set(Math.round(x), Math.round(y));
        }

        animateFlyers();
        animateWalkers();
        animateOwl();
        stage.animate(inFinale() ? finale.step : null, sinceStep());
        drawHits();

        for (let i = particles.length - 1; i >= 0; i--) {
            const particle = particles[i];
            particle.age += seconds;
            if (particle.age >= particle.life) {
                particle.sprite.destroy();
                particles.splice(i, 1);
                particle.done?.();
                continue;
            }
            particle.vy += particle.gravity * seconds;
            if (particle.wind) particle.vx += particle.wind * seconds;
            if (particle.drag) {
                const k = Math.max(0, 1 - particle.drag * seconds);
                particle.vx *= k;
                particle.vy *= k;
            }
            particle.x += particle.vx * seconds;
            particle.y += particle.vy * seconds;
            particle.tick?.(particle);
            particle.sprite.position.set(Math.round(particle.x), Math.round(particle.y));
            if (particle.anim) {
                particle.sprite.texture = particle.anim(particle);
                continue;
            }
            // Blink out instead of fading: alpha blending would add colors outside the palette.
            particle.sprite.visible = particle.age < particle.life * 0.7 || Math.floor(particle.age * 20) % 2 === 0;
        }

        app.renderer.render({ container: world, target, clear: true });
    }

    // The sunset sun sinking behind the hills, the bigger stars twinkling, the frozen old lighting fading out.
    function animateSky() {
        if (sun) {
            const t = reduced ? 1 : clamp((clock - sunSetAt) / SUNSET_MS, 0, 1);
            sun.sprite.position.set(sun.x, Math.round(sun.high + (sun.rest - sun.high) * (1 - (1 - t) * (1 - t))));
        }
        for (const star of twinkles) {
            const beat = reduced ? 0 : Math.floor(clock / 400) + star.phase;
            star.sprite.texture = defTexture(NIGHT.stars[beat % 4 < 2 ? 2 : 3]);
            star.sprite.visible = beat % 7 !== 0;
        }
        if (fade) {
            const t = (clock - fade.at) / fade.ms;
            if (t >= 1) endFade();
            // Stepped alpha, like the round's veil: no smooth blend across the whole screen.
            else fadeDisplay.alpha = 1 - Math.floor(t * FADE_STEPS) / FADE_STEPS;
        }
    }

    // The explosion's frames over the pit and the screen shake under it.
    function animateBlast() {
        const since = clock - blastAt;
        let t = since;
        let frame = -1;
        for (let i = 0; i < BLAST_MS.length && t >= 0; i++) {
            if (t < BLAST_MS[i]) frame = i;
            t -= BLAST_MS[i];
        }
        blastSprite.visible = frame >= 0 && !reduced;
        if (blastSprite.visible) {
            const def = EXPLOSION[frame];
            blastSprite.texture = defTexture(def);
            blastSprite.anchor.set(def.anchor.x / def.w, def.anchor.y / def.h);
        }
        // Whole art pixels, stepped every 40 ms and calming down.
        const shaking = !reduced && since >= 0 && since < SHAKE_MS;
        // The ground of finale step 1 rumbles in the same whole-pixel steps.
        const rumble = reduced || !inFinale() ? 0 : rumbleAt(finale.step, sinceStep());
        const k = shaking ? Math.ceil(3 * (1 - since / SHAKE_MS)) : rumble;
        const beat = Math.floor((shaking ? since : clock) / 40);
        display.position.set(k ? ((beat * 7) % 3 - 1) * k * layout.cssPx : 0, k ? ((beat * 5) % 3 - 1) * k * layout.cssPx : 0);
    }

    // A lightning strike flashes the whole screen white-blue for a moment.
    const STRIKE_VEIL = [['lightningCore', 0.45], ['lightning', 0.25], ['lightning', 0.1]];
    const stage = createFinaleStage({
        ground: groundLayer,
        light: lightLayer,
        far: farFlyerLayer,
        fx: fxLayer,
        labels: labelLayer,
        layout: () => layout,
        lighting: () => lighting,
        centre,
        clock: () => clock,
        serverNow: () => serverNow(),
        reduced: () => reduced,
        defTexture,
        flyerArt,
        depthKey,
        strike: () => {
            veilStart = clock;
            veilFrames = STRIKE_VEIL;
        },
    });

    /** Takes the finale's step and the circle's state as they are, without animating into them. */
    function settleFinale(step, ready) {
        finale.step = step;
        finale.ready = Boolean(ready);
        finale.at = -1e9;
        finale.pullAt = -1e9;
        owlFlight = { mode: step !== null && step >= 5 ? 'away' : 'perched', at: 0, leave: false };
    }

    settleFinale(snapshot.finale?.step ?? null, snapshot.ready);
    for (const w of snapshot.walkers ?? []) setWalker(w);
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
            // Flyers live seconds and are not in the snapshot: a full render (state, reset) starts without them.
            for (const f of flyers.values()) dropFlyer(f);
            flyers.clear();
            if (!reduced && next.round !== undefined && state.round !== undefined && next.round !== state.round) {
                veilStart = clock;
                veilFrames = VEIL;
            }
            // A resync or reset jumps straight to the state: no hop, no blast, no fade.
            endFade();
            jumpAt = -1e9;
            blastAt = -1e9;
            sunSetAt = -1e9;
            state = {
                round: next.round ?? state.round,
                scene: next.scene ?? 0,
                sceneStages: next.sceneStages ?? state.sceneStages,
                stageMax: next.stageMax ?? state.stageMax,
                threshold: next.threshold ?? state.threshold,
                elements: next.elements ?? [],
                bushes: next.bushes ?? state.bushes,
            };
            settleFinale(state.scene >= state.sceneStages ? next.finale?.step ?? null : null, next.ready);
            // Walkers outlive rounds (players.md, «На поле»): the snapshot's list replaces ours as a whole.
            if (next.walkers) {
                const drawn = new Map([...walkers.values()].map((w) => [w.id, w.drawn]));
                for (const w of walkers.values()) dropWalkerSprites(w);
                walkers.clear();
                for (const data of next.walkers) {
                    // One already on screen walks on from where it is drawn instead of jumping to the snapshot.
                    const w = { shoot: null, ...data, seed: seedOf(data.id), drawn: drawn.get(data.id) ?? null };
                    retrack(w, w.walk);
                    walkers.set(w.id, w);
                }
            }
            rebuild();
        },

        click(id, { stage, clicks, threshold }) {
            const p = pumpkins.get(id);
            if (onPit(id)) {
                if (reduced) return;
                bonfire.hit = clock;
                bonfireBurst(3);
                return;
            }
            if (bonfire && id === bonfire.id) {
                if (stage !== undefined) bonfire.stage = stage;
                if (reduced) return;
                // Shake and a puff of embers; the pit only shakes.
                bonfire.hit = clock;
                bonfireBurst(3);
                return;
            }
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
            // The state keeps up too: a resize rebuilds from it.
            const element = state.elements.find((e) => e.id === id);
            if (element) element.stage = stage;
            if (onPit(id)) {
                if (!reduced) bonfireBurst(10);
                return;
            }
            if (bonfire && id === bonfire.id) {
                const lit = bonfire.stage < 2 && stage >= 2;
                bonfire.stage = stage;
                bonfire.flare = clock;
                // The first embers at night bring the firelight's reach in with them.
                if (lit && lighting === 'night') rebuild();
                if (!reduced) bonfireBurst(10);
                return;
            }
            const p = pumpkins.get(id);
            if (!p) return;
            p.stage = stage;
            p.clicks = 0;
            applyArt(p);
            // The last pumpkin lit in scene 1: the whole patch jumps out.
            if (!out && jumpedOut(state.scene, state.stageMax, pumpkinElements())) {
                out = true;
                jumpAt = clock;
                pumpkins.forEach(applyArt);
            }
            if (reduced) return;
            p.flash?.sprite.destroy();
            const sprite = new Sprite(p.art.flash);
            sprite.position.set(p.pos.x - p.art.def.anchor.x, p.pos.y - p.lift - p.art.def.anchor.y);
            flashLayer.addChild(sprite);
            p.flash = { sprite, born: clock };
            p.bounce = clock + 70;
        },

        /** Channel event `flyer`: the flight runs from now for `duration` ms. */
        flyer(event) {
            launch(event);
        },

        /** Channel event `shot`: hit frame, tumble, feathers and points; unknown or gone flyers are ignored. */
        shot(event) {
            walkerShot(event);
            down(event);
        },

        /** Channel event `walker`: a character entered the field or changed its look or name. */
        walker(w) {
            setWalker(w);
        },

        /** Channel event `move`: the walker follows the new path on the server clock. */
        move(id, walk) {
            const w = walkers.get(id);
            if (!w) return;
            w.walk = walk;
            w.shoot = null;
            retrack(w, walk);
        },

        /** Channel event `leave`: the player has no connections left. */
        leave(id) {
            const w = walkers.get(id);
            if (!w) return;
            dropWalkerSprites(w);
            walkers.delete(id);
        },

        /** Marks this browser's own character: its name is drawn in another colour. */
        setMe(id) {
            if (id === meId) return;
            meId = id ?? null;
            for (const w of walkers.values()) {
                if (!w.sprite) continue;
                w.label?.destroy();
                w.label = walkerLabel(w);
            }
        },

        /** Config coordinates (percent, inverse patch spread) of the ground under a screen point, or null. */
        groundAt(clientX, clientY) {
            return groundAt(clientX, clientY);
        },

        flyerAt(clientX, clientY) {
            return flyerAt(clientX, clientY);
        },

        /** Sets the server-time source flights are measured against. */
        setServerClock(fn) {
            serverNow = fn;
        },

        /** Where walker `id` is drawn, config coordinates (null if none); a development aid for public/dev/lab.html. */
        walkerAt(id) {
            const p = walkers.get(id)?.drawn;
            return p ? { ...p } : null;
        },

        /** Outlines the flyers' hit boxes; a development aid for public/dev/lab.html. */
        showHits(on) {
            showHits = Boolean(on);
        },

        /**
         * Channel event `scene`: relights the world (the old lighting fades out over a few seconds, the sun sinks into
         * the sunset), lets the pumpkins jump out from scene 2 on, and blows the bonfire up on the way into the final
         * scene. The bonfire's lock lives in the DOM (client.js); the scene only draws its stage.
         */
        setScene(n, stageMax) {
            const before = lighting;
            const wasOut = out;
            const blast = !reduced && n >= state.sceneStages && state.scene < state.sceneStages && (bonfire?.stage ?? 0) > 0;
            state.scene = n;
            state.stageMax = stageMax ?? state.stageMax;
            if (blast) blastAt = clock;
            if (lightingOf(n) !== before || hasBonfire() !== Boolean(bonfire)) {
                // The blast's flash hides the cut; otherwise the old lighting fades out over the new one.
                if (!reduced && !blast && before !== lightingOf(n)) startFade(before === 'day' ? SUNSET_MS : FADE_MS);
                if (before === 'day') sunSetAt = clock;
                rebuild();
            } else {
                pumpkins.forEach(applyArt);
            }
            if (!wasOut && out) jumpAt = clock;
            if (blast) startBlast();
        },

        /**
         * The red magic circle on the ash pit of the final scene, drawn up to `progress` 0–1 (bonfire.js, magicCircle).
         * Its mechanics are not settled yet (docs/spec/scenario.md): nothing calls it, and 0 draws nothing.
         */
        circle(progress) {
            circleProgress = clamp(Number(progress) || 0, 0, 1);
        },

        /**
         * Point on the tree branch where the owl sits, in CSS pixels of the scene root, plus `px` (CSS pixels per art
         * pixel) to measure the 18-art-px owl up from it; follows resizes.
         */
        perch() {
            // Once the owl has flown away, its lines (the moderator's free text) hang over FrankenPHP's head; the
            // bubble measures the owl's height up from the point, so it goes that much below the head.
            if (owlGone()) {
                const c = centre();
                return { x: c.x * layout.cssPx, y: (c.y - FRANKEN_HEAD + OWL_H) * layout.cssPx, px: layout.cssPx };
            }
            return { ...perch, px: layout.cssPx };
        },

        /**
         * The owl says a line for `ms`: beak and wing; a dramatic line (`hoot`) starts puffed up. Returns the ms
         * until it starts. After the owl has flown away (finale step 5) the line is just shown over FrankenPHP.
         */
        owl({ ms, hoot = false }) {
            if (!owlGone()) owlTalk = { at: clock, until: clock + ms, hoot };
            return 0;
        },

        /**
         * The finale's step (null outside the final scene) and whether the circle is closed (`ready`). The next step
         * plays its animation from now; any other change — a reload, a resync, reduced motion — shows the step settled.
         */
        setFinale({ step = null, ready = false } = {}) {
            const prev = finale.step;
            finale.ready = Boolean(ready);
            if (step === prev) return;
            if (reduced || prev === null || step !== prev + 1) settleFinale(step, ready);
            else {
                finale.step = step;
                finale.at = clock;
                if (step === 1) finale.pullAt = clock;
                // The owl leaves the branch once its last line is over.
                if (step === 5) owlFlight = { mode: 'perched', at: clock, leave: true };
            }
            if (lightingOf(state.scene) !== lighting) rebuild();
            else pumpkins.forEach(applyArt);
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
