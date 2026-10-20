import { createRender, measure, reverb, scale } from './core.js';
import {
    bass, bird, chip, chipHat, chipKick, chipSnare, choir, crickets, drone, fire, heart, musicbox, organ, pad, pizz,
    theremin, toll, tom, tri, wind, xylo,
} from './instruments.js';

/**
 * Scene music: each track is a loop of exactly `bars` bars in three stems of the same length — base, rhythm, lead —
 * that the engine starts together and fades in and out by intensity. Notes are [beat, midi, beats]; a null midi
 * is a rest. Everything past the loop's end wraps to its start (see Stem), so the loop is seamless by construction.
 */

const STEMS = ['base', 'rhythm', 'lead'];

// The loudness of the full mix (all stems up) after normalisation; the engine's music bus sits under the effects.
const TARGET_RMS = 0.09;
const TARGET_PEAK = 0.5;

/** Melody helper: plays bar-relative [beat, midi, beats] notes through `fn(t, midi, seconds)`. */
function melody(T, beat, bars, fn) {
    bars.forEach((notes, bar) => {
        for (const [b, m, d] of notes) if (m !== null) fn(T(bar, b), m, d * beat);
    });
}

// ---------------------------------------------------------------- scene 0: «День: подготовка»
// Chiptune morning in F major: a music-box pulse tune over a bouncing triangle bass, birds all round. One borrowed
// D-flat chord in the middle and a chromatic slip keep it a little wicked under the sunshine.
const DAY = {
    bpm: 104,
    beats: 4,
    bars: 8,
    render({ r, T, beat, base, rhythm, lead }) {
        const roots = [41, 38, 34, 36, 41, 37, 43, 36];
        const fifths = [48, 45, 41, 43, 48, 44, 50, 43];
        const chords = [[65, 69, 72], [62, 65, 69], [62, 65, 70], [64, 67, 72], [65, 69, 72], [65, 68, 73], [62, 67, 70], [64, 67, 70, 72]];
        const pads = [[53, 57, 60, 65], [50, 57, 62, 65], [53, 58, 62, 65], [52, 55, 60, 64], [53, 57, 60, 65], [53, 56, 61, 65], [50, 55, 58, 62], [52, 55, 58, 64]];
        for (let bar = 0; bar < 8; bar++) {
            const bassLine = [roots[bar], fifths[bar], roots[bar] + 12, fifths[bar]];
            bassLine.forEach((m, k) => tri(r, base, T(bar, k), beat * 0.6, m, { amp: 0.32, slide: k === 2 ? -1 : 0 }));
            pad(r, base, T(bar, 0), beat * 4, pads[bar], { amp: 0.025, cutoff: 1100, attack: 0.4, send: 0.5 });
            // Off-beat chord stabs: the chip's one-channel chord, an arpeggio at 50 Hz.
            for (const b of [1.5, 3.5]) {
                const c = chords[bar];
                chip(r, rhythm, T(bar, b), beat * 0.35, c[0], { duty: 0.125, amp: 0.1, arp: c.map((m) => m - c[0]), decay: 6, pan: -0.3, send: 0.2 });
            }
            chipKick(r, rhythm, T(bar, 0), { amp: 0.5 });
            chipKick(r, rhythm, T(bar, 2), { amp: 0.45 });
            if (bar % 2) chipKick(r, rhythm, T(bar, 2.5), { amp: 0.3 });
            chipSnare(r, rhythm, T(bar, 1), { amp: 0.16, pan: 0.1 });
            chipSnare(r, rhythm, T(bar, 3), { amp: 0.16, pan: 0.1 });
            for (let k = 0; k < 8; k++) chipHat(r, rhythm, T(bar, k / 2), { amp: k % 2 ? 0.07 : 0.035, pan: 0.35 });
        }
        chipSnare(r, rhythm, T(7, 3.5), { amp: 0.12, pan: -0.2 });

        const tune = [
            [[0, 84, 0.5], [0.5, 81, 0.5], [1, 77, 1], [2, 81, 0.5], [2.5, 84, 0.5], [3, 86, 1]],
            [[0, 84, 1.5], [1.5, 81, 0.5], [2, 77, 0.5], [2.5, 74, 0.5], [3, 77, 1]],
            [[0, 82, 0.5], [0.5, 81, 0.5], [1, 79, 0.5], [1.5, 77, 0.5], [2, 74, 1], [3, 77, 1]],
            [[0, 79, 1.5], [1.5, 76, 0.5], [2, 72, 1], [3, 73, 0.5], [3.5, 74, 0.5]],
            [[0, 84, 0.5], [0.5, 81, 0.5], [1, 77, 1], [2, 81, 0.5], [2.5, 84, 0.5], [3, 86, 1]],
            [[0, 85, 0.5], [0.5, 84, 0.5], [1, 80, 1], [2, 77, 0.5], [2.5, 80, 0.5], [3, 85, 1]],
            [[0, 82, 1], [1, 79, 0.5], [1.5, 82, 0.5], [2, 86, 1], [3, 84, 0.5], [3.5, 82, 0.5]],
            [[0, 81, 0.5], [0.5, 79, 0.5], [1, 76, 0.5], [1.5, 79, 0.5], [2, 76, 1], [3, 72, 1]],
        ];
        melody(T, beat, tune, (t, m, d) => {
            chip(r, lead, t, d * 0.9, m, { duty: 0.125, amp: 0.13, decay: 3, vib: 0.15, vibDelay: 0.2, send: 0.25, pan: 0.15 });
            musicbox(r, lead, t, m + 12, { amp: 0.05, pan: -0.25, send: 0.45 });
        });

        // Birds: phrases spread over the loop, louder in the trees on the left.
        for (let k = 0; k < 16; k++) {
            bird(r, base, (k + r.rnd() * 0.8) * (8 * 4 * beat) / 16, { amp: 0.012 + r.rnd() * 0.02, pan: r.rnd() * 1.6 - 1, base: 2600 + r.rnd() * 1800 });
        }
    },
};

