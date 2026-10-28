import { Container, Graphics, Text } from 'https://cdn.jsdelivr.net/npm/pixi.js@8.22.0/dist/pixi.min.mjs';
import { PALETTE } from './palette.js';

const FONT = 'ui-monospace, "Cascadia Mono", Consolas, "Courier New", monospace';
const clamp = (v, min, max) => Math.max(min, Math.min(max, v));

/**
 * Speech bubbles at screen resolution over the magnified world, like the owl's (app.css .owl-bubble): cream, a
 * stepped dark frame and a stepped tail down to the speaker's head. FrankenPHP's phrases, RoadRunner's «Мип-мип!» and
 * the cursed world's toasts. Call `say` every frame a bubble is up, then `sweep` once: a bubble not said this frame
 * goes away.
 *
 * @param {object} env  labels — the layer for the bubbles, layout() — { width, cssPx } of the scene
 */
export function createBubbles(env) {
    const bubbles = new Map();

    /** The bubble `key` with `text` over `head`, an art point. */
    function say(key, text, head) {
        const { width, cssPx } = env.layout();
        const u = Math.max(2, Math.round(cssPx));
        let b = bubbles.get(key);
        if (!b || b.text !== text || b.u !== u) {
            b?.node.destroy({ children: true });
            const node = new Container();
            const frame = new Graphics();
            const label = new Text({
                text,
                style: { fontFamily: FONT, fontSize: width < 600 ? 12 : 13, fontWeight: '700', fill: PALETTE.outline, wordWrap: true, wordWrapWidth: Math.min(240, width - 48), lineHeight: width < 600 ? 15 : 17 },
            });
            node.addChild(frame, label);
            env.labels.addChild(node);
            b = { key, text, u, node, frame, label, w: Math.ceil(label.width) + 20, h: Math.ceil(label.height) + 12, tail: null };
            label.position.set(10, 6);
            bubbles.set(key, b);
        }
        // Up and to the right of the head, inside the screen; the tail follows the head.
        const hx = head.x * cssPx;
        const hy = head.y * cssPx;
        const tailH = 5 * u;
        const left = Math.round(clamp(hx - 18, 8, width - b.w - 8));
        const top = Math.round(Math.max(8, hy - tailH - b.h - u));
        const tail = Math.round(clamp(hx - left, 4 * u, b.w - 4 * u));
        if (tail !== b.tail) {
            b.tail = tail;
            // Frames first, then the cream: the tail's two steps open into the bubble and into each other.
            const f = b.frame.clear();
            f.rect(-u, -u, b.w + 2 * u, b.h + 2 * u).fill(PALETTE.outline);
            f.rect(tail - 3 * u, b.h, 6 * u, 3 * u).fill(PALETTE.outline);
            f.rect(tail - 2 * u, b.h + 2 * u, 4 * u, 3 * u).fill(PALETTE.outline);
            f.rect(0, 0, b.w, b.h).fill(PALETTE.cream);
            f.rect(tail - 2 * u, b.h, 4 * u, 2 * u).fill(PALETTE.cream);
            f.rect(tail - u, b.h + 2 * u, 2 * u, 2 * u).fill(PALETTE.cream);
        }
        b.node.position.set(left, top);
        b.seen = true;
    }

    function sweep() {
        for (const [key, b] of bubbles) {
            if (b.seen) {
                b.seen = false;
                continue;
            }
            b.node.destroy({ children: true });
            bubbles.delete(key);
        }
    }

    /** The screen y of bubble `key`'s top frame row, CSS px; null when it is not up. */
    function top(key) {
        const b = bubbles.get(key);
        return b ? b.node.y - b.u : null;
    }

    return { say, sweep, top };
}
