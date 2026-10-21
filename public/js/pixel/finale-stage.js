import { Sprite } from 'https://cdn.jsdelivr.net/npm/pixi.js@8.22.0/dist/pixi.min.mjs';
import { bossPhraseAt, phraseAt } from '../franken.js';
import { createBubbles } from './bubble.js';
import { BOSS, FINALE_EMISSIVE, FRANKEN, FX, RUNNER, SILHOUETTE, breathOf, frankenHalo } from './finale.js';
import { EMISSIVE, LIT_PALETTE } from './night.js';
import { PALETTE } from './palette.js';
import { T } from './finale-timeline.js';

/**
 * The finale's steps on the scene (docs/spec/scenario.md, «Финал»; docs/spec/frontend.md, «Финал»): FrankenPHP and
 * the silhouette on the circle, the column, smoke and lightning, RoadRunner coming in and leaving, and the speech
 * bubbles of RoadRunner and FrankenPHP. Everything is a function of the step and of `t`, the ms since the step began
 * on the scene clock: a reload sets `t` far in the past, so the same functions draw the settled end of the step.
 * The pumpkins, the walkers, the owl and the lighting stay in scene.js; they read T from here.
 */

export { T };

// RoadRunner's evening run along the horizon in the cursed world: a run of RUN_MS in every slot of LOOP_MS.
const LOOP_MS = 15000;
const RUN_MS = 6500;
// A FrankenPHP phrase is voiced only when caught this early: the frame loop stops in a background tab.
const PHRASE_FRESH_MS = 1500;

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

/** Whether the floating pumpkins' lights are out: they drop with the reveal, light up green at step 5, die at the dawn. */
export const lightsOut = (step, t) => (step === 2 && t >= T.reveal) || step === 3 || step === 4 || step >= 7;

// The boss's glasses catch the light once every GLINT_MS, a lens at a time.
const GLINT_MS = 3000;
const GLINT_LENS_MS = 80;
// The fallen glasses drop onto the heap with this gravity (art px/s²); the ash smokes in a wisp every WISP_MS.
const GLASSES_FALL = 260;
const WISP_MS = 2400;

// The smoke of the third strike, all of it a function of the ms since the strike, the same for every viewer.
const BURST_AT = T.strikes[2];
// Each puff bursts out of the strike over BURST_MS, rolls up and outward, holds dense for `hold` ms and thins out in
// four steps over `thin` ms. `x`, `y` — where it settles, art px from the circle's foot; `front` — before the figure.
const BURST_MS = 320;
const rand = (i, k) => mix(0x5e0c + k, i) / 2 ** 32;
const BURST = [
    // Before the figure: a dense veil over him, thinning first, once the reveal is over.
    ...Array.from({ length: 10 }, (_, i) => {
        const h = (i % 5) / 4;
        const side = i % 2 ? 1 : -1;
        return {
            x: side * (3 + rand(i, 1) * 16), y: -6 - h * 66 - rand(i, 2) * 6, size: h > 0.2 && h < 0.9 ? 3 : 2, front: true,
            hold: 650 + rand(i, 3) * 450, thin: 1600 + rand(i, 4) * 1400, rise: 3 + h * 5, drift: side * (2 + rand(i, 5) * 3),
        };
    }),
    // Behind him and out to the sides: a wider cloud up over his head that lingers longer.
    ...Array.from({ length: 10 }, (_, i) => {
        const h = (i % 5) / 4;
        const side = i % 2 ? -1 : 1;
        return {
            x: side * (16 + rand(i, 6) * 18), y: -10 - h * 72 - rand(i, 7) * 8, size: 2 + (i % 3 === 0 ? 1 : 0), front: false,
            hold: 1100 + rand(i, 8) * 700, thin: 2400 + rand(i, 9) * 1800, rise: 4 + h * 7, drift: side * (3 + rand(i, 10) * 4),
        };
    }),
    // A ring on the ground rolling out over the circle and lying there longest.
    ...Array.from({ length: 6 }, (_, i) => {
        const side = i % 2 ? 1 : -1;
        return {
            x: side * (18 + (i >> 1) * 13 + rand(i, 11) * 6), y: -2 + (i >> 1) * 2, size: 2 - (i >> 1 === 2 ? 1 : 0), front: i >> 1 !== 1,
            hold: 2600 + rand(i, 12) * 900, thin: 2600 + rand(i, 13) * 1200, rise: 0.6, drift: side * (4 + rand(i, 14) * 3),
        };
    }),
];
// Wisps curling up off him for a while after: start ms after the strike (wider apart as he cools), where on him, life.
const WISP_LIFE = 1700;
const WISPS = Array.from({ length: 14 }, (_, k) => {
    const spot = [[-12, -52], [12, -50], [-9, -34], [10, -36], [0, -66], [-5, -18], [6, -20]][Math.floor(rand(k, 15) * 7)];
    return { at: 700 + k * 380 + k * k * 14, x: spot[0] + Math.round(rand(k, 16) * 4 - 2), y: spot[1], drift: rand(k, 17) < 0.5 ? -1 : 1 };
});