// ---------------------------------------------------------------- scene 1: «Закат: зажигание»
// Sunset in A minor, slower: a warm pad and crickets, a rippling 25 % pulse arpeggio, and an ocarina-like square
// singing a waiting tune with a dotted-eighth echo. The last bar's E7 hangs the loop on a question.
const DUSK = {
    bpm: 84,
    beats: 4,
    bars: 8,
    render({ r, T, beat, bar: barLen, base, rhythm, lead }) {
        const roots = [33, 29, 33, 31, 33, 29, 38, 28];
        const pads = [[57, 60, 64, 67, 71], [53, 57, 60, 64], [57, 60, 64, 67, 71], [55, 59, 62, 64], [57, 60, 64, 69], [53, 57, 60, 64], [50, 57, 60, 64, 65], [52, 56, 59, 62]];
        const arps = [[69, 72, 76, 79], [65, 69, 72, 76], [69, 72, 76, 79], [67, 71, 74, 76], [69, 72, 76, 81], [65, 69, 72, 77], [62, 65, 69, 72], [64, 68, 71, 74]];
        for (let bar = 0; bar < 8; bar++) {
            pad(r, base, T(bar, 0), barLen, pads[bar], { amp: 0.045, cutoff: 1300, attack: 1.2, release: 1.2, send: 0.6, detune: 0.09 });
            tri(r, base, T(bar, 0), beat * 2.8, roots[bar] + 12, { amp: 0.28 });
            tri(r, base, T(bar, 3), beat * 0.9, roots[bar] + 19, { amp: 0.22 });
            const a = arps[bar];
            const order = [0, 1, 2, 3, 2, 1];
            for (let k = 0; k < 16; k++) {
                chip(r, rhythm, T(bar, k / 4), beat * 0.22, a[order[k % order.length]], { duty: 0.25, amp: 0.05 + (k % 4 === 0 ? 0.02 : 0), decay: 10, pan: Math.sin(k * 0.7) * 0.5, send: 0.4 });
            }
            chipKick(r, rhythm, T(bar, 0), { amp: 0.4 });
            chipKick(r, rhythm, T(bar, 2.5), { amp: 0.3 });
            for (let k = 0; k < 4; k++) chipHat(r, rhythm, T(bar, k + 0.5), { amp: 0.04, pan: -0.3 });
        }
        crickets(r, base, 0, 8 * barLen, { count: 5, amp: 0.012 });
        wind(r, base, 0, 8 * barLen, { amp: 0.02, fade: 2, low: 0.7 });

        const tune = [
            [[0, 76, 2], [2, 74, 1], [3, 72, 1]],
            [[0, 69, 3], [3, 72, 1]],
            [[0, 76, 1.5], [1.5, 79, 0.5], [2, 81, 2]],
            [[0, 79, 2], [2, 74, 2]],
            [[0, 76, 2], [2, 77, 1], [3, 76, 1]],
            [[0, 72, 3], [3, 69, 1]],
            [[0, 74, 1], [1, 77, 1], [2, 81, 1], [3, 79, 1]],
            [[0, 80, 3], [3, 76, 1]],
        ];
        melody(T, beat, tune, (t, m, d) => {
            chip(r, lead, t, d * 0.92, m, { duty: 0.5, amp: 0.1, vib: 0.2, vibDelay: 0.25, slide: -0.6, send: 0.5 });
            chip(r, lead, t + beat * 0.75, d * 0.8, m, { duty: 0.5, amp: 0.035, vib: 0.2, vibDelay: 0.25, pan: 0.6, send: 0.6 });
        });
    },
};

