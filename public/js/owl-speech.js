/**
 * The owl's speech under a line: a schedule of murmured syllables, the same for the sound (audio/engine.js, `speak`)
 * and the beak on the branch (pixel/scene.js). Pure and seeded by the text, so every viewer hears and sees the same.
 * The bubble hangs far longer than this (owl.js, showMs): the owl says its line, then waits while the room reads.
 */

/**
 * How long a line is spoken: per character, within [min, max] ms. 62 ms is a brisk read-aloud (~5–6 syllables a
 * second for Russian); under 1.2 s a word of two syllables sounds like a cough, past 8 s the murmur outstays its joke.
 */
export const SPEECH = { perChar: 62, min: 1200, max: 8000 };

/** Where the speech starts after a hoot (`hoot` of the catalog), ms: as the hoot (`owl.hoot`) dies away. */
export const HOOT_LEAD_MS = 950;

/** Syllable lengths of the `owl.babble` variants, s: the variant is the syllable's length class. */
export const SYLLABLE_LENGTHS = [0.08, 0.1, 0.12, 0.14, 0.16, 0.19, 0.22, 0.26];

// A step under this is a buzz, over that a drawl: a line far off the per-character pace keeps the step in range (a
// word or two is drawn out into slow hoots, a long line is cut at SPEECH.max).
const STEP_MS = { min: 110, max: 300 };
// Pauses after a word, in syllable steps.
const PAUSE = { hyphen: 0.25, space: 0.35, comma: 1.2, stop: 2.2 };
const VOWELS = /[аеёиоуыэюяaeiouy]/giu;

function hashOf(text) {
    let h = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) h = Math.imul(h ^ text.charCodeAt(i), 0x01000193);
    return h >>> 0;
}

