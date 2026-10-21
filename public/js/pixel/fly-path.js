/**
 * The flight of a fly (`kind: fly`, docs/spec/protocol.md): `from` → each of `stops` → `to`, a sit of `stop.sit` ms
 * at every stop. The server fixes only the points and the timing; the loops between them are built here from the
 * flyer's id alone, so every viewer draws the same fly at the same server moment. No DOM: sound.js uses it in Node too.
 */

// Loops per leg and their radius, percents of the screen's shorter side (protocol.md: 1–2 loops of 3–6 %).
const LOOPS = [1, 2];
const RADIUS = [3, 6];

// FNV-1a: a seed per flyer id and leg that is the same in every browser.
function hash(text) {
    let h = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) {
        h ^= text.charCodeAt(i);
        h = Math.imul(h, 0x01000193);
    }
    return h >>> 0;
}

const unit = (h, salt) => hash(`${h}:${salt}`) / 2 ** 32;
const point = (p) => ({ x: Number(p?.x) || 0, y: Number(p?.y) || 0 });

/**
 * The plan of a fly's flight from its `flyer` event: the points in order, the leg time — `duration` minus the sits,
 * split evenly over the legs — and each leg's loops.
 */
export function flyPlan(event) {
    const stops = (Array.isArray(event.stops) ? event.stops : [])
        .filter((s) => Number.isFinite(s?.x) && Number.isFinite(s?.y))
        .map((s) => ({ ...point(s), sit: Math.max(0, Number(s.sit) || 0) }));
    const points = [point(event.from), ...stops, point(event.to)];
    const sits = stops.reduce((sum, s) => sum + s.sit, 0);
    const count = points.length - 1;
    const leg = Math.max(1, (Math.max(1, Number(event.duration) || 0) - sits) / count);
    const legs = Array.from({ length: count }, (_, i) => {
        const seed = hash(`${event.flyer ?? ''}#${i}`);
        return {
            from: points[i],
            to: points[i + 1],
            loops: LOOPS[0] + Math.floor(unit(seed, 'n') * (LOOPS[1] - LOOPS[0] + 1)),
            radius: RADIUS[0] + unit(seed, 'r') * (RADIUS[1] - RADIUS[0]),
            dir: unit(seed, 'd') < 0.5 ? -1 : 1,
            // The circle's centre sits above the path (±0.6 rad): a fly coming down on a table top loops over it,
            // not through it.
            phase: Math.PI / 2 + (unit(seed, 'p') - 0.5) * 1.2,
            // Flies in already moving, leaves without slowing down; between stops it takes off and lands softly.
            ease: count === 1 ? 'linear' : i === 0 ? 'out' : i === count - 1 ? 'in' : 'inOut',
        };
    });
    return { legs, stops, leg };
}

const EASE = {
    linear: (t) => t,
    out: (t) => 1 - (1 - t) * (1 - t),
    in: (t) => t * t,
    inOut: (t) => t * t * (3 - 2 * t),
};

/**
 * A leg's point at eased progress `s` (0–1), screen percents. The loop is a circle through the point on the line, run
 * `loops` times as `s` goes 0 → 1: it adds nothing at both ends, so the fly lands exactly on the stop. `aspect` —
 * the screen's width / height, to keep the circle round in pixels.
 */
function legPoint(leg, s, aspect) {
    const side = Math.min(1, aspect);
    const rx = (leg.radius * side) / aspect;
    const ry = leg.radius * side;
    const a = leg.dir * 2 * Math.PI * leg.loops * s;
    return {
        x: leg.from.x + (leg.to.x - leg.from.x) * s + rx * (Math.cos(leg.phase + a) - Math.cos(leg.phase)),
        y: leg.from.y + (leg.to.y - leg.from.y) * s + ry * (Math.sin(leg.phase + a) - Math.sin(leg.phase)),
    };
}

// Facing by the direction along the curve at `s`; a vertical tangent keeps the leg's own direction.
function legLeft(leg, s, aspect) {
    const a = legPoint(leg, Math.max(0, s - 0.002), aspect);
    const b = legPoint(leg, Math.min(1, s + 0.002), aspect);
    const dx = b.x - a.x;
    return Math.abs(dx) > 1e-6 ? dx < 0 : leg.to.x < leg.from.x;
}

/**
 * Where the fly is `local` ms after `launchedAt`: { x, y } in screen percents, `left` — facing left, `sitting` — on a
 * stop, `since` — ms since it landed there. Before the launch it is at `from`, past the duration at `to`.
 */
export function flyAt(plan, local, aspect = 16 / 9) {
    let t = Math.max(0, local);
    for (let i = 0; i < plan.legs.length; i++) {
        const leg = plan.legs[i];
        if (t < plan.leg) {
            const s = EASE[leg.ease](t / plan.leg);
            return { ...legPoint(leg, s, aspect), left: legLeft(leg, s, aspect), sitting: false, since: 0 };
        }
        t -= plan.leg;
        const stop = plan.stops[i];
        if (stop && t < stop.sit) return { x: stop.x, y: stop.y, left: legLeft(leg, 1, aspect), sitting: true, since: t };
        if (stop) t -= stop.sit;
    }
    const last = plan.legs[plan.legs.length - 1];
    return { ...last.to, left: legLeft(last, 1, aspect), sitting: false, since: 0 };
}