// ---------------------------------------------------------------- scene 2: «Ночь: заклинание»
// A witches' waltz in D harmonic minor, 3/4: drawbar organ in three voices — held chords, a pedal, and a flute line in
// contrary motion — under a theremin that glides like green smoke. The campfire crackles under it all.
const NIGHT = {
    bpm: 132,
    beats: 3,
    bars: 16,
    render({ r, T, beat, bar: barLen, base, rhythm, lead }) {
        const roots = [38, 38, 31, 33, 38, 34, 31, 33, 38, 36, 34, 33, 31, 38, 40, 33];
        const chords = [[62, 65, 69], [62, 65, 69], [62, 67, 70], [61, 64, 67, 69], [62, 65, 69], [62, 65, 70], [62, 64, 67, 70], [61, 64, 67, 70], [62, 65, 69], [60, 62, 65, 69], [62, 65, 70], [61, 64, 69], [62, 67, 70], [62, 65, 69], [61, 64, 67, 70], [61, 64, 67, 69]];
        for (let bar = 0; bar < 16; bar++) {
            const c = chords[bar];
            organ(r, base, T(bar, 0), barLen * 0.98, c.map((m) => m - 12), { amp: 0.05, stops: 'full', attack: 0.04, release: 0.3, send: 0.6 });
            organ(r, base, T(bar, 0), barLen * 0.98, [roots[bar] - 12], { amp: 0.06, stops: 'flute', attack: 0.05, release: 0.3, send: 0.3 });
            pizz(r, rhythm, T(bar, 0), roots[bar], { amp: 0.42, bright: 0.25, dur: 0.8, send: 0.2 });
            for (const b of [1, 2]) organ(r, rhythm, T(bar, b), beat * 0.3, c, { amp: 0.045, stops: 'reed', attack: 0.004, release: 0.06, send: 0.35, pan: b === 1 ? -0.3 : 0.3 });
            tom(r, rhythm, T(bar, 0), { amp: bar % 4 === 3 ? 0.35 : 0.22, tone: 70, send: 0.4 });
            if (bar % 4 === 3) tom(r, rhythm, T(bar, 2.5), { amp: 0.15, tone: 95, pan: 0.3 });
            // The flute's counter-line: broken chord in eighths, climbing when the theremin falls.
            const up = bar % 2 === 0;
            const line = [c[0], c[1], c[2], c[0] + 12, c[2], c[1]].map((m) => m + 12);
            (up ? line : [...line].reverse()).forEach((m, k) => organ(r, lead, T(bar, k / 2), beat * 0.45, [m], { amp: 0.03, stops: 'flute', attack: 0.01, release: 0.05, send: 0.5, pan: -0.4 }));
        }
        fire(r, base, 0, 16 * barLen, { amp: 0.018, rate: 6 });

        const tune = [
            [[0, 81, 2], [2, 82, 1]], [[0, 81, 3]], [[0, 79, 1], [1, 82, 1], [2, 86, 1]], [[0, 85, 3]],
            [[0, 86, 1.5], [1.5, 81, 1.5]], [[0, 82, 2], [2, 77, 1]], [[0, 79, 1], [1, 76, 1], [2, 79, 1]], [[0, 81, 3]],
            [[0, 74, 1], [1, 77, 1], [2, 81, 1]], [[0, 86, 3]], [[0, 82, 1], [1, 81, 1], [2, 79, 1]], [[0, 77, 1.5], [1.5, 76, 1.5]],
            [[0, 79, 2], [2, 82, 1]], [[0, 81, 2], [2, 77, 1]], [[0, 76, 1], [1, 79, 1], [2, 82, 1]], [[0, 85, 2], [2, null, 1]],
        ];
        const notes = [];
        tune.forEach((bar, k) => bar.forEach(([b, m, d]) => notes.push([T(k, b), m, d * beat])));
        theremin(r, lead, notes, { amp: 0.11, pan: 0.2, send: 0.7, glide: 0.09 });
    },
};

