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
 * Where a walker stands at a reachable pumpkin of the cursed world for a beer or a clink (docs/spec/protocol.md, RPC
 * `beer`), in config percents, before the server brings it onto the lawn: on the side of the mug (`side`, 1 or -1),
 * `gap[size]` past the pumpkin where it floats after the jump out on a wide screen (`out` = jumpOut there), level with
 * it. The scene draws a walker whose walk ends within `near` of it at the drawn mug, on every screen: the lawn's
 * nearest point is that close for every reachable pumpkin of the config.
 */
export const PUMPKIN_STAND = { out: 1.18, gap: { l: 10, m: 9, s: 8 }, near: 2.5 };
export function pumpkinStand(element, side) {
    const out = (v, root) => root + (v - root) * PUMPKIN_STAND.out;
    const gap = PUMPKIN_STAND.gap[element.size] ?? PUMPKIN_STAND.gap.m;
    return { x: Math.round((out(element.x, ROOT.x) + side * gap) * 10) / 10, y: Math.round(out(element.y, ROOT.y) * 10) / 10 };
}

/**
 * Scene numbers the art depends on (docs/spec/scenario.md, «Сцена»): the bar opens the evening, the day, the sunset
 * and the night follow; the final scene is `sceneStages` of the snapshot, never a constant here.
 */
export const SCENE = { bar: 0, day: 1, sunset: 2, night: 3 };

/** Whether a scene is the bar: no patch, no pumpkins, the hall with the counter instead. */
export const isBar = (scene) => scene === SCENE.bar;

/**
 * The item a walker shows in its hand (docs/spec/frontend.md, «Бар и сдвиг номеров сцен»): in the bar empty hands
 * (`item: null` of the server) carry the pistol, as on the day; elsewhere the server's item as it is. Drawing only —
 * the bar's rules (take, gulp, clink) look at the server's item.
 */
export const shownItem = (item, scene) => item ?? (isBar(scene) ? 'pistol' : null);

/**
 * The bar's geometry when the markup has none (`#app[data-bar-layout]`, `walk.bar` of app/config/hellowin.php;
 * protocol.md, «Бар»), in percents of the screen without the patch spread: the floor, the obstacles (the counter a
 * rectangle, the tables ellipses), the strip in front of the counter where a mug is taken, where characters put back
 * on the floor appear, the entrance door's threshold and how far a newcomer walks in from it, how near two walkers
 * stand for `clink.auto`, and the perches where flies land (each drawn exactly there by pixel/bar.js).
 */
export const BAR_LAYOUT = {
    area: { x1: 3, y1: 50, x2: 94, y2: 97 },
    obstacles: [
        { x1: 62.5, y1: 46, x2: 95, y2: 60 },
        { cx: 22, cy: 64, rx: 13, ry: 7 },
        { cx: 44, cy: 78, rx: 13, ry: 7 },
        { cx: 76, cy: 80, rx: 13, ry: 7 },
    ],
    counter: { x1: 66, x2: 92, y: 62 },
    spawn: { y1: 90, y2: 94 },
    door: { x: 12, y: 50 },
    door_in: 6,
    roadrunner: { x: 74, y: 46 },
    perches: [
        { x1: 3, x2: 97, y: 8, name: 'beam' },
        { x1: 9, x2: 15, y: 30, name: 'lintel' },
        { x1: 45, x2: 55, y: 36, name: 'mantel' },
        { x1: 67, x2: 91, y: 26, name: 'shelf-top' },
        { x1: 67, x2: 91, y: 33, name: 'shelf' },
        { x1: 25, x2: 25, y: 33, name: 'shield-1' },
        { x1: 36, x2: 36, y: 33, name: 'shield-2' },
        { x1: 31, x2: 31, y: 20, name: 'shield-3' },
        { x1: 61, x2: 61, y: 20, name: 'shield-4' },
        { x1: 18, x2: 22, y: 45, name: 'barrels' },
    ],
    clink_reach: 10,
};

/** The counter of a bar layout: its first rectangle obstacle. */
export const barCounterOf = (layout) => layout?.obstacles?.find((o) => Number.isFinite(o.x1)) ?? null;

/**
 * Whether a walk comes in through the bar's door: its path starts on the threshold `door` (within half a percent), as
 * the server sends a newcomer's walk (protocol.md, `walker`). False without a door.
 */
export const fromDoor = (walk, door) => {
    const p = walk?.path?.[0];
    return Boolean(p && door && Math.hypot(p.x - door.x, p.y - door.y) <= 0.5);
};

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
