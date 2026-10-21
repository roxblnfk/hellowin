// Screen geometry shared by the DOM buttons (client.js) and the canvas (pixel/scene.js), so both put a pumpkin in
// the same place.

/** The root every vine grows from, in percents of the screen like element positions. */
export const ROOT = { x: 50, y: 63 };

/**
 * Horizontal spread of the patch around the root for a screen `width` CSS pixels wide. The config lays pumpkins
 * out for a wide screen; on narrower ones x moves away from the centre so the bigger-looking pumpkins still fit.
 */
export function spreadX(width) {
    return Math.min(1.6, Math.max(1, 1300 / width));
}

/** Screen x in percent for a config x. */
export const spreadLeft = (x, width) => ROOT.x + (x - ROOT.x) * spreadX(width);

/**
 * How far the lit pumpkins jump away from the root (docs/spec/scenario.md, scene 2) on a screen `width` CSS pixels
 * wide: every offset from the root grows by this factor. 1.18 on a wide screen keeps the far ring under the horizon
 * and the near one above the HUD; a phone, where the spread already pushes the ring to the screen sides, gets only a
 * small hop outward.
 */
export const jumpOut = (width) => 1 + 0.18 / spreadX(width) ** 3;

/**
 * Scene numbers the art depends on (docs/spec/scenario.md, «Сцена»): the bar opens the evening, the day, the sunset
 * and the night follow; the final scene is `sceneStages` of the snapshot, never a constant here.
 */
export const SCENE = { bar: 0, day: 1, sunset: 2, night: 3 };

/** Whether a scene is the bar: no patch, no pumpkins, the hall with the counter instead. */
export const isBar = (scene) => scene === SCENE.bar;

/**
 * The bar's geometry when the markup has none (`#app[data-bar-layout]`, `walk.bar` of app/config/hellowin.php;
 * protocol.md, «Бар»), in percents of the screen without the patch spread: the floor, the obstacles (the counter a
 * rectangle, the tables ellipses), the strip in front of the counter where a mug is taken, where newcomers appear,
 * and how near two walkers stand for `clink.auto`.
 */
export const BAR_LAYOUT = {
    area: { x1: 3, y1: 50, x2: 97, y2: 97 },
    obstacles: [
        { x1: 64, y1: 46, x2: 100, y2: 60 },
        { cx: 22, cy: 64, rx: 10, ry: 4.5 },
        { cx: 44, cy: 78, rx: 10, ry: 4.5 },
        { cx: 76, cy: 80, rx: 10, ry: 4.5 },
    ],
    counter: { x1: 66, x2: 95, y: 62 },
    spawn: { y1: 90, y2: 94 },
    clink_reach: 10,
};

/** The counter of a bar layout: its first rectangle obstacle. */
export const barCounterOf = (layout) => layout?.obstacles?.find((o) => Number.isFinite(o.x1)) ?? null;

/** The stage from which a pumpkin floats over its place (the last stage of the spell). */
export const FLOAT_STAGE = 11;

/** Pumpkins are the elements of type `pumpkin` (an element without a type is one too); bonfire, circle and others are not. */
export const isPumpkin = (e) => (e.type ?? 'pumpkin') === 'pumpkin';

/**
 * Whether the pumpkins have jumped out: every pumpkin is lit at the sunset, or any later scene. Derived from the state
 * alone, so a reload shows the same layout.
 */
export function jumpedOut(scene, stageMax, elements) {
    if (scene > SCENE.sunset) return true;
    const pumpkins = elements.filter(isPumpkin);
    return scene === SCENE.sunset && pumpkins.length > 0 && pumpkins.every((e) => e.stage >= stageMax);
}

/**
 * Whether a pumpkin floats: the last stage of the spell, or anything in the final scene — except finale steps 2–4,
 * when the pumpkins have dropped to the ground (docs/spec/scenario.md, «Финал»).
 */
export function floats(stage, scene, finalScene, step = null) {
    if (isBar(scene) || (scene >= finalScene && step >= 2 && step <= 4)) return false;
    return stage >= FLOAT_STAGE || scene >= finalScene;
}

/**
 * Whether the bonfire takes clicks: before the final scene (there the circle on its pit does), every pumpkin of the
 * scene done and the bonfire below its last stage of the scene.
 */
export function bonfireOpen(bonfire, stageMax, elements, scene, finalScene) {
    if (!bonfire || isBar(scene) || scene >= finalScene || bonfire.stage >= (bonfire.stageMax ?? 0)) return false;
    return elements.filter(isPumpkin).every((e) => e.stage >= stageMax);
}

/**
 * Whether the magic circle takes clicks: only in the final scene at finale step 0, below its last stage
 * (docs/spec/scenario.md, «Магический круг»).
 */
export function circleOpen(circle, scene, finalScene, finale) {
    return Boolean(circle) && scene >= finalScene && (finale?.step ?? 0) === 0 && circle.stage < (circle.stageMax ?? 0);
}
