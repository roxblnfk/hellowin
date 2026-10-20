// Client model of docs/spec/frontend.md: applies channel events to the DOM hooks and to the pixel scene.
// Shared by app.js (live channel) and public/dev/lab.html (generated events).

import { spreadX } from './layout.js';

const SCENE_NAMES = { 0: 'День: подготовка', 1: 'Закат', 2: 'Ночь' };
const DEFAULT_STAGE_MAX = 4;
// The last finale step: past it the final scene is the cursed world.
const CURSED_STEP = 5;

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

const flag = (value) => value === '1' || value === 'true';

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
        bushes: parseList(root.dataset.bushes),
        walkers: parseList(root.dataset.walkers),
        now: int(root.dataset.now, 0),
        ready: flag(root.dataset.ready),
        finale: parseFinale(root.dataset.finale),
        paused: flag(root.dataset.paused),
        zombies: flag(root.dataset.zombies),
        elements: [...root.querySelectorAll('[data-element]')].map((el) => ({
            id: el.dataset.element,
            type: el.dataset.type,
            x: Number.parseFloat(el.style.getPropertyValue('--x')),
            y: Number.parseFloat(el.style.getPropertyValue('--y')),
            size: el.dataset.size || 'm',
            stage: int(el.dataset.stage),
            clicks: int(el.dataset.clicks),
        })),
    };
}

/**
 * @param {HTMLElement} root  #app
 * @param {object|null} scene API of pixel/scene.js, or null without a canvas
 * @param {object} options
 * @param {() => Promise<object>} options.requestState  resolves with a fresh snapshot (RPC `state`)
 * @param {{say: Function}|null} [options.owl]  speech bubble of owl.js
 * @param {{update: Function}|null} [options.reveal]  reveal panel of reveal.js
 * @param {(model: object) => void} [options.onChange]  after every applied event or snapshot (the console repaints)
 */
