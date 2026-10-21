// Client model of docs/spec/frontend.md: applies channel events to the DOM hooks and to the pixel scene.
// Shared by app.js (live channel) and public/dev/lab.html (generated events).

import { SCENE, bonfireOpen, circleOpen, floats, isBar, isPumpkin, jumpOut, jumpedOut, spreadX } from './layout.js';
import { REPEAT_WAIT_MS, ROLL_STALE_MS, createDicePanel, createRoller, diceNote, diceOf, facesOf } from './dice.js';
import { createDawnTable, dawnOf } from './dawn.js';

const SCENE_NAMES = { [SCENE.bar]: 'Бар', [SCENE.day]: 'День: подготовка', [SCENE.sunset]: 'Закат', [SCENE.night]: 'Ночь' };
const DEFAULT_STAGE_MAX = 4;
// The counter outside the bar (protocol.md, `bar` in the snapshot); `drunk` and `target` — the hall's goal.
const NO_BAR = Object.freeze({ mugs: 0, max: 0, pouring: null, drunk: 0, target: 0, roadrunner: false });
// The finale steps of docs/spec/scenario.md, «Рапира»: the cursed world with its goal (5), the owl's rapier and the boss
// (6, still the cursed world), the dawn (7).
const CURSED_STEP = 5;
const RAPIER_STEP = 6;
const DAWN_STEP = 7;
// From this finale step the HUD's connection line says who answers on the line (scenario.md, «Финал», step 3).
const RUNNER_STEP = 3;
// The finale step the hall rolls the dice on (scenario.md, «Кости 6-6-6»).
const DICE_STEP = 1;
// Up to this width of #app the dawn's table is a phone's folded strip (docs/spec/frontend.md, «Топ рассвета»).
const DAWN_COMPACT_PX = 600;
// A `meep` older than this by its `at` is not drawn (docs/spec/frontend.md, «Пульт модератора», «Мип-мип»).
const MEEP_STALE_MS = 1500;
const OWL_TRY = 'Дайте я попробую!';
// The HUD's connection line; through the evening the other side seems to answer.
const STATUS = { connecting: 'подключение…', connected: 'связь с потусторонним: есть', disconnected: 'нет связи' };

/** Uptime as «Ч ч ММ мин» (scenario.md, «Финал»): hours unbounded, minutes two digits. */
export function formatUptime(ms) {
    const minutes = Math.max(0, Math.floor(ms / 60000));
    return `${Math.floor(minutes / 60)} ч ${String(minutes % 60).padStart(2, '0')} мин`;
}

const int = (value, fallback = 0) => {
    const n = Number.parseInt(value, 10);
    return Number.isNaN(n) ? fallback : n;
};

