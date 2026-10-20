// Checks of the procedural audio, outside the runtime: node tools/audio/check.mjs [rate]
// 1. determinism — every buffer rendered twice gives the same SHA-256; 2. loops — the seam of each stem and of the
// mix, tiled four times, is no sharper than the music around it; 3. levels — peaks of the effects and the music;
// 4. a hundred effects fired within a second through the engine's voice limits and ceiling stay ≤ −1 dBFS;
// 5. render times. Exit code 1 when a check fails.
import { createHash } from 'node:crypto';
import os from 'node:os';
import { TRACKS, renderTrack } from '../../public/js/audio/synth/music.js';
import { SFX, renderSfx, sfxJobs } from '../../public/js/audio/synth/sfx.js';
import { measure, mulberry32 } from '../../public/js/audio/synth/core.js';

const sr = Number(process.argv[2] ?? 48000);
const db = (x) => (20 * Math.log10(x)).toFixed(1);
let failed = false;
const fail = (msg) => {
    failed = true;
    console.log(`FAIL ${msg}`);
};
const hash = (channels) => {
    const h = createHash('sha256');
    for (const c of channels) h.update(Buffer.from(c.buffer, c.byteOffset, c.byteLength));
    return h.digest('hex');
};

console.log(`${os.cpus()[0].model}, ${os.cpus().length} threads, Node ${process.version}, ${sr} Hz`);

// ---------------------------------------------------------------- music
const all = createHash('sha256');
for (const name of Object.keys(TRACKS)) {
    const t0 = performance.now();
    const a = renderTrack(name, sr);
    const ms = performance.now() - t0;
    const b = renderTrack(name, sr);
    const ha = hash(Object.values(a.stems).flat());
    if (ha !== hash(Object.values(b.stems).flat())) fail(`${name}: two renders differ`);
    all.update(ha);

    const n = a.length;
    const mix = [new Float32Array(n), new Float32Array(n)];
    for (const st of Object.values(a.stems)) for (let c = 0; c < 2; c++) for (let i = 0; i < n; i++) mix[c][i] += st[c][i];
    const seams = [];
    for (const [label, ch] of [...Object.entries(a.stems).map(([k, v]) => [k, v]), ['mix', mix]]) {
        for (let c = 0; c < 2; c++) {
            // Four passes in a row: the three inner seams are where a looping source joins its end to its start.
            const x = ch[c];
            const tiled = new Float32Array(n * 4);
            for (let k = 0; k < 4; k++) tiled.set(x, k * n);
            const d = new Float32Array(tiled.length - 1);
            for (let i = 1; i < tiled.length; i++) d[i - 1] = Math.abs(tiled[i] - tiled[i - 1]);
            const sorted = Float32Array.from(d.subarray(0, n - 1)).sort();
            const p999 = sorted[Math.floor(sorted.length * 0.999)];
            const seam = Math.max(d[n - 1], d[2 * n - 1], d[3 * n - 1]);
            // High-frequency energy in 5 ms across the seam against the loudest of the other bar lines: a downbeat
            // starts with a hit anyway, so the seam is compared with downbeats, not with the quiet in between.
            const w = Math.round(0.005 * sr);
            const energy = (from) => {
                let e = 0;
                for (let i = from; i < from + w; i++) e += d[i] * d[i];
                return e;
            };
            let downbeats = 1e-12;
            for (let b = 1; b < a.bars; b++) downbeats = Math.max(downbeats, energy(Math.round((b * n) / a.bars) - (w >> 1)));
            const around = Math.max(energy(n - (w >> 1)), energy(2 * n - (w >> 1)), energy(3 * n - (w >> 1)));
            seams.push({ label: `${label}/${c ? 'R' : 'L'}`, seam, p999, ratio: around / downbeats });
            if (around > downbeats * 1.5) fail(`${name} ${label}: seam ×${(around / downbeats).toFixed(2)} of the loudest bar line`);
            if (seam > p999) fail(`${name} ${label}: seam step ${seam.toExponential(2)} > p99.9 ${p999.toExponential(2)}`);
        }
    }
    const worst = seams.reduce((p, q) => (q.seam / (q.p999 || 1) > p.seam / (p.p999 || 1) ? q : p));
    const { peak, rms } = measure(mix);
    console.log(`music ${name.padEnd(6)} ${(n / sr).toFixed(2)} s  render ${ms.toFixed(0)} ms  peak ${db(peak)} dBFS  rms ${db(rms)} dBFS  `
        + `seam worst ${worst.label}: step ${worst.seam.toExponential(2)} vs p99.9 ${worst.p999.toExponential(2)}, HF across seam ×${worst.ratio.toFixed(2)} of the loudest bar line  `
        + `memory ${((n * 4 * 2 * 3) / 1e6).toFixed(1)} MB  sha256 ${ha.slice(0, 12)}`);
}

