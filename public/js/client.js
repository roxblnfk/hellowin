// Client model of docs/spec/frontend.md: applies channel events to the DOM hooks and to the pixel scene.
// Shared by app.js (live channel) and public/dev/lab.html (generated events).

import { SCENE, bonfireOpen, circleOpen, floats, isBar, isPumpkin, jumpOut, jumpedOut, spreadX } from './layout.js';

const SCENE_NAMES = { [SCENE.bar]: 'Бар', [SCENE.day]: 'День: подготовка', [SCENE.sunset]: 'Закат', [SCENE.night]: 'Ночь' };
const DEFAULT_STAGE_MAX = 4;
// The counter outside the bar (protocol.md, `bar` in the snapshot); `drunk` and `target` — the hall's goal.
const NO_BAR = Object.freeze({ mugs: 0, pouring: null, drunk: 0, target: 0 });
// The last finale step: past it the final scene is the cursed world.
const CURSED_STEP = 5;
// From this finale step the HUD's connection line says who answers on the line (scenario.md, «Финал», step 3).
const RUNNER_STEP = 3;
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
    return { mugs: bar.mugs, pouring: bar.pouring ?? null, drunk: count(bar.drunk), target: count(bar.target) };
}

/** «Выпито 12 из 30» and how full the meter is, 0–1 (full once the goal is reached). */
export function drinksOf(bar) {
    const target = bar?.target ?? 0;
    const drunk = bar?.drunk ?? 0;
    return { text: `Выпито ${drunk} из ${target}`, fill: target > 0 ? Math.min(1, drunk / target) : 0 };
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
 */
export function createClient(root, scene, { requestState, requestTime = () => requestState().then((s) => s.now), now = () => Date.now(), owl = null, reveal = null, onChange = () => {}, sound = null }) {
    // `clinked`: whether I have clinked with the beer in my hand since taking it — only a hint for the «Выпить» button;
    // the server decides (protocol.md, RPC `drink`).
    const model = { ...readSnapshot(root), me: null, moderator: false, clinked: false };
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
        // The bar has no pumpkins: its meter of mugs drunk stands in for «готово N из M».
        const progress = hud('progress');
        if (progress) progress.hidden = isBar(model.scene);
        const ready = hud('ready');
        // In the final scene `ready` stays on through the finale; it waits for the moderator only at step 0.
        if (ready) ready.hidden = !model.ready || (model.scene >= model.sceneStages && (model.finale?.step ?? 0) >= 1);
    }

    function sceneName() {
        if (model.scene < model.sceneStages) return SCENE_NAMES[model.scene] ?? '';
        return (model.finale?.step ?? 0) >= CURSED_STEP ? 'Проклятый мир' : 'Финал';
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
        const drink = root.querySelector('[data-drink]');
        // Alone in the bar a player may drink without a clink (the server checks the same walkers).
        if (drink) drink.hidden = !(bar && canWalk() && holdsBeer() && (model.clinked || model.walkers.length <= 1));
        const meter = hud('drinks');
        if (meter) {
            const { text, fill } = drinksOf(model.bar);
            meter.hidden = !bar;
            meter.toggleAttribute('data-full', fill >= 1);
            meter.setAttribute('aria-valuenow', String(model.bar.drunk));
            meter.setAttribute('aria-valuemax', String(model.bar.target));
            meter.setAttribute('aria-valuetext', text);
            meter.style.setProperty('--fill', String(fill));
            const label = root.querySelector('[data-hud-drinks-text]');
            if (label) label.textContent = text;
        }
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
    }

    function load(snapshot) {
        takeTime(snapshot.now);
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

    function snapshotOf() {
        return { ...model, bar: { ...model.bar }, elements: model.elements.map((e) => ({ ...e })), walkers: model.walkers.map((w) => ({ ...w })) };
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
            // In the bar a clink counts for both glasses: mine, whether I came up or was the target.
            if (isBar(model.scene) && model.me !== null && (event.player?.id === model.me || event.walker?.id === model.me)) {
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
            model.bar = { ...model.bar, mugs: event.mugs, pouring: null };
            paintBar();
            scene?.mugs?.(event.mugs);
        },

        // The hall's count against its goal (the HUD's meter); readiness itself comes as `ready`.
        drinks(event) {
            model.bar = { ...model.bar, drunk: Number.isInteger(event.drunk) ? event.drunk : model.bar.drunk, target: Number.isInteger(event.target) ? event.target : model.bar.target };
            paintBar();
        },

        // A `bar.take` reached the counter: the first to arrive got a mug, a latecomer shrugs.
        take(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
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
            if (model.scene < model.sceneStages) model.finale = null;
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
                model.bar = { ...NO_BAR };
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
            // The circle takes clicks at step 0 only; the pumpkins drop at step 2 and float again at step 5.
            paintElements();
            paintStory();
            paintFinale();
            handOut(event);
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
            // The goal is the room's (it survives a reset): an event without one keeps ours.
            model.bar = barOf(event.bar ? { target: model.bar.target, ...event.bar } : null);
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
            else handOut(event);
            paintAll();
            scene?.render(snapshotOf());
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

    /** Whether this browser has a character on the field and may send `move`. */
    const canWalk = () => model.me !== null && model.walkers.some((w) => w.id === model.me);
    /** Whether the story is in the cursed world (finale step 5+), where a click may clink glasses. */
    const cursed = () => model.scene >= model.sceneStages && (model.finale?.step ?? -1) >= CURSED_STEP;
    const inBar = () => isBar(model.scene);
    const beerOf = (id) => model.walkers.find((w) => w.id === id)?.item === 'beer';

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
            if (!id || id === model.me || !canWalk() || !model.walkers.some((w) => w.id === id)) return false;
            return cursed() || (inBar() && beerOf(model.me) && beerOf(id));
        },

        /** Whether a click on the counter is worth RPC `bar.take`: in the bar, with a character and empty hands. */
        takesBeer() {
            return inBar() && canWalk() && !holdsBeer();
        },

        /** Whether Space or «Выпить» sends RPC `drink`: in the bar with beer in hand, clinked or not — the server says. */
        drinks() {
            return inBar() && canWalk() && holdsBeer();
        },

        /**
         * The partner to report in RPC `clink.auto` for an automatic clink striking now (scene cue `clink` with `sides`
         * in config coordinates), or null: only in the bar and only when my walker is in the group with beer — the
         * nearest other side that holds beer.
         */
        autoClinkPartner(cue) {
            if (!inBar() || !holdsBeer() || !Array.isArray(cue?.sides)) return null;
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

        /** Whether a click on element `id` is a clink (RPC `clink {pumpkin}`) instead of `click`: a reachable pumpkin, likewise. */
        clinksPumpkin(id) {
            const element = findElement(id);
            return Boolean(element && isPumpkin(element) && element.reachable) && cursed() && canWalk();
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
            const before = sound && { scene: model.scene, stageMax: model.stageMax, step: model.finale?.step ?? null, walkers: new Set(model.walkers.map((w) => w.id)) };
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
            onChange(model);
        },

        start() {
            const spread = () => {
                root.style.setProperty('--spread', String(spreadX(root.clientWidth || window.innerWidth)));
                paintOut();
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
const UI = '[data-debug-panel], [data-lab-panel], [data-reveal], .help-link, .hud, [data-join], [data-bar-counter]';

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
 *
 * @param {{clinksWalker: (id: string) => boolean}} client  the client model's routing
 * @param {(target: {walker: string}) => void} clink  sends RPC `clink`; nothing is drawn until the `move` and `clink` events
 */
export function bindClinking(root, scene, client, clink) {
    if (!scene) return;
    const target = (event) => {
        if (event.target.closest?.(UI)) return null;
        const id = scene.walkerHit(event.clientX, event.clientY);
        return id && client.clinksWalker(id) ? id : null;
    };
    root.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.detail === 0) return;
        const id = target(event);
        if (!id) return;
        event.preventDefault();
        event.stopPropagation();
        clink({ walker: id });
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
 * counter button is no such control: a mouse click on it keeps the focus there, and with beer in hand it has nothing
 * to do with Space anyway.
 */
export function isDrinkKey(event, doc = globalThis.document) {
    if (event.code !== 'Space' && event.key !== ' ') return false;
    if (event.repeat || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return false;
    const taker = event.target?.closest?.(SPACE_TAKERS);
    if ((taker && !taker.matches('[data-bar-counter]')) || event.target?.isContentEditable) return false;
    return !doc?.querySelector?.('dialog[open]');
}

/**
 * The bar's input (docs/spec/frontend.md, «Бар и сдвиг номеров сцен»): the counter button asks for a mug, Space and
 * the «Выпить» button drink. Nothing is drawn locally: the walk comes as `move`, the mug as `take`, the gulp as `drink`.
 *
 * @param {{takesBeer: () => boolean, drinks: () => boolean}} client  the client model's routing
 * @param {object} actions
 * @param {() => void} actions.take  sends RPC `bar.take`
 * @param {() => void} actions.drink  sends RPC `drink`
 */
export function bindBar(root, client, { take, drink }, doc = globalThis.document) {
    root.querySelector('[data-bar-counter]')?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (client.takesBeer()) take();
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
 * Walking: a click on the ground — not on a pumpkin, not on a control, and not on a flyer (bindShooting swallows
 * those in the capture phase) — asks the server to walk there. Nothing moves locally; the `move` event does it.
 *
 * @param {() => boolean} canWalk  whether this browser has a character on the field
 * @param {(point: {x: number, y: number}) => void} move  sends RPC `move` in config coordinates
 */
export function bindWalking(root, scene, canWalk, move) {
    if (!scene) return;
    root.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.target.closest(`[data-element], ${UI}`) || !canWalk()) return;
        const point = scene.groundAt(event.clientX, event.clientY);
        if (point) move(point);
    });
}