function parseList(json) {
    try {
        const list = JSON.parse(json || '[]');
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

function parseFinale(json) {
    try {
        const finale = JSON.parse(json || 'null');
        return finale && Number.isInteger(finale.step) ? finale : null;
    } catch {
        return null;
    }
}

/** `bar` of a snapshot or an event as the model keeps it; anything else is the empty counter. */
export function barOf(bar) {
    if (!bar || !Number.isInteger(bar.mugs)) return { ...NO_BAR };
    const count = (n) => (Number.isInteger(n) && n >= 0 ? n : 0);
    // `max` — how many mugs the counter is set for (0 — not told); the counter's slots cap the drawing anyway.
    // `roadrunner` — RoadRunner stands behind the counter (after the dawn's «В бар», until the moderator's reset).
    return { mugs: bar.mugs, max: count(bar.max), pouring: bar.pouring ?? null, drunk: count(bar.drunk), target: count(bar.target), roadrunner: bar.roadrunner === true };
}

/** «Выпито 12 из 30» and how full the meter is, 0–1 (full once the goal is reached). */
export function drinksOf(bar) {
    const target = bar?.target ?? 0;
    const drunk = bar?.drunk ?? 0;
    return { text: `Выпито ${drunk} из ${target}`, fill: target > 0 ? Math.min(1, drunk / target) : 0 };
}

const count = (n, fallback = 0) => (Number.isInteger(n) && n >= 0 ? n : fallback);

/** `cursed` of a snapshot or an event (the goal of finale step 5), or null. */
export function cursedOf(cursed) {
    if (!cursed || typeof cursed !== 'object') return null;
    return { drunk: count(cursed.drunk), slain: count(cursed.slain), drinksTarget: count(cursed.drinksTarget), zombiesTarget: count(cursed.zombiesTarget) };
}

/** `boss` of a snapshot or an event — FrankenPHP's {hp, max} once awake — or null. */
export function bossOf(boss) {
    if (!boss || !Number.isInteger(boss.hp)) return null;
    const hp = Math.max(0, boss.hp);
    return { hp, max: Math.max(hp, count(boss.max)) };
}

/** The two meters of step 5: «Выпито 7 из 20» and «Зомби 3 из 20», each with how full it is, 0–1. */
export function goalsOf(cursed) {
    const meter = (label, n, target) => ({ n, target, text: `${label} ${n} из ${target}`, fill: target > 0 ? Math.min(1, n / target) : 0 });
    return {
        drinks: meter('Выпито', cursed?.drunk ?? 0, cursed?.drinksTarget ?? 0),
        zombies: meter('Зомби', cursed?.slain ?? 0, cursed?.zombiesTarget ?? 0),
    };
}

function parseJson(json, fallback) {
    try {
        return JSON.parse(json || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

const flag = (value) => value === '1' || value === 'true';

const num = (value, fallback) => {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? n : fallback;
};

/**
 * Snapshot as rendered by the home template: #app attributes plus one button per element.
 */
export function readSnapshot(root) {
    return {
        round: int(root.dataset.round, 1),
        scene: int(document.body.dataset.scene),
        sceneStages: int(root.dataset.sceneStages, 1),
        stageMax: int(root.dataset.stageMax, DEFAULT_STAGE_MAX),
        threshold: int(root.dataset.threshold, 1),
        k: num(root.dataset.k, 1),
        online: int(root.dataset.online, 0),
        bushes: parseList(root.dataset.bushes),
        walkers: parseList(root.dataset.walkers),
        bar: barOf(parseJson(root.dataset.bar, null)),
        // `walk.bar` of the server's config (protocol.md, «Бар»): the hall's floor, tables and counter for the art.
        barLayout: parseJson(root.dataset.barLayout, null),
        now: int(root.dataset.now, 0),
        startedAt: int(root.dataset.startedAt, 0),
        ready: flag(root.dataset.ready),
        finale: parseFinale(root.dataset.finale),
        paused: flag(root.dataset.paused),
        zombies: flag(root.dataset.zombies),
        cursed: cursedOf(parseJson(root.dataset.cursed, null)),
        boss: bossOf(parseJson(root.dataset.boss, null)),
        dice: diceOf(parseJson(root.dataset.dice, null)),
        dawn: dawnOf(parseJson(root.dataset.dawn, null)),
        elements: [...root.querySelectorAll('[data-element]')].map((el) => {
            const element = {
                id: el.dataset.element,
                type: el.dataset.type,
                x: Number.parseFloat(el.style.getPropertyValue('--x')),
                y: Number.parseFloat(el.style.getPropertyValue('--y')),
                stage: int(el.dataset.stage),
                clicks: int(el.dataset.clicks),
            };
            // The bonfire and the circle have no size, but their own threshold and last stage (protocol.md, «Снимок»).
            if (!isPumpkin(element)) {
                return { ...element, threshold: int(el.dataset.threshold, 1), stageMax: int(el.dataset.stageMax) };
            }
            return { ...element, size: el.dataset.size || 'm', reachable: el.hasAttribute('data-reachable') };
        }),
    };
}

/**
 * @param {HTMLElement} root  #app
 * @param {object|null} scene API of pixel/scene.js, or null without a canvas
 * @param {object} options
 * @param {() => Promise<object>} options.requestState  resolves with a fresh snapshot (RPC `state`)
 * @param {() => Promise<number>} [options.requestTime]  resolves with the server's clock in ms (RPC `time`)
 * @param {{say: Function}|null} [options.owl]  speech bubble of owl.js
 * @param {{update: Function}|null} [options.reveal]  reveal panel of reveal.js
 * @param {(model: object) => void} [options.onChange]  after every applied event or snapshot (the console repaints)
 * @param {{load: Function, event: Function, me: Function}|null} [options.sound]  sound.js: plays what the events say
 * @param {((attempt: number) => Promise<object|undefined>)|null} [options.roll]  RPC `roll {attempt}`, resolving with the reply
 */
export function createClient(root, scene, { requestState, requestTime = () => requestState().then((s) => s.now), now = () => Date.now(), owl = null, reveal = null, onChange = () => {}, sound = null, roll = null }) {
    // `clinked`: whether I have clinked with the beer in my hand since taking it — only a hint for the «Выпить» button;
    // the server decides (protocol.md, RPC `drink`). `owlRolls` — the owl's rolls of the dice seen in this round.
    const model = { ...readSnapshot(root), me: null, moderator: false, clinked: false, owlRolls: 0 };
    // stageMax per scene as far as the client has seen it: a `reset` event returns to scene 0 without one.
    const stageMaxByScene = new Map([[model.scene, model.stageMax]]);
    const bonfireOf = () => model.elements.find((e) => e.type === 'bonfire') ?? null;
    const circleOf = () => model.elements.find((e) => e.type === 'circle') ?? null;
    const pumpkinsOf = () => model.elements.filter(isPumpkin);
    // The bonfire's last stage per scene, likewise (HELLOWIN_BONFIRE_STAGE_MAX: scene 0 has none by default).
    const bonfireMaxByScene = new Map(bonfireOf() ? [[model.scene, bonfireOf().stageMax]] : []);
    let syncing = null;
    // Server clock minus ours. The snapshot's `now` is only a first guess: it was stamped when the page was rendered,
    // so delivery and script loading (or a page restored from the back-forward cache) leave it behind, and every
    // viewer would see the same broadcast flyer at a different moment. syncClock() measures it properly.
    let offset = 0;
    let measured = false;
    const takeTime = (serverTime) => {
        if (serverTime > 0 && !measured) offset = serverTime - now();
    };
    const CLOCK_SAMPLES = 5;
    let clocking = null;
    takeTime(model.now);
    scene?.setServerClock(() => now() + offset);

    const hud = (name) => root.querySelector(`[data-hud-${name}]`);
    const button = (id) => root.querySelector(`[data-element="${CSS.escape(id)}"]`);
    const findElement = (id) => model.elements.find((e) => e.id === id);
    /** The finale step, or null outside the final scene. */
    const step = () => (model.scene >= model.sceneStages ? model.finale?.step ?? null : null);
    // My word was answered: the form stays closed until the `word` (or `cure`) event about me says what came of it.
    let wordClosed = false;
    // My dice (docs/spec/frontend.md, «Кости»): the attempts of RPC `roll`, my roll drawn big, the attempt whose event
    // has come (a reply without one is a repeat).
    const roller = createRoller((attempt) => (roll ? roll(attempt) : Promise.resolve(null)));
    const reduced = () => Boolean(globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
    const dicePanel = createDicePanel(root, { now, reduced });
    const dawnTable = createDawnTable(root, { reduced });
    let seenAttempt = 0;
    let diceLoop = false;

    function paintElement(element) {
        const el = button(element.id);
        if (!el) return;
        el.dataset.stage = element.stage;
        el.dataset.clicks = element.clicks;
        if (!isPumpkin(element)) {
            // The bonfire opens once every pumpkin is done, the circle at finale step 0 (layout.js); a type without a
            // rule stays locked. A locked click is not even sent (app.js).
            const open = element.type === 'bonfire' ? bonfireOpen(element, model.stageMax, model.elements, model.scene, model.sceneStages)
                : element.type === 'circle' ? circleOpen(element, model.scene, model.sceneStages, model.finale)
                : false;
            el.dataset.threshold = element.threshold;
            el.dataset.stageMax = element.stageMax;
            el.toggleAttribute('data-locked', !open);
            el.setAttribute('aria-disabled', String(!open));
            el.toggleAttribute('data-ready', element.clicks >= element.threshold && element.stage < element.stageMax);
            el.toggleAttribute('data-done', element.stageMax > 0 && element.stage >= element.stageMax);
            return;
        }
        el.toggleAttribute('data-reachable', element.reachable === true);
        el.toggleAttribute('data-ready', element.clicks >= model.threshold && element.stage < model.stageMax);
        el.toggleAttribute('data-done', element.stage >= model.stageMax);
        el.toggleAttribute('data-float', floats(element.stage, model.scene, model.sceneStages, model.finale?.step));
    }

    // The jump out (layout.js): its factor depends on the width, so a resize repaints it too.
    function paintOut() {
        const factor = jumpOut(root.clientWidth || window.innerWidth);
        root.style.setProperty('--out', String(jumpedOut(model.scene, model.stageMax, model.elements) ? factor : 1));
    }

    // Layout and lock follow every pumpkin at once: the jump out (--out) and the bonfire opening.
    function paintElements() {
        model.elements.forEach(paintElement);
        paintOut();
        paintCircle();
    }

    // The magic circle shows on the pit only in the final scene, drawn as far as its stage has come.
    function paintCircle() {
        const circle = circleOf();
        const shown = circle && model.scene >= model.sceneStages && circle.stageMax > 0;
        scene?.circle(shown ? circle.stage / circle.stageMax : 0);
    }

    function paintHud() {
        const set = (name, value) => {
            const node = hud(name);
            if (node) node.textContent = value;
        };
        set('round', model.round);
        set('scene', model.scene);
        set('scene-name', sceneName());
        set('done', pumpkinsOf().filter((e) => e.stage >= model.stageMax).length);
        set('total', pumpkinsOf().length);
        // The bar has no pumpkins: its meter of mugs drunk stands in for «готово N из M»; step 5 has its two goals.
        const progress = hud('progress');
        if (progress) progress.hidden = isBar(model.scene) || step() === CURSED_STEP;
        const ready = hud('ready');
        // In the final scene `ready` stays on through the finale; it waits for the moderator at step 0 (the closed
        // circle), at step 1 (the 6-6-6 rolled) and at step 5 (the cursed world's goals met).
        if (ready) ready.hidden = !model.ready || (model.scene >= model.sceneStages && step() !== 0 && step() !== DICE_STEP && step() !== CURSED_STEP);
    }

    function sceneName() {
        if (model.scene < model.sceneStages) return SCENE_NAMES[model.scene] ?? '';
        if (step() >= DAWN_STEP) return 'Рассвет';
        return step() >= CURSED_STEP ? 'Проклятый мир' : 'Финал';
    }

    // Story state shared by the HUD, the console and CSS: body attributes plus the reveal panel.
    function paintStory() {
        const body = document.body;
        body.toggleAttribute('data-ready', model.ready);
        if (model.finale) body.dataset.finaleStep = model.finale.step;
        else delete body.dataset.finaleStep;
        body.toggleAttribute('data-paused', model.paused);
        body.toggleAttribute('data-zombies', model.zombies);
        reveal?.update(model.finale, uptime());
        paintStatus();
        paintHud();
    }

    // Since RoadRunner started, on the server clock (protocol.md, `startedAt`); null when the server did not say.
    const uptime = () => (model.startedAt > 0 ? Math.max(0, now() + offset - model.startedAt) : null);
    let status = null;

    // The connection line of the HUD: «на связи» with the other side all evening, RoadRunner from finale step 3.
    function paintStatus() {
        const node = root.querySelector('[data-status]');
        if (!node || status === null) return;
        node.dataset.status = status;
        const runner = status === 'connected' && model.scene >= model.sceneStages && (model.finale?.step ?? 0) >= RUNNER_STEP;
        const up = uptime();
        node.textContent = !runner ? STATUS[status] : up === null ? 'RoadRunner: работает' : `RoadRunner: работает · аптайм ${formatUptime(up)}`;
    }

    const myWalker = () => (model.me === null ? null : model.walkers.find((w) => w.id === model.me) ?? null);
    const holdsBeer = () => myWalker()?.item === 'beer';

    // The counter takes a click only with a character and empty hands; «Выпить» shows only when I may drink as far
    // as the client knows (docs/spec/frontend.md, «Бар и сдвиг номеров сцен»).
    function paintBar() {
        const bar = isBar(model.scene);
        document.body.toggleAttribute('data-bar', bar);
        root.dataset.bar = JSON.stringify(model.bar);
        const counter = root.querySelector('[data-bar-counter]');
        if (counter) {
            counter.hidden = !bar;
            counter.setAttribute('aria-disabled', String(!(bar && canWalk() && !holdsBeer())));
        }
        const runner = root.querySelector('[data-roadrunner]');
        if (runner) {
            runner.hidden = !(bar && model.bar.roadrunner);
            runner.setAttribute('aria-disabled', String(!clinksRoadrunner() && !meepsRoadrunner()));
        }
        const drink = root.querySelector('[data-drink]');
        // Alone on the field a player may drink without a clink (the server checks the same walkers).
        if (drink) drink.hidden = !(drinks() && (model.clinked || model.walkers.length <= 1));
        const { text, fill } = drinksOf(model.bar);
        paintMeter(hud('drinks'), root.querySelector('[data-hud-drinks-text]'), { shown: bar, text, fill, n: model.bar.drunk, target: model.bar.target });
    }

    // A pixel meter of the HUD (role="progressbar"): the bar's mugs, the cursed world's goals.
    function paintMeter(meter, label, { shown, text, fill, n, target }) {
        if (!meter) return;
        meter.hidden = !shown;
        meter.toggleAttribute('data-full', fill >= 1);
        meter.setAttribute('aria-valuenow', String(n));
        meter.setAttribute('aria-valuemax', String(target));
        meter.setAttribute('aria-valuetext', text);
        meter.style.setProperty('--fill', String(fill));
        if (label) label.textContent = text;
    }

    // The rapier's part of the page (docs/spec/frontend.md, «Рапира»): the goals of step 5, FrankenPHP's health and the
    // button over him, the word form or the zombie's line, and the state attributes CSS keys off.
    function paintRapier() {
        const body = document.body;
        const alive = model.boss !== null && model.boss.hp > 0;
        root.dataset.cursed = JSON.stringify(model.cursed);
        root.dataset.boss = JSON.stringify(model.boss);
        body.toggleAttribute('data-zombified', zombified());
        body.toggleAttribute('data-boss', alive);
        const goals = goalsOf(model.cursed);
        for (const goal of ['drinks', 'zombies']) {
            const meter = root.querySelector(`[data-hud-goal="${goal}"]`);
            paintMeter(meter, meter?.querySelector('[data-hud-goal-text]'), { shown: step() === CURSED_STEP && model.cursed !== null, ...goals[goal] });
        }
        const hp = root.querySelector('[data-boss-hp]');
        if (hp) {
            hp.hidden = !alive || step() !== RAPIER_STEP;
            if (alive) {
                const text = `HP ${model.boss.hp} / ${model.boss.max}`;
                hp.setAttribute('aria-valuenow', String(model.boss.hp));
                hp.setAttribute('aria-valuemax', String(model.boss.max));
                hp.setAttribute('aria-valuetext', text);
                hp.style.setProperty('--hp', String(model.boss.max > 0 ? model.boss.hp / model.boss.max : 0));
                const label = hp.querySelector('[data-boss-hp-text]');
                if (label) label.textContent = text;
            }
        }
        const franken = root.querySelector('[data-franken]');
        if (franken) {
            franken.hidden = !alive || step() !== RAPIER_STEP;
            franken.setAttribute('aria-disabled', String(!strikes()));
        }
        const word = root.querySelector('[data-word]');
        if (word) word.hidden = wordClosed || !asksWord();
        const zombie = root.querySelector('[data-word-zombie]');
        if (zombie) zombie.hidden = !zombified();
    }

    // The dice at the top (docs/spec/frontend.md, «Кости»): the call or the winner, «Бросить», my roll drawn big.
    function paintDice() {
        const on = step() === DICE_STEP && model.dice?.on === true;
        document.body.toggleAttribute('data-dice', on);
        root.dataset.dice = JSON.stringify(model.dice);
        const panel = root.querySelector('[data-dice]');
        if (panel) panel.hidden = !(step() === DICE_STEP && (on || Boolean(model.dice?.winner)));
        const note = root.querySelector('[data-dice-note]');
        if (note) note.textContent = diceNote(model.dice) ?? '';
        const button = root.querySelector('[data-dice-roll]');
        if (button) {
            button.hidden = !on;
            button.setAttribute('aria-disabled', String(!rolls()));
        }
    }

    // My dice move on the animation frames until they have landed and dimmed; the button comes back as they land.
    function animateDice() {
        const moving = dicePanel.frame();
        paintDice();
        if (!moving || typeof globalThis.requestAnimationFrame !== 'function') {
            diceLoop = false;
            return;
        }
        globalThis.requestAnimationFrame(animateDice);
    }
    function startDiceLoop() {
        if (diceLoop) return;
        diceLoop = true;
        animateDice();
    }

    // The dawn's top shooters at the side (docs/spec/frontend.md, «Топ рассвета»): only at step 7; my place is my index
    // in the board. On a phone the folded strip sits on the HUD, whose height wraps with the width.
    function paintDawn() {
        root.dataset.dawn = JSON.stringify(model.dawn);
        const width = root.clientWidth || window.innerWidth;
        const hudHeight = root.querySelector('.hud')?.offsetHeight;
        if (hudHeight) root.style.setProperty('--hud-h', `${hudHeight}px`);
        dawnTable.paint({ dawn: model.dawn, me: model.me, shown: step() === DAWN_STEP, compact: width <= DAWN_COMPACT_PX });
    }

    // The moderator's hit zone over RoadRunner of the finale (shown by the scene only while he is on screen).
    function paintMeep() {
        const zone = root.querySelector('[data-rr-meep]');
        if (zone) zone.hidden = !(model.moderator && (step() ?? -1) >= RUNNER_STEP);
    }

    function paintAll() {
        document.body.dataset.scene = model.scene;
        document.body.toggleAttribute('data-final', model.scene >= model.sceneStages);
        paintStory();
        root.dataset.round = model.round;
        root.dataset.sceneStages = model.sceneStages;
        root.dataset.stageMax = model.stageMax;
        root.dataset.threshold = model.threshold;
        root.dataset.k = model.k;
        root.dataset.online = model.online;
        paintElements();
        paintHud();
        paintBar();
        paintRapier();
        paintDice();
        paintMeep();
        paintDawn();
    }

    function load(snapshot) {
        takeTime(snapshot.now);
        // Another round: my attempts start from 1 again, the owl has not rolled yet.
        if (snapshot.round !== model.round) forgetDice();
        Object.assign(model, {
            round: snapshot.round,
            scene: snapshot.scene,
            sceneStages: snapshot.sceneStages,
            stageMax: snapshot.stageMax ?? stageMaxByScene.get(snapshot.scene) ?? DEFAULT_STAGE_MAX,
            threshold: snapshot.threshold,
            k: snapshot.k ?? model.k,
            online: snapshot.online ?? model.online,
            bushes: snapshot.bushes ?? model.bushes,
            walkers: snapshot.walkers ?? model.walkers,
            bar: barOf(snapshot.bar),
            startedAt: snapshot.startedAt ?? model.startedAt,
            ready: Boolean(snapshot.ready),
            finale: snapshot.finale ?? null,
            paused: Boolean(snapshot.paused),
            zombies: Boolean(snapshot.zombies),
            cursed: cursedOf(snapshot.cursed),
            boss: bossOf(snapshot.boss),
            dice: diceOf(snapshot.dice),
            dawn: dawnOf(snapshot.dawn),
        });
        stageMaxByScene.set(model.scene, model.stageMax);
        // A snapshot does not say whether my beer was clinked: a beer still in hand keeps what the client saw.
        if (!holdsBeer()) model.clinked = false;
        const known = new Map(model.elements.map((e) => [e.id, e]));
        model.elements = snapshot.elements.map((e) => (!isPumpkin(e)
            ? { ...known.get(e.id), ...e }
            : { ...known.get(e.id), ...e, size: e.size ?? known.get(e.id)?.size ?? 'm', reachable: e.reachable === true }));
        if (bonfireOf()) bonfireMaxByScene.set(model.scene, bonfireOf().stageMax);
        paintAll();
        scene?.render(snapshotOf());
        paintCircle();
        // A rumour still being poured is said by the late viewer too (it is in the snapshot).
        const pouring = model.bar.pouring;
        if (isBar(model.scene) && Number.isInteger(pouring?.rumor) && pouring.until > now() + offset) owl?.rumor?.(pouring.rumor, pouring.until - (now() + offset));
        sound?.load(model);
        onChange(model);
    }

    // The scene plays the finale's step; `ready` at step 0 is the closed circle, the cue for the ritual pose.
    function paintFinale() {
        scene?.setFinale({ step: model.scene >= model.sceneStages ? model.finale?.step ?? null : null, ready: model.ready });
    }

    function forgetDice() {
        roller.forget();
        dicePanel.clear();
        seenAttempt = 0;
        model.owlRolls = 0;
    }

    function snapshotOf() {
        return { ...model, bar: { ...model.bar }, cursed: model.cursed && { ...model.cursed }, boss: model.boss && { ...model.boss }, elements: model.elements.map((e) => ({ ...e })), walkers: model.walkers.map((w) => ({ ...w })) };
    }

    /** Re-reads the whole state over RPC; events arriving meanwhile are dropped, the snapshot covers them. */
    function resync() {
        if (syncing) return syncing;
        syncing = requestState()
            .then(load)
            .catch((error) => console.warn('state rpc failed', error))
            .finally(() => (syncing = null));
        return syncing;
    }

    const handlers = {
        click(event) {
            const element = findElement(event.element);
            if (!element) return;
            element.clicks = event.clicks;
            element.stage = event.stage;
            // A click on the bonfire or the circle carries its own threshold, not the pumpkins'.
            if (!isPumpkin(element)) {
                if (event.threshold !== undefined) element.threshold = event.threshold;
            } else if (event.threshold !== undefined && event.threshold !== model.threshold) {
                model.threshold = event.threshold;
                root.dataset.threshold = model.threshold;
                paintElements();
            }
            paintElement(element);
            paintHud();
            scene?.click(element.id, { stage: element.stage, clicks: element.clicks, threshold: element.threshold ?? model.threshold, player: event.player ?? null });
        },

        // Flyers live a few seconds and are not part of the snapshot: they are only passed on to the scene.
        flyer(event) {
            scene?.flyer(event);
        },

        shot(event) {
            scene?.shot(event);
        },

        // Characters on the field survive a reset: they live in the snapshot's `walkers`, not in the round.
        walker(event) {
            const w = event.walker;
            if (!w?.id) return;
            model.walkers = [...model.walkers.filter((x) => x.id !== w.id), w];
            scene?.walker(w);
            paintBar();
            paintRapier();
        },

        move(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (!w || !event.walk) return;
            w.walk = event.walk;
            // Heading to a clink by click; a move without the field cancels the one not reached yet.
            if (event.clink) w.clink = event.clink;
            else delete w.clink;
            scene?.move(w.id, w.walk, w.clink ?? null);
        },

        // The walk to it is over; the moment itself is not state, and the scene does not play one that came too late.
        clink(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (w) delete w.clink;
            // A clink counts for both glasses (with a pumpkin or RoadRunner, the one who came up): mine, whether I came up or was the target.
            if ((isBar(model.scene) || cursed()) && model.me !== null && (event.player?.id === model.me || event.walker?.id === model.me)) {
                model.clinked = true;
                paintBar();
            }
            scene?.clink(event);
        },

        leave(event) {
            const id = event.player?.id;
            model.walkers = model.walkers.filter((x) => x.id !== id);
            scene?.leave(id);
            paintBar();
            paintRapier();
        },

        // Every viewer plays the same foam from `since` to `until` on the server clock.
        // Now and then the owl says a rumour as it pours: low priority, it never cuts a line off (owl.js).
        pour(event) {
            const rumor = Number.isInteger(event.rumor) ? event.rumor : undefined;
            model.bar = { ...model.bar, pouring: { since: event.since, until: event.until, ...(rumor === undefined ? {} : { rumor }) } };
            paintBar();
            scene?.pour?.(event.since, event.until);
            if (rumor !== undefined) owl?.rumor?.(rumor, event.until - (now() + offset));
        },

        mugs(event) {
            model.bar = { ...model.bar, mugs: event.mugs, max: Number.isInteger(event.max) && event.max >= 0 ? event.max : model.bar.max, pouring: null };
            paintBar();
            scene?.mugs?.(event.mugs);
        },

        // The hall's count against its goal (the HUD's meter); readiness itself comes as `ready`.
        drinks(event) {
            model.bar = { ...model.bar, drunk: Number.isInteger(event.drunk) ? event.drunk : model.bar.drunk, target: Number.isInteger(event.target) ? event.target : model.bar.target };
            paintBar();
        },

        // A `bar.take` reached the counter: the first to arrive got a mug, a latecomer shrugs. With `pumpkin` a `beer`
        // reached a pumpkin of step 5, which never runs dry; the scene hands the mug over before the walker holds it.
        take(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (event.pumpkin?.id) {
                scene?.takeFromPumpkin?.(event.pumpkin.id, event.player?.id ?? null);
                if (w) {
                    w.item = 'beer';
                    scene?.walker({ ...w });
                }
                if (event.player?.id === model.me) model.clinked = false;
                paintBar();
                paintRapier();
                return;
            }
            if (event.ok) {
                if (Number.isInteger(event.mugs)) model.bar = { ...model.bar, mugs: event.mugs };
                if (w) {
                    w.item = 'beer';
                    scene?.walker({ ...w });
                }
                if (event.player?.id === model.me) model.clinked = false;
                scene?.mugs?.(model.bar.mugs);
            } else if (w) {
                scene?.shrug?.(w.id);
            }
            paintBar();
        },

        // The hands are empty at once in the model; the scene keeps the mug through the gulp.
        drink(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (!w) return;
            w.item = null;
            if (w.id === model.me) model.clinked = false;
            scene?.drink?.(w.id);
            paintBar();
        },

        pumpkin(event) {
            const element = findElement(event.element);
            if (!element) return;
            element.stage = event.stage;
            element.clicks = 0;
            // The last pumpkin opens the bonfire and may send the patch jumping out.
            paintElements();
            paintHud();
            scene?.pumpkin(element.id, element.stage);
        },

        // The new scene is not ready yet; entering the final one, `finale {step: 0}` follows.
        scene(event) {
            model.scene = event.scene;
            model.stageMax = event.stageMax;
            model.ready = false;
            model.dawn = null;
            if (model.scene < model.sceneStages) {
                model.finale = null;
                model.dice = null;
            }
            stageMaxByScene.set(model.scene, model.stageMax);
            const bonfire = bonfireOf();
            if (bonfire && event.bonfireStageMax !== undefined) {
                bonfire.stageMax = event.bonfireStageMax;
                bonfireMaxByScene.set(model.scene, bonfire.stageMax);
            }
            // The circle's last stage: HELLOWIN_CIRCLE_STAGES in the final scene, 0 before it.
            const circle = circleOf();
            if (circle) circle.stageMax = event.circleStageMax ?? 0;
            // Out of the bar the mugs on the counter and in hand are gone (protocol.md, «Бар»).
            if (!isBar(model.scene)) {
                // RoadRunner's flag outlives the scene changes: only a reset sets or clears it.
                model.bar = { ...NO_BAR, max: model.bar.max, roadrunner: model.bar.roadrunner };
                model.clinked = false;
            }
            paintAll();
            scene?.setScene(model.scene, model.stageMax);
            if (!placeAll(event)) handOut(event);
            paintBar();
        },

        ready(event) {
            if (event.scene !== model.scene) return;
            model.ready = true;
            paintStory();
            paintFinale();
        },

        finale(event) {
            model.finale = event.reveal ? { step: event.step, reveal: event.reveal } : { step: event.step };
            // Into step 5 the closed circle's readiness is gone: from here only the cursed world's goals give it. Step 1
            // likewise waits for the first 6-6-6 (the dice come on with their own event).
            if (event.step === CURSED_STEP || event.step === DICE_STEP) model.ready = false;
            if (event.step === DICE_STEP) model.owlRolls = 0;
            if (event.step < DICE_STEP) model.dice = null;
            // The dawn's count starts empty with the step (protocol.md, «Топ рассвета»); its first hit brings the board.
            model.dawn = event.step === DAWN_STEP ? model.dawn ?? { board: [] } : null;
            // The dawn lifts every zombie and fells FrankenPHP, whether his health ran out or the moderator forced it.
            const lifted = event.step >= DAWN_STEP ? model.walkers.filter((w) => w.zombie) : [];
            lifted.forEach((w) => delete w.zombie);
            if (event.step >= DAWN_STEP && model.boss) {
                model.boss = { ...model.boss, hp: 0 };
                scene?.boss?.({ ...model.boss });
            }
            if ('item' in event) model.clinked = false;
            else lifted.forEach((w) => scene?.walker({ ...w }));
            if (event.step >= DAWN_STEP) wordClosed = false;
            // The circle takes clicks at step 0 only; the pumpkins drop at step 2 and float again at step 5.
            paintElements();
            paintStory();
            paintFinale();
            handOut(event);
            paintBar();
            paintRapier();
            paintDice();
            paintMeep();
            paintDawn();
        },

        // The dice come on with step 1 and go off with the first 6-6-6 (`winner`) or a forced step 2; the silhouette's
        // eyes flare at the win.
        dice(event) {
            const had = Boolean(model.dice?.winner);
            model.dice = diceOf(event);
            if (model.dice?.winner && !had) scene?.diceWin?.();
            paintDice();
            paintHud();
        },

        // A new roll: little dice over the roller (or the owl, who asks to have a go first); mine also big at the top.
        roll(event) {
            const dice = facesOf(event.dice);
            if (!dice || !Number.isInteger(event.attempt)) return;
            const fresh = !(now() + offset - event.at > ROLL_STALE_MS);
            if (event.owl === true) {
                model.owlRolls = Math.max(model.owlRolls, event.attempt);
                if (fresh) owl?.aside?.(OWL_TRY);
            }
            scene?.roll?.(event);
            if (model.me !== null && event.player?.id === model.me) {
                seenAttempt = Math.max(seenAttempt, event.attempt);
                if (fresh) dicePanel.start(dice, event.attempt);
                else dicePanel.settle(dice);
                startDiceLoop();
            }
            paintDice();
        },

        // The moderator's click on RoadRunner: a «Мип-мип!» over him, if he is on screen; no state.
        meep(event) {
            if (now() + offset - event.at > MEEP_STALE_MS) return;
            scene?.meep?.(event.flyer ?? null);
        },

        // A counted hit at the dawn: the whole ranking again, which replaces ours.
        dawn(event) {
            model.dawn = dawnOf(event);
            paintDawn();
        },

        // The goal of step 5: mugs drunk and zombies slain against their targets (readiness itself comes as `ready`).
        cursed(event) {
            model.cursed = cursedOf(event);
            paintRapier();
        },

        // FrankenPHP woke up with the first rapier (glasses, health), or the moderator changed his health.
        boss(event) {
            model.boss = bossOf(event);
            scene?.boss?.(model.boss && { ...model.boss });
            paintRapier();
        },

        // A rapier's blow: the golden chain lightning from the striker, the health the server counted after it.
        strike(event) {
            if (Number.isInteger(event.hp)) model.boss = bossOf({ hp: event.hp, max: Math.max(event.hp, model.boss?.max ?? 0) });
            scene?.strike?.(event);
            scene?.boss?.(model.boss && { ...model.boss });
            paintRapier();
        },

        // The magic word, checked by the server only: the rapier in hand, or a zombie worker the server walks around.
        word(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (w) {
                if (event.ok) w.item = 'rapier';
                else w.zombie = true;
                scene?.word?.(event);
                scene?.walker({ ...w });
            }
            if (event.player?.id === model.me) wordClosed = false;
            paintBar();
            paintRapier();
        },

        // A zombie player cured by someone else: the item stays, and the word is offered again.
        cure(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (w) {
                delete w.zombie;
                scene?.cure?.(event);
                scene?.walker({ ...w });
            }
            if (event.player?.id === model.me) wordClosed = false;
            paintBar();
            paintRapier();
        },

        // The bubble is a moment, not state: a resync does not bring an old line back.
        owl(event) {
            owl?.say(event, model.scene);
        },

        pause(event) {
            model.paused = Boolean(event.on);
            paintStory();
        },

        zombies(event) {
            model.zombies = Boolean(event.on);
            paintStory();
        },

        // The moderator's multiplier: every threshold at once, already multiplied by the server (the client does
        // not know the base values). A pumpkin with enough clicks turns ready at once.
        thresholds(event) {
            model.k = Number(event.k);
            root.dataset.k = model.k;
            const { pumpkin, bonfire, circle } = event.thresholds ?? {};
            if (pumpkin !== undefined) {
                model.threshold = pumpkin;
                root.dataset.threshold = pumpkin;
            }
            if (bonfire !== undefined && bonfireOf()) bonfireOf().threshold = bonfire;
            if (circle !== undefined && circleOf()) circleOf().threshold = circle;
            paintElements();
        },

        // Only `online` for now: the players' table on the page is still to come (players.md).
        leaderboard(event) {
            if (!Number.isInteger(event.online)) return;
            model.online = event.online;
            root.dataset.online = model.online;
        },

        // The pause and the multiplier are about the room, not the story: they survive a reset (protocol.md, «Снимок»).
        reset(event) {
            model.round = event.round;
            // A reset leads into the bar: no pumpkin stages, ready again only once the hall has drunk its goal.
            model.scene = SCENE.bar;
            model.finale = null;
            model.zombies = false;
            model.clinked = false;
            model.cursed = null;
            model.boss = null;
            model.dice = null;
            model.dawn = null;
            forgetDice();
            wordClosed = false;
            // The goal is the room's (it survives a reset): an event without one keeps ours.
            model.bar = barOf(event.bar ? { target: model.bar.target, ...event.bar } : null);
            // The dawn's «В бар» brings RoadRunner behind the counter, the moderator's reset sends him away; the
            // server's `reset.bar.roadrunner` says so too, an event without it is read the same way.
            if (typeof event.bar?.roadrunner !== 'boolean') model.bar.roadrunner = event.again === true;
            model.ready = model.bar.target > 0 && model.bar.drunk >= model.bar.target;
            model.stageMax = stageMaxByScene.get(SCENE.bar) ?? 0;
            model.elements.forEach((e) => {
                e.stage = 0;
                e.clicks = 0;
                if (e.type === 'bonfire') e.stageMax = bonfireMaxByScene.get(SCENE.bar) ?? 0;
                if (e.type === 'circle') e.stageMax = 0;
            });
            const placed = Array.isArray(event.walkers);
            if (placed) model.walkers = event.walkers.map((w) => ({ ...w }));
            else {
                model.walkers.forEach((w) => delete w.zombie);
                handOut(event);
            }
            paintAll();
            // `again`: the dawn's «В бар», a new round of the evening — the scene fades the dawn into the bar instead of
            // cutting to it like the console's reset.
            scene?.render(snapshotOf(), { again: event.again === true });
            if (placed) scene?.setWalkers?.(model.walkers.map((w) => ({ ...w })));
        },
    };

    // The item in every walker's hand follows the story: `scene`, `finale` and `reset` carry it instead of a walker
    // event each.
    // Leaving the bar puts every walker down on the lawn at once: the event's list replaces ours, and nobody walks
    // over from the hall (another location). Returns whether the event carried the list.
    function placeAll(event) {
        if (!Array.isArray(event.walkers)) return false;
        model.walkers = event.walkers.map((w) => ({ ...w }));
        scene?.setWalkers?.(model.walkers.map((w) => ({ ...w })));
        return true;
    }

    function handOut(event) {
        if (!('item' in event)) return;
        model.walkers.forEach((w) => {
            w.item = event.item;
            scene?.walker({ ...w });
        });
    }

    /** Whether my walker is a zombie worker (a wrong word): the server walks it, every action of mine is off. */
    function zombified() {
        return myWalker()?.zombie === true;
    }
    /** Whether this browser has a character on the field and may send `move`: not while it is a zombie. */
    function canWalk() {
        return myWalker() !== null && !zombified();
    }
    /** Whether the story is in the cursed world (finale step 5+), where a click may clink glasses. */
    const cursed = () => (step() ?? -1) >= CURSED_STEP;
    const inBar = () => isBar(model.scene);
    const beerOf = (id) => model.walkers.find((w) => w.id === id)?.item === 'beer';
    const zombieOf = (id) => model.walkers.find((w) => w.id === id)?.zombie === true;
    /** Whether a click on RoadRunner behind the counter is RPC `clink {roadrunner: true}`: he stands there, beer in my hand. */
    function clinksRoadrunner() {
        return inBar() && model.bar.roadrunner && canWalk() && holdsBeer();
    }
    /** Whether the moderator's click on RoadRunner behind the counter is RPC `meep {}` instead of a clink. */
    function meepsRoadrunner() {
        return inBar() && model.bar.roadrunner && model.moderator;
    }
    /**
     * Whether «Бросить» or Space sends RPC `roll`: step 1 with the dice on, my walker on the field and no zombie, and my
     * last roll has landed (nor is one on its way).
     */
    function rolls() {
        return step() === DICE_STEP && model.dice?.on === true && canWalk() && !dicePanel.rolling() && !roller.pending;
    }
    /** Whether Space or «Выпить» sends RPC `drink`: in the bar or the cursed world with beer in hand — the server says. */
    function drinks() {
        return (inBar() || cursed()) && canWalk() && holdsBeer();
    }
    /** Whether a click on FrankenPHP is RPC `strike`: step 6, he is awake and standing, a rapier in my hand. */
    function strikes() {
        return step() === RAPIER_STEP && (model.boss?.hp ?? 0) > 0 && canWalk() && myWalker().item === 'rapier';
    }
    /** Whether the word form is open to me: step 6, my walker on the field, no rapier yet, not a zombie. */
    function asksWord() {
        return step() === RAPIER_STEP && canWalk() && myWalker().item !== 'rapier';
    }

    return {
        model,
        load,

        /** The server clock as this client measured it, ms. */
        serverNow: () => now() + offset,

        /** Whether a click on the element would be accepted: a locked bonfire or circle is not worth an RPC. */
        clickable(id) {
            // The bar has no patch: its hidden buttons send nothing (the server would answer `locked`).
            return !isBar(model.scene) && !button(id)?.hasAttribute('data-locked');
        },
        resync,

        /** This browser's player id from RPC `me` / `register` (`player.id`); null for an anonymous one. */
        setMe(id) {
            model.me = id ?? null;
            paintBar();
            scene?.setMe(model.me);
            paintDawn();
            sound?.me(model.me, model);
        },

        canWalk,

        cursed,

        inBar,

        /**
         * Whether a click on walker `id` is a clink (RPC `clink {walker}`): someone else's and I am on the field, in the
         * cursed world — or in the bar, where both of us hold beer.
         */
        clinksWalker(id) {
            if (!id || id === model.me || !canWalk() || !model.walkers.some((w) => w.id === id) || zombieOf(id)) return false;
            return cursed() || (inBar() && beerOf(model.me) && beerOf(id));
        },

        /** Whether a click on walker `id` is RPC `cure {walker}`: step 6, someone else's zombie, and I am on the field and no zombie. */
        curesWalker(id) {
            return step() === RAPIER_STEP && Boolean(id) && id !== model.me && canWalk() && zombieOf(id);
        },

        /** Whether a click on element `id` is RPC `beer {pumpkin}`: step 5, a reachable pumpkin, my hands empty. */
        beersPumpkin(id) {
            const element = findElement(id);
            return Boolean(element && isPumpkin(element) && element.reachable) && step() === CURSED_STEP && canWalk() && !myWalker().item;
        },

        strikes,

        asksWord,

        zombified,

        /** My word was answered: the form closes until the event about it arrives. */
        closeWord() {
            wordClosed = true;
            paintRapier();
        },

        clinksRoadrunner,

        meepsRoadrunner,

        /** Whether a click on a flyer is the moderator's RPC `meep {flyer}`: a runner on the horizon (players shoot it). */
        meepsFlyer(kind) {
            return model.moderator && kind === 'roadrunner';
        },

        rolls,

        /**
         * Rolls my dice once (RPC `roll` with the next attempt; `bad attempt` resyncs and rolls again). Nothing is drawn
         * until my `roll` event; a reply with no event after REPEAT_WAIT_MS was a repeat, and shows its dice landed.
         */
        async roll() {
            if (!rolls()) return null;
            const sent = roller.roll();
            paintDice();
            let reply = null;
            try {
                reply = await sent;
            } catch (error) {
                console.warn('roll rpc failed', error);
            }
            paintDice();
            if (reply?.ok === false) console.warn('roll rejected', reply.error);
            const dice = reply?.ok ? facesOf(reply.dice) : null;
            if (dice && seenAttempt < reply.attempt) {
                setTimeout(() => {
                    if (seenAttempt >= reply.attempt) return;
                    seenAttempt = reply.attempt;
                    dicePanel.settle(dice);
                    paintDice();
                }, REPEAT_WAIT_MS);
            }
            return reply;
        },

        /** Repaints my dice for the current time: the lab's frozen clock has no animation frames. */
        frame() {
            dicePanel.frame();
            paintDice();
        },

        /** Forgets my attempts and my roll on screen, as a new round does; the lab's replay of a frozen run needs it. */
        forgetDice() {
            forgetDice();
            paintDice();
        },

        /** Whether a click on the counter is worth RPC `bar.take`: in the bar, with a character and empty hands. */
        takesBeer() {
            return inBar() && canWalk() && !holdsBeer();
        },

        drinks,

        /**
         * The partner to report in RPC `clink.auto` for an automatic clink striking now (scene cue `clink` with `sides`
         * in config coordinates), or null: only in the bar or the cursed world, and only when my walker is in the group
         * with beer — the nearest other side that holds beer.
         */
        autoClinkPartner(cue) {
            if (!drinks() || !Array.isArray(cue?.sides)) return null;
            const mine = cue.sides.find((side) => side.id === model.me);
            if (!mine) return null;
            let best = null;
            for (const side of cue.sides) {
                if (side.id === model.me || !beerOf(side.id)) continue;
                const d = Math.hypot(side.x - mine.x, side.y - mine.y);
                if (!best || d < best.d) best = { id: side.id, d };
            }
            return best?.id ?? null;
        },

        /** A successful `clink.auto` reply: my beer counts as clinked. */
        clinkedAuto() {
            if (!holdsBeer()) return;
            model.clinked = true;
            paintBar();
        },

        /**
         * Whether a click on element `id` is a clink (RPC `clink {pumpkin}`) instead of `click`: a reachable pumpkin at
         * step 5 with beer in my hand (from step 6 the pumpkins hold no mugs).
         */
        clinksPumpkin(id) {
            const element = findElement(id);
            return Boolean(element && isPumpkin(element) && element.reachable) && step() === CURSED_STEP && canWalk() && holdsBeer();
        },

        /**
         * Measures the clock offset without touching the scene: a few round trips, and the one with the shortest RTT
         * wins — its server time is taken as read at the middle of the trip (NTP-style). Called on every connect and
         * whenever the tab comes back.
         */
        syncClock() {
            if (clocking) return clocking;
            clocking = (async () => {
                let best = null;
                for (let i = 0; i < CLOCK_SAMPLES; i++) {
                    const sent = now();
                    const server = await requestTime();
                    const back = now();
                    if (!(server > 0)) continue;
                    const rtt = back - sent;
                    if (!best || rtt < best.rtt) best = { rtt, offset: server + rtt / 2 - back };
                }
                if (best) {
                    offset = best.offset;
                    measured = true;
                }
            })()
                .catch((error) => console.warn('clock sync failed', error))
                .finally(() => (clocking = null));
            return clocking;
        },

        /** Applies one channel event (protocol.md); events of another round trigger a resync. */
        apply(event) {
            const handler = handlers[event?.type];
            if (!handler || syncing) return;
            if (event.type !== 'reset' && event.round !== model.round) {
                resync();
                return;
            }
            // What the event changed is the sound's cue: a finale step that follows the one before, a new walker.
            const before = sound && { scene: model.scene, stageMax: model.stageMax, step: model.finale?.step ?? null, walkers: new Set(model.walkers.map((w) => w.id)), bossHp: model.boss?.hp ?? null };
            handler(event);
            sound?.event(event, model, before);
            onChange(model);
        },

        /** The connection's state for the HUD line: 'connecting', 'connected' or 'disconnected'. */
        setStatus(next) {
            status = next;
            paintStatus();
        },

        /** Whether this connection is the moderator's (RPC `me`, `moderator`); it decides whether the console shows. */
        setModerator(on) {
            model.moderator = Boolean(on);
            paintBar();
            paintMeep();
            onChange(model);
        },

        start() {
            const spread = () => {
                root.style.setProperty('--spread', String(spreadX(root.clientWidth || window.innerWidth)));
                paintOut();
                paintDawn();
            };
            spread();
            new ResizeObserver(spread).observe(root);
            paintAll();
            sound?.load(model);
            // The uptime ticks in the HUD and on the reveal: repaint when its minute changes.
            let shown = null;
            setInterval(() => {
                const up = uptime();
                const minute = up === null ? null : Math.floor(up / 60000);
                if (minute === shown) return;
                shown = minute;
                paintStatus();
                reveal?.update(model.finale, up);
            }, 1000);
        },
    };
}

// Controls laid over the scene: a press on them is never a shot or a step.
const UI = '[data-debug-panel], [data-lab-panel], [data-reveal], .help-link, .hud, [data-join], [data-bar-counter], [data-roadrunner], [data-rr-meep], [data-word], [data-word-zombie], .dice, [data-dawn-toggle]';

/**
 * Shooting at flyers. The canvas lies under the pumpkin buttons, so the hit test runs on the root in the capture
 * phase: a press on a flyer is a shot and the click that follows it is swallowed before it reaches a pumpkin
 * button. A press anywhere else goes on as a normal click.
 *
 * @param {(flyer: string) => void} shoot  sends RPC `shoot`; the fall is drawn only on the channel's `shot` event
 */
export function bindShooting(root, scene, shoot) {
    if (!scene) return;
    let swallowClick = false;
    root.addEventListener('pointerdown', (event) => {
        swallowClick = false;
        if (event.target.closest(UI)) return;
        const flyer = scene.flyerAt(event.clientX, event.clientY);
        if (!flyer) return;
        swallowClick = true;
        event.preventDefault();
        event.stopPropagation();
        shoot(flyer);
    }, { capture: true });
    root.addEventListener('click', (event) => {
        if (!swallowClick) return;
        swallowClick = false;
        event.preventDefault();
        event.stopPropagation();
    }, { capture: true });
}

/**
 * Clinking in the cursed world: a click or tap on someone else's walker asks the server to walk up and clink
 * (`clinksWalker`), instead of a step to that spot or a click on a pumpkin button over it. Runs on the root in the
 * capture phase after bindShooting, so a flyer shot (already prevented) stays first; a keyboard click (`detail` 0,
 * no pointer position) is left to its button. Over such a walker the root gets `data-clink-hover` (a pointer cursor).
 * At finale step 6 a click on a zombie player cures it instead (`curesWalker`).
 *
 * @param {{clinksWalker: (id: string) => boolean, curesWalker?: (id: string) => boolean}} client  the client model's routing
 * @param {(target: {walker: string}) => void} clink  sends RPC `clink`; nothing is drawn until the `move` and `clink` events
 * @param {(target: {walker: string}) => void} [cure]  sends RPC `cure`; the cure is drawn on the `cure` event
 */
export function bindClinking(root, scene, client, clink, cure = null) {
    if (!scene) return;
    const cures = (id) => Boolean(cure && client.curesWalker?.(id));
    const target = (event) => {
        if (event.target.closest?.(UI)) return null;
        const id = scene.walkerHit(event.clientX, event.clientY);
        return id && (cures(id) || client.clinksWalker(id)) ? id : null;
    };
    root.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.detail === 0) return;
        const id = target(event);
        if (!id) return;
        event.preventDefault();
        event.stopPropagation();
        if (cures(id)) cure({ walker: id });
        else clink({ walker: id });
    }, { capture: true });
    root.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch') return;
        root.toggleAttribute('data-clink-hover', target(event) !== null);
    });
    root.addEventListener('pointerleave', () => root.removeAttribute('data-clink-hover'));
}

// Where Space belongs to the control: typing, a button it presses, a select it opens.
const SPACE_TAKERS = 'input, textarea, select, button, [contenteditable]:not([contenteditable="false"]), [role="button"]';

/**
 * Whether a keydown is the bar's Space for a drink: plain Space (no modifiers, no auto-repeat), not on a control that
 * takes Space itself, and no dialog open (the join screen is one). Whether I may drink is the client's to say. The
 * counter button and a pumpkin button are no such controls: a mouse click on them (a mug, a beer or a clink) keeps the
 * focus there, and while I may drink Space is the drink; Enter still presses them.
 */
export function isDrinkKey(event, doc = globalThis.document) {
    if (event.code !== 'Space' && event.key !== ' ') return false;
    if (event.repeat || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return false;
    const taker = event.target?.closest?.(SPACE_TAKERS);
    if ((taker && !taker.matches('[data-bar-counter], [data-roadrunner], .pumpkin')) || event.target?.isContentEditable) return false;
    return !doc?.querySelector?.('dialog[open]');
}

/**
 * The bar's input (docs/spec/frontend.md, «Бар и сдвиг номеров сцен»): the counter button asks for a mug, the button
 * over RoadRunner clinks with him, Space and the «Выпить» button drink. Nothing is drawn locally: the walk comes as
 * `move`, the mug as `take`, the glasses as `clink`, the gulp as `drink`.
 *
 * @param {{takesBeer: () => boolean, drinks: () => boolean, clinksRoadrunner?: () => boolean}} client  the client model's routing
 * @param {object} actions
 * @param {() => void} actions.take  sends RPC `bar.take`
 * @param {() => void} actions.drink  sends RPC `drink`
 * @param {() => void} [actions.clink]  sends RPC `clink {roadrunner: true}`
 * @param {() => void} [actions.meep]  sends the moderator's RPC `meep {}`
 */
export function bindBar(root, client, { take, drink, clink = null, meep = null }, doc = globalThis.document) {
    root.querySelector('[data-bar-counter]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (client.takesBeer()) take();
    });
    // He stands over the counter's button: with empty hands a click on him is still a click on the counter.
    root.querySelector('[data-roadrunner]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (meep && client.meepsRoadrunner?.()) meep();
        else if (clink && client.clinksRoadrunner?.()) clink();
        else if (client.takesBeer()) take();
    });
    root.querySelector('[data-drink]')?.addEventListener('click', () => {
        if (client.drinks()) drink();
    });
    doc.addEventListener('keydown', (event) => {
        if (!isDrinkKey(event, doc) || !client.drinks()) return;
        event.preventDefault();
        drink();
    });
}

