// Offline mix of a cutscene's sound from the game's own synth (public/js/audio/synth): cues → stereo Float32 at sr.
// Cue kinds:
//   { sfx, at, variant?, pan?, pitch?, vol? }                 one sound, as engine.js playVoice
//   { speak: 'owl' | 'franken', text, at, pan?, vol?, pitch? } beep-speech, as engine.js speak (no hoot: add an sfx cue)
//   { music, from, to, intensity: number | [[t, x], …], fadeIn?, fadeOut? }  a looping track, stems by intensity
import { writeFileSync } from 'node:fs';
import { SFX, renderSfx } from '../../public/js/audio/synth/sfx.js';
import { renderTrack } from '../../public/js/audio/synth/music.js';
import { speechPlan } from '../../public/js/owl-speech.js';

const BUS = { music: 0.5, sfx: 0.5, ui: 0.4 };            // engine.js DEFAULT_VOLUME
const CEILING = 10 ** (-1 / 20);

const smooth = (x, a, b) => { const k = Math.min(1, Math.max(0, (x - a) / (b - a))); return k * k * (3 - 2 * k); };
const stemLevels = (x) => ({ base: 1, rhythm: smooth(x, 0.15, 0.5), lead: smooth(x, 0.45, 0.85) });

export function mix(cues, duration, sr = 48000) {
    const n = Math.ceil(duration * sr);
    const L = new Float32Array(n), R = new Float32Array(n);
    const music = [new Float32Array(n), new Float32Array(n)];
    const cache = new Map();
    const buffer = (name, variant) => {
        const key = `${name}|${variant}`;
        if (!cache.has(key)) cache.set(key, renderSfx(name, variant, sr));
        return cache.get(key);
    };
    const ducks = [];
    let auto = 0;

    function voice(name, { at, variant = null, pan = 0, pitch = 0, vol = 1 }) {
        const def = SFX[name];
        if (!def) throw new Error(`unknown sound ${name}`);
        const v = (variant ?? auto++) % def.variants;
        const ch = buffer(name, v);
        const rate = 2 ** (pitch / 12);
        const gain = vol * BUS[def.bus];
        const p = Math.max(-1, Math.min(1, pan));
        // StereoPanner: equal power for mono; for stereo the far side folds into the near one.
        const a = ((p + 1) * Math.PI) / 4;
        const start = Math.round(at * sr);
        const len = Math.floor((ch[0].length - 1) / rate);
        for (let i = 0; i < len; i++) {
            const o = start + i;
            if (o < 0 || o >= n) continue;
            const s = i * rate, k = Math.floor(s), f = s - k;
            const l = ch[0][k] + (ch[0][k + 1] - ch[0][k]) * f;
            if (ch.length === 1) { L[o] += l * gain * Math.cos(a); R[o] += l * gain * Math.sin(a); }
            else {
                const r = ch[1][k] + (ch[1][k + 1] - ch[1][k]) * f;
                if (p <= 0) { L[o] += (l + r * -p) * gain; R[o] += r * (1 + p) * gain; }
                else { L[o] += l * (1 - p) * gain; R[o] += (r + l * p) * gain; }
            }
        }
        if (def.duck) ducks.push([at, at + def.duck]);
    }

    for (const c of cues) {
        if (c.sfx) voice(c.sfx, c);
        else if (c.speak === 'owl') {
            for (const s of speechPlan(c.text).syllables) {
                voice('owl.babble', { at: c.at + s.at / 1000, variant: s.variant, pan: c.pan ?? 0, pitch: s.pitch + (c.pitch ?? 0), vol: (c.vol ?? 1) * s.volume });
            }
        } else if (c.speak === 'franken') {
            const words = String(c.text).trim();
            const count = Math.max(2, Math.min(9, Math.round(words.replace(/\s+/g, '').length / 7)));
            for (let k = 0; k < count; k++) {
                const last = k >= count - 2;
                const pitch = (words.endsWith('?') && last ? 1.5 : 0) - (!words.endsWith('?') && last ? 0.8 : 0) + Math.sin(k * 12.9) * 0.4 + (c.pitch ?? 0);
                voice('franken.grumble', { at: c.at + k * 0.21, pan: c.pan ?? 0, pitch, vol: c.vol ?? 1 });
            }
        }
    }

    for (const c of cues.filter((x) => x.music)) {
        const tr = renderTrack(c.music, sr);
        const from = Math.round(c.from * sr), to = Math.min(n, Math.round((c.to ?? duration) * sr));
        const level = (t) => {
            if (typeof c.intensity === 'number') return c.intensity;
            const keys = c.intensity;
            if (t <= keys[0][0]) return keys[0][1];
            for (let i = 1; i < keys.length; i++) if (t <= keys[i][0]) {
                const [t0, x0] = keys[i - 1], [t1, x1] = keys[i];
                return x0 + (x1 - x0) * ((t - t0) / (t1 - t0));
            }
            return keys.at(-1)[1];
        };
        for (let o = from; o < to; o++) {
            const t = o / sr;
            const lv = stemLevels(level(t));
            const fade = Math.min(1, (t - c.from) / (c.fadeIn ?? 0.01), ((c.to ?? duration) - t) / (c.fadeOut ?? 0.01));
            const i = (o - from) % tr.length;
            for (const key of ['base', 'rhythm', 'lead']) {
                const g = lv[key] * fade * (c.vol ?? 1) * BUS.music;
                music[0][o] += tr.stems[key][0][i] * g;
                music[1][o] += tr.stems[key][1][i] * g;
            }
        }
    }

    // The music ducks under the big effects (engine.js duck): down to 0.35 fast, back over ~0.5 s.
    let g = 1;
    const down = 1 - Math.exp(-1 / (0.06 * sr)), up = 1 - Math.exp(-1 / (0.5 * sr));
    for (let o = 0; o < n; o++) {
        const t = o / sr;
        const target = ducks.some(([a, b]) => t >= a && t < b) ? 0.35 : 1;
        g += (target - g) * (target < g ? down : up);
        L[o] += music[0][o] * g;
        R[o] += music[1][o] * g;
    }

    // Loudness for a video: bring the peak to −1 dBFS through the engine's soft ceiling.
    let peak = 0;
    for (let o = 0; o < n; o++) peak = Math.max(peak, Math.abs(L[o]), Math.abs(R[o]));
    const gain = peak > 0 ? 1.15 / peak : 1;
    const knee = 0.5;
    const shape = (x) => { const a = Math.abs(x); return Math.sign(x) * (a <= knee ? a : knee + (CEILING - knee) * Math.tanh((a - knee) / (CEILING - knee))); };
    for (let o = 0; o < n; o++) { L[o] = shape(L[o] * gain); R[o] = shape(R[o] * gain); }
    return [L, R];
}

export function writeWav(path, [L, R], sr = 48000) {
    const n = L.length;
    const buf = Buffer.alloc(44 + n * 4);
    buf.write('RIFF', 0); buf.writeUInt32LE(36 + n * 4, 4); buf.write('WAVE', 8);
    buf.write('fmt ', 12); buf.writeUInt32LE(16, 16); buf.writeUInt16LE(1, 20); buf.writeUInt16LE(2, 22);
    buf.writeUInt32LE(sr, 24); buf.writeUInt32LE(sr * 4, 28); buf.writeUInt16LE(4, 32); buf.writeUInt16LE(16, 34);
    buf.write('data', 36); buf.writeUInt32LE(n * 4, 40);
    for (let i = 0; i < n; i++) {
        buf.writeInt16LE(Math.round(Math.max(-1, Math.min(1, L[i])) * 32767), 44 + i * 4);
        buf.writeInt16LE(Math.round(Math.max(-1, Math.min(1, R[i])) * 32767), 46 + i * 4);
    }
    writeFileSync(path, buf);
}
