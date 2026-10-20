import { SFX } from './synth/sfx.js';

/**
 * Audio engine: plays buffers rendered by the worker, synthesises nothing in real time.
 * Graph: sound → its bus (music / sfx / ui) → master → compressor → ceiling (−1 dBFS) → destination.
 * The AudioContext is made on the first user gesture (`unlock`, `unlockOn`); before that every call is a silent no-op.
 */

const STORAGE_KEY = 'hellowin.audio';
const BUSES = ['music', 'sfx', 'ui'];
const DEFAULT_VOLUME = { master: 1, music: 0.5, sfx: 0.5, ui: 0.4 };
// Version of the default mix: volumes saved under another one are dropped, so a retuned mix reaches everyone.
const MIX = 2;
const CEILING = 10 ** (-1 / 20);
const MAX_VOICES = 32;
const SPEAKERS = { owl: { sound: 'owl.babble', gap: 0.16 }, franken: { sound: 'franken.grumble', gap: 0.21 } };

/** Which track a scene state plays: by scene, and in the final scene by the finale step. */
export function trackOf({ scene = 0, sceneStages = 3, finale = null } = {}) {
    if (scene >= sceneStages) return (finale?.step ?? 0) >= 5 ? 'cursed' : 'blood';
    return ['day', 'dusk', 'night'][scene] ?? 'night';
}

/** The track worth rendering ahead: the one the next scene (or the end of the finale) will ask for. */
export const NEXT_TRACK = { day: 'dusk', dusk: 'night', night: 'blood', blood: 'cursed', cursed: null };

const smooth = (x, a, b) => {
    const k = Math.min(1, Math.max(0, (x - a) / (b - a)));
    return k * k * (3 - 2 * k);
};

/** Stem gains for an intensity 0..1: the base always plays, the rhythm comes in next, the lead last. */
const stemLevels = (x) => ({ base: 1, rhythm: smooth(x, 0.15, 0.5), lead: smooth(x, 0.45, 0.85) });

const curve = (up) => {
    const c = new Float32Array(64);
    for (let i = 0; i < 64; i++) c[i] = up ? Math.sin((Math.PI / 2) * (i / 63)) : Math.cos((Math.PI / 2) * (i / 63));
    return c;
};
const FADE_IN = curve(true);
const FADE_OUT = curve(false);

function loadSettings() {
    try {
        const raw = JSON.parse(globalThis.localStorage?.getItem(STORAGE_KEY) ?? 'null');
        return raw && typeof raw === 'object' ? raw : {};
    } catch {
        return {};
    }
}

function saveSettings(settings) {
    try {
        globalThis.localStorage?.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Private mode or blocked storage: the settings live for this page only.
    }
}

/**
 * Options: `context` — an AudioContext or OfflineAudioContext to use instead of making one (tests);
 * `musicRate` — divisor of the context's rate for the music: 1.5 by default (everything below ~15 kHz, two thirds of
 * the memory and render time), 2 on devices reporting ≤ 4 GB, 1 for the full rate.
 */
