// Voice limits of the engine against a recording fake AudioContext: node tools/audio/voices.mjs
// Each source's start and stop are logged; a source sounds if it is never stopped or stopped after it starts.
// Exit code 1 when a check fails.

class Param {
    constructor(v = 1) {
        this.value = v;
    }

    setValueAtTime() {}

    setTargetAtTime() {}

    cancelScheduledValues() {}

    setValueCurveAtTime() {}
}

class FakeNode {
    connect(n) {
        return n;
    }

    disconnect() {}
}

const sources = [];

// The engine treats an OfflineAudioContext as headless (no resume, no visibility hooks).
globalThis.OfflineAudioContext = class extends FakeNode {
    constructor() {
        super();
        this.sampleRate = 16000;
        this.currentTime = 0;
        this.destination = new FakeNode();
        this.state = 'suspended';
    }

    createGain() {
        return Object.assign(new FakeNode(), { gain: new Param() });
    }

    createStereoPanner() {
        return Object.assign(new FakeNode(), { pan: new Param(0) });
    }

    createDynamicsCompressor() {
        const n = new FakeNode();
        for (const k of ['threshold', 'knee', 'ratio', 'attack', 'release']) n[k] = new Param(0);
        return n;
    }

    createWaveShaper() {
        return new FakeNode();
    }

    createBuffer(channels, length, sampleRate) {
        return { numberOfChannels: channels, length, sampleRate, duration: length / sampleRate, copyToChannel() {} };
    }

    createBufferSource() {
        const src = Object.assign(new FakeNode(), {
            playbackRate: new Param(1),
            startAt: null,
            stopAt: null,
            start(t) {
                this.startAt = t;
            },
            stop(t = 0) {
                this.stopAt = t;
            },
        });
        sources.push(src);
        return src;
    }
};

// No Worker in Node: the engine renders on the main thread, and says so once per kind.
console.warn = () => {};
const { createAudio } = await import('../../public/js/audio/engine.js');
const { SFX } = await import('../../public/js/audio/synth/sfx.js');

const ctx = new OfflineAudioContext();
const audio = createAudio({ context: ctx });
await audio.unlock();
await audio.whenReady('core');

let failed = false;
const sounding = (list) => list.filter((s) => s.stopAt === null || s.stopAt > s.startAt + 0.05);
function check(label, run, expect) {
    sources.length = 0;
    run();
    const got = sounding(sources).length;
    const ok = expect(got, sources.length);
    if (!ok) failed = true;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}: ${got} of ${sources.length} sound`);
    audio.stopAll();
    ctx.currentTime += 10;
}

// Spaced out by more than the sound's length, none overlaps: all of them must sound.
const carve = SFX['pumpkin.carve'];
check(`${carve.voices * 3} carves one after another`, () => {
    for (let k = 0; k < carve.voices * 3; k++) audio.play('pumpkin.carve', { delay: k * (carve.dur + 0.05) });
}, (got, all) => got === all);

// All at once: only the limit may sound at the end, the newest ones.
check(`${carve.voices * 3} carves at once`, () => {
    for (let k = 0; k < carve.voices * 3; k++) audio.play('pumpkin.carve');
}, (got) => got === carve.voices);

// A line of beep-speech: every syllable sounds.
check('a long owl line', () => audio.speak('owl', 'RoadRunner мёртв… Ритуал на тыквах вернёт его, если постараться!'),
    (got, all) => got === all && all >= 10);

// The same burst scheduled ahead: voices not started yet count too.
check(`${carve.voices * 3} carves at once, half a second ahead`, () => {
    for (let k = 0; k < carve.voices * 3; k++) audio.play('pumpkin.carve', { delay: 0.5 });
}, (got) => got === carve.voices);

check('speech after a hoot, with delay', () => {
    audio.play('owl.hoot');
    audio.speak('owl', 'Вот он! Он возвращается!', { delay: 0.9 });
}, (got, all) => got === all && sources.slice(1).every((s) => s.startAt >= 0.9));

// Two lines at once fit the babble's limit of two; a third cuts the oldest line only.
check('three owl lines at once', () => {
    for (let k = 0; k < 3; k++) audio.speak('owl', 'Что это? Что мы… ЧТО ВЫ НАДЕЛАЛИ?');
}, (got, all) => got === (all / 3) * 2);

process.exit(failed ? 1 : 0);
