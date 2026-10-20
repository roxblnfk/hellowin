// What the page plays (docs/spec/frontend.md, «Звук»): like the drawing, only from channel events and from moments
// of the server clock; a click of the visitor's own plays nothing but the HUD's and the console's buttons.

import { CLINK, CLINK_STALE_MS } from './pixel/clink.js';
import { T } from './pixel/finale-timeline.js';
import { trackOf } from './audio/engine.js';

const FLYER_SOUNDS = {
    duck: 'duck.quack',
    chicken: 'chicken.cluck',
    bat: 'bat.squeak',
    worm: 'worm.squelch',
    firefly: 'firefly.ding',
    witch: 'witch.cackle',
    zombie: 'zombie.groan',
    roadrunner: 'runner.steps',
    crow: 'crow.caw',
    ghost: 'ghost.wail',
};
const SHOT_SOUNDS = { pistol: 'shot.pistol', candle: 'shot.candle', book: 'shot.book', beer: 'shot.beer', 'red-flame': 'shot.redflame' };
const DEPTH_VOLUME = [0.5, 0.75, 1];
const OTHERS_VOLUME = 0.6;
const HOOT_S = 0.95;
// The scoop stage of scene 0 (scenario.md, «Сцена 0»): the sprites draw it at stage 3, whatever HELLOWIN_SCENE_STAGE_MAX says.
const SCOOP_STAGE = 3;
// A flyer heard later than this after its launch (a burst of held-back events) stays silent; it may still fly on screen.
const FLYER_FRESH_MS = 1500;
// A background tab returning gets held-back publications in a burst: none of them sounds for this long after it shows.
const SETTLE_MS = 600;
const STEP_S = 0.35;
const MAX_STEPS = 12;
// A scheduled part of a clink already this far behind is left out instead of played late.
const LATE_S = 0.25;

const clamp = (x, a, b) => Math.min(b, Math.max(a, x));
/** Stereo position of a point x in percent of the width. */
export const panOf = (x) => (Number.isFinite(x) ? clamp((x - 50) / 50, -1, 1) * 0.8 : 0);

const isPumpkin = (e) => e?.type === 'pumpkin';
const lastPoint = (walk) => walk?.path?.[walk.path.length - 1] ?? null;

/** x of a flyer at server time `now`, in percent: along its curve through `via`, or straight. */
function flyerX(f, now) {
    const k = clamp((now - f.launchedAt) / Math.max(1, f.duration), 0, 1);
    const from = f.from?.x ?? 50;
    const to = f.to?.x ?? from;
    if (!f.via) return from + (to - from) * k;
    return (1 - k) ** 2 * from + 2 * k * (1 - k) * f.via.x + k * k * to;
}

/**
 * @param {object} audio  engine of public/js/audio/engine.js (or a fake with the same play / speak / setMusic /
 *   setIntensity / stopAll / whenReady)
 * @param {object} options
 * @param {() => number} options.serverNow  the server clock, ms (Date.now() + the client's offset)
 * @param {() => number} [options.now]  a monotonic clock, ms
 * @param {() => boolean} [options.owlGone]  whether the owl has flown away (its lines are then FrankenPHP's)
 * @param {(fn: Function, ms: number) => number} [options.later]  setTimeout
 * @param {Document|null} [options.doc]  for the visibility of the tab; null — always visible
 */
