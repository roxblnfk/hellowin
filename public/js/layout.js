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
 * How far the lit pumpkins jump away from the root (docs/spec/scenario.md, scene 1) on a screen `width` CSS pixels
 * wide: every offset from the root grows by this factor. 1.18 on a wide screen keeps the far ring under the horizon
 * and the near one above the HUD; a phone, where the spread already pushes the ring to the screen sides, gets only a
 * small hop outward.
 */
export const jumpOut = (width) => 1 + 0.18 / spreadX(width) ** 3;

/** The stage from which a pumpkin floats over its place (the last stage of the spell). */
export const FLOAT_STAGE = 11;

/** Pumpkins are the elements of type `pumpkin` (an element without a type is one too); bonfire, circle and others are not. */
export const isPumpkin = (e) => (e.type ?? 'pumpkin') === 'pumpkin';

/**
 * Whether the pumpkins have jumped out: every pumpkin is lit in scene 1, or any later scene. Derived from the state
 * alone, so a reload shows the same layout.
 */
export function jumpedOut(scene, stageMax, elements) {
    if (scene >= 2) return true;
    const pumpkins = elements.filter(isPumpkin);
    return scene === 1 && pumpkins.length > 0 && pumpkins.every((e) => e.stage >= stageMax);
}

/** Whether a pumpkin floats: the last stage of the spell, or anything in the final scene. */
export const floats = (stage, scene, finalScene) => stage >= FLOAT_STAGE || scene >= finalScene;

/** Whether the bonfire takes clicks: every pumpkin of the scene done and the bonfire below its last stage of the scene. */
export function bonfireOpen(bonfire, stageMax, elements) {
    if (!bonfire || bonfire.stage >= (bonfire.stageMax ?? 0)) return false;
    return elements.filter(isPumpkin).every((e) => e.stage >= stageMax);
}

/**
 * Whether the magic circle takes clicks: only in the final scene at finale step 0, below its last stage
 * (docs/spec/scenario.md, «Магический круг»).
 */
export function circleOpen(circle, scene, finalScene, finale) {
    return Boolean(circle) && scene >= finalScene && (finale?.step ?? 0) === 0 && circle.stage < (circle.stageMax ?? 0);
}