/**
 * The smoke after the third strike at `t` into step 2: [{ size, fade, frame, x, y, layer }] with x, y in art px from
 * the circle's foot and `layer` — 'back' behind the figure, 'front' before it on the ground, 'over' rising off him.
 * Reduced motion keeps the puffs where they settle, without billowing, and leaves out the wisps.
 */
export function strikeSmokeAt(t, clock, reduced) {
    const into = t - BURST_AT;
    if (into < 0) return [];
    const out = [];
    BURST.forEach((p, i) => {
        const a = into - (i % 7) * 15;
        if (a < 0) return;
        const fade = a < p.hold ? 0 : Math.floor(((a - p.hold) / p.thin) * 4);
        if (fade > 3) return;
        const frame = reduced ? 0 : (Math.floor(clock / 240) + i) % 4;
        if (reduced) {
            out.push({ size: p.size, fade, frame, x: p.x, y: p.y, layer: p.front ? 'front' : 'back' });
            return;
        }
        // Out of the strike point at his head, fast, then easing into the slow roll.
        const e = 1 - (1 - Math.min(1, a / BURST_MS)) ** 3;
        const s = a / 1000;
        const x = lerp(p.x * 0.15, p.x, e) + p.drift * s + Math.sin(s * 1.3 + i) * 1.5;
        const y = lerp(-40, p.y, e) - p.rise * s;
        out.push({ size: Math.max(0, p.size - (e < 0.5 ? 1 : 0)), fade, frame, x, y, layer: p.front ? 'front' : 'back' });
    });
    if (reduced) return out;
    WISPS.forEach((w, k) => {
        const a = into - w.at;
        if (a < 0 || a >= WISP_LIFE) return;
        const k01 = a / WISP_LIFE;
        const up = 26 * (1 - (1 - k01) ** 2);
        out.push({
            size: k01 < 0.35 ? 0 : 1, fade: Math.min(3, Math.floor(k01 * 4)), frame: (Math.floor(clock / 240) + k) % 4,
            x: w.x + w.drift * (k01 * 7 + Math.sin(k01 * 5 + k) * 2), y: w.y - up, layer: 'over',
        });
    });
    return out;
}

/** How many puff sprites strikeSmokeAt() may ask for at once. */
const STRIKE_PUFFS = BURST.length + Math.ceil(WISP_LIFE / 380);

/**
 * How far FrankenPHP has crumbled: ms since he started (null while he stands). At zero health he falls where he is; at
 * the dawn he lies in ash, falling from its start if nobody felled him first.
 */
export function downOf(step, t, boss, clock) {
    if (step === 6 && boss && boss.hp <= 0) return clock - boss.downAt;
    if (step >= 7) return boss && boss.downAt > -1e8 ? clock - boss.downAt : t;
    return null;
}

/** Whether the walkers stand in the ritual pose: from the closed circle until the lightning. */
export const ritualOf = (step, ready, t) => (step === 0 && ready) || step === 1 || (step === 2 && t < T.reveal);