// ---------------------------------------------------------------- scene 3: «Кровавая луна»
// Four-part choir in C-sharp Phrygian over a low drone: chords that lean on the flat second (D) and end on G-sharp
// major, so the loop pulls back into its own minor. A heartbeat under it; a soprano and a tolling bell on top.
const BLOOD = {
    bpm: 72,
    beats: 4,
    bars: 8,
    render({ r, T, beat, bar: barLen, base, rhythm, lead }) {
        const satb = [[49, 56, 61, 64], [50, 57, 62, 66], [49, 56, 61, 64], [49, 57, 61, 64], [54, 57, 61, 66], [50, 57, 62, 66], [56, 61, 63, 68], [56, 60, 63, 68]];
        drone(r, base, 0, 8 * barLen, 37, { amp: 0.06, cutoff: 260, fade: 2.5 });
        for (let bar = 0; bar < 8; bar++) {
            choir(r, base, T(bar, 0), barLen, satb[bar], { amp: 0.11, vowel: bar % 2 ? 'o' : 'a', attack: 0.9, release: 1.4, send: 0.8 });
            for (const b of [0, 2]) heart(r, rhythm, T(bar, b), { amp: 0.5 });
            if (bar % 2 === 1) tom(r, rhythm, T(bar, 3.5), { amp: 0.25, tone: 55, send: 0.6 });
        }
        for (let bar = 0; bar < 8; bar++) for (let k = 0; k < 4; k++) chipHat(r, rhythm, T(bar, k + 0.75), { amp: 0.02, pan: k % 2 ? 0.6 : -0.6 });

        const tune = [
            [[0, 68, 2], [2, 69, 2]], [[0, 69, 3], [3, 66, 1]], [[0, 68, 4]], [[0, 64, 2], [2, 66, 2]],
            [[0, 69, 2], [2, 68, 1], [3, 66, 1]], [[0, 66, 2], [2, 69, 2]], [[0, 68, 2], [2, 72, 2]], [[0, 73, 2], [2, 72, 2]],
        ];
        melody(T, beat, tune, (t, m, d) => choir(r, lead, t, d, [m + 12], { amp: 0.09, vowel: 'u', attack: 0.25, release: 0.6, singers: 2, spread: 0.2, send: 0.9 }));
        toll(r, lead, T(0, 0), 61, { amp: 0.09, dur: 7, send: 0.8 });
        toll(r, lead, T(4, 0), 56, { amp: 0.08, dur: 7, pan: 0.3, send: 0.8 });
    },
};

