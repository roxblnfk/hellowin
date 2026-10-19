// Client model of docs/spec/frontend.md: applies channel events to the DOM hooks and to the pixel scene.
// Shared by app.js (live channel) and public/dev/lab.html (generated events).

const SCENE_NAMES = { 0: 'День: подготовка' };
const DEFAULT_STAGE_MAX = 4;

const int = (value, fallback = 0) => {
    const n = Number.parseInt(value, 10);
    return Number.isNaN(n) ? fallback : n;
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
        elements: [...root.querySelectorAll('[data-element]')].map((el) => ({
            id: el.dataset.element,
            type: el.dataset.type,
            x: Number.parseFloat(el.style.left),
            y: Number.parseFloat(el.style.top),
            size: el.dataset.size || 'm',
            stage: int(el.dataset.stage),
            clicks: int(el.dataset.clicks),
        })),
    };
}

/**
 * @param {HTMLElement} root  #app
 * @param {object|null} scene API of pixel/scene.js, or null without a canvas
 * @param {{requestState: () => Promise<object>}} transport  resolves with a fresh snapshot (RPC `state`)
 */
export function createClient(root, scene, { requestState }) {
    const model = readSnapshot(root);
    // stageMax per scene as far as the client has seen it: a `reset` event returns to scene 0 without one.
    const stageMaxByScene = new Map([[model.scene, model.stageMax]]);
    let syncing = null;

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
        set('scene-name', model.scene >= model.sceneStages ? 'Финал' : SCENE_NAMES[model.scene] ?? '');
        set('done', model.elements.filter((e) => e.stage >= model.stageMax).length);
        set('total', model.elements.length);
    }

    function paintAll() {
        document.body.dataset.scene = model.scene;
        document.body.toggleAttribute('data-final', model.scene >= model.sceneStages);
        root.dataset.round = model.round;
        root.dataset.sceneStages = model.sceneStages;
        root.dataset.stageMax = model.stageMax;
        root.dataset.threshold = model.threshold;
        model.elements.forEach(paintElement);
        paintHud();
    }

    function load(snapshot) {
        Object.assign(model, {
            round: snapshot.round,
            scene: snapshot.scene,
            sceneStages: snapshot.sceneStages,
            stageMax: snapshot.stageMax ?? stageMaxByScene.get(snapshot.scene) ?? DEFAULT_STAGE_MAX,
            threshold: snapshot.threshold,
        });
        stageMaxByScene.set(model.scene, model.stageMax);
        const known = new Map(model.elements.map((e) => [e.id, e]));
        model.elements = snapshot.elements.map((e) => ({ ...known.get(e.id), ...e, size: e.size ?? known.get(e.id)?.size ?? 'm' }));
        paintAll();
        scene?.render(snapshotOf());
    }

    function snapshotOf() {
        return { ...model, elements: model.elements.map((e) => ({ ...e })) };
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

        pumpkin(event) {
            const element = findElement(event.element);
            if (!element) return;
            element.stage = event.stage;
            element.clicks = 0;
            paintElement(element);
            paintHud();
            scene?.pumpkin(element.id, element.stage);
        },

        scene(event) {
            model.scene = event.scene;
            model.stageMax = event.stageMax;
            stageMaxByScene.set(model.scene, model.stageMax);
            paintAll();
            scene?.setScene(model.scene, model.stageMax);
        },

        reset(event) {
            model.round = event.round;
            model.scene = 0;
            model.stageMax = stageMaxByScene.get(0) ?? DEFAULT_STAGE_MAX;
            model.elements.forEach((e) => {
                e.stage = 0;
                e.clicks = 0;
            });
            paintAll();
            scene?.render(snapshotOf());
            if (!stageMaxByScene.has(0)) resync();
        },
    };

    return {
        model,
        load,
        resync,

        /** Applies one channel event (protocol.md); events of another round trigger a resync. */
        apply(event) {
            const handler = handlers[event?.type];
            if (!handler || syncing) return;
            if (event.type !== 'reset' && event.round !== model.round) {
                resync();
                return;
            }
            handler(event);
        },

        start() {
            paintAll();
        },
    };
}