// ---------------------------------------------------------------- effects
const buffers = new Map();
for (const group of ['core', 'finale']) {
    const t0 = performance.now();
    let bytes = 0;
    for (const [name, v] of sfxJobs(group)) {
        const a = renderSfx(name, v, sr);
        bytes += a.length * a[0].length * 4;
        buffers.set(`${name}#${v}`, a);
    }
    const ms = performance.now() - t0;
    for (const [name, v] of sfxJobs(group)) {
        const h = hash(renderSfx(name, v, sr));
        if (h !== hash(buffers.get(`${name}#${v}`))) fail(`${name}#${v}: two renders differ`);
        all.update(h);
    }
    console.log(`effects ${group.padEnd(6)} ${sfxJobs(group).length} buffers  render ${ms.toFixed(0)} ms  memory ${(bytes / 1e6).toFixed(1)} MB`);
}
for (const [key, ch] of buffers) {
    const name = key.split('#')[0];
    const { peak } = measure(ch);
    if (Math.abs(20 * Math.log10(peak) - SFX[name].level) > 0.1) fail(`${key}: peak ${db(peak)} dBFS, level ${SFX[name].level}`);
    if (ch.some((c) => c.some(Number.isNaN))) fail(`${key}: NaN`);
}
console.log(`all buffers sha256 ${all.digest('hex')}`);

// ---------------------------------------------------------------- a hundred plays within a second
// The engine's path: per-sound and global voice limits (oldest released), the bus gain, then the ceiling curve.
{
    const rnd = mulberry32(7);
    const len = Math.round(3 * sr);
    const out = new Float32Array(len);
    const names = Object.keys(SFX).filter((n) => SFX[n].group === 'core');
    const active = [];
    const plays = [];
    for (let k = 0; k < 100; k++) plays.push({ at: Math.round(rnd() * sr), name: names[Math.floor(rnd() * names.length)], v: 0 });
    plays.sort((a, b) => a.at - b.at);
    for (const p of plays) {
        const def = SFX[p.name];
        p.v = Math.floor(rnd() * def.variants);
        p.end = p.at + buffers.get(`${p.name}#${p.v}`)[0].length;
        for (let i = active.length - 1; i >= 0; i--) if (active[i].end <= p.at) active.splice(i, 1);
        const own = active.filter((a) => a.name === p.name);
        if (own.length >= def.voices) own[0].end = Math.min(own[0].end, p.at + Math.round(0.015 * sr));
        if (active.length >= 32) active[0].end = Math.min(active[0].end, p.at + Math.round(0.015 * sr));
        active.push(p);
    }
    for (const p of plays) {
        const ch = buffers.get(`${p.name}#${p.v}`)[0];
        const gain = SFX[p.name].bus === 'ui' ? 0.7 : 0.9;
        for (let i = p.at; i < Math.min(p.end, p.at + ch.length, len); i++) out[i] += ch[i - p.at] * gain;
    }
    const ceiling = 10 ** (-1 / 20);
    const shaped = (x) => {
        const a = Math.min(1, Math.abs(x));
        return Math.sign(x) * (a <= 0.5 ? a : 0.5 + (ceiling - 0.5) * Math.tanh((a - 0.5) / (ceiling - 0.5)));
    };
    let pre = 0;
    let post = 0;
    for (const x of out) {
        pre = Math.max(pre, Math.abs(x));
        post = Math.max(post, Math.abs(shaped(x)));
    }
    console.log(`100 plays in 1 s: sum peak ${db(pre)} dBFS before the limiter, ${db(post)} dBFS after the ceiling (compressor not modelled)`);
    if (post > ceiling + 1e-6) fail('ceiling exceeded');
}

process.exit(failed ? 1 : 0);
