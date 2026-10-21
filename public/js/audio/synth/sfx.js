import { SVF, Saw, createRender, dbToGain, hashSeed, measure, mtof, panLR, reverb, scale } from './core.js';
import {
    bell, blip, brass, chip, choir, crackle, heart, impact, kick, murmur, musicbox, noiseSweep, partials, pizz, riser,
    theremin, toll, tom, vox, xylo,
} from './instruments.js';

/**
 * Sound effects: every sound is its own short buffer, rendered once per variant with the seed of its name and
 * variant, then peak-normalised to `level` dBFS. Fields: `dur` seconds (tails included), `variants` (the engine picks
 * one at random and jitters the rate), `voices` (how many may sound at once), `bus` (sfx or ui), `duck` (seconds the
 * music dips under it), `jitter` (rate spread, 0 for the tuned ones), `stereo` (kept wide — the stingers; the rest
 * are folded to mono and panned at play time, half the memory), `group` (`core` renders on unlock, `finale` when the
 * finale comes near).
 */

const TAU = 2 * Math.PI;

/** A pitched thump: a sine falling from f0 to f1 — the body of pops and plops. */
function thump(r, out, t, { f0 = 300, f1 = 90, dur = 0.12, amp = 0.6, pan = 0, send = 0.1 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const [pl, pr] = panLR(pan);
    let ph = 0;
    for (let i = 0; i < n; i++) {
        const k = i / n;
        ph += (f0 * (f1 / f0) ** k) / sr;
        const v = Math.sin(TAU * ph) * Math.min(1, i / (0.001 * sr)) * (1 - k) ** 2 * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** Bubbles: a few rising sine blips, the sound of beer and swamp. */
function bubbles(r, out, t, count, { spread = 0.4, amp = 0.15, low = 300, high = 1100, pan = 0 } = {}) {
    for (let k = 0; k < count; k++) {
        const f = low + r.rnd() * (high - low);
        blip(r, out, t + r.rnd() * spread, f, f * (1.6 + r.rnd() * 0.6), 0.03 + r.rnd() * 0.04, { amp: amp * (0.5 + r.rnd() * 0.5), pan: pan + (r.rnd() - 0.5) * 0.6, send: 0.15 });
    }
}

/** Steps: soft low ticks on grass, `n` of them `gap` seconds apart. */
function steps(r, out, t, n, gap, { amp = 0.3, tone = 700, accel = 1, crescendo = 0, pan = 0 } = {}) {
    let st = t;
    let g = gap;
    for (let k = 0; k < n; k++) {
        const a = amp * (1 + crescendo * (k / n)) * (0.8 + r.rnd() * 0.3);
        noiseSweep(r, out, st, 0.06, { from: tone * 1.3, to: tone * 0.7, q: 1.2, amp: a, mode: 'bp', curve: 2, send: 0.05, pan: pan + (k % 2 ? 0.08 : -0.08) });
        thump(r, out, st, { f0: 140, f1: 70, dur: 0.05, amp: a * 0.5, pan });
        st += g;
        g *= accel;
    }
}

const fx = (def) => ({ variants: 1, voices: 4, bus: 'sfx', level: -6, jitter: 0.04, duck: 0, stereo: false, group: 'core', ...def });

export const SFX = {
    // ---------------------------------------------------------------- pumpkins, scene 0
    'pumpkin.carve': fx({
        dur: 0.35, variants: 5, voices: 6, level: -10,
        render(r, out) {
            const p = (r.rnd() - 0.5) * 0.4;
            thump(r, out, 0, { f0: 380 + r.rnd() * 180, f1: 110, dur: 0.08, amp: 0.7, pan: p });
            noiseSweep(r, out, 0, 0.05, { from: 3000, to: 1200, q: 2, amp: 0.5, pan: p });
            noiseSweep(r, out, 0.03, 0.18, { from: 1900 + r.rnd() * 600, to: 350, q: 4, amp: 0.45, pan: p, curve: 1.5 });
        },
    }),
    'pumpkin.scoop': fx({
        dur: 0.6, variants: 5, voices: 5, level: -11,
        render(r, out) {
            const p = (r.rnd() - 0.5) * 0.4;
            noiseSweep(r, out, 0, 0.12, { from: 300, to: 1400, q: 5, amp: 0.55, pan: p, curve: 0.5 });
            noiseSweep(r, out, 0.1, 0.16, { from: 1400, to: 250, q: 5, amp: 0.5, pan: p, curve: 1.2 });
            thump(r, out, 0.04, { f0: 220, f1: 420, dur: 0.07, amp: 0.3, pan: p });
            for (let k = 0; k < 3; k++) blip(r, out, 0.22 + k * 0.06 + r.rnd() * 0.04, 2400 + r.rnd() * 1800, 1800, 0.02, { amp: 0.25, pan: p + (r.rnd() - 0.5), send: 0.2 });
        },
    }),
    'pumpkin.burned': fx({
        dur: 0.15, variants: 3, voices: 4, level: -20,
        render(r, out) {
            thump(r, out, 0, { f0: 260 + r.rnd() * 60, f1: 160, dur: 0.06, amp: 0.6 });
            noiseSweep(r, out, 0, 0.03, { from: 900, to: 600, q: 1, amp: 0.3, mode: 'lp' });
        },
    }),
    // A stage done: a wooden pop and two happy chip notes climbing.
    'pumpkin.stage': fx({
        dur: 0.9, variants: 3, voices: 4, level: -10, jitter: 0,
        render(r, out, v) {
            thump(r, out, 0, { f0: 500, f1: 150, dur: 0.07, amp: 0.6 });
            const root = [72, 74, 76][v];
            chip(r, out, 0.03, 0.08, root, { duty: 0.25, amp: 0.25, send: 0.3 });
            chip(r, out, 0.11, 0.25, root + 7, { duty: 0.25, amp: 0.25, decay: 8, send: 0.35 });
            musicbox(r, out, 0.11, root + 19, { amp: 0.15, dur: 0.7 });
        },
    }),

    // ---------------------------------------------------------------- scene 1: fire
    'pumpkin.ignite': fx({
        dur: 0.6, variants: 5, voices: 5, level: -12,
        render(r, out) {
            noiseSweep(r, out, 0, 0.09, { from: 2500, to: 5000, q: 1.5, amp: 0.4, mode: 'hp', grain: 8 });
            noiseSweep(r, out, 0.06, 0.35, { from: 200, to: 900, q: 0.8, amp: 0.7, mode: 'lp', attack: 0.06, curve: 1.5 });
            crackle(r, out, 0.1, 0.4, { rate: 25, amp: 0.25, width: 0.3 });
        },
    }),
    // Stage of the lit pumpkin: a «whoomph» of flame catching.
    'pumpkin.lit': fx({
        dur: 1.2, variants: 3, voices: 3, level: -14,
        render(r, out) {
            noiseSweep(r, out, 0, 0.9, { from: 180, to: 2200, q: 0.7, amp: 0.9, mode: 'lp', attack: 0.08, curve: 2 });
            thump(r, out, 0.02, { f0: 120, f1: 45, dur: 0.35, amp: 0.7 });
            crackle(r, out, 0.15, 0.9, { rate: 40, amp: 0.35 });
        },
    }),

    // ---------------------------------------------------------------- scene 2: magic
    'pumpkin.enchant': fx({
        dur: 0.9, variants: 5, voices: 5, level: -12,
        render(r, out) {
            const scale = [74, 77, 79, 81, 84, 86, 89];
            for (let k = 0; k < 3; k++) {
                const m = scale[Math.floor(r.rnd() * scale.length)] + 12;
                bell(r, out, k * 0.05, m, { amp: 0.15, decay: 6, pan: (r.rnd() - 0.5) * 0.8, dur: 0.8, send: 0.6 });
            }
            noiseSweep(r, out, 0, 0.4, { from: 6000, to: 10000, q: 3, amp: 0.08, mode: 'bp', send: 0.5 });
        },
    }),
    // Stage of the enchanted pumpkin: a magic swell gliding up into a chime.
    'pumpkin.charged': fx({
        dur: 1.8, variants: 3, voices: 3, level: -11,
        render(r, out, v) {
            const sr = r.sr;
            const n = Math.round(0.6 * sr);
            let p1 = 0;
            let p2 = 0;
            for (let i = 0; i < n; i++) {
                const k = i / n;
                const f = 300 * 2 ** (k * 2.5);
                p1 += f / sr;
                p2 += (f * 1.007) / sr;
                const val = (Math.sin(TAU * p1) + Math.sin(TAU * p2)) * 0.15 * k * k;
                out.put(i, val * 0.8, val, 0.7);
            }
            const top = [86, 88, 89][v];
            bell(r, out, 0.6, top, { amp: 0.3, decay: 2, dur: 1.2 });
            bell(r, out, 0.6, top - 12, { amp: 0.2, decay: 2.5, dur: 1.2, pan: -0.3 });
        },
    }),

    // ---------------------------------------------------------------- bonfire
    'bonfire.click': fx({
        dur: 0.5, variants: 5, voices: 6, level: -12,
        render(r, out) {
            crackle(r, out, 0, 0.35, { rate: 60, amp: 0.5, tone: 1400 });
            tom(r, out, 0, { amp: 0.4, tone: 110 + r.rnd() * 40, send: 0.1 });
        },
    }),
    'bonfire.stage': fx({
        dur: 1.8, variants: 3, voices: 2, level: -14,
        render(r, out) {
            noiseSweep(r, out, 0, 1.5, { from: 250, to: 2500, q: 0.6, amp: 1, mode: 'lp', attack: 0.15, curve: 1.6 });
            thump(r, out, 0, { f0: 90, f1: 40, dur: 0.6, amp: 0.8 });
            crackle(r, out, 0.1, 1.5, { rate: 70, amp: 0.4 });
        },
    }),
    'bonfire.explode': fx({
        stereo: true, dur: 4.5, voices: 1, level: -7, duck: 3, jitter: 0,
        render(r, out) {
            impact(r, out, 0, { amp: 1, dur: 3.5, low: 28 });
            noiseSweep(r, out, 0, 3.5, { from: 3000, to: 120, q: 0.6, amp: 0.8, mode: 'lp', curve: 1.3, send: 0.5 });
            crackle(r, out, 0.2, 3.5, { rate: 50, amp: 0.25, tone: 2000, width: 1 });
            crackle(r, out, 0.05, 1.2, { rate: 120, amp: 0.3, tone: 1200, width: 1 });
        },
    }),

    // ---------------------------------------------------------------- magic circle
    'circle.click': fx({
        group: 'finale', dur: 0.5, variants: 5, voices: 6, level: -13,
        render(r, out) {
            noiseSweep(r, out, 0, 0.16, { from: 2500 + r.rnd() * 1500, to: 3500, q: 3, amp: 0.5, grain: 30, pan: (r.rnd() - 0.5) * 0.5 });
            blip(r, out, 0, 110, 98, 0.3, { amp: 0.4, wave: 'square', send: 0.4 });
        },
    }),
    'circle.stage': fx({
        group: 'finale', dur: 2.6, variants: 3, voices: 2, level: -9,
        render(r, out, v) {
            const chords = [[49, 56, 61, 64], [50, 57, 62, 65], [52, 56, 61, 64]];
            choir(r, out, 0, 0.9, chords[v].map((m) => m + 12), { amp: 0.25, vowel: 'a', attack: 0.05, release: 0.9, send: 0.9 });
            tom(r, out, 0, { amp: 0.8, tone: 50, send: 0.5 });
        },
    }),
    'circle.done': fx({
        stereo: true, group: 'finale', dur: 4, voices: 1, level: -7, duck: 2.5, jitter: 0,
        render(r, out) {
            choir(r, out, 0, 2.2, [49, 56, 61, 64, 68, 73], { amp: 0.25, vowel: 'o', attack: 0.3, release: 1.2, send: 0.9 });
            for (let k = 0; k < 8; k++) heart(r, out, k * 0.25, { amp: 0.4 * (1 - k / 10), gap: 0.1 });
            toll(r, out, 0, 49, { amp: 0.3, dur: 3.8 });
        },
    }),

    // ---------------------------------------------------------------- flyers and ground targets
    'duck.quack': fx({
        dur: 0.45, variants: 4, voices: 3, level: -18,
        render(r, out) {
            const m = 61 + r.rnd() * 4;
            vox(r, out, 0, 0.13, m, { vowel: 'a', to: 'e', duty: 0.4, glide: -3, drive: 1.8, amp: 0.5, breath: 0.1 });
            if (r.rnd() < 0.7) vox(r, out, 0.18, 0.12, m - 1, { vowel: 'a', to: 'e', duty: 0.4, glide: -4, drive: 1.8, amp: 0.45, breath: 0.1 });
        },
    }),
    'chicken.cluck': fx({
        dur: 0.8, variants: 4, voices: 3, level: -19,
        render(r, out) {
            const m = 68 + r.rnd() * 3;
            for (let k = 0; k < 3; k++) vox(r, out, k * 0.11, 0.05, m, { vowel: 'u', to: 'a', duty: 0.3, drive: 2, amp: 0.5 });
            vox(r, out, 0.36, 0.3, m + 2, { vowel: 'a', to: 'o', duty: 0.25, glide: 5, drive: 2.5, amp: 0.5, breath: 0.15 });
        },
    }),
    'bat.squeak': fx({
        dur: 0.3, variants: 4, voices: 3, level: -19,
        render(r, out) {
            for (let k = 0; k < 3; k++) {
                const f = 3200 + r.rnd() * 1600;
                blip(r, out, k * 0.06, f, f * 0.75, 0.035, { amp: 0.4, send: 0.2 });
            }
        },
    }),
    // «Кар-кар»: a hoarse, buzzing «a», a narrow pulse overdriven with breath.
    'crow.caw': fx({
        dur: 0.9, variants: 4, voices: 3, level: -18,
        render(r, out) {
            const m = 50 + r.rnd() * 4;
            const caws = 2 + Math.floor(r.rnd() * 2);
            for (let k = 0; k < caws; k++) {
                vox(r, out, k * 0.26, 0.2, m - k * 0.7, { vowel: 'a', to: 'o', duty: 0.12, glide: -2.5, drive: 6, amp: 0.5, breath: 0.5, send: 0.25 });
            }
        },
    }),
    // «У-у-у»: a ghost gliding up and sinking back, a wide slow vibrato over a breath of air.
    'ghost.wail': fx({
        dur: 2.4, variants: 3, voices: 2, level: -15,
        render(r, out) {
            const m = 64 + r.rnd() * 5;
            theremin(r, out, [[0, m, 0.5], [0.5, m + 7, 0.6], [1.1, m - 2, 0.8]], { amp: 0.3, glide: 0.18, vib: 0.7, vibRate: 4.2, send: 0.8 });
            noiseSweep(r, out, 0, 1.9, { from: 500, to: 900, q: 2, amp: 0.12, attack: 0.4, curve: 1.2, send: 0.6 });
        },
    }),
    // A fly passing by: a buzzing saw near 200 Hz that wobbles in pitch and loudness, rising as it comes and dropping as
    // it goes (Doppler), soft-filtered so it stays in the background.
    'fly.buzz': fx({
        dur: 1.4, variants: 4, voices: 3, level: -14,
        render(r, out) {
            const sr = r.sr;
            const n = Math.round(1.3 * sr);
            const saw = new Saw(sr, r.rnd());
            const bp = new SVF(sr).set(1100 + r.rnd() * 500, 1.5);
            const lp = new SVF(sr).set(2800, 0.6);
            const f0 = 190 + r.rnd() * 50;
            const wob = 5 + r.rnd() * 4;
            const ph = r.rnd() * TAU;
            let jitter = 0;
            for (let i = 0; i < n; i++) {
                const s = i / sr;
                const k = i / n;
                jitter += (r.noise() * 0.6 - jitter) * 0.002;
                const doppler = 1.2 * Math.cos(Math.PI * k);
                const f = f0 * 2 ** ((doppler + 0.6 * Math.sin(TAU * wob * s + ph) + jitter) / 12);
                const x = saw.next(f);
                bp.tick(x);
                const am = 0.75 + 0.25 * Math.sin(TAU * (wob * 1.7) * s);
                const e = Math.sin(Math.PI * k) ** 1.5 * am;
                const v = lp.tick(bp.bp * 1.4 + x * 0.25) * e * 0.5;
                out.put(i, v, v, 0.1);
            }
        },
    }),
    'worm.squelch': fx({
        dur: 0.5, variants: 4, voices: 3, level: -13,
        render(r, out) {
            noiseSweep(r, out, 0, 0.25, { from: 200, to: 900, q: 6, amp: 0.6, curve: 1 });
            bubbles(r, out, 0.05, 3, { spread: 0.25, low: 150, high: 400, amp: 0.4 });
        },
    }),
    'firefly.ding': fx({
        dur: 0.9, variants: 4, voices: 3, level: -17,
        render(r, out) {
            const m = [93, 96, 98, 100][Math.floor(r.rnd() * 4)];
            bell(r, out, 0, m, { amp: 0.3, decay: 4, dur: 0.85, pan: (r.rnd() - 0.5) * 0.6 });
        },
    }),
    // «Хе-хе-хе-хе»: syllables falling and speeding up, a nasal «e».
    'witch.cackle': fx({
        dur: 1.6, variants: 2, voices: 1, level: -16,
        render(r, out) {
            let t = 0;
            for (let k = 0; k < 7; k++) {
                const d = 0.11 - k * 0.006;
                vox(r, out, t, d, 79 - k * 0.8 + r.rnd(), { vowel: 'e', to: 'a', duty: 0.2, drive: 1.8, amp: 0.45, glide: -2, breath: 0.2, send: 0.4 });
                t += d + 0.04;
            }
        },
    }),
    'zombie.groan': fx({
        dur: 1.8, variants: 3, voices: 2, level: -17,
        render(r, out) {
            vox(r, out, 0, 1.4, 38 + r.rnd() * 4, { vowel: 'u', to: 'o', duty: 0.15, glide: -3, drive: 4, amp: 0.5, breath: 0.25, send: 0.4 });
        },
    }),
    // RoadRunner far on the horizon: a quiet patter crossing.
    'runner.steps': fx({
        dur: 1.6, variants: 2, voices: 1, level: -22,
        render(r, out) {
            steps(r, out, 0, 16, 0.085, { amp: 0.3, tone: 1200 });
        },
    }),
    'bush.rustle': fx({
        dur: 0.6, variants: 4, voices: 3, level: -15,
        render(r, out) {
            noiseSweep(r, out, 0, 0.5, { from: 3000, to: 1800, q: 0.8, amp: 0.5, mode: 'hp', grain: 40, attack: 0.03, curve: 0.8 });
        },
    }),

    // ---------------------------------------------------------------- shots by the item in hand
    'shot.pistol': fx({
        dur: 0.7, variants: 4, voices: 4, level: -4,
        render(r, out) {
            noiseSweep(r, out, 0, 0.12, { from: 2800, to: 800, q: 0.7, amp: 0.9, mode: 'lp', curve: 3, attack: 0.004 });
            thump(r, out, 0, { f0: 180, f1: 50, dur: 0.15, amp: 0.9, send: 0.3 });
            noiseSweep(r, out, 0.02, 0.5, { from: 900, to: 300, q: 0.7, amp: 0.15, mode: 'lp', send: 0.6 });
        },
    }),
    'shot.candle': fx({
        dur: 0.6, variants: 4, voices: 4, level: -2,
        render(r, out) {
            noiseSweep(r, out, 0, 0.35, { from: 300, to: 2500, q: 1, amp: 0.8, mode: 'lp', attack: 0.04, curve: 1.6, pan1: 0.3 });
            crackle(r, out, 0.05, 0.25, { rate: 50, amp: 0.3 });
        },
    }),
    'shot.book': fx({
        dur: 0.9, variants: 4, voices: 4, level: -5,
        render(r, out) {
            const sr = r.sr;
            const n = Math.round(0.3 * sr);
            const f = new SVF(sr);
            for (let i = 0; i < n; i++) {
                const k = i / n;
                const buzz = (r.rnd() < 0.35 ? 1 : -1) * (Math.sin(TAU * 60 * (i / sr)) > 0 ? 1 : 0.4);
                const v = f.run(buzz, 6000 - 4000 * k, 1.5) * (1 - k) ** 1.5 * 0.5;
                out.put(i, v, v * 0.9, 0.3);
            }
            bell(r, out, 0.02, 88 + Math.floor(r.rnd() * 3) * 2, { amp: 0.15, decay: 5, dur: 0.8 });
        },
    }),
    // Beer: a short burp and bubbles flying at the target.
    'shot.beer': fx({
        dur: 0.8, variants: 4, voices: 3, level: -12,
        render(r, out) {
            vox(r, out, 0, 0.22 + r.rnd() * 0.1, 40 + r.rnd() * 5, { vowel: 'o', to: 'a', duty: 0.2, glide: -2, drive: 5, amp: 0.6, breath: 0.4 });
            bubbles(r, out, 0.15, 6, { spread: 0.35, amp: 0.25 });
        },
    }),
    'shot.redflame': fx({
        dur: 0.6, variants: 4, voices: 4, level: -5,
        render(r, out) {
            noiseSweep(r, out, 0, 0.25, { from: 3000, to: 7000, q: 1, amp: 0.5, mode: 'hp', grain: 20 });
            blip(r, out, 0, 600, 1800, 0.25, { amp: 0.35, wave: 'pulse', send: 0.4 });
        },
    }),

    // ---------------------------------------------------------------- hits and score
    'flyer.hit': fx({
        dur: 0.3, variants: 4, voices: 4, level: -12,
        render(r, out) {
            thump(r, out, 0, { f0: 700, f1: 200, dur: 0.07, amp: 0.7 });
            noiseSweep(r, out, 0, 0.08, { from: 2000, to: 800, q: 1, amp: 0.5, curve: 2 });
        },
    }),
    'flyer.fall': fx({
        dur: 1.1, variants: 3, voices: 3, level: -14,
        render(r, out) {
            blip(r, out, 0, 1600 + r.rnd() * 300, 280, 0.7, { amp: 0.25, wave: 'pulse', send: 0.2 });
            thump(r, out, 0.72, { f0: 160, f1: 60, dur: 0.15, amp: 0.7 });
            noiseSweep(r, out, 0.72, 0.15, { from: 900, to: 300, amp: 0.4, mode: 'lp' });
        },
    }),

    // ---------------------------------------------------------------- walkers and the cursed tavern
    'walk.step': fx({
        dur: 0.12, variants: 5, voices: 6, level: -24,
        render(r, out) {
            steps(r, out, 0, 1, 0, { amp: 0.4, tone: 600 + r.rnd() * 300 });
        },
    }),
    'clink.glass': fx({
        dur: 1.2, variants: 4, voices: 3, level: -11,
        render(r, out) {
            const f = 1900 + r.rnd() * 400;
            partials(r, out, 0, f, [[1, 1, 7], [2.32, 0.6, 9], [4.25, 0.35, 12], [6.63, 0.2, 18]], { amp: 0.3, dur: 1.0, send: 0.3 });
            partials(r, out, 0.008, f * 1.06, [[1, 1, 8], [2.32, 0.5, 10], [4.25, 0.3, 14]], { amp: 0.22, dur: 0.9, pan: 0.2, send: 0.3 });
            noiseSweep(r, out, 0.05, 0.4, { from: 1200, to: 500, q: 2, amp: 0.08, grain: 30 });
        },
    }),
    // «Ура!»: a small crowd gliding «u» → «a», each voice its own pitch.
    'clink.cheer': fx({
        dur: 1.2, variants: 3, voices: 2, level: -13,
        render(r, out) {
            for (let k = 0; k < 5; k++) {
                vox(r, out, r.rnd() * 0.08, 0.6 + r.rnd() * 0.25, 55 + r.rnd() * 12, { vowel: 'u', to: 'a', duty: 0.35, glide: 3 + r.rnd() * 2, drive: 1.5, amp: 0.25, pan: (r.rnd() - 0.5) * 1.2, breath: 0.2, send: 0.35 });
            }
        },
    }),

    // ---------------------------------------------------------------- the bar
    // The owl pours one mug (HELLOWIN_BAR_POUR_MS, 2–3 s): the tap opens, beer streams into a mug whose ring rises as
    // it fills, the foam fizzes up, the tap closes.
    'bar.pour': fx({
        dur: 2.8, variants: 3, voices: 2, level: -15, jitter: 0.02,
        render(r, out) {
            thump(r, out, 0, { f0: 420, f1: 300, dur: 0.04, amp: 0.4 });
            const sr = r.sr;
            const n = Math.round(2.3 * sr);
            const f = new SVF(sr);
            const lp = new SVF(sr).set(2600, 0.6);
            let g = 1;
            for (let i = 0; i < n; i++) {
                const k = i / n;
                if (r.rnd() < 30 / sr) g = 0.6 + r.rnd() * 0.8;
                g += (1 - g) * 0.001;
                f.run(r.noise(), 380 * 4 ** k, 5);
                const e = Math.min(1, i / (0.08 * sr)) * Math.min(1, (n - i) / (0.15 * sr));
                const v = lp.tick(f.bp * 1.2 + r.noise() * 0.05) * e * g * 0.6;
                out.put(Math.round(0.05 * sr) + i, v, v, 0.2);
            }
            bubbles(r, out, 0.4, 14, { spread: 1.9, low: 500, high: 1400, amp: 0.08 });
            noiseSweep(r, out, 1.6, 0.9, { from: 5000, to: 7000, q: 0.8, amp: 0.05, mode: 'hp', attack: 0.3, curve: 1, grain: 200 });
            thump(r, out, 2.4, { f0: 380, f1: 260, dur: 0.04, amp: 0.35 });
        },
    }),
    // A full mug set down on the counter: a heavy wooden knock with a slosh.
    'bar.mug': fx({
        dur: 0.5, variants: 4, voices: 3, level: -16,
        render(r, out) {
            tom(r, out, 0, { amp: 0.6, tone: 150 + r.rnd() * 30, send: 0.15 });
            thump(r, out, 0, { f0: 900, f1: 500, dur: 0.02, amp: 0.25 });
            noiseSweep(r, out, 0.03, 0.2, { from: 900, to: 500, q: 2, amp: 0.15, attack: 0.02, curve: 1.5 });
        },
    }),
    // A mug taken off the counter: a light scrape and the beer swaying in it.
    'bar.take': fx({
        dur: 0.5, variants: 4, voices: 3, level: -12,
        render(r, out) {
            noiseSweep(r, out, 0, 0.12, { from: 1400, to: 1000, q: 1.5, amp: 0.25, attack: 0.02, grain: 60 });
            noiseSweep(r, out, 0.08, 0.3, { from: 500, to: 1100, q: 3, amp: 0.3, attack: 0.04, curve: 1.2 });
            thump(r, out, 0.02, { f0: 600, f1: 420, dur: 0.03, amp: 0.15 });
        },
    }),
    // No mug left: two dull knocks of knuckles on bare wood.
    'bar.empty': fx({
        dur: 0.45, variants: 3, voices: 2, level: -15,
        render(r, out) {
            for (const [t, a] of [[0, 0.6], [0.14, 0.45]]) {
                thump(r, out, t, { f0: 200, f1: 130, dur: 0.07, amp: a });
                noiseSweep(r, out, t, 0.03, { from: 700, to: 500, q: 1, amp: a * 0.3, mode: 'lp' });
            }
        },
    }),
    // Drinking: two or three gulps, then a contented, low «а-ах».
    'bar.gulp': fx({
        dur: 1.6, variants: 3, voices: 3, level: -16,
        render(r, out) {
            const gulps = 2 + Math.floor(r.rnd() * 2);
            for (let k = 0; k < gulps; k++) {
                const t = k * 0.24;
                thump(r, out, t, { f0: 260, f1: 140, dur: 0.09, amp: 0.5 });
                noiseSweep(r, out, t, 0.1, { from: 300, to: 700, q: 4, amp: 0.3, attack: 0.01, curve: 1 });
            }
            murmur(r, out, gulps * 0.24 + 0.1, 0.7, 50 + r.rnd() * 3, { glide: -3, cutoff: 700, breath: 0.25, amp: 0.4 });
        },
    }),

    // ---------------------------------------------------------------- voices
    'owl.hoot': fx({
        dur: 1.5, variants: 2, voices: 1, level: -14,
        render(r, out) {
            for (const [t, d, m] of [[0, 0.3, 66], [0.42, 0.55, 65]]) {
                const sr = r.sr;
                const i0 = r.at(t);
                const n = Math.round(d * sr);
                const f = new SVF(sr).set(mtof(m), 4);
                let ph = 0;
                for (let i = 0; i < n; i++) {
                    const k = i / n;
                    ph += mtof(m + 1.2 * Math.sin(Math.PI * k) - 1.5 * k * k) / sr;
                    const e = Math.sin(Math.PI * Math.min(1, k * 1.6)) ** 1.5 * (1 - k * 0.5);
                    f.tick(r.noise());
                    const v = (Math.sin(TAU * ph) + f.bp * 0.6) * e * 0.4;
                    out.put(i0 + i, v, v, 0.5);
                }
            }
        },
    }),
    // One syllable of the owl's muffled murmur; the engine strings them under a line (speak).
    'owl.babble': fx({
        dur: 0.2, variants: 6, voices: 2, level: -17, bus: 'ui', jitter: 0.03,
        render(r, out) {
            murmur(r, out, 0, 0.13 + r.rnd() * 0.05, 64 + r.rnd() * 4, { glide: (r.rnd() - 0.5) * 1.5, cutoff: 1100, amp: 0.4 });
        },
    }),
    'franken.grumble': fx({
        dur: 0.26, variants: 6, voices: 2, level: -21, bus: 'ui', jitter: 0.03,
        render(r, out) {
            murmur(r, out, 0, 0.17 + r.rnd() * 0.07, 40 + r.rnd() * 4, { glide: (r.rnd() - 0.5) * 1.5, cutoff: 520, growl: 0.8, breath: 0.08, amp: 0.45 });
        },
    }),

    // ---------------------------------------------------------------- finale stingers
    // Step 1: the circle flares, the ground shakes for T.rumble, a riser into the rising silhouette.
    'finale.wake': fx({
        stereo: true, group: 'finale', dur: 4, voices: 1, level: -6, duck: 3, jitter: 0,
        render(r, out) {
            riser(r, out, 0, 2.4, { amp: 0.6, from: 120, to: 5000 });
            noiseSweep(r, out, 0, 3, { from: 60, to: 90, q: 0.7, amp: 1.2, mode: 'lp', attack: 0.3, curve: 0.7, grain: 6, send: 0.2 });
            thump(r, out, 0, { f0: 70, f1: 30, dur: 1.2, amp: 0.7 });
            choir(r, out, 0.3, 2.2, [49, 50, 56], { amp: 0.25, vowel: 'a', attack: 1.5, release: 1, send: 0.8 });
        },
    }),
    // Step 2: one strike of lightning with thunder; the step plays three at T.strikes.
    'finale.strike': fx({
        stereo: true, group: 'finale', dur: 3, variants: 3, voices: 3, level: -7, duck: 2, jitter: 0.03,
        render(r, out) {
            noiseSweep(r, out, 0, 0.18, { from: 8000, to: 2000, q: 0.6, amp: 1, mode: 'hp', curve: 2, grain: 80 });
            crackle(r, out, 0, 0.25, { rate: 300, amp: 0.8, tone: 3000, width: 0.8 });
            noiseSweep(r, out, 0.05, 2.6, { from: 400, to: 60, q: 0.6, amp: 1.2, mode: 'lp', attack: 0.15, curve: 1.2, grain: 10, send: 0.5 });
        },
    }),
    // Step 2, under FrankenPHP's reveal: a brass «ta-daa» that sags at the end — triumphant, and not quite.
    'finale.tada': fx({
        stereo: true, group: 'finale', dur: 2.6, voices: 1, level: -6, duck: 2.2, jitter: 0,
        render(r, out) {
            for (const m of [60, 64, 67]) brass(r, out, 0, 0.14, m, { amp: 0.25, bright: 1.2 });
            for (const m of [65, 69, 72, 77]) brass(r, out, 0.2, 1.5, m, { amp: 0.25, bright: 1.1, sag: 1.6, vib: 0.25 });
            kick(r, out, 0.2, { amp: 0.5 });
            pizz(r, out, 1.75, 41, { amp: 0.4, bright: 0.2, dur: 0.6 });
        },
    }),
    // Step 3: RoadRunner's run from the horizon down to the front, steps getting closer for T.run.
    'finale.run': fx({
        stereo: true, group: 'finale', dur: 5, voices: 1, level: -9, jitter: 0,
        render(r, out) {
            steps(r, out, 0, 48, 0.098, { amp: 0.12, tone: 1100, crescendo: 6, accel: 0.999 });
            noiseSweep(r, out, 3.9, 0.8, { from: 600, to: 3000, q: 0.7, amp: 0.4, mode: 'lp', attack: 0.4, curve: 2 });
        },
    }),
    // Step 3: «мип-мип» — two bright chirped tones, the second a fourth up; our own signal.
    'finale.meep': fx({
        stereo: true, group: 'finale', dur: 0.8, voices: 1, level: -9, jitter: 0, duck: 0.8,
        render(r, out) {
            for (const [t, m] of [[0, 88], [0.2, 93]]) {
                vox(r, out, t, 0.14, m, { vowel: 'i', duty: 0.3, glide: 1.5, drive: 2, amp: 0.4, send: 0.2 });
                chip(r, out, t, 0.12, m, { duty: 0.5, amp: 0.25, slide: -2, send: 0.2 });
            }
        },
    }),
    // Step 4: the reveal fanfare — brass climbing into a bright choir chord with a bell.
    'finale.reveal': fx({
        stereo: true, group: 'finale', dur: 4.5, voices: 1, level: -5, duck: 3.5, jitter: 0,
        render(r, out) {
            [60, 64, 67, 72].forEach((m, k) => brass(r, out, k * 0.12, 0.13, m, { amp: 0.22, bright: 1.2 }));
            for (const m of [72, 76, 79, 84]) brass(r, out, 0.5, 1.8, m, { amp: 0.18, bright: 1.3, vib: 0.18 });
            choir(r, out, 0.5, 2.2, [60, 64, 67, 72, 76], { amp: 0.25, vowel: 'a', attack: 0.15, release: 1.4, send: 0.9 });
            bell(r, out, 0.5, 96, { amp: 0.2, decay: 1.2, dur: 3 });
            noiseSweep(r, out, 0, 0.5, { from: 2000, to: 9000, q: 0.7, amp: 0.25, mode: 'hp', attack: 0.45, curve: 0.5 });
            impact(r, out, 0.5, { amp: 0.35, dur: 2.5, low: 40 });
        },
    }),
    // Step 5: the owl flies off — wing beats moving right and away, one last hoot fading.
    'finale.owlAway': fx({
        stereo: true, group: 'finale', dur: 3.5, voices: 1, level: -9, jitter: 0,
        render(r, out) {
            for (let k = 0; k < 10; k++) {
                const t = k * 0.2;
                noiseSweep(r, out, t, 0.12, { from: 300, to: 900, q: 0.8, amp: 0.7 * (1 - k / 12), mode: 'lp', attack: 0.04, pan: 0.1 + k * 0.08 });
            }
            vox(r, out, 1.4, 0.6, 65, { vowel: 'u', duty: 0.5, glide: -2, amp: 0.2, pan: 0.8, send: 0.9, breath: 0.3 });
        },
    }),

    // ---------------------------------------------------------------- interface
    'ui.click': fx({
        dur: 0.06, variants: 3, voices: 3, level: -16, bus: 'ui', jitter: 0.05,
        render(r, out) {
            noiseSweep(r, out, 0, 0.025, { from: 3500, to: 3000, q: 1.5, amp: 0.6, curve: 3, send: 0 });
            thump(r, out, 0, { f0: 1100, f1: 700, dur: 0.02, amp: 0.4, send: 0 });
        },
    }),
    'ui.join': fx({
        dur: 1.4, voices: 1, level: -10, bus: 'ui', jitter: 0,
        render(r, out) {
            [72, 76, 79, 84].forEach((m, k) => musicbox(r, out, k * 0.07, m + 12, { amp: 0.25, dur: 1.1 }));
            chip(r, out, 0.28, 0.4, 84, { duty: 0.125, amp: 0.15, decay: 5, send: 0.4 });
        },
    }),
    // The scene is ready, waiting for the host: a two-note «ding-dong».
    'ui.ready': fx({
        stereo: true, dur: 3, voices: 1, level: -9, bus: 'ui', jitter: 0,
        render(r, out) {
            toll(r, out, 0, 72, { amp: 0.25, dur: 2.5 });
            toll(r, out, 0.5, 67, { amp: 0.25, dur: 2.5 });
        },
    }),
    // The ritual is reset: a tape rewinding down.
    'ui.reset': fx({
        dur: 1.4, voices: 1, level: -9, bus: 'ui', jitter: 0,
        render(r, out) {
            blip(r, out, 0, 1500, 120, 0.9, { amp: 0.3, wave: 'square', send: 0.3 });
            noiseSweep(r, out, 0, 0.9, { from: 6000, to: 300, q: 2, amp: 0.3, grain: 40 });
            xylo(r, out, 0.95, 67, { amp: 0.4 });
        },
    }),
};

/** Renders one variant of an effect: [L, R] for a stereo sound, [mono] otherwise; peak at the sound's level. */
export function renderSfx(name, variant, sr) {
    const def = SFX[name];
    if (!def) throw new Error(`unknown sound ${name}`);
    const r = createRender(sr, def.dur, { seed: hashSeed(name, variant) });
    const out = r.stem();
    def.render(r, out, variant);
    reverb(sr, out, { input: 0.012 });
    // A short fade at the end: tails cut by `dur` must not click.
    const fade = Math.min(r.n, Math.round(0.01 * sr));
    for (let i = 0; i < fade; i++) {
        const g = i / fade;
        out.L[r.n - 1 - i] *= g;
        out.R[r.n - 1 - i] *= g;
    }
    let channels = [out.L, out.R];
    if (!def.stereo) {
        for (let i = 0; i < r.n; i++) out.L[i] = (out.L[i] + out.R[i]) * 0.5;
        channels = [out.L];
    }
    const { peak } = measure(channels);
    if (peak > 0) scale(channels, dbToGain(def.level) / peak);
    return channels;
}

/** Every [name, variant] pair of a group, in render order: short frequent sounds first. */
export function sfxJobs(group = 'core') {
    const jobs = [];
    const names = Object.keys(SFX).filter((n) => SFX[n].group === group).sort((a, b) => SFX[a].dur - SFX[b].dur);
    for (const name of names) for (let v = 0; v < SFX[name].variants; v++) jobs.push([name, v]);
    return jobs;
}
