// What the page plays (docs/spec/frontend.md, «Звук»): like the drawing, only from channel events and from moments
// of the server clock; a click of the visitor's own plays nothing but the HUD's and the console's buttons.

import { CLINK, CLINK_STALE_MS } from './pixel/clink.js';
import { flyAt, flyPlan } from './pixel/fly-path.js';
import { wanderAt } from './pixel/wander.js';
import { T } from './pixel/finale-timeline.js';
import { THROW, THROW_STALE_MS, throwElapsed } from './pixel/mug-throw.js';
import { FART } from './pixel/elephant.js';
import { HEAL_AT, ZBURN } from './pixel/zombie-burn.js';
import { trackOf } from './audio/engine.js';
import { SCENE, fromDoor, isBar, shownItem } from './layout.js';
import { seedOf } from './pixel/sprites.js';
import { ROLL_LAND_MS, ROLL_TUMBLE_MS, winsRoll } from './dice.js';
// A namespace import: the zombies' deaths (`DEATHS`) may not be there yet, and a missing name must not break the sound.
import * as FLYER_ART from './pixel/flyers.js';
import { pick } from './i18n.js';
import { HOOT_LEAD_MS } from './owl-speech.js';

const TEXT = pick({
    ru: { soundOn: 'Звук: вкл', soundOff: 'Звук: выкл', soundOnLabel: 'Звук включён', soundOffLabel: 'Звук выключен', musicOn: 'Музыка включена', musicOff: 'Музыка выключена' },
    en: { soundOn: 'Sound: on', soundOff: 'Sound: off', soundOnLabel: 'Sound on', soundOffLabel: 'Sound off', musicOn: 'Music on', musicOff: 'Music off' },
});
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
    fly: 'fly.buzz',
};
const SHOT_SOUNDS = { pistol: 'shot.pistol', candle: 'shot.candle', book: 'shot.book', beer: 'shot.beer', 'red-flame': 'shot.redflame', rapier: 'shot.rapira' };
// A zombie worker's death by kind (docs/spec/frontend.md, «Рапира»): the kind follows the flyer's id the way the scene
// picks it, DEATHS[crc32(flyer) % length]; the order below stands in until pixel/flyers.js exports DEATHS.
const DEATH_KINDS = ['pieces', 'meat', 'dust', 'head', 'fire', 'sink'];
// The engine's names: green fire is `burn`.
const DEATH_SOUNDS = { pieces: 'zombie.die.pieces', meat: 'zombie.die.meat', dust: 'zombie.die.dust', head: 'zombie.die.head', fire: 'zombie.die.burn', sink: 'zombie.die.sink' };
// FrankenPHP stands on the circle in the middle of the patch.
const FRANKEN_X = 50;
// Strikes come by the dozen a second: the whole scene hears one at most this often.
const STRIKE_GAP_MS = 120;
const ZOMBIFY_S = 0.3;
const DEPTH_VOLUME = [0.5, 0.75, 1];
const OTHERS_VOLUME = 0.6;
const HOOT_S = HOOT_LEAD_MS / 1000;
// The scoop stage of the day (scenario.md, «Сцена 1»): the sprites draw it at stage 3, whatever HELLOWIN_SCENE_STAGE_MAX says.
const SCOOP_STAGE = 3;
// A flyer heard later than this after its launch (a burst of held-back events) stays silent; it may still fly on screen.
const FLYER_FRESH_MS = 1500;
// A background tab returning gets held-back publications in a burst: none of them sounds for this long after it shows.
const SETTLE_MS = 600;
const STEP_S = 0.35;
const MAX_STEPS = 12;
// A scheduled part of a clink already this far behind is left out instead of played late.
const LATE_S = 0.25;
// A pour heard later than this after it began (a reload mid-pour, a held-back event) stays silent.
const POUR_FRESH_MS = 400;
// A walk in through the bar's door heard later than this after it began (a reload, a held-back event) stays silent.
const DOOR_FRESH_MS = 400;
// A roll or a meep heard later than this after its `at` stays silent, like a flyer.
const ROLL_FRESH_MS = 1500;
const MEEP_FRESH_MS = 1500;
// The wandering zombies of finale steps 5–6 (frontend.md, «Бродячие зомби»): a crowd rising at once groans once per
// RISE_GAP_MS, and the living ones mutter now and then — one groan every 6–10 s from one of them, quieter.
const RISE_GAP_MS = 400;
const AMBIENT_MS = [6000, 10000];
const AMBIENT_VOLUME = 0.4;
// The floor every scene climbs from by its progress.
const LOW_INTENSITY = 0.2;
const BROKEN_INTENSITY = 0.1;
// The fight with FrankenPHP starts half-way up: the rhythm is in from his first blow.
const BOSS_INTENSITY = 0.5;
// The finale's steps whose music follows their goals (frontend.md, «Звук», «Нарастание»).
const CURSED_STEP = 5;
const BOSS_STEP = 6;