export function createClient(root, scene, { requestState, now = () => Date.now(), owl = null, reveal = null, onChange = () => {} }) {
    const model = { ...readSnapshot(root), me: null, moderator: false };
    // stageMax per scene as far as the client has seen it: a `reset` event returns to scene 0 without one.
    const stageMaxByScene = new Map([[model.scene, model.stageMax]]);
    let syncing = null;
    // Server clock minus ours, from the snapshot's `now`; 0 until one is known.
    let offset = 0;
    const takeTime = (serverTime) => {
        if (serverTime > 0) offset = serverTime - now();
    };
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
        el.toggleAttribute('data-ready', element.clicks >= model.threshold && element.stage < model.stageMax);
        el.toggleAttribute('data-done', element.stage >= model.stageMax);
    }

    function paintHud() {
        const set = (name, value) => {
            const node = hud(name);
            if (node) node.textContent = value;
        };
        set('round', model.round);
        set('scene', model.scene);
        set('scene-name', sceneName());
        set('done', model.elements.filter((e) => e.stage >= model.stageMax).length);
        set('total', model.elements.length);
        const ready = hud('ready');
        if (ready) ready.hidden = !model.ready;
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
        reveal?.update(model.finale);
        paintHud();
    }

    function paintAll() {
        document.body.dataset.scene = model.scene;
        document.body.toggleAttribute('data-final', model.scene >= model.sceneStages);
        paintStory();
        root.dataset.round = model.round;
        root.dataset.sceneStages = model.sceneStages;
        root.dataset.stageMax = model.stageMax;
        root.dataset.threshold = model.threshold;
        model.elements.forEach(paintElement);
        paintHud();
    }

    function load(snapshot) {
        takeTime(snapshot.now);
        Object.assign(model, {
            round: snapshot.round,
            scene: snapshot.scene,
            sceneStages: snapshot.sceneStages,
            stageMax: snapshot.stageMax ?? stageMaxByScene.get(snapshot.scene) ?? DEFAULT_STAGE_MAX,
            threshold: snapshot.threshold,
            bushes: snapshot.bushes ?? model.bushes,
            walkers: snapshot.walkers ?? model.walkers,
            ready: Boolean(snapshot.ready),
            finale: snapshot.finale ?? null,
            paused: Boolean(snapshot.paused),
            zombies: Boolean(snapshot.zombies),
        });
        stageMaxByScene.set(model.scene, model.stageMax);
        const known = new Map(model.elements.map((e) => [e.id, e]));
        model.elements = snapshot.elements.map((e) => ({ ...known.get(e.id), ...e, size: e.size ?? known.get(e.id)?.size ?? 'm' }));
        paintAll();
        scene?.render(snapshotOf());
        onChange(model);
    }

    function snapshotOf() {
        return { ...model, elements: model.elements.map((e) => ({ ...e })), walkers: model.walkers.map((w) => ({ ...w })) };
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
            if (event.threshold !== undefined && event.threshold !== model.threshold) {
                model.threshold = event.threshold;
                root.dataset.threshold = model.threshold;
                model.elements.forEach(paintElement);
            }
            paintElement(element);
            paintHud();
            scene?.click(element.id, { stage: element.stage, clicks: element.clicks, threshold: model.threshold });
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
        },

        move(event) {
            const w = model.walkers.find((x) => x.id === event.player?.id);
            if (!w || !event.walk) return;
            w.walk = event.walk;
            scene?.move(w.id, w.walk);
        },

        leave(event) {
            const id = event.player?.id;
            model.walkers = model.walkers.filter((x) => x.id !== id);
            scene?.leave(id);
        },

        pumpkin(event) {
            const element = findElement(event.element);
            if (!element) return;
            element.stage = event.stage;
            element.clicks = 0;
            paintElement(element);
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
            paintAll();
            scene?.setScene(model.scene, model.stageMax);
            handOut(event);
        },

        ready(event) {
            if (event.scene !== model.scene) return;
            model.ready = true;
            paintStory();
        },

        finale(event) {
            model.finale = event.reveal ? { step: event.step, reveal: event.reveal } : { step: event.step };
            paintStory();
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

        // The pause is about the room, not the story: it survives a reset (protocol.md, «Снимок»).
        reset(event) {
            model.round = event.round;
            model.scene = 0;
            model.ready = false;
            model.finale = null;
            model.zombies = false;
            model.stageMax = stageMaxByScene.get(0) ?? DEFAULT_STAGE_MAX;
            model.elements.forEach((e) => {
                e.stage = 0;
                e.clicks = 0;
            });
            paintAll();
            handOut(event);
            scene?.render(snapshotOf());
            if (!stageMaxByScene.has(0)) resync();
        },
    };

    // The item in every walker's hand follows the scene: `scene` and `reset` carry it instead of a walker event each.
    function handOut(event) {
        if (!('item' in event)) return;
        model.walkers.forEach((w) => {
            w.item = event.item;
            scene?.walker({ ...w });
        });
    }

    return {
        model,
        load,
        resync,

        /** This browser's player id from RPC `me` / `register` (`player.id`); null for an anonymous one. */
        setMe(id) {
            model.me = id ?? null;
            scene?.setMe(model.me);
        },

        /** Whether this browser has a character on the field and may send `move`. */
        canWalk() {
            return model.me !== null && model.walkers.some((w) => w.id === model.me);
        },

        /** Refreshes the clock offset from RPC `state` without touching the scene (after the tab comes back). */
        syncClock() {
            return requestState().then((snapshot) => takeTime(snapshot?.now)).catch((error) => console.warn('state rpc failed', error));
        },

        /** Applies one channel event (protocol.md); events of another round trigger a resync. */
        apply(event) {
            const handler = handlers[event?.type];
            if (!handler || syncing) return;
            if (event.type !== 'reset' && event.round !== model.round) {
                resync();
                return;
            }
            handler(event);
            onChange(model);
        },

        /** Whether this connection is the moderator's (RPC `me`, `moderator`); it decides whether the console shows. */
        setModerator(on) {
            model.moderator = Boolean(on);
            onChange(model);
        },

        start() {
            const spread = () => root.style.setProperty('--spread', String(spreadX(root.clientWidth || window.innerWidth)));
            spread();
            new ResizeObserver(spread).observe(root);
            paintAll();
        },
    };
}

// Controls laid over the scene: a press on them is never a shot or a step.
const UI = '[data-debug-panel], [data-lab-panel], [data-reveal], .help-link, .hud, [data-join]';

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