// ---------------------------------------------------------------- cursed world: the tavern
// Danse macabre as a chiptune polka in G minor: xylophone bones and a pulse twin on the tune, a triangle tuba on the
// «oom» and arpeggiated pulse chords on the «pah», a stomping noise kit. Spooky, but everyone has a beer.
const CURSED = {
    bpm: 168,
    beats: 4,
    bars: 16,
    render({ r, T, beat, base, rhythm, lead }) {
        const chords = [[67, 70, 74], [66, 69, 72], [67, 70, 74], [66, 69, 72], [67, 70, 74], [67, 72, 75], [66, 69, 72], [67, 70, 74], [65, 70, 74], [65, 69, 72, 75], [65, 70, 74], [67, 70, 74], [67, 72, 75], [67, 70, 74], [66, 69, 72], [66, 69, 72]];
        const tuba = [[43, 38], [38, 33], [43, 38], [38, 33], [43, 38], [36, 31], [38, 33], [43, 38], [34, 41], [41, 36], [34, 41], [43, 38], [36, 31], [43, 38], [38, 33], [38, 45]];
        for (let bar = 0; bar < 16; bar++) {
            const c = chords[bar];
            tri(r, base, T(bar, 0), beat * 0.7, tuba[bar][0], { amp: 0.38, slide: -2 });
            tri(r, base, T(bar, 2), beat * 0.7, tuba[bar][1], { amp: 0.34, slide: -2 });
            for (const b of [1, 3]) chip(r, base, T(bar, b), beat * 0.45, c[0] - 12, { duty: 0.25, amp: 0.07, arp: c.map((m) => m - c[0]), decay: 5, send: 0.2, pan: b === 1 ? -0.25 : 0.25 });
            chipKick(r, rhythm, T(bar, 0), { amp: 0.5 });
            chipKick(r, rhythm, T(bar, 2), { amp: 0.42 });
            chipSnare(r, rhythm, T(bar, 1), { amp: 0.18, decay: 22 });
            chipSnare(r, rhythm, T(bar, 3), { amp: 0.2, decay: 22 });
            for (let k = 0; k < 8; k++) chipHat(r, rhythm, T(bar, k / 2), { amp: k % 2 ? 0.06 : 0.03, open: k === 7 && bar % 2 === 1, pan: 0.4 });
            if (bar % 4 === 3) for (const b of [3, 3.25, 3.5, 3.75]) chipSnare(r, rhythm, T(bar, b), { amp: 0.1, decay: 30, pan: -0.3 });
        }

        const tune = [
            [[0, 79, 0.5], [0.5, 82, 0.5], [1, 86, 0.5], [1.5, 82, 0.5], [2, 79, 0.5], [2.5, 82, 0.5], [3, 86, 1]],
            [[0, 84, 0.5], [0.5, 81, 0.5], [1, 78, 0.5], [1.5, 81, 0.5], [2, 84, 1], [3, 81, 1]],
            [[0, 82, 0.5], [0.5, 79, 0.5], [1, 74, 0.5], [1.5, 79, 0.5], [2, 82, 0.5], [2.5, 86, 0.5], [3, 91, 1]],
            [[0, 90, 0.5], [0.5, 86, 0.5], [1, 84, 0.5], [1.5, 81, 0.5], [2, 78, 1], [3, 74, 1]],
            [[0, 79, 0.5], [0.5, 82, 0.5], [1, 86, 0.5], [1.5, 82, 0.5], [2, 79, 0.5], [2.5, 82, 0.5], [3, 86, 1]],
            [[0, 84, 0.5], [0.5, 87, 0.5], [1, 91, 0.5], [1.5, 87, 0.5], [2, 84, 0.5], [2.5, 79, 0.5], [3, 75, 1]],
            [[0, 74, 0.5], [0.5, 78, 0.5], [1, 81, 0.5], [1.5, 84, 0.5], [2, 86, 0.5], [2.5, 84, 0.5], [3, 81, 0.5], [3.5, 78, 0.5]],
            [[0, 79, 1], [1, 74, 0.5], [1.5, 79, 0.5], [2, 79, 1]],
            [[0, 82, 0.5], [0.5, 86, 0.5], [1, 89, 0.5], [1.5, 86, 0.5], [2, 82, 1], [3, 77, 1]],
            [[0, 81, 0.5], [0.5, 84, 0.5], [1, 87, 0.5], [1.5, 84, 0.5], [2, 81, 1], [3, 77, 1]],
            [[0, 86, 0.5], [0.5, 84, 0.5], [1, 82, 0.5], [1.5, 81, 0.5], [2, 82, 1], [3, 86, 1]],
            [[0, 91, 1.5], [1.5, 86, 0.5], [2, 82, 1], [3, 79, 1]],
            [[0, 84, 0.5], [0.5, 84, 0.5], [1, 87, 1], [2, 84, 0.5], [2.5, 84, 0.5], [3, 79, 1]],
            [[0, 82, 0.5], [0.5, 82, 0.5], [1, 86, 1], [2, 82, 0.5], [2.5, 79, 0.5], [3, 74, 1]],
            [[0, 78, 0.5], [0.5, 81, 0.5], [1, 84, 0.5], [1.5, 86, 0.5], [2, 87, 1], [3, 86, 0.5], [3.5, 84, 0.5]],
            [[0, 82, 0.5], [0.5, 81, 0.5], [1, 78, 0.5], [1.5, 81, 0.5], [2, 79, 1], [3, 74, 0.5], [3.5, 78, 0.5]],
        ];
        melody(T, beat, tune, (t, m, d) => {
            xylo(r, lead, t, m, { amp: 0.16, pan: 0.2 });
            chip(r, lead, t, Math.min(d, 0.5) * 0.8, m - 12, { duty: 0.125, amp: 0.06, decay: 5, pan: -0.2, send: 0.15 });
        });
    },
};