const clamp = (x, a, b) => Math.min(b, Math.max(a, x));
/** Stereo position of a point x in percent of the width. */
export const panOf = (x) => (Number.isFinite(x) ? clamp((x - 50) / 50, -1, 1) * 0.8 : 0);

const isPumpkin = (e) => e?.type === 'pumpkin';
const lastPoint = (walk) => walk?.path?.[walk.path.length - 1] ?? null;

/**
 * x of a flyer at server time `now`, in percent: along its curve through `via`, or straight; a fly along its loops, a
 * wandering zombie along its route.
 */
function flyerX(f, now) {
    if (f.kind === 'fly') return flyAt(f.plan ??= flyPlan(f), now - f.launchedAt).x;
    if (f.wander === true) return wanderAt(f.flyer, f.from, now - f.launchedAt, f.memo ??= {}).x;
    const k = clamp((now - f.launchedAt) / Math.max(1, f.duration), 0, 1);
    const from = f.from?.x ?? 50;
    const to = f.to?.x ?? from;
    if (!f.via) return from + (to - from) * k;
    return (1 - k) ** 2 * from + 2 * k * (1 - k) * f.via.x + k * k * to;
}

/** The death of zombie flyer `id`: the scene's DEATHS when pixel/flyers.js has them (ids or {id}), else DEATH_KINDS. */
export function deathOf(id) {
    const list = Array.isArray(FLYER_ART.DEATHS) && FLYER_ART.DEATHS.length ? FLYER_ART.DEATHS : DEATH_KINDS;
    const death = list[seedOf(id) % list.length];
    return typeof death === 'string' ? death : death?.id ?? death?.kind ?? null;
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
 * @param {() => number|null} [options.owlX]  where the owl sits, percent of the width (the pan of its rolls)
 * @param {() => number|null} [options.runnerAt]  x of RoadRunner of a `meep` in percent, null when
 *   he is not on screen (scene.runnerAt)
 */
export function createSound(audio, { serverNow, now = () => performance.now(), owlGone = () => false, later = (fn, ms) => setTimeout(fn, ms), cancel = (id) => clearTimeout(id), doc = globalThis.document ?? null, owlX = () => null, runnerAt = () => null }) {
    const flyers = new Map();
    const thrown = new Map();
    let roseAt = -1e9;
    let muttering = null;
    const wanderers = () => [...flyers.values()].filter((f) => f.wander === true);
    const forgetWanderers = (ids = null) => {
        for (const f of wanderers()) if (!ids || ids.includes(f.flyer)) flyers.delete(f.flyer);
    };
    /** Keeps one groan scheduled while any wandering zombie lives; the groan comes from where a random one walks. */
    function mutter() {
        if (muttering !== null || !wanderers().length) return;
        muttering = later(() => {
            muttering = null;
            const live = wanderers();
            if (!live.length) return;
            const f = live[Math.floor(Math.random() * live.length)];
            play('zombie.groan', { pan: panOf(flyerX(f, serverNow())), volume: AMBIENT_VOLUME });
            mutter();
        }, AMBIENT_MS[0] + Math.random() * (AMBIENT_MS[1] - AMBIENT_MS[0]));
    }
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
    const music = (model) => audio.setMusic(trackOf({ scene: model.scene, sceneStages: model.sceneStages, finale: model.finale, boss: model.boss }));
    let struckAt = -1e9;
    // The rise's brass and drums dropping out as FrankenPHP steps out of the smoke (finale step 2).
    let breaking = null;
    const walkerX = (model, id) => lastPoint(model.walkers.find((w) => w.id === id)?.walk)?.x;
    const volumeOf = (model, ...ids) => (model.me !== null && ids.includes(model.me) ? 1 : OTHERS_VOLUME);
    // The line being spoken (audio.speak's handle): the next line or the bubble closing cuts it off.
    let speech = null;
    const hush = () => {
        speech?.stop?.();
        speech = null;
    };

    const share = (n, of) => (of > 0 ? clamp(n / of, 0, 1) : 0);
    const rising = (x) => LOW_INTENSITY + (1 - LOW_INTENSITY) * clamp(x, 0, 1);

    /**
     * 0.2 + 0.8 × the share of the scene passed, the whole way from its readiness: the pumpkins, the bar's mugs, the
     * circle, the cursed world's two goals; FrankenPHP's fight climbs from BOSS_INTENSITY by the health he has lost (a heal
     * brings it down again); the other finale steps from 1 on play in full.
     */
    function intensity(model) {
        const step = finalScene(model) ? (model.finale?.step ?? 0) : null;
        // The epic breaks on FrankenPHP: only the choir of the rise hangs on through step 2.
        if (step === 2) return BROKEN_INTENSITY;
        // Step 6 keeps the readiness of step 5's goals: its own measure is FrankenPHP's health.
        if (step === BOSS_STEP) {
            const b = model.boss;
            const lost = b?.max > 0 ? 1 - share(b.hp, b.max) : 0;
            return BOSS_INTENSITY + (1 - BOSS_INTENSITY) * lost;
        }
        if (model.ready) return 1;
        if (isBar(model.scene)) return rising(share(model.bar?.drunk ?? 0, model.bar?.target ?? 0));
        if (step === CURSED_STEP) {
            const c = model.cursed;
            return c ? rising((share(c.drunk, c.drinksTarget) + share(c.slain, c.zombiesTarget)) / 2) : LOW_INTENSITY;
        }
        if (step !== null && step >= 1) return 1;
        let part;
        if (finalScene(model)) {
            const circle = model.elements.find((e) => e.type === 'circle');
            part = circle?.stageMax > 0 ? circle.stage / circle.stageMax : 0;
        } else {
            const pumpkins = model.elements.filter(isPumpkin);
            const span = model.stageMax - base.stage;
            part = pumpkins.length && span > 0
                ? pumpkins.reduce((a, p) => a + clamp((p.stage - base.stage) / span, 0, 1), 0) / pumpkins.length
                : 1;
        }
        return rising(part);
    }

    function baseFor(model) {
        if (model.scene <= SCENE.day) return { scene: model.scene, stage: 0 };
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
        if (model.scene === SCENE.day) return event.stage >= SCOOP_STAGE ? 'pumpkin.scoop' : 'pumpkin.carve';
        return model.scene === SCENE.sunset ? 'pumpkin.ignite' : 'pumpkin.enchant';
    }

    function stageSound(model, element) {
        if (element.type === 'bonfire') return 'bonfire.stage';
        if (element.type === 'circle') return element.stageMax > 0 && element.stage >= element.stageMax ? 'circle.done' : 'circle.stage';
        if (!isPumpkin(element)) return null;
        return model.scene === SCENE.day ? 'pumpkin.stage' : model.scene === SCENE.sunset ? 'pumpkin.lit' : 'pumpkin.charged';
    }

    function flyer(model, event) {
        const at = serverNow();
        const wander = event.wander === true;
        const expiresAt = wander ? Infinity : event.expiresAt ?? event.launchedAt + event.duration;
        for (const [id, f] of flyers) if (at > f.expiresAt + 2000) flyers.delete(id);
        if (!(at < expiresAt)) return;
        flyers.set(event.flyer, { ...event, expiresAt });
        if (wander) mutter();
        if (at - event.launchedAt > FLYER_FRESH_MS) return;
        const delay = Math.max(0, (event.launchedAt - at) / 1000);
        const volume = DEPTH_VOLUME[event.depth] ?? 1;
        const name = FLYER_SOUNDS[event.kind];
        if (wander && now() - roseAt < RISE_GAP_MS) return;
        if (wander) roseAt = now();
        if (name) play(name, { pan: panOf(event.from?.x), volume, delay });
        const bush = event.bush && model.bushes.find((b) => b.id === event.bush);
        if (bush) play('bush.rustle', { pan: panOf(bush.x), volume, delay });
    }

    // A thrown empty mug bursts at THROW.impact after its `at`, like the scene draws it (pixel/mug-throw.js); the
    // flyer it hit falls then, and its `shot` sounds then. Kept by flyer: {player, impactAt} in server ms.
    function throwMug(model, event) {
        const id = event.player?.id;
        if (!id || !event.flyer) return;
        const at = serverNow();
        for (const [flyer, th] of thrown) if (at > th.impactAt + 2000) thrown.delete(flyer);
        const elapsed = throwElapsed(event.at, at);
        const impactAt = at - (elapsed ?? THROW_STALE_MS) + THROW.impact;
        thrown.set(event.flyer, { player: id, impactAt });
        if (elapsed === null) return;
        const f = flyers.get(event.flyer);
        const x = f && at <= f.expiresAt + 1000 ? flyerX(f, impactAt) : walkerX(model, id);
        play('mug.shatter', { pan: panOf(x), volume: volumeOf(model, id), delay: Math.max(0, (impactAt - at) / 1000) });
    }

    function shot(model, event) {
        const f = flyers.get(event.flyer);
        const at = serverNow();
        // Like the scene: a flyer this page never saw, or one long gone, falls silently.
        if (!f || at > f.expiresAt + 1000) return;
        const id = event.player?.id;
        const mug = thrown.get(event.flyer);
        const delay = mug && mug.player === id ? Math.max(0, (mug.impactAt - at) / 1000) : 0;
        const pan = panOf(flyerX(f, at + delay * 1000));
        play('flyer.hit', { pan, delay });
        // A zombie worker does not fall: it dies its own way.
        if (f.kind === 'zombie') play(DEATH_SOUNDS[deathOf(event.flyer)] ?? 'flyer.fall', { pan, delay: delay + 0.05 });
        else play('flyer.fall', { pan, delay: delay + 0.05 });
        if (f.wander === true) flyers.delete(event.flyer);
        // A thrown mug has no shot of its own: its burst is the sound.
        if (mug && mug.player === id) return;
        const walker = id && model.walkers.find((w) => w.id === id);
        const fire = walker && SHOT_SOUNDS[shownItem(walker.item, model.scene)];
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
        // Forced onto the dawn with FrankenPHP still standing: he crumbles now.
        if (step === 7 && before.bossHp > 0) play('franken.die', { pan: panOf(FRANKEN_X) });
        // The rooster and the brass as the sun starts to climb out from behind the hills.
        if (step === 7) play('dawn.rise', { delay: 1 });
    }

    function walker(model, event, before) {
        const id = event.walker?.id;
        // A newcomer walking in through the bar's door: the door creaks, if the walk has only just begun.
        const door = model.barLayout?.door;
        if (fromDoor(event.walker?.walk, door) && serverNow() - event.walker.walk.startedAt < DOOR_FRESH_MS) play('bar.door', { pan: panOf(door.x) });
        if (!id || before.walkers.has(id)) return;
        appeared.set(id, now());
        if (id === model.me) joinedNow(id);
    }

    function joinedNow(id) {
        if (joined === id) return;
        joined = id;
        play('ui.join');
    }

    // Where the counter stands (`walk.bar.counter` of the markup), for the pan of the mugs; the middle without it.
    const counterX = (model) => {
        const c = model.barLayout?.counter;
        return c && Number.isFinite(c.x1) && Number.isFinite(c.x2) ? (c.x1 + c.x2) / 2 : 50;
    };

    const handlers = {
        // The bar (docs/spec/frontend.md, «Бар и сдвиг номеров сцен»): the engine may not know these names yet, and an
        // unknown name plays nothing.
        pour(model, event) {
            const late = serverNow() - event.since;
            if (!(late < POUR_FRESH_MS)) return;
            play('bar.pour', { pan: panOf(counterX(model)), delay: Math.max(0, -late / 1000) });
        },
        mugs(model) {
            play('bar.mug', { pan: panOf(counterX(model)) });
        },
        take(model, event) {
            const id = event.player?.id;
            // A pumpkin of step 5 hands out a mug: the sound is at the pumpkin.
            if (event.pumpkin?.id) {
                const pumpkin = model.elements.find((e) => e.id === event.pumpkin.id);
                play('pumpkin.beer', { pan: panOf(pumpkin?.x ?? walkerX(model, id)), volume: volumeOf(model, id) });
                return;
            }
            play(event.ok ? 'bar.take' : 'bar.empty', { pan: panOf(walkerX(model, id)), volume: volumeOf(model, id) });
        },
        // The rapier (docs/spec/frontend.md, «Звук», «Рапира»).
        word(model, event) {
            const id = event.player?.id;
            const pan = panOf(walkerX(model, id));
            if (event.ok) {
                play('word.ok', { pan, volume: volumeOf(model, id) });
                return;
            }
            if (id && id === model.me) play('word.fail', { pan });
            play('player.zombify', { pan, volume: volumeOf(model, id), delay: ZOMBIFY_S });
        },
        cure(model, event) {
            const id = event.player?.id;
            play('player.cure', { pan: panOf(walkerX(model, id)), volume: volumeOf(model, id, event.by?.id) });
        },
        strike(model, event) {
            const id = event.player?.id;
            if (now() - struckAt >= STRIKE_GAP_MS) {
                struckAt = now();
                play('shot.rapira', { pan: panOf(walkerX(model, id)), volume: volumeOf(model, id) });
                play('franken.hit', { pan: panOf(FRANKEN_X), volume: volumeOf(model, id) });
            }
            if (event.hp === 0) play('franken.die', { pan: panOf(FRANKEN_X) });
            music(model);
            progress(model);
        },
        boss(model) {
            music(model);
            progress(model);
        },
        // The bar's mugs and the cursed world's goals move its music up.
        drinks: progress,
        cursed: progress,
        // A zombie burning out at step 6: the flash and the hissing fire where it stood, the heal's chime over FrankenPHP
        // as the wisp reaches him (zombie-burn.js, HEAL_AT); nothing once ZBURN.staleMs late.
        burn(model, event) {
            const f = flyers.get(event.flyer);
            flyers.delete(event.flyer);
            const at = Number.isFinite(event.at) ? event.at : serverNow();
            const late = serverNow() - at;
            if (!(late < ZBURN.staleMs)) return;
            play('zombie.burn', { pan: panOf(f ? flyerX(f, at) : null) });
            if (event.heal > 0) play('zombie.heal', { pan: panOf(FRANKEN_X), delay: Math.max(0, (HEAL_AT - late) / 1000) });
            progress(model);
        },
        // The dice of finale step 1: the throw, the landing (mine lands big at the top, a little later), the toll of 6-6-6.
        roll(model, event) {
            const late = serverNow() - event.at;
            if (!(late < ROLL_FRESH_MS)) return;
            const id = event.player?.id ?? null;
            const mine = id !== null && id === model.me;
            const options = { pan: panOf(event.owl === true ? owlX() : walkerX(model, id)), volume: mine ? 1 : OTHERS_VOLUME };
            play('dice.roll', options);
            // Mine starts as its event arrives, the little dice over the others at the event's `at`.
            const land = mine ? ROLL_LAND_MS / 1000 : Math.max(0, (ROLL_TUMBLE_MS - late) / 1000);
            play('dice.land', { ...options, delay: land });
            if (winsRoll(event)) play('dice.666', { delay: land });
        },
        // The moderator's click on RoadRunner: his «мип-мип», wherever he stands on screen; silent when he is not there.
        meep(model, event) {
            if (!(serverNow() - event.at < MEEP_FRESH_MS)) return;
            const x = runnerAt();
            if (x !== null && x !== undefined) play('rr.meep', { pan: panOf(x) });
        },
        // The elephant's fart, from where it flies, and the shot of whoever let it out; silent once FART.staleMs late.
        fart(model, event) {
            const at = serverNow();
            if (!(at - event.at < FART.staleMs)) return;
            const f = flyers.get(event.flyer);
            play('elephant.fart', { pan: panOf(f ? flyerX(f, at) : 50), delay: 0.08 });
            const id = event.player?.id;
            const walker = id && model.walkers.find((w) => w.id === id);
            const fire = walker && SHOT_SOUNDS[shownItem(walker.item, model.scene)];
            if (fire) play(fire, { pan: panOf(lastPoint(walker.walk)?.x), volume: volumeOf(model, id) });
        },
        drink(model, event) {
            const id = event.player?.id;
            play('bar.gulp', { pan: panOf(walkerX(model, id)), volume: volumeOf(model, id) });
        },
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
            if (breaking !== null) cancel(breaking);
            breaking = null;
            if (finalScene(model) && model.finale?.step === 2 && before.step === 1) {
                // The rise plays in full until FrankenPHP is revealed, then breaks.
                audio.setIntensity(1);
                breaking = later(() => {
                    breaking = null;
                    progress(model);
                }, T.reveal);
            } else progress(model);
        },
        reset(model, event) {
            base = { scene: 0, stage: 0 };
            forgetWanderers();
            steps.forEach(cancel);
            steps = [];
            // A new round of the evening (`again`) is a story's turn, not the console's cut: the dawn's music crossfades
            // into the bar's.
            if (event.again !== true) {
                audio.stopAll();
                play('ui.reset');
            }
            music(model);
            audio.setIntensity(0.2);
        },
        flyer,
        shot,
        throw: throwMug,
        gone(model, event) {
            forgetWanderers(Array.isArray(event.flyers) ? event.flyers : []);
        },
        move: walk,
        clink,
        walker,
    };

    return {
        /** A snapshot taken as a whole (the page's start, a resync): music and intensity, never an effect. */
        load(model) {
            base = baseFor(model);
            // The wandering zombies of the snapshot walk on silently; only their mutter goes on.
            forgetWanderers();
            const at = serverNow();
            for (const f of Array.isArray(model.flyers) ? model.flyers : []) if (f?.wander === true && f.flyer) flyers.set(f.flyer, { ...f, expiresAt: Infinity });
            for (const [id, f] of flyers) if (at > f.expiresAt + 2000) flyers.delete(id);
            mutter();
            music(model);
            progress(model);
            if (model.scene >= model.sceneStages - 1) audio.whenReady?.('finale');
        },

        /** A channel event the client has just applied; `before` — {scene, stageMax, step, walkers: Set} before it. */
        event(event, model, before) {
            // The dawn sends the wandering zombies away whether or not its `gone` arrives.
            if (event?.type === 'finale' && event.step >= 7) forgetWanderers();
            handlers[event?.type]?.(model, event, before);
        },

        /** This browser's player id: a walker of it that has just come out is its entrance. */
        me(id, model) {
            if (id && appeared.has(id) && now() - appeared.get(id) < 3000 && model.walkers.some((w) => w.id === id)) joinedNow(id);
        },

        /**
         * The owl's bubble has opened with a line; after the owl has gone, the line hangs over FrankenPHP. A number of
         * the countdown before finale step 1 (`count`) is a beat of the circle's drum, not speech.
         */
        owl({ text, hoot = false, x = null, count = false }) {
            hush();
            if (quiet()) return;
            const pan = panOf(x);
            if (count) {
                audio.play('circle.stage', { pan });
                return;
            }
            if (owlGone()) {
                speech = audio.speak('franken', text, { pan });
                return;
            }
            if (hoot) audio.play('owl.hoot', { pan });
            // The speech starts as the hoot dies away.
            speech = audio.speak('owl', text, { pan, delay: hoot ? HOOT_S : 0 });
        },

        /** The owl's bubble has closed: what is left of its line fades out. */
        owlHush() {
            hush();
        },

        /** A moment of the scene's clock (scene.onCue). */
        cue(c) {
            if (c?.type === 'owl-away') play('finale.owlAway');
            if (c?.type === 'franken' && !quiet()) audio.speak('franken', c.text, { pan: panOf(c.x) });
            if (c?.type === 'clink') play('clink.glass', { pan: panOf(c.x), volume: 0.5 });
            if (c?.type === 'owl-rapier') play('owl.rapier', { pan: panOf(c.x) });
            // RoadRunner's toast behind the bar's counter: a soft «мип» as he lifts the mug.
            if (c?.type === 'rr-toast') play('bar.meep', { pan: panOf(c.x), volume: 0.4 });
            // RoadRunner's «мип-мип» at the far end of a dash across the finale's foreground, or on the horizon with
            // its own, quieter `volume`.
            if (c?.type === 'rr-meep') play('rr.meep', { pan: panOf(c.x), volume: c.volume ?? 0.7 });
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
            if (sound.dataset.icon === undefined) sound.textContent = on ? TEXT.soundOn : TEXT.soundOff;
            else sound.setAttribute('aria-label', on ? TEXT.soundOnLabel : TEXT.soundOffLabel);
        }
        if (volume && document.activeElement !== volume) volume.value = String(Math.round((state.volume?.master ?? audio.volume('master')) * 100));
        if (music) {
            const playing = (state.volume?.music ?? audio.volume('music')) > 0;
            music.setAttribute('aria-pressed', String(playing));
            music.setAttribute('aria-label', playing ? TEXT.musicOn : TEXT.musicOff);
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
