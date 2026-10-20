import { Container, Graphics, Sprite, Text } from 'https://cdn.jsdelivr.net/npm/pixi.js@8.22.0/dist/pixi.min.mjs';
import { phraseAt } from '../franken.js';
import { FINALE_EMISSIVE, FRANKEN, FX, RUNNER, SILHOUETTE, frankenHalo } from './finale.js';
import { EMISSIVE, LIT_PALETTE } from './night.js';
import { PALETTE } from './palette.js';

/**
 * The finale's steps on the scene (docs/spec/scenario.md, «Финал»; docs/spec/frontend.md, «Финал»): FrankenPHP and
 * the silhouette on the circle, the column, smoke and lightning, RoadRunner coming in and leaving, and the speech
 * bubbles of RoadRunner and FrankenPHP. Everything is a function of the step and of `t`, the ms since the step began
 * on the scene clock: a reload sets `t` far in the past, so the same functions draw the settled end of the step.
 * The pumpkins, the walkers, the owl and the lighting stay in scene.js; they read T from here.
 */

/** Timeline of the steps, ms since the step's event. */
export const T = {
    // Step 1: the circle flashes, the column lights up, smoke rolls, the silhouette rises; the ground rumbles while the
    // pumpkins are pulled into a round dance.
    flash: 90,
    column: 180,
    cloud: 300,
    rise: 600,
    riseFrame: 400,
    rumble: 2200,
    pull: 1500,
    // Step 2: three strikes; the third cuts to the night under its flash, then the smoke clears off FrankenPHP and the
    // pumpkins' lights go out as they drop.
    strikes: [0, 380, 760],
    strikeFrame: 60,
    night: 780,
    reveal: 900,
    revealFrame: 150,
    fling: 700,
    // Step 3: on a diagonal from the screen edge at the horizon down to the front, a beat of standing, «мип-мип».
    run: 4600,
    meep: 5000,
    meepMs: 1800,
    // Step 5: the pumpkins rise and light up; RoadRunner runs back up the diagonal and off the edge; FrankenPHP keeps
    // quiet while the owl says its last line and flies away.
    rise5: 1000,
    leave: 1200,
    back: 4400,
    quiet: 14000,
};

// RoadRunner's evening run along the horizon in the cursed world: a run of RUN_MS in every slot of LOOP_MS.
const LOOP_MS = 15000;
const RUN_MS = 6500;

// FrankenPHP's lime body keeps half its colour at night: the logo has to read under the moon. RoadRunner, the other
// hero, keeps half of all its colours but the outline.
const LIME = new Set(['frankenLight', 'franken', 'frankenDark', 'frankenDeep']);
const UNLIT = new Set([...EMISSIVE, ...FINALE_EMISSIVE]);

function mixColor(a, b, k) {
    return [16, 8, 0].reduce((acc, sh) => acc | (Math.round(((a >> sh) & 255) * (1 - k) + ((b >> sh) & 255) * k) << sh), 0);
}

/**
 * A bake for a hero of the finale: light keys unlit (sparks, the red rim), the keys `half` picks halfway between day
 * and the lighting, the rest lit like everything standing on the ground.
 */
function heroBake(half) {
    const bakes = new Map();
    return (def, lighting = 'day') => {
        if (!bakes.has(lighting)) bakes.set(lighting, new WeakMap());
        const cache = bakes.get(lighting);
        if (!cache.has(def)) cache.set(def, paintHero(def, lighting, half));
        return cache.get(def);
    };
}