export function createAudio({ context = null, musicRate = (globalThis.navigator?.deviceMemory ?? 8) <= 4 ? 2 : 1.5 } = {}) {
    const settings = loadSettings();
    const volume = { ...DEFAULT_VOLUME, ...(settings.mix === MIX ? settings.volume : {}) };
    let muted = settings.muted === true;
    let ctx = null;
    let offline = false;
    let nodes = null;
    let intensity = 0.5;
    let desiredMusic = null;
    let music = null;
    let switchToken = 0;
    let utterances = 0;
    let rng = (Date.now() ^ 0x5bd1e995) >>> 0;
    const listeners = new Set();
    const sfx = new Map();
    const groups = new Map();
    const voices = [];
    const tracks = new Map();
    const pendingTracks = new Map();
    const stats = { sfx: {}, tracks: {} };

    // The engine's own jitter (variant choice, rate spread): xorshift, not Math.random, like the synth.
    const rand = () => {
        rng ^= rng << 13;
        rng ^= rng >>> 17;
        rng ^= rng << 5;
        return (rng >>> 0) / 4294967296;
    };

    const emit = () => listeners.forEach((fn) => fn(api.state()));
    const save = () => saveSettings({ mix: MIX, muted, volume });

    // ---------------------------------------------------------------- rendering

    let requestId = 0;
    const requests = new Map();
    // One worker per kind: the first track does not wait behind the effects. `false` — failed, render here.
    const workers = { sfx: null, track: null };

    function workerFor(kind) {
        if (workers[kind] !== null) return workers[kind] || null;
        try {
            const w = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
            w.onmessage = ({ data }) => requests.get(data.id)?.handle(data);
            // A module worker that fails to load reports it here: what it was asked goes to the main thread.
            w.onerror = (event) => {
                console.warn('audio worker failed, rendering on the main thread', event.message);
                w.terminate();
                workers[kind] = false;
                for (const [id, { msg, handle }] of requests) {
                    if (msg.kind !== kind) continue;
                    requests.delete(id);
                    renderHere(msg, handle).catch((error) => handle({ error: String(error?.message ?? error) }));
                }
            };
            workers[kind] = w;
        } catch (error) {
            console.warn('audio worker unavailable, rendering on the main thread', error);
            workers[kind] = false;
        }
        return workers[kind] || null;
    }

    /** Sends a request to the worker or, without one, renders on the main thread between frames. */
    function request(msg, onMessage) {
        const id = ++requestId;
        return new Promise((resolve, reject) => {
            const handle = (data) => {
                if (data.error) {
                    requests.delete(id);
                    reject(new Error(data.error));
                    return;
                }
                const done = onMessage(data);
                if (done) {
                    requests.delete(id);
                    resolve(data);
                }
            };
            const worker = workerFor(msg.kind);
            if (worker) {
                requests.set(id, { msg, handle });
                worker.postMessage({ id, ...msg });
            } else {
                renderHere(msg, handle).catch((error) => handle({ error: String(error?.message ?? error) }));
            }
        });
    }

    async function renderHere(msg, handle) {
        const pause = () => new Promise((resolve) => setTimeout(resolve, 0));
        const t0 = performance.now();
        if (msg.kind === 'track') {
            const { renderTrack } = await import('./synth/music.js');
            await pause();
            handle({ track: renderTrack(msg.name, msg.sr), ms: performance.now() - t0 });
            return;
        }
        const { renderSfx, sfxJobs } = await import('./synth/sfx.js');
        for (const [name, variant] of sfxJobs(msg.group)) {
            await pause();
            handle({ name, variant, channels: renderSfx(name, variant, msg.sr) });
        }
        handle({ done: true, ms: performance.now() - t0 });
    }

    function toBuffer(channels, sr) {
        const buffer = ctx.createBuffer(channels.length, channels[0].length, sr);
        channels.forEach((data, ch) => buffer.copyToChannel(data, ch));
        return buffer;
    }

    /** Renders a group of effects once; resolves when all its variants are playable. */
    function loadGroup(group) {
        if (!ctx) return Promise.resolve();
        if (!groups.has(group)) {
            const wall = performance.now();
            let bytes = 0;
            groups.set(group, request({ kind: 'sfx', group, sr: ctx.sampleRate }, (data) => {
                if (data.done) {
                    stats.sfx[group] = { ms: Math.round(data.ms), wall: Math.round(performance.now() - wall), bytes };
                    emit();
                    return true;
                }
                const list = sfx.get(data.name) ?? [];
                list[data.variant] = toBuffer(data.channels, ctx.sampleRate);
                bytes += data.channels.length * data.channels[0].length * 4;
                sfx.set(data.name, list);
                return false;
            }).catch((error) => console.warn('audio effects failed to render', error)));
        }
        return groups.get(group);
    }

    /** Renders a track once and keeps its stems as AudioBuffers; resolves with the track. */
    function loadTrack(name) {
        if (!ctx || !name) return Promise.resolve(null);
        if (tracks.has(name)) return Promise.resolve(tracks.get(name));
        if (!pendingTracks.has(name)) {
            const wall = performance.now();
            pendingTracks.set(name, request({ kind: 'track', name, sr: Math.round(ctx.sampleRate / musicRate) }, () => true).then(({ track, ms }) => {
                const buffers = {};
                for (const [stem, channels] of Object.entries(track.stems)) buffers[stem] = toBuffer(channels, track.sr);
                const loop = track.length / track.sr;
                const entry = { name, buffers, bars: track.bars, bar: loop / track.bars, used: 0 };
                tracks.set(name, entry);
                evictTracks();
                pendingTracks.delete(name);
                stats.tracks[name] = { ms: Math.round(ms), wall: Math.round(performance.now() - wall), seconds: +loop.toFixed(2), bytes: track.length * 4 * 2 * 3 };
                emit();
                return entry;
            }).catch((error) => {
                pendingTracks.delete(name);
                console.warn(`audio track ${name} failed to render`, error);
                return null;
            }));
        }
        return pendingTracks.get(name);
    }

    /** Keeps at most two tracks in memory (a fading one may make three): the playing, the asked-for and the one ahead go last. */
    function evictTracks() {
        if (tracks.size <= 2) return;
        const keep = new Set([music?.name, music?.prev?.name, desiredMusic, NEXT_TRACK[desiredMusic]]);
        const order = [...tracks.values()].filter((t) => !keep.has(t.name)).sort((a, b) => a.used - b.used);
        while (tracks.size > 2 && order.length) tracks.delete(order.shift().name);
    }

    // ---------------------------------------------------------------- graph

    function build() {
        const master = ctx.createGain();
        master.gain.value = muted ? 0 : volume.master;
        const limiter = ctx.createDynamicsCompressor();
        limiter.threshold.value = -6;
        limiter.knee.value = 3;
        limiter.ratio.value = 20;
        limiter.attack.value = 0.002;
        limiter.release.value = 0.15;
        // The compressor overshoots on its attack and adds make-up gain; the shaper is the hard guarantee: memoryless,
        // linear to −6 dBFS, then bending into the ceiling, and anything past ±1 maps to the ceiling.
        const ceiling = ctx.createWaveShaper();
        const n = 4097;
        const shape = new Float32Array(n);
        const knee = 0.5;
        for (let i = 0; i < n; i++) {
            const x = (i / (n - 1)) * 2 - 1;
            const a = Math.abs(x);
            const y = a <= knee ? a : knee + (CEILING - knee) * Math.tanh((a - knee) / (CEILING - knee));
            shape[i] = Math.sign(x) * y;
        }
        ceiling.curve = shape;
        master.connect(limiter).connect(ceiling).connect(ctx.destination);
        const buses = {};
        for (const name of BUSES) {
            buses[name] = ctx.createGain();
            buses[name].gain.value = volume[name];
            buses[name].connect(master);
        }
        const duck = ctx.createGain();
        duck.connect(buses.music);
        nodes = { master, limiter, ceiling, buses, duck };
    }

    // ---------------------------------------------------------------- music

    function stopSources(m, at) {
        // A timer, not src.stop(at): a stop cannot be taken back, and a fade may be cancelled by a quick switch.
        const check = () => {
            if (!m.stopping) return;
            if (ctx.currentTime < at) {
                setTimeout(check, Math.max(50, (at - ctx.currentTime) * 1000));
                return;
            }
            for (const s of Object.values(m.stems)) {
                try {
                    s.src.stop();
                } catch {
                    // Already stopped.
                }
                s.src.disconnect();
            }
            m.out.disconnect();
            if (music?.prev === m) music.prev = null;
            evictTracks();
        };
        m.stopping = true;
        setTimeout(check, Math.max(50, (at - ctx.currentTime) * 1000));
    }

    function fadeOut(m, at, dur) {
        const g = m.out.gain;
        const now = ctx.currentTime;
        const level = g.value;
        g.cancelScheduledValues(now);
        g.setValueAtTime(level, now);
        // A curve may not share its start with another event: start it a hair later when fading out right now.
        const start = Math.max(at, now + 0.01);
        g.setValueCurveAtTime(FADE_OUT.map((v) => v * level), start, dur);
        stopSources(m, start + dur + 0.05);
    }

    function startTrack(track) {
        const now = ctx.currentTime;
        // A switch still waiting for its bar is dropped; the track it was replacing keeps playing.
        if (music && music.t0 > now) {
            music.stopping = true;
            for (const s of Object.values(music.stems)) {
                try {
                    s.src.stop();
                } catch {
                    // Never started.
                }
            }
            music.out.disconnect();
            const prev = music.prev;
            music = prev ?? null;
            if (music) {
                music.stopping = false;
                music.out.gain.cancelScheduledValues(now);
                music.out.gain.setValueAtTime(1, now);
            }
        }
        if (music?.name === track.name) return;
        let at = now + 0.05;
        let fade = 1.2;
        if (music) {
            // On the playing track's next bar line, crossfading over one of its bars.
            const k = Math.ceil((now + 0.08 - music.t0) / music.bar);
            at = music.t0 + k * music.bar;
            fade = music.bar;
            fadeOut(music, at, fade);
        }
        const out = ctx.createGain();
        out.gain.setValueAtTime(0, now);
        out.gain.setValueCurveAtTime(FADE_IN, at, fade);
        out.connect(nodes.duck);
        const levels = stemLevels(intensity);
        const stems = {};
        for (const [name, buffer] of Object.entries(track.buffers)) {
            const src = ctx.createBufferSource();
            src.buffer = buffer;
            src.loop = true;
            const gain = ctx.createGain();
            gain.gain.value = levels[name];
            src.connect(gain).connect(out);
            src.start(at);
            stems[name] = { src, gain };
        }
        track.used = now;
        music = { name: track.name, t0: at, bar: track.bar, out, stems, prev: music, stopping: false };
        evictTracks();
        emit();
    }

    function applyMusic() {
        const token = ++switchToken;
        const name = desiredMusic;
        if (!name) {
            if (music && !music.stopping) {
                fadeOut(music, ctx.currentTime, Math.min(2, music.bar));
                music = null;
                emit();
            }
            return;
        }
        if (name === 'blood' || name === 'cursed') loadGroup('finale');
        loadTrack(name).then((track) => {
            if (track && token === switchToken) startTrack(track);
            const next = NEXT_TRACK[name];
            if (next) loadTrack(next);
        });
    }

    // ---------------------------------------------------------------- effects

    /**
     * Fades a voice out from `when` (not before it starts) and drops it from the count at once: a released voice
     * that stayed listed until its end would be picked again by the next limit, and the limit would stop working.
     */
    function release(voice, fadeMs = 15, when = ctx.currentTime) {
        const i = voices.indexOf(voice);
        if (i >= 0) voices.splice(i, 1);
        const t = Math.max(when, voice.start);
        voice.gain.gain.cancelScheduledValues(t);
        voice.gain.gain.setTargetAtTime(0, t, fadeMs / 3000);
        try {
            voice.src.stop(t + fadeMs / 1000 + 0.01);
        } catch {
            // Already stopped.
        }
    }

    /**
     * Makes room for a voice sounding over [at, end): of the groups overlapping it, those over `limit` are released,
     * oldest first, from `at`. A group is one voice, or all syllables of one spoken line: a line never cuts its own
     * syllables, and a line that gives way stops whole rather than stuttering.
     */
    function makeRoom(list, at, end, limit) {
        const groups = new Map();
        for (const v of list) {
            const key = v.utterance ?? v;
            if (!groups.has(key)) groups.set(key, { first: v.start, overlaps: false, voices: [] });
            const g = groups.get(key);
            g.first = Math.min(g.first, v.start);
            g.voices.push(v);
            if (v.start < end && v.end > at) g.overlaps = true;
        }
        const order = [...groups.values()].filter((g) => g.overlaps).sort((a, b) => a.first - b.first);
        while (order.length >= limit) order.shift().voices.forEach((v) => release(v, 15, at));
    }

    function playVoice(name, { pan = 0, pitch = 0, volume: vol = 1, delay = 0 } = {}, utterance = null) {
        const def = SFX[name];
        if (!def || !ctx || muted) return null;
        if (!offline && ctx.state !== 'running') return null;
        const variants = (sfx.get(name) ?? []).filter(Boolean);
        if (!variants.length) return null;

        const now = ctx.currentTime;
        for (let i = voices.length - 1; i >= 0; i--) if (voices[i].end <= now) voices.splice(i, 1);
        const at = now + Math.max(0, delay);
        const buffer = variants[Math.floor(rand() * variants.length)];
        const rate = 2 ** (pitch / 12) * (1 + (rand() * 2 - 1) * def.jitter);
        const end = at + buffer.duration / rate;
        const others = (v) => v.utterance === null || v.utterance !== utterance;
        makeRoom(voices.filter((v) => v.name === name && others(v)), at, end, def.voices);
        makeRoom(voices.filter(others), at, end, MAX_VOICES);

        const src = ctx.createBufferSource();
        src.buffer = buffer;
        src.playbackRate.value = rate;
        const gain = ctx.createGain();
        gain.gain.value = vol;
        let tail = src.connect(gain);
        if (pan && ctx.createStereoPanner) {
            const panner = ctx.createStereoPanner();
            panner.pan.value = Math.max(-1, Math.min(1, pan));
            tail = tail.connect(panner);
        }
        tail.connect(nodes.buses[def.bus]);
        const voice = { name, src, gain, start: at, end, utterance };
        voices.push(voice);
        src.onended = () => {
            const i = voices.indexOf(voice);
            if (i >= 0) voices.splice(i, 1);
            src.disconnect();
            gain.disconnect();
        };
        src.start(at);
        if (def.duck) duck(at, def.duck);
        return { stop: () => release(voice) };
    }

    function duck(at, seconds) {
        const g = nodes.duck.gain;
        g.setTargetAtTime(0.35, at, 0.06);
        g.setTargetAtTime(1, at + seconds, 0.5);
    }

    // ---------------------------------------------------------------- lifecycle

    function onVisibility() {
        if (!ctx || offline) return;
        if (document.visibilityState === 'hidden') ctx.suspend();
        else if (!muted) ctx.resume();
    }

    const api = {
        /**
         * Creates (or resumes) the AudioContext — call it inside a user gesture handler — and starts rendering the
         * effects and the asked-for track. Safe to call again.
         */
        unlock() {
            if (ctx) {
                if (!muted && !offline && ctx.state === 'suspended') ctx.resume();
                return Promise.resolve();
            }
            try {
                ctx = context ?? new (globalThis.AudioContext ?? globalThis.webkitAudioContext)({ latencyHint: 'interactive' });
            } catch (error) {
                console.warn('Web Audio unavailable', error);
                return Promise.resolve();
            }
            offline = typeof OfflineAudioContext !== 'undefined' && ctx instanceof OfflineAudioContext;
            build();
            if (!offline) {
                if (muted) ctx.suspend();
                else ctx.resume();
                document.addEventListener('visibilitychange', onVisibility);
            }
            loadGroup('core');
            if (desiredMusic) applyMusic();
            emit();
            return Promise.resolve();
        },

        /** Unlocks on the first pointer or key press on `target`. */
        unlockOn(target = globalThis.document) {
            const events = ['pointerdown', 'keydown', 'touchend'];
            const once = () => {
                events.forEach((e) => target.removeEventListener(e, once, true));
                api.unlock();
            };
            events.forEach((e) => target.addEventListener(e, once, true));
        },

        /** Resolves when a group of effects ('core', 'finale') is rendered; starts rendering it if needed. */
        whenReady(group = 'core') {
            return loadGroup(group);
        },

        /**
         * Plays an effect. `pan` −1..1, `pitch` in semitones, `volume` 0..1+, `delay` in seconds from now.
         * Returns a handle with stop(), or null when the sound is not playable yet (locked, muted, still rendering).
         */
        play(name, options = {}) {
            return playVoice(name, options);
        },

        /**
         * Beep-speech under a speech bubble: `who` — 'owl' or 'franken'; syllables follow the text's length and tone.
         * `delay` in seconds, e.g. to start after an owl.hoot. The syllables of one line never limit each other.
         */
        speak(who, text, { pan = 0, volume: vol = 1, delay = 0 } = {}) {
            const speaker = SPEAKERS[who];
            if (!speaker) return;
            const utterance = ++utterances;
            const words = String(text ?? '').trim();
            const count = Math.max(2, Math.min(9, Math.round(words.replace(/\s+/g, '').length / 7)));
            const question = words.endsWith('?');
            const shout = words.endsWith('!');
            for (let k = 0; k < count; k++) {
                const last = k >= count - 2;
                const pitch = (question && last ? 1.5 : 0) - (!question && last ? 0.8 : 0) + (rand() - 0.5) * 0.8;
                playVoice(speaker.sound, { pan, pitch, volume: vol * (shout ? 1.05 : 1), delay: Math.max(0, delay) + k * speaker.gap + rand() * 0.02 }, utterance);
            }
        },

        /** Switches the music to `name` (or silence with null) on the playing track's next bar line, ~1 bar crossfade. */
        setMusic(name) {
            if (name === desiredMusic) return;
            desiredMusic = name ?? null;
            if (ctx) applyMusic();
        },

        /** Renders a track ahead of time without playing it. */
        preload(name) {
            return loadTrack(name);
        },

        /** 0..1: 0 — only the base stem, ~0.5 — with rhythm, 1 — everything. Glides over about a second. */
        setIntensity(x) {
            intensity = Math.max(0, Math.min(1, Number(x) || 0));
            if (!ctx || !music) return;
            const levels = stemLevels(intensity);
            const now = ctx.currentTime;
            for (const m of [music, music.prev]) {
                if (!m || m.stopping) continue;
                for (const [name, s] of Object.entries(m.stems)) s.gain.gain.setTargetAtTime(levels[name], now, 0.4);
            }
        },

        /** Mutes everything and remembers it; unmuting unlocks, so call it from the button's click. */
        setMuted(on) {
            muted = Boolean(on);
            save();
            if (!ctx) {
                if (!muted) api.unlock();
                emit();
                return;
            }
            const g = nodes.master.gain;
            const now = ctx.currentTime;
            g.cancelScheduledValues(now);
            if (muted) {
                g.setTargetAtTime(0, now, 0.03);
                if (!offline) setTimeout(() => muted && ctx.suspend(), 250);
            } else {
                const up = () => g.setTargetAtTime(volume.master, ctx.currentTime, 0.05);
                if (offline) up();
                else ctx.resume().then(up);
            }
            emit();
        },

        toggleMute() {
            api.setMuted(!muted);
            return muted;
        },

        /** Bus volume 0..1 ('master', 'music', 'sfx', 'ui'), remembered. */
        setVolume(bus, value) {
            if (!(bus in DEFAULT_VOLUME)) return;
            volume[bus] = Math.max(0, Math.min(1, Number(value) || 0));
            save();
            if (nodes) {
                const node = bus === 'master' ? nodes.master : nodes.buses[bus];
                if (bus !== 'master' || !muted) node.gain.setTargetAtTime(volume[bus], ctx.currentTime, 0.05);
            }
            emit();
        },

        volume(bus) {
            return volume[bus];
        },

        /** Silences every effect at once (a reset, a page leaving); the music keeps going. */
        stopAll() {
            if (!ctx) return;
            [...voices].forEach((v) => release(v, 40));
        },

        /** {unlocked, running, muted, music, intensity, volume}. */
        state() {
            return {
                unlocked: Boolean(ctx),
                running: Boolean(ctx) && (offline || ctx.state === 'running'),
                muted,
                music: music?.name ?? null,
                intensity,
                volume: { ...volume },
            };
        },

        /** Calls fn(state) on every change of the state above; returns the unsubscribe. */
        subscribe(fn) {
            listeners.add(fn);
            return () => listeners.delete(fn);
        },

        /** Render timings and memory, for the dev page. */
        stats() {
            return JSON.parse(JSON.stringify(stats));
        },

        get context() {
            return ctx;
        },

        get output() {
            return nodes?.ceiling ?? null;
        },
    };
    return api;
}
