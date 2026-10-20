/**
 * DSP primitives of the procedural audio: seeded PRNG, stereo buses, filters, band-limited oscillators, reverb.
 * No DOM and no Web Audio: everything renders into Float32Array, so the same code runs in a Worker and in Node.
 * The sample rate always comes from the caller (the AudioContext's rate in the browser).
 */

export const mtof = (m) => 440 * 2 ** ((m - 69) / 12);
export const clamp = (x, a, b) => (x < a ? a : x > b ? b : x);
export const dbToGain = (db) => 10 ** (db / 20);
const TAU = 2 * Math.PI;

/** mulberry32: the only randomness of the synth; the same seed gives the same sound bit for bit. */
export function mulberry32(seed) {
    let s = seed | 0;
    return () => {
        s = (s + 0x6d2b79f5) | 0;
        let t = Math.imul(s ^ (s >>> 15), 1 | s);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** FNV-1a of the parts' text: a stable seed from a sound's name and variant. */
export function hashSeed(...parts) {
    let h = 0x811c9dc5;
    for (const ch of parts.join(':')) {
        h ^= ch.charCodeAt(0);
        h = Math.imul(h, 0x01000193);
    }
    return h >>> 0;
}

/** Equal-power pan, p from -1 (left) to 1 (right): [left gain, right gain]. */
export function panLR(p) {
    const a = ((clamp(p, -1, 1) + 1) * Math.PI) / 4;
    return [Math.cos(a), Math.sin(a)];
}

/**
 * A stereo bus with a reverb send. A looping stem wraps writes past its end to the start, so a note or a reverb
 * tail crossing the loop point sounds again at the top of the next pass: the loop has no seam to fix afterwards.
 */
export class Stem {
    constructor(n, wrap = false) {
        this.n = n;
        this.wrap = wrap;
        this.L = new Float32Array(n);
        this.R = new Float32Array(n);
        this.sendL = new Float32Array(n);
        this.sendR = new Float32Array(n);
        this.wet = false;
    }

    put(i, l, r, send = 0) {
        const n = this.n;
        if (this.wrap) {
            i %= n;
            if (i < 0) i += n;
        } else if (i < 0 || i >= n) {
            return;
        }
        this.L[i] += l;
        this.R[i] += r;
        if (send) {
            this.sendL[i] += l * send;
            this.sendR[i] += r * send;
            this.wet = true;
        }
    }
}

/** One render: the rate, the length, the seeded noise, seconds → samples. */
export function createRender(sr, seconds, { seed = 1, wrap = false } = {}) {
    const rnd = mulberry32(seed);
    const n = Math.max(1, Math.round(seconds * sr));
    return {
        sr,
        n,
        wrap,
        rnd,
        noise: () => rnd() * 2 - 1,
        at: (t) => Math.round(t * sr),
        stem: () => new Stem(n, wrap),
    };
}

/** Topology-preserving state-variable filter; `run` retunes per sample, `set` + `tick` keep a fixed tuning. */
export class SVF {
    constructor(sr) {
        this.sr = sr;
        this.ic1 = 0;
        this.ic2 = 0;
        this.lp = 0;
        this.bp = 0;
        this.hp = 0;
        this.set(1000, 0.7);
    }

    set(fc, q) {
        this.g = Math.tan((Math.PI * clamp(fc, 10, this.sr * 0.45)) / this.sr);
        this.k = 1 / q;
        this.a1 = 1 / (1 + this.g * (this.g + this.k));
        return this;
    }

    tick(x) {
        const { g, a1 } = this;
        const v3 = x - this.ic2;
        const v1 = a1 * this.ic1 + g * a1 * v3;
        const v2 = this.ic2 + g * a1 * this.ic1 + g * g * a1 * v3;
        this.ic1 = 2 * v1 - this.ic1;
        this.ic2 = 2 * v2 - this.ic2;
        this.lp = v2;
        this.bp = v1;
        this.hp = x - this.k * v1 - v2;
        return v2;
    }

    run(x, fc, q) {
        this.set(fc, q);
        return this.tick(x);
    }
}

/** One-pole lowpass/DC-blocker pair for cheap smoothing. */
export class OnePole {
    constructor(sr, fc) {
        this.a = 1 - Math.exp((-TAU * fc) / sr);
        this.y = 0;
    }

    tick(x) {
        this.y += this.a * (x - this.y);
        return this.y;
    }
}

export function polyblep(t, dt) {
    if (t < dt) {
        t /= dt;
        return t + t - t * t - 1;
    }
    if (t > 1 - dt) {
        t = (t - 1) / dt;
        return t * t + t + t + 1;
    }
    return 0;
}

/** Band-limited saw (polyBLEP). */
export class Saw {
    constructor(sr, phase = 0) {
        this.sr = sr;
        this.ph = phase;
    }

    next(f) {
        const dt = f / this.sr;
        this.ph += dt;
        if (this.ph >= 1) this.ph -= 1;
        return 2 * this.ph - 1 - polyblep(this.ph, dt);
    }
}

/** Band-limited pulse with a duty cycle per sample: the chiptune square at 12.5 %, 25 % and 50 %. */
export class Pulse {
    constructor(sr, phase = 0) {
        this.sr = sr;
        this.ph = phase;
    }

    next(f, duty = 0.5) {
        const dt = f / this.sr;
        this.ph += dt;
        if (this.ph >= 1) this.ph -= 1;
        let v = this.ph < duty ? 1 : -1;
        v += polyblep(this.ph, dt);
        let t2 = this.ph - duty;
        if (t2 < 0) t2 += 1;
        v -= polyblep(t2, dt);
        // Centred, so a narrow pulse carries no DC into the mix.
        return v - (2 * duty - 1);
    }
}

/** The NES triangle: a 32-step staircase, the grit that makes a chiptune bass. */
export class StepTriangle {
    constructor(sr, phase = 0) {
        this.sr = sr;
        this.ph = phase;
    }

    next(f) {
        this.ph += f / this.sr;
        if (this.ph >= 1) this.ph -= 1;
        const step = Math.floor(this.ph * 32);
        const level = step < 16 ? 15 - step : step - 16;
        return level / 7.5 - 1;
    }
}

/**
 * NES-style noise: a 15-bit LFSR clocked at `rate` Hz; `short` taps bit 6 for the metallic 93-step loop.
 * Deterministic by construction, no PRNG needed.
 */
export class LfsrNoise {
    constructor(sr) {
        this.sr = sr;
        this.reg = 1;
        this.acc = 0;
        this.out = 1;
    }

    next(rate, short = false) {
        this.acc += rate / this.sr;
        while (this.acc >= 1) {
            this.acc -= 1;
            const bit = (this.reg ^ (this.reg >> (short ? 6 : 1))) & 1;
            this.reg = (this.reg >> 1) | (bit << 14);
            this.out = this.reg & 1 ? -1 : 1;
        }
        return this.out;
    }
}

/** A single-cycle wavetable from [harmonic, amplitude] pairs, peak-normalised. */
export function makeTable(harmonics, size = 2048) {
    const t = new Float32Array(size + 1);
    let peak = 0;
    for (let i = 0; i < size; i++) {
        let v = 0;
        for (const [h, a] of harmonics) v += Math.sin((TAU * h * i) / size) * a;
        t[i] = v;
        peak = Math.max(peak, Math.abs(v));
    }
    for (let i = 0; i < size; i++) t[i] /= peak || 1;
    t[size] = t[0];
    return t;
}

export class TableOsc {
    constructor(sr, table, phase = 0) {
        this.sr = sr;
        this.t = table;
        this.size = table.length - 1;
        this.ph = phase;
    }

    next(f) {
        this.ph += f / this.sr;
        this.ph -= Math.floor(this.ph);
        const x = this.ph * this.size;
        const i = x | 0;
        return this.t[i] + (this.t[i + 1] - this.t[i]) * (x - i);
    }
}

/**
 * Freeverb of the stem's send, mixed into its dry buses. A looping stem is primed with its own last `prime`
 * seconds first, so the tail at the top of the loop is the tail of the loop's end: a seamless reverb.
 */
export function reverb(sr, stem, { room = 0.84, damp = 0.25, input = 0.015, wet = 1, prime = 2 } = {}) {
    if (!stem.wet) return;
    const scale = sr / 44100;
    const combLen = [1557, 1617, 1491, 1422, 1277, 1356, 1188, 1116].map((x) => Math.round(x * scale));
    const apLen = [556, 441, 341, 225].map((x) => Math.round(x * scale));
    const n = stem.n;
    const primeN = stem.wrap ? Math.min(n, Math.round(prime * sr)) : 0;
    for (let ch = 0; ch < 2; ch++) {
        const spread = ch ? Math.round(23 * scale) : 0;
        const combs = combLen.map((len) => ({ buf: new Float32Array(len + spread), i: 0, f: 0 }));
        const aps = apLen.map((len) => ({ buf: new Float32Array(len + spread), i: 0 }));
        const x = ch ? stem.sendR : stem.sendL;
        const y = ch ? stem.R : stem.L;
        for (let k = -primeN; k < n; k++) {
            const v = x[k < 0 ? k + n : k] * input;
            let s = 0;
            for (let c = 0; c < 8; c++) {
                const cb = combs[c];
                const o = cb.buf[cb.i];
                cb.f = o * (1 - damp) + cb.f * damp;
                cb.buf[cb.i] = v + cb.f * room;
                if (++cb.i === cb.buf.length) cb.i = 0;
                s += o;
            }
            for (let a = 0; a < 4; a++) {
                const ap = aps[a];
                const o = ap.buf[ap.i];
                ap.buf[ap.i] = s + o * 0.5;
                if (++ap.i === ap.buf.length) ap.i = 0;
                s = o - s;
            }
            if (k >= 0) y[k] += s * wet;
        }
    }
}

/**
 * A continuous bed (wind, crickets' hiss, fire) of `dur` seconds plus `fade` of overrun with equal-power fades:
 * on a looping stem the overrun wraps over the faded-in start, an equal-power crossfade of uncorrelated noise.
 * `gen(i, out)` writes the left and right sample into `out`.
 */
export function bed(r, stem, t, dur, fade, gen, send = 0) {
    const i0 = r.at(t);
    const n = Math.round((dur + fade) * r.sr);
    const fn = Math.max(1, Math.round(fade * r.sr));
    const out = new Float64Array(2);
    for (let i = 0; i < n; i++) {
        let e = 1;
        if (i < fn) e = Math.sin((Math.PI / 2) * (i / fn));
        else if (i >= n - fn) e = Math.cos((Math.PI / 2) * ((i - (n - fn)) / fn));
        gen(i, out);
        stem.put(i0 + i, out[0] * e, out[1] * e, send);
    }
}

/** Peak and RMS over the given channels. */
export function measure(channels) {
    let peak = 0;
    let sum = 0;
    let count = 0;
    for (const ch of channels) {
        for (let i = 0; i < ch.length; i++) {
            const a = Math.abs(ch[i]);
            if (a > peak) peak = a;
            sum += ch[i] * ch[i];
        }
        count += ch.length;
    }
    return { peak, rms: Math.sqrt(sum / Math.max(1, count)) };
}

export function scale(channels, gain) {
    for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= gain;
}