export const TRACKS = { day: DAY, dusk: DUSK, night: NIGHT, blood: BLOOD, cursed: CURSED };

/** Seconds of one bar and of the loop. */
export function timing(name) {
    const def = TRACKS[name];
    const beat = 60 / def.bpm;
    return { beat, bar: beat * def.beats, loop: beat * def.beats * def.bars, bars: def.bars, beats: def.beats, bpm: def.bpm };
}

/**
 * Renders a track: {name, sr, bars, bpm, beats, length (samples), stems: {base, rhythm, lead} as [L, R]}.
 * The loop's length is rounded to whole samples; the engine takes the bar from the buffer's duration.
 */
export function renderTrack(name, sr, { seed = 1 } = {}) {
    const def = TRACKS[name];
    if (!def) throw new Error(`unknown track ${name}`);
    const tm = timing(name);
    const r = createRender(sr, tm.loop, { seed, wrap: true });
    const stems = { base: r.stem(), rhythm: r.stem(), lead: r.stem() };
    const T = (bar, beat = 0) => (bar * def.beats + beat) * tm.beat;
    def.render({ r, T, beat: tm.beat, bar: tm.bar, ...stems });
    for (const key of STEMS) reverb(sr, stems[key], { prime: 2.5 });

    const mix = [new Float32Array(r.n), new Float32Array(r.n)];
    for (const key of STEMS) {
        for (let i = 0; i < r.n; i++) {
            mix[0][i] += stems[key].L[i];
            mix[1][i] += stems[key].R[i];
        }
    }
    const { peak, rms } = measure(mix);
    const gain = Math.min(TARGET_RMS / (rms || 1), TARGET_PEAK / (peak || 1));
    const out = {};
    for (const key of STEMS) {
        scale([stems[key].L, stems[key].R], gain);
        out[key] = [stems[key].L, stems[key].R];
    }
    return { name, sr, bars: def.bars, bpm: def.bpm, beats: def.beats, length: r.n, stems: out };
}
