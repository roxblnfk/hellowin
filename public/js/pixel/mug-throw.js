// The throw of an empty mug at a flyer (docs/spec/frontend.md, «Бросок кружки»): one timeline from the event's `at`
// for the scene and the sound, so the shatter, its sound and the flyer's fall meet on every client.

export const THROW = {
    // The mug held out on the aimed arm, then off the hand.
    release: 120,
    // Where it hits: the shatter, its sound and the fall of a flyer it brought down.
    impact: 540,
    // How long the thrower keeps the pose.
    pose: 900,
};

// A `throw` heard later than this after its `at` (a held-back event, a reconnect) is neither drawn nor heard.
export const THROW_STALE_MS = 1500;

// Quarter turns of the mug on its way, and how high the arc rises over the straight line, art px.
const QUARTERS = 6;
const LIFT = [8, 26];
const LIFT_SHARE = 0.3;

/**
 * How far into the throw `serverNow` is, ms, or null when the event is stale; an event from a clock running ahead
 * counts from now.
 */
export function throwElapsed(at, serverNow) {
    if (!Number.isFinite(at)) return 0;
    const late = serverNow - at;
    return late > THROW_STALE_MS ? null : Math.max(0, late);
}

/** The throw's phase `t` ms in: the mug in hand (`wind`), on its way (`fly`, `k` 0..1), or burst (`done`). */
export function throwPhase(t) {
    if (t < THROW.release) return { phase: 'wind', k: 0 };
    if (t < THROW.impact) return { phase: 'fly', k: (t - THROW.release) / (THROW.impact - THROW.release) };
    return { phase: 'done', k: 1 };
}

/**
 * The mug's point at share `k` of its flight from `from` to `to` (art px, y down): an arc lifted by a share of the
 * distance, and its turn — a quarter index 0..3, clockwise in the throw's direction.
 */
export function throwPoint(from, to, k) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    const lift = Math.min(LIFT[1], Math.max(LIFT[0], Math.hypot(dx, dy) * LIFT_SHARE));
    return {
        x: from.x + dx * k,
        y: from.y + dy * k - 4 * lift * k * (1 - k),
        turn: Math.floor(k * QUARTERS) % 4,
    };
}