/**
 * Whether a keydown is Space for a roll of the dice: the same rules as isDrinkKey (plain Space, not on a control that
 * takes it, no dialog open). At finale step 1 Space is the roll alone: nothing there drinks.
 */
export function isRollKey(event, doc = globalThis.document) {
    return isDrinkKey(event, doc);
}

/**
 * The dice of finale step 1 (docs/spec/frontend.md, «Кости»): «Бросить» or Space rolls when `client.rolls()`, else
 * nothing goes out. The dice themselves come back as the `roll` event.
 *
 * @param {{rolls: () => boolean, roll: () => Promise<unknown>}} client
 */
export function bindDice(root, client, doc = globalThis.document) {
    root.querySelector('[data-dice-roll]')?.addEventListener('click', (event) => {
        event.preventDefault();
        if (client.rolls()) client.roll();
    });
    doc.addEventListener('keydown', (event) => {
        if (!isRollKey(event, doc) || !client.rolls()) return;
        event.preventDefault();
        client.roll();
    });
}

/**
 * The moderator's hit zone over RoadRunner of the finale: RPC `meep {}`; the bubble comes back as the `meep` event.
 */
export function bindMeep(root, meep) {
    root.querySelector('[data-rr-meep]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        meep();
    });
}

// The word form's answers to a refusal (protocol.md, RPC `word`); anything else is «Не вышло».
const WORD_ERRORS = {
    'not now': 'Сейчас не время',
    'has rapier': 'Рапира уже у тебя',
    zombified: 'Зомби слов не молвят',
    'invalid text': 'От 1 до 40 знаков',
    'no character': 'Сначала выйди на поле',
    'rate limited': 'Не так быстро',
};
const WORD_NOTE_MS = 3000;