function paintHero(def, lighting, half) {
    const c = document.createElement('canvas');
    c.width = def.w;
    c.height = def.h;
    const ctx = c.getContext('2d');
    const img = ctx.createImageData(def.w, def.h);
    const lit = LIT_PALETTE[lighting] ?? PALETTE;
    for (let y = 0; y < def.h; y++) {
        for (let x = 0; x < def.w; x++) {
            const key = def.legend[def.rows[y][x]];
            if (!key) continue;
            const color = UNLIT.has(key) || lighting === 'day' ? PALETTE[key] : half(key) ? mixColor(PALETTE[key], lit[key], 0.5) : lit[key];
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

/** FrankenPHP and the silhouette (the lime of the logo half-lit). */
export const frankenBake = heroBake((key) => LIME.has(key));
/** RoadRunner up close (everything but the outline half-lit). */
export const runnerBake = heroBake((key) => key !== 'outline');

const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
const lerp = (a, b, k) => a + (b - a) * k;

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

/** Shake of the ground in whole art pixels at `t` into the step: strong at first, then a rumble. */
export function rumbleAt(step, t) {
    if (step !== 1 || t < 0 || t >= T.rumble) return 0;
    return t < 500 ? 2 : 1;
}

/** Whether the scene is lit by the night again: the moon turns normal with the reveal of step 2. */
export const nightOf = (step, t) => step >= 3 || (step === 2 && t >= T.night);

/** Whether the floating pumpkins' lights are out: they drop with the reveal and light up green at step 5. */
export const lightsOut = (step, t) => (step === 2 && t >= T.reveal) || step === 3 || step === 4;

/** Whether the walkers stand in the ritual pose: from the closed circle until the lightning. */
export const ritualOf = (step, ready, t) => (step === 0 && ready) || step === 1 || (step === 2 && t < T.reveal);

/**
 * @param {object} env  scene internals: layers (ground, light, far, fx, labels), layout(), lighting(), centre() — the
 *   circle's foot in art px, clock(), serverNow(), reduced(), defTexture(def, lit), flyerArt(def, hazy), depthKey(x, y),
 *   strike() — a lightning strike begins (the scene flashes its veil)
 */
export function createFinaleStage(env) {
    // Sprites on the ground are made again with groundLayer on every rebuild; the rest live as long as the scene.
    let g = null;
    const lightning = new Sprite();
    lightning.visible = false;
    env.fx.addChild(lightning);
    const runnerFar = new Sprite();
    runnerFar.visible = false;
    env.far.addChild(runnerFar);
    const bubbles = new Map();
    let lastStrike = -1;

    function build() {
        const make = (layer, { add = false, alpha = 1 } = {}) => {
            const s = new Sprite();
            s.visible = false;
            s.alpha = alpha;
            if (add) s.blendMode = 'add';
            layer.addChild(s);
            return s;
        };
        g = {
            flash: make(env.ground),
            column: make(env.ground, { add: true, alpha: 0.55 }),
            cloudBack: make(env.ground),
            figure: make(env.ground),
            cloudFront: make(env.ground, { alpha: 0.85 }),
            creep: [0, 1, 2, 3].map(() => make(env.ground, { alpha: 0.8 })),
            runner: make(env.ground),
            halo: make(env.light, { add: true, alpha: 0.45 }),
        };
    }

    const place = (sprite, def, x, y, texture, z) => {
        sprite.visible = true;
        sprite.texture = texture;
        sprite.anchor.set(def.anchor.x / def.w, def.anchor.y / def.h);
        sprite.position.set(Math.round(x), Math.round(y));
        if (z !== undefined) sprite.zIndex = z;
    };

    const frankenTexture = (def) => env.defTexture(def, env.lighting(), frankenBake);

    // FrankenPHP's own clock: breathing, a burst of sparks off the bolts every 2.4 s, talking while a phrase is up.
    function frankenFrame(clock, phrase) {
        if (env.reduced()) return phrase ? FRANKEN.talk[1] : FRANKEN.idle[0];
        if (phrase) {
            const beat = Math.floor(clock / 180);
            return beat % 10 >= 7 ? FRANKEN.talk[2] : FRANKEN.talk[beat % 2];
        }
        const s = clock % 2400;
        if (s > 2000 && s < 2270) return FRANKEN.spark[Math.floor((s - 2000) / 90)];
        return FRANKEN.idle[Math.floor(clock / 700) % 2];
    }

    function figureOf(step, t, clock, phrase) {
        const idle = env.reduced() ? SILHOUETTE.idle[0] : SILHOUETTE.idle[Math.floor(clock / 500) % 2];
        if (step === 1) {
            if (t < T.rise) return null;
            if (t < T.rise + 4 * T.riseFrame) return SILHOUETTE.rise[Math.floor((t - T.rise) / T.riseFrame)];
            return idle;
        }
        if (step === 2) {
            if (t < T.reveal) return idle;
            if (t < T.reveal + 3 * T.revealFrame) return SILHOUETTE.reveal[Math.floor((t - T.reveal) / T.revealFrame)];
        }
        return frankenFrame(clock, phrase);
    }

    // Where RoadRunner stops in the foreground: right of the circle, feet above the HUD.
    function frontSpot() {
        const { artW, artH, cssPx } = env.layout();
        const c = env.centre();
        return { x: Math.round(Math.min(artW - 24, c.x + artW * 0.2)), y: Math.round(artH - 78 / cssPx) };
    }

    const sizeOf = (p) => (p < 0.15 ? 's' : p < 0.4 ? 'm' : p < 0.7 ? 'l' : 'xl');

    /**
     * RoadRunner at `t` into the step: { far, def, x, y, flip, meep } or null. It comes on one diagonal from the right
     * screen edge at the horizon down to the front spot. `k` runs 0 → 1 along it in perspective — slow and small by
     * the hills, faster and bigger as it nears — and the sprite grows from the hazy far speck through the sizes.
     */
    function runnerOf(step, t, clock, now) {
        const { artW, horizon } = env.layout();
        const front = frontSpot();
        const edge = { x: artW + 8, y: horizon - 1 };
        const far = (x, flip, ms = clock) => ({ far: true, def: RUNNER.far[Math.floor(ms / 100) % 4], x, y: horizon - 1, flip });
        // Even ground speed looks like an ease-in on screen: the far part of the way is foreshortened.
        const along = (p) => p ** 1.8;
        const on = (k, pose, flip) => {
            const x = lerp(edge.x, front.x, k);
            const y = lerp(edge.y, front.y, k);
            if (k < 0.1) return { far: true, def: RUNNER.far[Math.floor(clock / 100) % 4], x, y, flip };
            const set = RUNNER[sizeOf(k)];
            const def = pose === 'run' ? set.run[Math.floor(clock / 70) % 4] : set[pose];
            return { far: false, def, x, y, flip };
        };
        // Heading down-left toward the front spot it faces left (mirrored); the stop turns it to the centre, also left.
        if (step === 3) {
            if (t < T.run) return on(along(t / T.run), 'run', true);
            const into = t - T.meep;
            return { ...on(1, into >= 0 && into < T.meepMs ? 'meep' : 'stop', true), meep: into >= 0 && into < T.meepMs };
        }
        if (step === 4) return on(1, 'stop', true);
        if (step === 5) {
            if (t < T.leave) return on(1, 'stop', true);
            if (t < T.leave + T.back) return on(along(1 - (t - T.leave) / T.back), 'run', false);
            // Then it keeps running along the horizon, now and then, like in scene 0: one run per slot, on the server
            // clock so every viewer sees the same.
            const slot = Math.floor(now / LOOP_MS);
            const into = now - slot * LOOP_MS;
            if (into >= RUN_MS) return null;
            const ltr = mix(0x7a11, slot) % 2 === 0;
            const k = into / RUN_MS;
            return far(ltr ? lerp(-8, artW + 8, k) : lerp(artW + 8, -8, k), !ltr, into);
        }
        return null;
    }

    // ------------------------------------------------------------------ speech bubbles
    const FONT = 'ui-monospace, "Cascadia Mono", Consolas, "Courier New", monospace';

    // A pixel bubble at screen resolution like the owl's (app.css .owl-bubble): cream, a stepped dark frame and a
    // stepped tail down to the speaker's head.
    function bubble(key, text, head) {
        const { width, cssPx } = env.layout();
        const u = Math.max(2, Math.round(cssPx));
        let b = bubbles.get(key);
        if (!b || b.text !== text || b.u !== u) {
            b?.node.destroy({ children: true });
            const node = new Container();
            const frame = new Graphics();
            const label = new Text({
                text,
                style: { fontFamily: FONT, fontSize: width < 600 ? 12 : 13, fontWeight: '700', fill: PALETTE.outline, wordWrap: true, wordWrapWidth: Math.min(240, width - 48), lineHeight: width < 600 ? 15 : 17 },
            });
            node.addChild(frame, label);
            env.labels.addChild(node);
            b = { key, text, u, node, frame, label, w: Math.ceil(label.width) + 20, h: Math.ceil(label.height) + 12, tail: null };
            label.position.set(10, 6);
            bubbles.set(key, b);
        }
        // Up and to the right of the head, inside the screen; the tail follows the head.
        const hx = head.x * cssPx;
        const hy = head.y * cssPx;
        const tailH = 5 * u;
        const left = Math.round(clamp(hx - 18, 8, width - b.w - 8));
        const top = Math.round(Math.max(8, hy - tailH - b.h - u));
        const tail = Math.round(clamp(hx - left, 4 * u, b.w - 4 * u));
        if (tail !== b.tail) {
            b.tail = tail;
            // Frames first, then the cream: the tail's two steps open into the bubble and into each other.
            const f = b.frame.clear();
            f.rect(-u, -u, b.w + 2 * u, b.h + 2 * u).fill(PALETTE.outline);
            f.rect(tail - 3 * u, b.h, 6 * u, 3 * u).fill(PALETTE.outline);
            f.rect(tail - 2 * u, b.h + 2 * u, 4 * u, 3 * u).fill(PALETTE.outline);
            f.rect(0, 0, b.w, b.h).fill(PALETTE.cream);
            f.rect(tail - 2 * u, b.h, 4 * u, 2 * u).fill(PALETTE.cream);
            f.rect(tail - u, b.h + 2 * u, 2 * u, 2 * u).fill(PALETTE.cream);
        }
        b.node.position.set(left, top);
        b.seen = true;
    }

    function sweepBubbles() {
        for (const [key, b] of bubbles) {
            if (b.seen) {
                b.seen = false;
                continue;
            }
            b.node.destroy({ children: true });
            bubbles.delete(key);
        }
    }

    // ------------------------------------------------------------------ the frame
    function hideAll() {
        if (g) {
            for (const s of [g.flash, g.column, g.cloudBack, g.figure, g.cloudFront, g.runner, g.halo, ...g.creep]) s.visible = false;
        }
        lightning.visible = false;
        runnerFar.visible = false;
    }

    /** One frame of `step` (null outside the finale) at `t` ms into it. */
    function animate(step, t) {
        hideAll();
        if (!g || step === null || step < 1) {
            lastStrike = -1;
            sweepBubbles();
            return;
        }
        const clock = env.clock();
        const now = env.serverNow();
        const c = env.centre();
        const base = env.depthKey(c.x, c.y);
        const lit = env.lighting();
        const reduced = env.reduced();
        const frameOf = (list, ms) => list[reduced ? 0 : Math.floor(clock / ms) % list.length];

        if (step === 1 && t < 3 * T.flash && !reduced) {
            const def = FX.flash[Math.floor(t / T.flash)];
            place(g.flash, def, c.x, c.y, env.defTexture(def), base + 1);
        }
        if ((step === 1 && t >= T.column) || (step === 2 && t < T.night)) {
            const def = frameOf(FX.column, 90);
            place(g.column, def, c.x, c.y + 2, env.defTexture(def), base + 2);
        }
        if ((step === 1 && t >= T.cloud) || (step === 2 && t < T.reveal)) {
            const def = frameOf(FX.smoke.cloud, 220);
            place(g.cloudBack, def, c.x, c.y + 4, env.defTexture(def, lit), base + 3);
            const front = FX.smoke.cloud[(FX.smoke.cloud.indexOf(def) + 2) % 4];
            place(g.cloudFront, front, c.x, c.y + 10, env.defTexture(front, lit), env.depthKey(c.x, c.y + 10));
        }

        // FrankenPHP talks only in the cursed world, and not over the owl's farewell.
        const phrase = step === 5 && t >= T.quiet ? phraseAt(now) : null;
        const figure = figureOf(step, t, clock, phrase);
        if (figure) place(g.figure, figure, c.x, c.y, frankenTexture(figure), base + 4);
        if (phrase) bubble('franken', phrase.text, { x: c.x + 4, y: c.y - 84 });

        // Lightning over everything, each strike flashing the screen.
        if (step === 2 && !reduced) {
            T.strikes.forEach((at, k) => {
                const s = t - at;
                if (s < 0 || s >= 3 * T.strikeFrame) return;
                const def = FX.lightning[k][Math.floor(s / T.strikeFrame)];
                place(lightning, def, c.x + (k - 1) * 3, c.y - 52, env.defTexture(def));
                if (k > lastStrike) {
                    lastStrike = k;
                    env.strike();
                }
            });
        }

        // The smoke creeps over the ground after the lightning, until the cursed world.
        if ((step === 2 && t >= T.reveal) || step === 3 || step === 4) {
            const { artW } = env.layout();
            const k = artW / 320;
            [[-60, 6, 0], [10, 10, 2], [70, 2, 1], [-20, 22, 3]].forEach(([dx, dy, phase], i) => {
                const def = FX.smoke.creep[(Math.floor(clock / 300) + phase) % 4];
                const x = c.x + dx * k + (reduced ? 0 : Math.sin(clock / 5000 + i * 1.7) * 8);
                place(g.creep[i], def, x, c.y + dy, env.defTexture(def, lit), env.depthKey(x, c.y + dy));
            });
        }

        if (step === 5) {
            const def = haloDef();
            place(g.halo, def, c.x, c.y, env.defTexture(def));
        }

        const r = runnerOf(step, t, clock, now);
        if (r) {
            if (r.far) {
                const art = env.flyerArt(r.def, true);
                runnerFar.visible = true;
                runnerFar.texture = art.texture;
                runnerFar.anchor.set(art.ax, art.ay);
                runnerFar.scale.x = r.flip ? -1 : 1;
                runnerFar.position.set(Math.round(r.x), Math.round(r.y));
            } else {
                place(g.runner, r.def, r.x, r.y, env.defTexture(r.def, lit, runnerBake), env.depthKey(r.x, r.y));
                g.runner.scale.x = r.flip ? -1 : 1;
                if (r.meep) bubble('runner', 'Мип-мип!', { x: r.x + (r.flip ? -6 : 6), y: r.y - r.def.h });
            }
        }
        sweepBubbles();
    }

    let halo = null;
    // Wider than the pit, which covers its middle.
    const haloDef = () => (halo ??= frankenHalo(46, 12));

    return { build, animate };
}