/** 0..1 for (seed, n): one draw per syllable and purpose, independent of how many came before. */
function draw(seed, n) {
    let h = Math.imul(seed ^ Math.imul(n + 1, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    h ^= h >>> 16;
    return (h >>> 0) / 4294967296;
}

function pauseOf(gap, last) {
    if (last) return 0;
    if (/[.!?…]/u.test(gap)) return PAUSE.stop;
    if (/[,;:]|\s[—–-]\s?/u.test(gap)) return PAUSE.comma;
    if (/\s/u.test(gap)) return PAUSE.space;
    return PAUSE.hyphen;
}

/**
 * The words of `text` with their syllables (vowels, 1–5), the pause after each, and the phrase each ends: `phrase`
 * counts the sentences and clauses, `tone` — the phrase's end ('?', '!' or '').
 */
function wordsOf(text) {
    const words = [...text.matchAll(/[\p{L}\p{N}]+/gu)];
    let phrase = 0;
    const out = words.map((m, i) => {
        const end = m.index + m[0].length;
        const gap = text.slice(end, words[i + 1]?.index ?? text.length);
        const syllables = Math.max(1, Math.min(5, (m[0].match(VOWELS) ?? []).length || Math.ceil(m[0].length / 3)));
        const word = { syllables, pause: pauseOf(gap, i === words.length - 1), phrase, gap };
        if (word.pause >= PAUSE.comma || i === words.length - 1) phrase++;
        return word;
    });
    const tones = [];
    for (const w of out) tones[w.phrase] = w.gap.includes('?') ? '?' : w.gap.includes('!') ? '!' : tones[w.phrase] ?? '';
    return out.map((w) => ({ ...w, tone: tones[w.phrase] }));
}

/**
 * The spoken length of `text`, ms, before it is laid out in syllables (SPEECH); the plan may end a little earlier.
 */
export const speechMs = (text) => Math.min(SPEECH.max, Math.max(SPEECH.min, String(text ?? '').trim().length * SPEECH.perChar));

/**
 * The owl's syllables for `text`: { ms, syllables: [{at, len, variant, pitch, volume, open}], gestures: [{at, until}] }.
 * `at`, `len`, `ms` in ms from the speech's start; `variant` — of `owl.babble` (SYLLABLE_LENGTHS); `pitch` —
 * semitones; `volume` 0..1; `open` — the beak, 1 open, 2 wide (the stressed syllable of a word); `gestures` — when the
 * wing comes up. An empty text says nothing.
 */
export function speechPlan(text) {
    const said = String(text ?? '').trim();
    const words = wordsOf(said);
    if (!words.length) return { ms: 0, syllables: [], gestures: [] };
    const seed = hashOf(said);
    const target = speechMs(said);
    const units = words.reduce((n, w) => n + w.syllables + w.pause, 0);
    const step = Math.min(STEP_MS.max, Math.max(STEP_MS.min, target / units));
    const phrases = words.reduce((n, w) => Math.max(n, w.phrase + 1), 0);
    const inPhrase = Array(phrases).fill(0);
    for (const w of words) inPhrase[w.phrase] += w.syllables;
    const seen = Array(phrases).fill(0);

    const syllables = [];
    const gestures = [];
    let t = 0;
    let n = 0;
    for (const [i, w] of words.entries()) {
        if (t >= SPEECH.max) break;
        const start = t;
        const stress = Math.floor(draw(seed, 3 * i) * w.syllables);
        for (let k = 0; k < w.syllables && t < SPEECH.max; k++, n++) {
            const pos = seen[w.phrase]++ / Math.max(1, inPhrase[w.phrase] - 1);
            const fromEnd = inPhrase[w.phrase] - seen[w.phrase];
            const stressed = k === stress;
            // Before a pause the syllable is drawn out; within a word they are clipped.
            const long = k === w.syllables - 1 && w.pause >= PAUSE.comma;
            const len = step * (long ? 1.15 : 0.7 + 0.2 * draw(seed, 7 * n + 1)) / 1000;
            const variant = SYLLABLE_LENGTHS.reduce((best, l, v) => (Math.abs(l - len) < Math.abs(SYLLABLE_LENGTHS[best] - len) ? v : best), 0);
            // Each phrase falls by two semitones; a question rises on its last two syllables, an exclamation sits higher.
            let pitch = 1 - 2 * pos + (stressed ? 1.3 : 0) + (draw(seed, 7 * n + 2) - 0.5) * 1.4;
            if (w.tone === '?' && fromEnd < 2) pitch += fromEnd === 0 ? 4 : 2.5;
            if (w.tone === '!') pitch += 1;
            const volume = (stressed ? 1 : 0.72 + 0.16 * draw(seed, 7 * n + 3)) * (w.tone === '!' ? 1 : 0.95);
            syllables.push({ at: Math.round(t), len: Math.round(SYLLABLE_LENGTHS[variant] * 1000), variant, pitch: Math.round(pitch * 100) / 100, volume: Math.round(volume * 100) / 100, open: stressed ? 2 : 1 });
            t += step;
        }
        // Now and then a word of two or more syllables gets the wing, more often in an exclamation.
        if (w.syllables >= 2 && draw(seed, 3 * i + 1) < (w.tone === '!' ? 0.35 : 0.18)) gestures.push({ at: Math.round(start), until: Math.round(t + step * 0.5) });
        t += step * w.pause;
    }
    const last = syllables.at(-1);
    return { ms: last.at + last.len, syllables, gestures };
}

/**
 * The beak at `t` ms into the speech: 0 shut, 1 open, 2 wide; the beak opens with a syllable and shuts on its last
 * quarter, so even syllables back to back read as separate.
 */
export function beakAt(plan, t) {
    for (const s of plan?.syllables ?? []) {
        if (s.at > t) break;
        if (t < s.at + s.len * 0.75) return s.open;
    }
    return 0;
}

/** Whether the wing is up at `t` ms into the speech. */
export const wingAt = (plan, t) => (plan?.gestures ?? []).some((g) => t >= g.at && t < g.until);
