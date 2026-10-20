import { LfsrNoise, OnePole, Pulse, SVF, Saw, StepTriangle, TableOsc, bed, clamp, makeTable, mtof, panLR } from './core.js';

/**
 * Instruments: each writes one event into a stem — `fn(r, out, t, …)`, where `r` is the render (rate, seeded noise)
 * and `t` is seconds from the stem's start. Amplitudes are relative: tracks and effects are normalised after mixing.
 */

const TAU = 2 * Math.PI;

// ---------------------------------------------------------------- chiptune

/** NES-ish volume: 16 steps. */
const steps16 = (e) => Math.ceil(clamp(e, 0, 1) * 15) / 15;

/**
 * Pulse-wave voice. `duty` 0.125 / 0.25 / 0.5; `arp` — semitone offsets cycled at `arpRate` Hz (the chip's
 * one-channel chord); `slide` — semitones the note scoops from; `sweep` — semitones per second after the attack.
 */
export function chip(r, out, t, dur, midi, { duty = 0.25, amp = 0.2, pan = 0, send = 0.15, decay = 0, release = 0.04, vib = 0, vibDelay = 0.15, arp = null, arpRate = 50, slide = 0, sweep = 0, dutyLfo = 0 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const rel = Math.round(release * sr);
    const osc = new Pulse(sr, r.rnd());
    const lp = new SVF(sr).set(7000, 0.6);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n + rel; i++) {
        const s = i / sr;
        let m = midi;
        if (arp) m += arp[Math.floor(s * arpRate) % arp.length];
        if (slide) m += slide * Math.exp(-s * 40);
        if (sweep) m += sweep * s;
        if (vib && s > vibDelay) m += vib * Math.sin(TAU * 5.5 * (s - vibDelay));
        let e = Math.min(1, s / 0.003) * (decay ? Math.exp(-s * decay) : 1);
        if (i >= n) e *= 1 - (i - n) / rel;
        const d = dutyLfo ? duty + dutyLfo * Math.sin(TAU * 0.7 * s) : duty;
        const v = lp.tick(osc.next(mtof(m), d)) * steps16(e) * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** NES triangle bass: no volume control on the real chip, so only a short gate. */
export function tri(r, out, t, dur, midi, { amp = 0.35, slide = 0, send = 0 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const osc = new StepTriangle(sr, 0);
    const g = Math.round(0.004 * sr);
    for (let i = 0; i < n + g; i++) {
        const s = i / sr;
        const m = midi + (slide ? slide * Math.exp(-s * 30) : 0);
        const e = Math.min(1, i / g) * (i < n ? 1 : 1 - (i - n) / g);
        const v = osc.next(mtof(m)) * e * amp;
        out.put(i0 + i, v, v, send);
    }
}

/** Chip drums from the noise channel and a falling triangle: kick, snare, hat. */
export function chipKick(r, out, t, { amp = 0.6 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(0.18 * sr);
    const osc = new StepTriangle(sr, 0.75);
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        const v = osc.next(55 + 260 * Math.exp(-s * 35)) * steps16(Math.exp(-s * 18)) * amp;
        out.put(i0 + i, v, v);
    }
}

export function chipSnare(r, out, t, { amp = 0.3, decay = 18, pan = 0 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(0.25 * sr);
    const nz = new LfsrNoise(sr);
    const osc = new StepTriangle(sr, 0);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        const v = (nz.next(14000) * steps16(Math.exp(-s * decay)) * 0.8 + osc.next(180 + 120 * Math.exp(-s * 60)) * Math.exp(-s * 40) * 0.6) * amp;
        out.put(i0 + i, v * pl, v * pr, 0.08);
    }
}

export function chipHat(r, out, t, { amp = 0.12, open = false, pan = 0.2 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round((open ? 0.16 : 0.04) * sr);
    const nz = new LfsrNoise(sr);
    nz.reg = 1 + Math.floor(r.rnd() * 32000);
    const hp = new SVF(sr).set(6000, 0.7);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        hp.tick(nz.next(44000, true));
        const v = hp.hp * steps16(Math.exp(-s * (open ? 20 : 80))) * amp;
        out.put(i0 + i, v * pl, v * pr);
    }
}

// ---------------------------------------------------------------- drums

export function kick(r, out, t, { amp = 0.9, tone = 46 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(0.42 * sr);
    let ph = 0;
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        ph += (TAU * (tone + 120 * Math.exp(-s * 38) + 40 * Math.exp(-s * 300))) / sr;
        let v = Math.sin(ph) * Math.exp(-s * 7.5) * Math.min(1, s / 0.002) * 0.95;
        v += r.noise() * Math.exp(-s * 900) * 0.2;
        v = Math.tanh(v * 1.6) * amp;
        out.put(i0 + i, v, v);
    }
}

export function clap(r, out, t, { amp = 0.5 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const f = new SVF(sr).set(1300, 0.9);
    const n = Math.round(0.3 * sr);
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        let burst = 0;
        for (const o of [0, 0.011, 0.022]) if (s >= o) burst += Math.exp(-(s - o) * 260);
        f.tick(r.noise());
        const v = f.bp * (burst * 0.6 + Math.exp(-s * 18) * 0.5) * amp * 2.2;
        out.put(i0 + i, v * 0.9, v, 0.25);
    }
}

export function hat(r, out, t, { amp = 0.15, open = false, pan = 0.2 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const f = new SVF(sr).set(9000, 0.8);
    const n = Math.round((open ? 0.22 : 0.05) * sr);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n; i++) {
        f.tick(r.noise());
        const v = f.hp * Math.exp(-(i / sr) * (open ? 16 : 85)) * amp;
        out.put(i0 + i, v * pl, v * pr);
    }
}

/** Frame drum / low tom: a falling sine with a skin slap. */
export function tom(r, out, t, { amp = 0.5, tone = 90, pan = 0, send = 0.3 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(0.6 * sr);
    const f = new SVF(sr).set(700, 1);
    const [pl, pr] = panLR(pan);
    let ph = 0;
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        ph += (TAU * tone * (1 + 0.6 * Math.exp(-s * 30))) / sr;
        f.tick(r.noise());
        const v = Math.tanh((Math.sin(ph) * Math.exp(-s * 6) + f.bp * Math.exp(-s * 50) * 0.8) * 1.2) * Math.min(1, s / 0.002) * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** Heartbeat: «lub» and a softer «dub», low sines falling 70 → 38 Hz. */
export function heart(r, out, t, { amp = 0.6, gap = 0.24 } = {}) {
    const sr = r.sr;
    for (const [dt, a] of [[0, 1], [gap, 0.7]]) {
        const i0 = r.at(t + dt);
        const n = Math.round(0.45 * sr);
        let ph = 0;
        for (let i = 0; i < n; i++) {
            const s = i / sr;
            ph += (TAU * (38 + 32 * Math.exp(-s * 18))) / sr;
            const v = Math.tanh(Math.sin(ph) * Math.min(1, s / 0.012) * Math.exp(-s * 9) * 1.4) * amp * a;
            out.put(i0 + i, v, v);
        }
    }
}

// ---------------------------------------------------------------- tonal

export function bass(r, out, t, dur, midi, { amp = 0.36, cut = 1500, send = 0 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const o1 = new Saw(sr, r.rnd());
    const o2 = new Saw(sr, r.rnd());
    const f = new SVF(sr);
    const fr = mtof(midi);
    let sub = 0;
    const rel = Math.round(0.02 * sr);
    for (let i = 0; i < n + rel; i++) {
        const s = i / sr;
        const e = Math.min(1, s / 0.004) * (i < n ? 1 : 1 - (i - n) / rel);
        const x = (o1.next(fr) + o2.next(fr * 1.006)) * 0.5;
        sub += (TAU * fr) / sr;
        const v = (f.run(x, 140 + cut * Math.exp(-s * 16), 1.1) * 0.75 + Math.sin(sub) * 0.4) * e * amp;
        out.put(i0 + i, v, v, send);
    }
}

/** Detuned-saw pad; the cutoff breathes slowly. */
export function pad(r, out, t, dur, notes, { amp = 0.06, cutoff = 1800, attack = 0.25, release = 0.8, send = 0.5, detune = 0.11 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const rel = Math.round(release * sr);
    const voices = [];
    notes.forEach((m, k) => {
        for (const det of [-detune, 0, detune]) {
            const [pl, pr] = panLR(clamp(((k % 3) - 1) * 0.5 + det * 3, -1, 1));
            voices.push({ o: new Saw(sr, r.rnd()), f: mtof(m + det), pl, pr });
        }
    });
    const fl = new SVF(sr);
    const fr = new SVF(sr);
    for (let i = 0; i < n + rel; i++) {
        const s = i / sr;
        const e = Math.min(1, s / attack) * (i < n ? 1 : Math.exp(-(i - n) / (0.25 * sr)));
        let l = 0;
        let rr = 0;
        for (const v of voices) {
            const x = v.o.next(v.f);
            l += x * v.pl;
            rr += x * v.pr;
        }
        const c = cutoff * (0.8 + 0.2 * Math.sin(s * 1.3));
        out.put(i0 + i, fl.run(l, c, 0.6) * e * amp, fr.run(rr, c, 0.6) * e * amp, send);
    }
}

export function pluck(r, out, t, midi, { amp = 0.12, pan = 0, decay = 9, send = 0.35 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(0.6 * sr);
    const o = new Saw(sr, r.rnd());
    const o2 = new Saw(sr, r.rnd());
    const f = new SVF(sr);
    const fr = mtof(midi);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        const x = o.next(fr) * 0.6 + o2.next(fr * 2.003) * 0.25;
        const v = f.run(x, 600 + 5200 * Math.exp(-s * 22), 0.9) * Math.exp(-s * decay) * Math.min(1, s / 0.002) * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** Karplus–Strong string: pizzicato, plucked bass, lute. `bright` 0–1 colours the excitation. */
export function pizz(r, out, t, midi, { amp = 0.3, pan = 0, dur = 0.9, damp = 0.996, bright = 0.5, send = 0.25 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const len = sr / mtof(midi);
    const size = Math.ceil(len) + 2;
    const buf = new Float32Array(size);
    const lp = new SVF(sr).set(800 + bright * 7000, 0.6);
    for (let i = 0; i < size; i++) buf[i] = lp.tick(r.noise());
    const [pl, pr] = panLR(pan);
    let w = 0;
    let prev = 0;
    const fade = Math.round(0.05 * sr);
    for (let i = 0; i < n; i++) {
        // Fractional delay read by linear interpolation keeps the pitch in tune.
        const rp = w - len;
        const ri = Math.floor(rp);
        const fr = rp - ri;
        const a = buf[((ri % size) + size) % size];
        const b = buf[(((ri + 1) % size) + size) % size];
        const x = a + (b - a) * fr;
        const y = (x + prev) * 0.5 * damp;
        prev = x;
        buf[w % size] = y;
        w++;
        const e = Math.min(1, i / 30) * (i > n - fade ? (n - i) / fade : 1);
        const v = x * e * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/**
 * Additive partials by recurrence oscillators (no Math.sin per sample): bells, music box, xylophone, glass.
 * `parts` — [ratio, amplitude, decay per second].
 */
export function partials(r, out, t, freq, parts, { amp = 0.1, pan = 0, send = 0.3, dur = 2, attack = 0.001, trem = 0, tremRate = 5.5 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const [pl, pr] = panLR(pan);
    const osc = [];
    for (const [m, a, d] of parts) {
        const w = (TAU * freq * m) / sr;
        if (freq * m >= sr * 0.45) continue;
        osc.push({ c: 2 * Math.cos(w), y1: Math.sin(-w), y2: Math.sin(-2 * w), a, e: 1, k: Math.exp(-d / sr) });
    }
    const fade = Math.round(0.02 * sr);
    for (let i = 0; i < n; i++) {
        let v = 0;
        for (const o of osc) {
            const y = o.c * o.y1 - o.y2;
            o.y2 = o.y1;
            o.y1 = y;
            v += y * o.a * o.e;
            o.e *= o.k;
        }
        const s = i / sr;
        let e = Math.min(1, s / attack) * (i > n - fade ? (n - i) / fade : 1);
        if (trem) e *= 1 - trem * (0.5 + 0.5 * Math.sin(TAU * tremRate * s));
        v *= e * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

export const bell = (r, out, t, midi, { amp = 0.1, pan = 0, decay = 3.5, send = 0.6, dur = 2.5 } = {}) =>
    partials(r, out, t, mtof(midi), [[1, 1, decay], [2.76, 0.4, decay * 2], [5.4, 0.18, decay * 2.9], [8.93, 0.08, decay * 4.1]], { amp, pan, send, dur });

/** Church bell: hum, prime, minor tierce, quint, nominal — the minor third is what makes it toll. */
export const toll = (r, out, t, midi, { amp = 0.12, pan = 0, send = 0.7, dur = 5 } = {}) =>
    partials(r, out, t, mtof(midi), [[0.5, 0.6, 0.6], [1, 1, 1.1], [1.19, 0.5, 1.4], [1.5, 0.3, 1.6], [2, 0.45, 1.9], [2.74, 0.2, 3], [3.76, 0.12, 4.5]], { amp, pan, send, dur, attack: 0.002 });

/** Music box tine: a bright inharmonic ring and a tick of the pin. */
export function musicbox(r, out, t, midi, { amp = 0.12, pan = 0, send = 0.4, dur = 1.6 } = {}) {
    partials(r, out, t, mtof(midi), [[1, 1, 2.6], [3.0, 0.08, 7], [5.98, 0.18, 9], [9.9, 0.06, 16]], { amp, pan, send, dur });
    const i0 = r.at(t);
    const f = new SVF(r.sr).set(6000, 2);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < Math.round(0.006 * r.sr); i++) {
        const v = f.tick(r.noise()) * (1 - i / (0.006 * r.sr)) * amp * 0.3;
        out.put(i0 + i, v * pl, v * pr);
    }
}

/** Xylophone: hard mallet, partials 1 / 3.93 / 9.0, short — the dancing skeletons. */
export function xylo(r, out, t, midi, { amp = 0.14, pan = 0, send = 0.25, dur = 0.7 } = {}) {
    partials(r, out, t, mtof(midi), [[1, 1, 9], [3.93, 0.3, 22], [9.0, 0.08, 40]], { amp, pan, send, dur });
    const i0 = r.at(t);
    const f = new SVF(r.sr).set(2500, 1.5);
    const [pl, pr] = panLR(pan);
    const k = Math.round(0.01 * r.sr);
    for (let i = 0; i < k; i++) {
        f.tick(r.noise());
        const v = f.bp * (1 - i / k) * amp * 0.6;
        out.put(i0 + i, v * pl, v * pr);
    }
}

export function blip(r, out, t, f0, f1, dur, { amp = 0.08, pan = 0, wave = 'sine', send = 0.3 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const [pl, pr] = panLR(pan);
    const pulse = new Pulse(sr, 0);
    let ph = 0;
    for (let i = 0; i < n; i++) {
        const k = i / n;
        const f = f0 * (f1 / f0) ** k;
        ph += f / sr;
        const x = wave === 'square' ? pulse.next(f, 0.5) * 0.5 : wave === 'pulse' ? pulse.next(f, 0.125) * 0.5 : Math.sin(TAU * ph);
        const v = x * Math.min(1, i / (0.002 * sr)) * (1 - k) ** 2 * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

export function riser(r, out, t, dur, { amp = 0.2, from = 200, to = 8000, tone = true } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const fl = new SVF(sr);
    const fr = new SVF(sr);
    const o = new Saw(sr, r.rnd());
    const o2 = new Saw(sr, r.rnd());
    for (let i = 0; i < n; i++) {
        const k = i / n;
        const e = k ** 2.2;
        const fc = from * (to / from) ** k;
        const tn = tone ? (o.next(110 * 4 ** k) + o2.next(110.7 * 4 ** k)) * 0.15 : 0;
        fl.run(r.noise(), fc, 2);
        fr.run(r.noise(), fc * 1.05, 2);
        const l = (fl.bp * 1.6 + tn) * e * amp;
        const rr = (fr.bp * 1.6 + tn) * e * amp;
        out.put(i0 + i, l, rr, 0.4);
    }
}

export function impact(r, out, t, { amp = 0.8, dur = 2.4, low = 32 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const f = new SVF(sr).set(5200, 0.5);
    const f2 = new SVF(sr).set(900, 0.8);
    let ph = 0;
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        ph += (TAU * (low + 70 * Math.exp(-s * 9))) / sr;
        const boom = Math.sin(ph) * Math.exp(-s * 2.6) * 0.9;
        f.tick(r.noise());
        f2.tick(r.noise());
        const crash = f.hp * Math.exp(-s * 3.2) * 0.35;
        const body = f2.bp * Math.exp(-s * 14) * 0.6;
        const v = Math.tanh((boom + crash + body) * 1.3) * amp;
        out.put(i0 + i, v, v * 0.97, 0.4);
    }
}

/** Drawbar organ: a wavetable of the drawbars, a slow Leslie-like tremolo and a key click. */
const ORGAN_BARS = {
    full: [[0.5, 0.6], [1, 1], [1.5, 0.5], [2, 0.8], [3, 0.4], [4, 0.5], [5, 0.15], [6, 0.2], [8, 0.25]],
    flute: [[1, 1], [2, 0.35], [4, 0.1]],
    reed: [[1, 1], [2, 0.7], [3, 0.55], [4, 0.45], [5, 0.38], [6, 0.3], [7, 0.24], [8, 0.2], [9, 0.15], [10, 0.12], [12, 0.08]],
};
const tables = new Map();
const tableOf = (name) => {
    if (!tables.has(name)) tables.set(name, makeTable(ORGAN_BARS[name]));
    return tables.get(name);
};

export function organ(r, out, t, dur, notes, { amp = 0.08, stops = 'full', attack = 0.01, release = 0.12, send = 0.55, leslie = 6, pan = 0, detune = 0 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const rel = Math.round(release * sr);
    const table = tableOf(stops);
    const voices = notes.flatMap((m) => {
        const f = mtof(m);
        const v = [{ o: new TableOsc(sr, table, r.rnd()), f }];
        // A second reed slightly sharp gives the accordion's musette beating.
        if (detune) v.push({ o: new TableOsc(sr, table, r.rnd()), f: f * 2 ** (detune / 1200) });
        return v;
    });
    const norm = 1 / Math.sqrt(voices.length);
    const lp = new SVF(sr).set(stops === 'flute' ? 3000 : 5500, 0.6);
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n + rel; i++) {
        const s = i / sr;
        const vib = 1 + 0.002 * Math.sin(TAU * leslie * s);
        let x = 0;
        for (const v of voices) x += v.o.next(v.f * vib);
        let e = Math.min(1, s / attack) * (i < n ? 1 : 1 - (i - n) / rel);
        if (s < 0.008) x += r.noise() * 0.25 * (1 - s / 0.008);
        const y = lp.tick(x * norm) * e * amp;
        const rot = 0.5 + 0.5 * Math.sin(TAU * leslie * 0.5 * s);
        out.put(i0 + i, y * pl * (0.75 + 0.25 * rot), y * pr * (1 - 0.25 * rot), send);
    }
}

/**
 * Theremin-like lead: one continuous voice gliding between the notes ([t, midi, dur] in seconds), sine with a little
 * second and third harmonic, a slow wide vibrato. Rendered as one event, so the glides are real portamento.
 */
export function theremin(r, out, notes, { amp = 0.12, pan = 0, send = 0.6, glide = 0.07, vib = 0.28, vibRate = 5.2 } = {}) {
    if (!notes.length) return;
    const sr = r.sr;
    const t0 = notes[0][0];
    const end = Math.max(...notes.map(([t, , d]) => t + d));
    const i0 = r.at(t0);
    const rel = Math.round(0.3 * sr);
    const n = Math.round((end - t0) * sr);
    const [pl, pr] = panLR(pan);
    const k = 1 - Math.exp(-1 / (glide * sr));
    const lp = new OnePole(sr, 2400);
    let m = notes[0][1];
    let ph = 0;
    let ni = 0;
    let gate = 0;
    for (let i = 0; i < n + rel; i++) {
        const tt = t0 + i / sr;
        while (ni + 1 < notes.length && tt >= notes[ni + 1][0]) ni++;
        const [nt, nm, nd] = notes[ni];
        const on = i < n && tt < nt + nd;
        if (nm !== null && on) m += (nm - m) * k;
        gate += ((on && nm !== null ? 1 : 0) - gate) * (on ? 0.0015 : 0.0004);
        const since = tt - nt;
        const mv = m + vib * Math.min(1, since / 0.35) * Math.sin(TAU * vibRate * tt);
        ph += mtof(mv) / sr;
        ph -= Math.floor(ph);
        const x = Math.sin(TAU * ph) + 0.18 * Math.sin(2 * TAU * ph) + 0.08 * Math.sin(3 * TAU * ph);
        const v = lp.tick(x) * gate * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** Vowel formants (Hz, gain) for the choir and the creature voices. */
export const VOWELS = {
    a: [[800, 1], [1150, 0.5], [2900, 0.12]],
    e: [[530, 1], [1840, 0.45], [2480, 0.15]],
    o: [[450, 1], [800, 0.4], [2830, 0.05]],
    u: [[325, 1], [700, 0.25], [2530, 0.04]],
    i: [[270, 1], [2290, 0.3], [3010, 0.15]],
};

/** Choir pad: detuned saws per note, each singer with its own vibrato, through three formant bandpasses. */
export function choir(r, out, t, dur, notes, { amp = 0.1, vowel = 'a', attack = 0.6, release = 1.2, send = 0.8, spread = 0.6, singers = 3 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const rel = Math.round(release * sr);
    const voices = [];
    notes.forEach((m, k) => {
        for (let j = 0; j < singers; j++) {
            const [pl, pr] = panLR(clamp((r.rnd() * 2 - 1) * spread, -1, 1));
            voices.push({ o: new Saw(sr, r.rnd()), f: mtof(m + (r.rnd() - 0.5) * 0.16), rate: 4.6 + r.rnd() * 1.4, ph: r.rnd() * TAU, pl, pr });
        }
    });
    const forms = VOWELS[vowel];
    const fl = forms.map(([f]) => new SVF(sr).set(f, 6));
    const fr = forms.map(([f]) => new SVF(sr).set(f * 1.02, 6));
    const norm = 1 / Math.sqrt(voices.length);
    for (let i = 0; i < n + rel; i++) {
        const s = i / sr;
        const e = Math.min(1, s / attack) * (i < n ? 1 : Math.exp(-(i - n) / (0.3 * sr)));
        let l = 0;
        let rr = 0;
        for (const v of voices) {
            const x = v.o.next(v.f * (1 + 0.004 * Math.sin(TAU * v.rate * s + v.ph)));
            l += x * v.pl;
            rr += x * v.pr;
        }
        let yl = 0;
        let yr = 0;
        for (let k = 0; k < forms.length; k++) {
            fl[k].tick(l);
            fr[k].tick(rr);
            yl += fl[k].bp * forms[k][1];
            yr += fr[k].bp * forms[k][1];
        }
        out.put(i0 + i, yl * e * amp * norm, yr * e * amp * norm, send);
    }
}

/** Brass: two saws, a filter that opens with the breath, a scoop into pitch and delayed vibrato; `sag` bends the end down. */
export function brass(r, out, t, dur, midi, { amp = 0.12, pan = 0, send = 0.4, bright = 1, sag = 0, vib = 0.12 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const rel = Math.round(0.08 * sr);
    const o1 = new Saw(sr, r.rnd());
    const o2 = new Saw(sr, r.rnd());
    const f = new SVF(sr);
    const [pl, pr] = panLR(pan);
    const fr = mtof(midi);
    for (let i = 0; i < n + rel; i++) {
        const s = i / sr;
        const k = Math.min(1, i / n);
        let m = midi - 0.6 * Math.exp(-s * 25);
        if (s > 0.18) m += vib * Math.sin(TAU * 5.6 * s) * Math.min(1, (s - 0.18) / 0.2);
        if (sag) m -= sag * Math.max(0, (k - 0.55) / 0.45) ** 2;
        const ff = (fr * mtof(m)) / fr;
        const e = Math.min(1, s / 0.03) * (i < n ? 1 - 0.15 * k : (1 - (i - n) / rel) * 0.85);
        const cut = (300 + 2600 * bright * Math.min(1, s / 0.07)) * (0.55 + 0.45 * e);
        const x = (o1.next(ff) + o2.next(ff * 1.004)) * 0.5;
        const v = Math.tanh(f.run(x, cut, 0.9) * 1.8) * e * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** A voice-like blip: pulse through vowel formants, for beep-speech and creature calls. */
export function vox(r, out, t, dur, midi, { amp = 0.2, vowel = 'a', to = null, duty = 0.3, pan = 0, send = 0.2, glide = 0, drive = 1, breath = 0 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const p = new Pulse(sr, r.rnd());
    const a = VOWELS[vowel];
    const b = VOWELS[to ?? vowel];
    const fs = a.map(() => new SVF(sr));
    const [pl, pr] = panLR(pan);
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        const k = i / n;
        const x = p.next(mtof(midi + glide * k), duty) + breath * r.noise();
        let y = 0;
        for (let j = 0; j < fs.length; j++) {
            const fc = a[j][0] + (b[j][0] - a[j][0]) * k;
            fs[j].run(x, fc, 5);
            y += fs[j].bp * (a[j][1] + (b[j][1] - a[j][1]) * k);
        }
        const e = Math.min(1, s / 0.008) * Math.min(1, (n - i) / (0.02 * sr));
        const v = Math.tanh(y * drive) * e * amp;
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

// ---------------------------------------------------------------- noise and nature

/** Filtered noise burst with a moving band: squelch, whoosh, scrape — the workhorse of the effects. */
export function noiseSweep(r, out, t, dur, { from = 400, to = 2000, q = 1, amp = 0.3, mode = 'bp', pan = 0, send = 0.2, attack = 0.005, curve = 1, grain = 0, pan1 = null } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const f = new SVF(sr);
    let g = 1;
    for (let i = 0; i < n; i++) {
        const s = i / sr;
        const k = i / n;
        f.run(r.noise(), from * (to / from) ** k, q);
        if (grain && r.rnd() < grain / sr) g = 0.2 + r.rnd() * 1.6;
        else if (grain) g += (1 - g) * 0.002;
        const e = Math.min(1, s / attack) * (1 - k) ** curve;
        const v = f[mode] * e * amp * g;
        const [pl, pr] = panLR(pan1 === null ? pan : pan + (pan1 - pan) * k);
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** Crackles: sparse random pops through a highpass, `rate` per second. */
export function crackle(r, out, t, dur, { rate = 20, amp = 0.3, tone = 1800, pan = 0, width = 0.6, send = 0.15 } = {}) {
    const sr = r.sr;
    const i0 = r.at(t);
    const n = Math.round(dur * sr);
    const hp = new SVF(sr).set(tone, 0.8);
    let c = 0;
    let p = pan;
    for (let i = 0; i < n; i++) {
        if (r.rnd() < rate / sr) {
            c += (r.rnd() * 2 - 1) * 1.6;
            p = clamp(pan + (r.rnd() * 2 - 1) * width, -1, 1);
        }
        c *= 0.9;
        hp.tick(c);
        const v = hp.hp * amp;
        const [pl, pr] = panLR(p);
        out.put(i0 + i, v * pl, v * pr, send);
    }
}

/** Wind: low-passed noise with a resonant band rising and falling, slow gusts. */
export function wind(r, out, t, dur, { amp = 0.05, fade = 2, low = 1, send = 0.3 } = {}) {
    const sr = r.sr;
    const fl = new SVF(sr);
    const fr = new SVF(sr);
    const gl = new SVF(sr);
    const gr = new SVF(sr);
    const gust = 0.13 + r.rnd() * 0.05;
    const ph = r.rnd() * TAU;
    bed(r, out, t, dur, fade, (i, o) => {
        const s = i / sr;
        const g = 0.55 + 0.45 * Math.sin(TAU * gust * s + ph) * Math.sin(TAU * gust * 0.37 * s);
        const fc = (260 + 700 * g * g) * low;
        const ll = fl.run(r.noise(), fc * 1.6, 0.6);
        const rr = fr.run(r.noise(), fc * 1.7, 0.6);
        gl.run(ll, fc, 3);
        gr.run(rr, fc * 1.04, 3);
        o[0] = (gl.bp * 1.4 + ll * 0.25) * g * amp;
        o[1] = (gr.bp * 1.4 + rr * 0.25) * g * amp;
    }, send);
}

/** Crickets: a few insects, each a pure tone pulsed in short trains at its own rate and place. */
export function crickets(r, out, t, dur, { count = 4, amp = 0.03 } = {}) {
    const sr = r.sr;
    for (let c = 0; c < count; c++) {
        const f = 3900 + r.rnd() * 1300;
        const pan = r.rnd() * 1.6 - 0.8;
        const [pl, pr] = panLR(pan);
        const period = 0.5 + r.rnd() * 0.5;
        const pulses = 2 + Math.floor(r.rnd() * 3);
        const pulseRate = 26 + r.rnd() * 12;
        const a = amp * (0.5 + r.rnd() * 0.5);
        const pd = 1 / pulseRate;
        for (let ct = t + r.rnd() * period; ct < t + dur; ct += period * (0.92 + r.rnd() * 0.16)) {
            if (r.rnd() < 0.15) continue;
            const i0 = r.at(ct);
            const n = Math.round(pulses * pd * sr);
            const w = (TAU * f) / sr;
            for (let i = 0; i < n; i++) {
                const k = ((i / sr) % pd) / pd;
                const e = Math.sin(Math.PI * Math.min(1, k * 1.6)) ** 2;
                const v = Math.sin(w * i) * e * a;
                out.put(i0 + i, v * pl, v * pr, 0.3);
            }
        }
    }
}

/** One bird phrase: a few fast whistled syllables sweeping up or down. */
export function bird(r, out, t, { amp = 0.05, pan = 0, base = 3200 } = {}) {
    const sr = r.sr;
    const [pl, pr] = panLR(pan);
    const syll = 2 + Math.floor(r.rnd() * 4);
    const shape = r.rnd();
    let st = t;
    for (let j = 0; j < syll; j++) {
        const d = 0.04 + r.rnd() * 0.07;
        const f0 = base * (0.8 + r.rnd() * 0.6);
        const f1 = f0 * (shape < 0.5 ? 1.5 + r.rnd() * 0.5 : 0.6 + r.rnd() * 0.2);
        const i0 = r.at(st);
        const n = Math.round(d * sr);
        let ph = 0;
        for (let i = 0; i < n; i++) {
            const k = i / n;
            ph += (f0 + (f1 - f0) * Math.sin((Math.PI / 2) * k) + 180 * Math.sin(TAU * 40 * k * d)) / sr;
            const v = Math.sin(TAU * ph) * Math.sin(Math.PI * k) ** 1.5 * amp;
            out.put(i0 + i, v * pl, v * pr, 0.35);
        }
        st += d + 0.02 + r.rnd() * 0.05;
    }
}

/** Campfire bed: a low roar plus crackles. */
export function fire(r, out, t, dur, { amp = 0.05, rate = 9, fade = 1.5 } = {}) {
    const sr = r.sr;
    const fl = new SVF(sr).set(260, 0.7);
    const fr = new SVF(sr).set(280, 0.7);
    bed(r, out, t, dur, fade, (i, o) => {
        const s = i / sr;
        const g = 0.8 + 0.2 * Math.sin(TAU * 0.31 * s) * Math.sin(TAU * 0.07 * s);
        o[0] = fl.tick(r.noise()) * amp * 2 * g;
        o[1] = fr.tick(r.noise()) * amp * 2 * g;
    }, 0.1);
    crackle(r, out, t, dur, { rate, amp: amp * 2.2, tone: 1500, width: 0.5 });
}

/** A low drone: two saws a fifth apart, a slowly opening filter. */
export function drone(r, out, t, dur, midi, { amp = 0.12, fifth = true, cutoff = 400, send = 0.4, fade = 2 } = {}) {
    const sr = r.sr;
    const os = [new Saw(sr, r.rnd()), new Saw(sr, r.rnd()), new Saw(sr, r.rnd())];
    const fs = [mtof(midi), mtof(midi) * 1.004, mtof(midi + (fifth ? 7 : 12))];
    const fl = new SVF(sr);
    const fr = new SVF(sr);
    let sub = 0;
    const subF = mtof(midi - 12);
    const lfo = 1 / (dur / 2);
    bed(r, out, t, dur, fade, (i, o) => {
        const s = i / sr;
        const c = cutoff * (1 + 0.6 * Math.sin(TAU * lfo * s));
        const a = os[0].next(fs[0]);
        const b = os[1].next(fs[1]);
        const c5 = os[2].next(fs[2]) * 0.5;
        sub += (TAU * subF) / sr;
        o[0] = (fl.run(a + c5, c, 1.4) + Math.sin(sub) * 0.5) * amp;
        o[1] = (fr.run(b + c5, c * 1.05, 1.4) + Math.sin(sub) * 0.5) * amp;
    }, send);
}