export function createSound(audio, { serverNow, now = () => performance.now(), owlGone = () => false, later = (fn, ms) => setTimeout(fn, ms), cancel = (id) => clearTimeout(id), doc = globalThis.document ?? null }) {
    const flyers = new Map();
    let shownAt = -1e9;
    let steps = [];
    let joined = null;
    const appeared = new Map();
    // The stage every pumpkin stood at when the scene began: the share of the scene passed counts from it.
    let base = { scene: 0, stage: 0 };

    doc?.addEventListener?.('visibilitychange', () => {
        if (doc.visibilityState === 'visible') shownAt = now();
    });
    const quiet = () => (doc ? doc.visibilityState === 'hidden' : false) || now() - shownAt < SETTLE_MS;
    const play = (name, options) => (quiet() ? null : audio.play(name, options));

    const finalScene = (model) => model.scene >= model.sceneStages;
    const music = (model) => audio.setMusic(trackOf({ scene: model.scene, sceneStages: model.sceneStages, finale: model.finale }));
    const walkerX = (model, id) => lastPoint(model.walkers.find((w) => w.id === id)?.walk)?.x;
    const volumeOf = (model, ...ids) => (model.me !== null && ids.includes(model.me) ? 1 : OTHERS_VOLUME);

    /** 0.2 + 0.8 × the share of the scene passed; the whole way from the scene's readiness and finale step 1. */
    function intensity(model) {
        if (model.ready || (finalScene(model) && (model.finale?.step ?? 0) >= 1)) return 1;
        let share;
        if (finalScene(model)) {
            const circle = model.elements.find((e) => e.type === 'circle');
            share = circle?.stageMax > 0 ? circle.stage / circle.stageMax : 0;
        } else {
            const pumpkins = model.elements.filter(isPumpkin);
            const span = model.stageMax - base.stage;
            share = pumpkins.length && span > 0
                ? pumpkins.reduce((a, p) => a + clamp((p.stage - base.stage) / span, 0, 1), 0) / pumpkins.length
                : 1;
        }
        return 0.2 + 0.8 * clamp(share, 0, 1);
    }

    function baseFor(model) {
        if (model.scene === 0) return { scene: 0, stage: 0 };
        if (base.scene === model.scene) return base;
        // A page opened mid-scene does not know where the scene began: its lowest pumpkin is the best guess.
        const stages = model.elements.filter(isPumpkin).map((p) => p.stage);
        return { scene: model.scene, stage: stages.length ? Math.min(model.stageMax, ...stages) : 0 };
    }

    function progress(model) {
        audio.setIntensity(intensity(model));
    }

    function clickSound(model, event, element) {
        if (element.type === 'bonfire') return 'bonfire.click';
        if (element.type === 'circle') return 'circle.click';
        if (!isPumpkin(element)) return null;
        if (event.clicks > event.threshold || event.stage >= model.stageMax || finalScene(model)) return 'pumpkin.burned';
        if (model.scene === 0) return event.stage >= SCOOP_STAGE ? 'pumpkin.scoop' : 'pumpkin.carve';
        return model.scene === 1 ? 'pumpkin.ignite' : 'pumpkin.enchant';
    }

    function stageSound(model, element) {
        if (element.type === 'bonfire') return 'bonfire.stage';
        if (element.type === 'circle') return element.stageMax > 0 && element.stage >= element.stageMax ? 'circle.done' : 'circle.stage';
        if (!isPumpkin(element)) return null;
        return ['pumpkin.stage', 'pumpkin.lit'][model.scene] ?? 'pumpkin.charged';
    }

    function flyer(model, event) {
        const at = serverNow();
        const expiresAt = event.expiresAt ?? event.launchedAt + event.duration;
        for (const [id, f] of flyers) if (at > f.expiresAt + 2000) flyers.delete(id);
        if (!(at < expiresAt)) return;
        flyers.set(event.flyer, { ...event, expiresAt });
        if (at - event.launchedAt > FLYER_FRESH_MS) return;
        const delay = Math.max(0, (event.launchedAt - at) / 1000);
        const volume = DEPTH_VOLUME[event.depth] ?? 1;
        const name = FLYER_SOUNDS[event.kind];
        if (name) play(name, { pan: panOf(event.from?.x), volume, delay });
        const bush = event.bush && model.bushes.find((b) => b.id === event.bush);
        if (bush) play('bush.rustle', { pan: panOf(bush.x), volume, delay });
    }

    function shot(model, event) {
        const f = flyers.get(event.flyer);
        const at = serverNow();
        // Like the scene: a flyer this page never saw, or one long gone, falls silently.
        if (!f || at > f.expiresAt + 1000) return;
        const pan = panOf(flyerX(f, at));
        play('flyer.hit', { pan });
        play('flyer.fall', { pan, delay: 0.05 });
        const id = event.player?.id;
        const walker = id && model.walkers.find((w) => w.id === id);
        const fire = walker && SHOT_SOUNDS[walker.item];
        if (fire) play(fire, { pan: panOf(lastPoint(walker.walk)?.x), volume: volumeOf(model, id) });
    }

    // My own walker's steps, on a timer rather than as delayed sounds: a newer move cancels the ones not yet heard.
    function walk(model, event) {
        steps.forEach(cancel);
        steps = [];
        if (!event.player?.id || event.player.id !== model.me) return;
        const { path, startedAt, arrivesAt } = event.walk ?? {};
        if (!path?.length || !(arrivesAt > startedAt)) return;
        const at = serverNow();
        const from = path[0].x;
        const to = path[path.length - 1].x;
        const count = Math.min(MAX_STEPS, Math.floor((arrivesAt - startedAt) / 1000 / STEP_S) + 1);
        for (let k = 0; k < count; k++) {
            const t = startedAt + k * STEP_S * 1000;
            if (t < at - 100) continue;
            const x = from + (to - from) * ((t - startedAt) / (arrivesAt - startedAt));
            steps.push(later(() => play('walk.step', { pan: panOf(x) }), Math.max(0, t - at)));
        }
    }

    function clink(model, event) {
        if (!Number.isFinite(event.at) || !event.player?.id) return;
        const at = serverNow();
        if (at - event.at > CLINK_STALE_MS) return;
        const ids = [event.player.id, event.walker?.id].filter(Boolean);
        const options = { pan: panOf(walkerX(model, event.player.id)), volume: volumeOf(model, ...ids) };
        const glass = (event.at - at + CLINK.strike) / 1000;
        const cheer = (event.at - at + CLINK.raise) / 1000;
        if (glass > -LATE_S) play('clink.glass', { ...options, delay: Math.max(0, glass) });
        if (cheer > -LATE_S) play('clink.cheer', { ...options, delay: Math.max(0, cheer) });
    }

    // The next step of the finale, as the scene animates it; a step reached any other way shows settled and is silent.
    function finale(model, before) {
        const step = model.finale?.step;
        if (!finalScene(model) || before.step === null || step !== before.step + 1) return;
        if (step === 1) play('finale.wake');
        if (step === 2) {
            T.strikes.forEach((ms) => play('finale.strike', { delay: ms / 1000 }));
            play('finale.tada', { delay: T.reveal / 1000 });
        }
        if (step === 3) {
            play('finale.run');
            play('finale.meep', { delay: T.meep / 1000 });
        }
        if (step === 4) play('finale.reveal');
    }

    function walker(model, event, before) {
        const id = event.walker?.id;
        if (!id || before.walkers.has(id)) return;
        appeared.set(id, now());
        if (id === model.me) joinedNow(id);
    }

    function joinedNow(id) {
        if (joined === id) return;
        joined = id;
        play('ui.join');
    }

    const handlers = {
        click(model, event) {
            const element = model.elements.find((e) => e.id === event.element);
            if (!element) return;
            const name = clickSound(model, event, element);
            if (name) play(name, { pan: panOf(element.x), volume: volumeOf(model, event.player?.id) });
            progress(model);
        },
        pumpkin(model, event) {
            const element = model.elements.find((e) => e.id === event.element);
            if (!element) return;
            const name = stageSound(model, element);
            if (name) play(name, { pan: panOf(element.x), volume: volumeOf(model, event.player?.id) });
            progress(model);
        },
        scene(model, event, before) {
            base = { scene: model.scene, stage: before.stageMax };
            if (before.scene < model.sceneStages && finalScene(model)) play('bonfire.explode');
            // The finale's effects render while the night is still on.
            if (model.scene >= model.sceneStages - 1) audio.whenReady?.('finale');
            music(model);
            progress(model);
        },
        ready(model) {
            if (!model.ready) return;
            play('ui.ready');
            audio.setIntensity(1);
        },
        finale(model, event, before) {
            finale(model, before);
            music(model);
            progress(model);
        },
        reset(model) {
            base = { scene: 0, stage: 0 };
            steps.forEach(cancel);
            steps = [];
            audio.stopAll();
            play('ui.reset');
            music(model);
            audio.setIntensity(0.2);
        },
        flyer,
        shot,
        move: walk,
        clink,
        walker,
        zombies(model, event) {
            if (event.on === true) play('zombie.groan');
        },
    };

    return {
        /** A snapshot taken as a whole (the page's start, a resync): music and intensity, never an effect. */
        load(model) {
            base = baseFor(model);
            music(model);
            progress(model);
            if (model.scene >= model.sceneStages - 1) audio.whenReady?.('finale');
        },

        /** A channel event the client has just applied; `before` — {scene, stageMax, step, walkers: Set} before it. */
        event(event, model, before) {
            handlers[event?.type]?.(model, event, before);
        },

        /** This browser's player id: a walker of it that has just come out is its entrance. */
        me(id, model) {
            if (id && appeared.has(id) && now() - appeared.get(id) < 3000 && model.walkers.some((w) => w.id === id)) joinedNow(id);
        },

        /** The owl's bubble has opened with a line; after the owl has gone, the line hangs over FrankenPHP. */
        owl({ text, hoot = false, x = null }) {
            if (quiet()) return;
            const pan = panOf(x);
            if (owlGone()) {
                audio.speak('franken', text, { pan });
                return;
            }
            if (hoot) audio.play('owl.hoot', { pan });
            // The speech starts as the hoot dies away.
            audio.speak('owl', text, { pan, delay: hoot ? HOOT_S : 0 });
        },

        /** A moment of the scene's clock (scene.onCue). */
        cue(c) {
            if (c?.type === 'owl-away') play('finale.owlAway');
            if (c?.type === 'franken' && !quiet()) audio.speak('franken', c.text, { pan: panOf(c.x) });
            if (c?.type === 'clink') play('clink.glass', { pan: panOf(c.x), volume: 0.5 });
        },
    };
}

