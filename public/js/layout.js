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
