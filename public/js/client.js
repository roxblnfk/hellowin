// Client model of docs/spec/frontend.md: applies channel events to the DOM hooks and to the pixel scene.
// Shared by app.js (live channel) and public/dev/lab.html (generated events).

import { bonfireOpen, circleOpen, floats, isPumpkin, jumpOut, jumpedOut, spreadX } from './layout.js';

const SCENE_NAMES = { 0: 'День: подготовка', 1: 'Закат', 2: 'Ночь' };
const DEFAULT_STAGE_MAX = 4;
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
            return { ...element, size: el.dataset.size || 'm' };
        }),
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
    const bonfireOf = () => model.elements.find((e) => e.type === 'bonfire') ?? null;
    const circleOf = () => model.elements.find((e) => e.type === 'circle') ?? null;
    const pumpkinsOf = () => model.elements.filter(isPumpkin);
    // The bonfire's last stage per scene, likewise (HELLOWIN_BONFIRE_STAGE_MAX: scene 0 has none by default).
    const bonfireMaxByScene = new Map(bonfireOf() ? [[model.scene, bonfireOf().stageMax]] : []);
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

    function paintAll() {
        document.body.dataset.scene = model.scene;
        document.body.toggleAttribute('data-final', model.scene >= model.sceneStages);
        paintStory();
        root.dataset.round = model.round;
        root.dataset.sceneStages = model.sceneStages;
        root.dataset.stageMax = model.stageMax;
        root.dataset.threshold = model.threshold;
        paintElements();
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
            startedAt: snapshot.startedAt ?? model.startedAt,
            ready: Boolean(snapshot.ready),
            finale: snapshot.finale ?? null,
            paused: Boolean(snapshot.paused),
            zombies: Boolean(snapshot.zombies),
        });
        stageMaxByScene.set(model.scene, model.stageMax);
        const known = new Map(model.elements.map((e) => [e.id, e]));
        model.elements = snapshot.elements.map((e) => (!isPumpkin(e)
            ? { ...known.get(e.id), ...e }
            : { ...known.get(e.id), ...e, size: e.size ?? known.get(e.id)?.size ?? 'm' }));
        if (bonfireOf()) bonfireMaxByScene.set(model.scene, bonfireOf().stageMax);
        paintAll();
        scene?.render(snapshotOf());
        paintCircle();
        onChange(model);
    }

    // The scene plays the finale's step; `ready` at step 0 is the closed circle, the cue for the ritual pose.
    function paintFinale() {
        scene?.setFinale({ step: model.scene >= model.sceneStages ? model.finale?.step ?? null : null, ready: model.ready });
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
            scene?.click(element.id, { stage: element.stage, clicks: element.clicks, threshold: element.threshold ?? model.threshold });
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
            paintAll();
            scene?.setScene(model.scene, model.stageMax);
            handOut(event);
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
                if (e.type === 'bonfire') e.stageMax = bonfireMaxByScene.get(0) ?? 0;
                if (e.type === 'circle') e.stageMax = 0;
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

        /** Whether a click on the element would be accepted: a locked bonfire or circle is not worth an RPC. */
        clickable(id) {
            return !button(id)?.hasAttribute('data-locked');
        },
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