/**
 * The HUD's sound switch `[data-sound-toggle]`: shown once the engine is there, its label and aria-pressed follow the
 * engine's state, a click toggles the mute (unmuting unlocks the audio, so it has to come from the click).
 */
const MUSIC_KEY = 'hellowin.audio.music';

/**
 * The sound controls inside `root`, each optional: the sound on/off button `[data-sound-toggle]` (a text button in the
 * lab's HUD, an icon in the page's corner panel), the master volume slider `[data-volume]` and the music on/off button
 * `[data-music-toggle]`. Music off is the music bus at 0; the level it had is kept to come back to. The panel
 * `[data-sound-panel]` stays hidden until the engine has loaded.
 */
export function bindSoundControls(root, audio) {
    const sound = root.querySelector('[data-sound-toggle]');
    const volume = root.querySelector('[data-volume]');
    const music = root.querySelector('[data-music-toggle]');
    const musicLevel = () => {
        try {
            const v = Number(localStorage.getItem(MUSIC_KEY));
            return v > 0 && v <= 1 ? v : 0.5;
        } catch {
            return 0.5;
        }
    };
    const paint = (state) => {
        const on = !state.muted;
        if (sound) {
            sound.setAttribute('aria-pressed', String(on));
            if (sound.dataset.icon === undefined) sound.textContent = on ? 'Звук: вкл' : 'Звук: выкл';
            else sound.setAttribute('aria-label', on ? 'Звук включён' : 'Звук выключен');
        }
        if (volume && document.activeElement !== volume) volume.value = String(Math.round((state.volume?.master ?? audio.volume('master')) * 100));
        if (music) {
            const playing = (state.volume?.music ?? audio.volume('music')) > 0;
            music.setAttribute('aria-pressed', String(playing));
            music.setAttribute('aria-label', playing ? 'Музыка включена' : 'Музыка выключена');
        }
    };
    root.querySelectorAll('[data-sound-panel], [data-sound-toggle]').forEach((el) => (el.hidden = false));
    paint(audio.state());
    audio.subscribe(paint);
    sound?.addEventListener('click', () => audio.toggleMute());
    // Dragging the slider is a gesture: it may unlock and unmute the sound.
    volume?.addEventListener('input', () => {
        const v = Number(volume.value) / 100;
        audio.setVolume('master', v);
        if (v > 0 && audio.state().muted) audio.setMuted(false);
    });
    music?.addEventListener('click', () => {
        const level = audio.volume('music');
        if (level > 0) {
            try {
                localStorage.setItem(MUSIC_KEY, String(level));
            } catch {
                // Without storage the music comes back at the default level.
            }
            audio.setVolume('music', 0);
        } else {
            audio.setVolume('music', musicLevel());
            if (audio.state().muted) audio.setMuted(false);
        }
    });
}

/** The only sound of a local click: a button of the HUD or of the moderator's console. */
export function bindUiClicks(target, audio) {
    target.addEventListener('click', (event) => {
        if (event.target.closest?.('.hud button, [data-sound-panel] button, [data-debug-panel] button')) audio.play('ui.click');
    });
}