/**
 * @param {object} env  scene internals: layers (ground, light, far, fx, labels), layout(), lighting(), centre() — the
 *   circle's foot in art px, clock(), serverNow(), reduced(), defTexture(def, lit), flyerArt(def, hazy), depthKey(x, y),
 *   strike() — a lightning strike begins (the scene flashes its veil), cue(cue) — a moment the sound plays, boss() —
 *   the boss of step 6 as the scene keeps it: { hp, max, downAt, hitAt, woke } (scene clock) or null
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
    let lastStrike = -1;
    let lastPhrase = null;

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
            glasses: make(env.ground),
            wisp: make(env.ground, { alpha: 0.7 }),
            puffs: Array.from({ length: STRIKE_PUFFS }, () => make(env.ground)),
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
        if (step >= 5) {
            if (step === 5 && t < T.leave) return on(1, 'stop', true);
            if (step === 5 && t < T.leave + T.back) return on(along(1 - (t - T.leave) / T.back), 'run', false);
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
    const speech = createBubbles({ labels: env.labels, layout: env.layout });
    const bubble = speech.say;
    const sweepBubbles = speech.sweep;

    // ------------------------------------------------------------------ the frame
    function hideAll() {
        if (g) {
            for (const s of [g.flash, g.column, g.cloudBack, g.figure, g.cloudFront, g.runner, g.halo, g.glasses, g.wisp, ...g.creep, ...g.puffs]) s.visible = false;
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
        // The cloud of step 1 stays until the burst of the third strike has swallowed it.
        if ((step === 1 && t >= T.cloud) || (step === 2 && t < BURST_AT + 150)) {
            const def = frameOf(FX.smoke.cloud, 220);
            place(g.cloudBack, def, c.x, c.y + 4, env.defTexture(def, lit), base + 3);
            const front = FX.smoke.cloud[(FX.smoke.cloud.indexOf(def) + 2) % 4];
            place(g.cloudFront, front, c.x, c.y + 10, env.defTexture(front, lit), env.depthKey(c.x, c.y + 10));
        }

        const boss = env.boss?.() ?? null;
        const down = downOf(step, t, boss, clock);
        // FrankenPHP talks only in the cursed world, and not over the owl's farewell or its return; while the boss stands
        // he has his own lines, more often; felled, he is silent.
        const alive = boss && boss.hp > 0;
        const phrase = down !== null ? null
            : step === 5 && t >= T.quiet ? phraseAt(now)
                : step === 6 && alive ? bossPhraseAt(now)
                    : step === 6 && t >= T.quiet6 ? phraseAt(now) : null;
        let figure = down === null ? figureOf(step, t, clock, phrase) : null;
        // A strike makes him flinch.
        const hit = figure && boss && clock - boss.hitAt >= 0 && clock - boss.hitAt < T.hit && !reduced;
        if (hit) figure = BOSS.hit;
        if (figure) {
            place(g.figure, figure, c.x + (hit ? 1 : 0), c.y, frankenTexture(figure), base + 4);
            if (step === 6 && alive) {
                const s = clock % GLINT_MS;
                const glasses = BOSS.glasses[breathOf(figure)][reduced ? 0 : s < GLINT_LENS_MS ? 1 : s < 2 * GLINT_LENS_MS ? 2 : 0];
                place(g.glasses, glasses, c.x + (hit ? 1 : 0), c.y, frankenTexture(glasses), base + 4.5);
            }
        }
        if (down !== null) fallen(down, c, base, boss, clock, reduced);
        if (phrase) bubble('franken', phrase.text, { x: c.x + 4, y: c.y - 84 });
        // Spoken once, as it comes up: a phrase already halfway through on a reload stays silent.
        if (phrase && phrase.at !== lastPhrase) {
            lastPhrase = phrase.at;
            if (now - phrase.at < PHRASE_FRESH_MS) env.cue?.({ type: 'franken', text: phrase.text, x: (c.x / env.layout().artW) * 100 });
        }

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

        if (step === 2) {
            strikeSmokeAt(t, clock, reduced).slice(0, g.puffs.length).forEach((p, i) => {
                const def = FX.smoke.billow[p.size][p.fade][p.frame];
                const x = c.x + p.x;
                const z = p.layer === 'back' ? base + 3.5 : p.layer === 'over' ? base + 5 : env.depthKey(x, c.y + 8);
                place(g.puffs[i], def, x, c.y + p.y, env.defTexture(def, lit), z);
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

        if ((step === 5 || step === 6) && down === null) {
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

    /**
     * FrankenPHP felled, `down` ms ago: he darkens, cracks and crumbles into a heap (BOSS.collapse), then the ash
     * pile stays with its embers flickering and a wisp of smoke; the boss's glasses drop onto it.
     */
    function fallen(down, c, base, boss, clock, reduced) {
        const k = reduced ? BOSS.collapse.length : Math.floor(down / T.collapseFrame);
        const def = k < BOSS.collapse.length ? BOSS.collapse[k] : BOSS.ash[reduced ? 0 : Math.floor(clock / 300) % BOSS.ash.length];
        place(g.figure, def, c.x, c.y, frankenTexture(def), base + 4);
        if (k >= BOSS.collapse.length && !reduced) {
            const into = clock % WISP_MS;
            const wisp = FX.smoke.puff[0][Math.min(3, Math.floor((into / WISP_MS) * 4))];
            place(g.wisp, wisp, c.x + 6 + Math.round(into / 600), c.y - 12 - Math.round(into / 140), env.defTexture(wisp, env.lighting()), base + 5);
        }
        if (!boss?.woke) return;
        // The glasses fall from his eyes onto the top of the heap.
        const head = c.y - 62;
        const rest = c.y - 10;
        const y = reduced ? rest : Math.min(rest, head + 0.5 * GLASSES_FALL * (down / 1000) ** 2);
        const glasses = y < rest ? BOSS.glasses[0][0] : BOSS.glassesFallen;
        place(g.glasses, glasses, y < rest ? c.x : c.x - 2, y < rest ? c.y + (y - head) : rest, frankenTexture(glasses), base + 4.5);
    }

    let halo = null;
    // Wider than the pit, which covers its middle.
    const haloDef = () => (halo ??= frankenHalo(46, 12));

    /** Hides FrankenPHP for one frame: a fade into the dawn freezes the night without him, he falls in the new light. */
    function hideFigure() {
        if (g) for (const sprite of [g.figure, g.glasses, g.halo, g.wisp]) sprite.visible = false;
    }

    return { build, animate, hideFigure };
}