/**
 * The magic word of finale step 6 (docs/spec/frontend.md, «Рапира»): the form at the top sends RPC `word {text}`.
 * Only the server knows the word and its forms: the page turns down nothing but an empty line. An answer closes the
 * form (`client.closeWord`); the rapier or the zombie comes as the `word` event, a refusal is a note under the field.
 *
 * @param {{closeWord: () => void}} client
 * @param {(text: string) => Promise<{ok: boolean, correct?: boolean, error?: string}|undefined>} say  RPC `word`
 */
export function bindWord(root, client, say) {
    const form = root.querySelector('[data-word]');
    if (!form) return;
    const input = form.querySelector('[data-word-input]');
    const submit = form.querySelector('[data-word-submit]');
    const note = form.querySelector('[data-word-note]');
    let noteTimer = 0;
    const tell = (text) => {
        note.textContent = text;
        clearTimeout(noteTimer);
        noteTimer = setTimeout(() => (note.textContent = ''), WORD_NOTE_MS);
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = input.value.trim();
        if (!text) {
            tell('Молви хоть что-нибудь');
            return;
        }
        submit.disabled = true;
        try {
            const reply = await say(text);
            if (reply?.ok) {
                input.value = '';
                note.textContent = '';
                client.closeWord();
            } else {
                tell(WORD_ERRORS[reply?.error] ?? 'Не вышло');
            }
        } catch (error) {
            console.warn('word rpc failed', error);
            tell('Не вышло');
        } finally {
            submit.disabled = false;
        }
    });
}

/**
 * The button over FrankenPHP at finale step 6: a blow of my rapier (RPC `strike`) when `client.strikes()`, else
 * nothing. The lightning and the health come back as the `strike` event.
 */
export function bindStriking(root, client, strike) {
    root.querySelector('[data-franken]')?.addEventListener('click', (event) => {
        event.preventDefault();
        if (client.strikes()) strike();
    });
}

/**
 * Walking: a click on the ground — not on a pumpkin, not on a control, and not on a flyer (bindShooting swallows
 * those in the capture phase) — asks the server to walk there. Nothing moves locally; the `move` event does it.
 *
 * @param {() => boolean} canWalk  whether this browser has a character on the field
 * @param {(point: {x: number, y: number}) => void} move  sends RPC `move` in config coordinates
 */
export function bindWalking(root, scene, canWalk, move) {
    if (!scene) return;
    root.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.target.closest(`[data-element], [data-franken], ${UI}`) || !canWalk()) return;
        const point = scene.groundAt(event.clientX, event.clientY);
        if (point) move(point);
    });
}
