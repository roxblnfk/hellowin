/**
 * The route of a wandering zombie worker of finale steps 5–6 (`flyer` with `wander: true`, docs/spec/protocol.md,
 * «Бродячие зомби»). The server sends only where it rises and when; the walk from there is built here from the
 * flyer's id alone, leg by leg, so every viewer sees the same zombie at the same spot at the same server moment. No
 * DOM: sound.js and the Node tests use it too.
 */

import { ROOT } from '../layout.js';

// The walk zone in config percents: the lawn and the scorched patch (app/config/hellowin.php, `walk.area`) a little
// inside its edges, without the circle in the middle where FrankenPHP stands.
export const WANDER_AREA = { x1: 5, y1: 46, x2: 95, y2: 95 };
export const WANDER_CIRCLE = { cx: 50, cy: 63, rx: 12, ry: 9 };

// The rise out of the ground, then legs of STEP percents at SPEED %/s (a third of the characters' HELLOWIN_WALK_SPEED)
// with a PAUSE after each; y steps are flattened a little, so a leg reads as a shuffle across the lawn, not up it.
export const RISE_MS = 600;
const SPEED = 4;
const STEP = [4, 14];
const PAUSE = [600, 2500];
const Y_SCALE = 0.75;
const TRIES = 12;
// Where a zombie with no free direction heads: the open middle of its quarter of the lawn, clear of the circle.
const homeOf = (p) => ({ x: p.x < WANDER_CIRCLE.cx ? 22 : 78, y: p.y < WANDER_CIRCLE.cy ? 52 : 85 });

function hash(text) {
    let h = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) {
        h ^= text.charCodeAt(i);
        h = Math.imul(h, 0x01000193);
    }
    return h >>> 0;
}

const unit = (h, salt) => hash(`${h}:${salt}`) / 2 ** 32;
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

const inCircle = (p) => ((p.x - WANDER_CIRCLE.cx) / WANDER_CIRCLE.rx) ** 2 + ((p.y - WANDER_CIRCLE.cy) / WANDER_CIRCLE.ry) ** 2 < 1;

/** Whether a point is somewhere a wandering zombie may walk. */
export const inWanderZone = (p) => p.x >= WANDER_AREA.x1 && p.x <= WANDER_AREA.x2 && p.y >= WANDER_AREA.y1 && p.y <= WANDER_AREA.y2 && !inCircle(p);

const clear = (a, b) => Array.from({ length: 12 }, (_, i) => (i + 1) / 12).every((t) => !inCircle({ x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t }));

/** Leg `k` of zombie `id` from point `p` at server-relative time `at`: its target, walk time and the pause after. */
function leg(id, k, p, at) {
    const h = hash(`${id}#${k}`);
    let to = null;
    for (let i = 0; i < TRIES && !to; i++) {
        const a = unit(h, `a${i}`) * 2 * Math.PI;
        const d = STEP[0] + unit(h, `d${i}`) * (STEP[1] - STEP[0]);
        const q = { x: p.x + Math.cos(a) * d, y: p.y + Math.sin(a) * d * Y_SCALE };
        if (inWanderZone(q) && clear(p, q)) to = q;
    }
    if (!to) {
        // Cornered (or standing in the circle): a step toward the open lawn.
        const home = homeOf(p);
        const dx = home.x - p.x;
        const dy = home.y - p.y;
        const len = Math.hypot(dx, dy) || 1;
        const d = Math.min(len, STEP[1]);
        to = { x: clamp(p.x + (dx / len) * d, WANDER_AREA.x1, WANDER_AREA.x2), y: clamp(p.y + (dy / len) * d, WANDER_AREA.y1, WANDER_AREA.y2) };
    }
    const walk = Math.max(1, (Math.hypot(to.x - p.x, to.y - p.y) / SPEED) * 1000);
    const pause = PAUSE[0] + unit(h, 'p') * (PAUSE[1] - PAUSE[0]);
    return { k, from: p, to, at, walk, pause, left: to.x < p.x, turn: unit(h, 't') < 0.35 };
}

/**
 * Where zombie `id`, risen at `from` (config percents), is `local` ms after its `launchedAt`: { x, y } in config
 * percents, `left` — facing left, `phase` — 'rise', 'walk' or 'pause', `since` — ms into that phase. `memo` (any
 * object kept per zombie) holds the current leg, so a call per frame does not replay the whole route from the rise.
 */
export function wanderAt(id, from, local, memo = null) {
    const t = Math.max(0, local);
    const start = { x: Number(from?.x) || 0, y: Number(from?.y) || 0 };
    let l = memo?.leg && memo.leg.at <= t && memo.id === id ? memo.leg : leg(id, 0, start, RISE_MS);
    if (t < RISE_MS) return { ...start, left: l.left, phase: 'rise', since: t };
    while (t >= l.at + l.walk + l.pause) l = leg(id, l.k + 1, l.to, l.at + l.walk + l.pause);
    if (memo) Object.assign(memo, { id, leg: l });
    const into = t - l.at;
    if (into < l.walk) {
        const s = into / l.walk;
        return { x: l.from.x + (l.to.x - l.from.x) * s, y: l.from.y + (l.to.y - l.from.y) * s, left: l.left, phase: 'walk', since: into };
    }
    const since = into - l.walk;
    // Now and then it turns round halfway through the pause.
    const left = l.turn && since >= l.pause / 2 ? !l.left : l.left;
    return { ...l.to, left, phase: 'pause', since };
}

/**
 * Where route point `p` (config percents) stands on a screen `width` CSS px wide: screen percents { x, y }. The zone
 * spreads round the root by `spread` like the characters' patch (layout.js, `spreadX`) while its sides stay `margin` px
 * inside the edges, and narrower when they would not; its bottom is squeezed above `floor` (percent of the height,
 * where the bottom controls begin). A phone otherwise shows a third of the zone at its edges and hides the bottom
 * under the HUD, where a zombie cannot be shot.
 */
export function wanderOnScreen(p, { width, spread = 1, margin = 0, floor = 100 }) {
    const m = (margin / Math.max(1, width)) * 100;
    const fit = Math.min((ROOT.x - m) / (ROOT.x - WANDER_AREA.x1), (100 - m - ROOT.x) / (WANDER_AREA.x2 - ROOT.x));
    const bottom = clamp(floor, WANDER_AREA.y1, WANDER_AREA.y2);
    const y = clamp(p.y, WANDER_AREA.y1, WANDER_AREA.y2);
    return {
        x: ROOT.x + (p.x - ROOT.x) * Math.max(0, Math.min(spread, fit)),
        y: WANDER_AREA.y1 + ((y - WANDER_AREA.y1) * (bottom - WANDER_AREA.y1)) / (WANDER_AREA.y2 - WANDER_AREA.y1),
    };
}
