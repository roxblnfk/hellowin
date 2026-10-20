import { toastOf } from '../toasts.js';

/*
 * Clinking glasses in the cursed world (docs/spec/scenario.md, «Чоканье»; docs/spec/frontend.md, «Финал»). Frontend
 * only, no messages: who clinks with whom, when and with which toast follows from the walkers' server paths and the
 * server clock, both the same on every client.
 *
 * Time runs in slots of SLOT_MS. At a slot's start the walkers with beer that stand (by their server path at that
 * moment) are joined into groups: two are neighbours when they stand within NEAR of each other in config coordinates
 * (so the screen size does not matter), a group is a chain of neighbours. A group is a candidate only in every
 * COOLDOWN-th slot, at a phase the hash of its ids picks, and there only when the hash of its ids and the slot says so
 * (CHANCE %). A candidate clinks unless two of its walkers were together in another candidate of the COOLDOWN - 1
 * slots before (the group changed: someone came or left) — so a pair clinks at most once per COOLDOWN × SLOT_MS. The
 * rule looks only at candidates, never at its own past answers, so a client that just opened the page agrees with one
 * that has been watching all along.
 */

export const SLOT_MS = 6000;
// The clink starts this far into its slot; the toast stays up to TOAST_GAP before the slot ends.
export const START_MS = 300;
const TOAST_GAP = 500;
const COOLDOWN = 4;
const CHANCE = 75;
// Neighbours: within an ellipse of these half-axes, in config units (% of the width and of the height).
const NEAR = { x: 9, y: 6 };

/** The clink's timeline, ms from its start: turn, reach, strike (the foam), back, the raised toast; then idle. */
export const CLINK = { reach: 400, strike: 750, back: 1100, raise: 1400, end: 3200, toast: 800, reply: 2400 };

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

/** FNV-1a of a string. */
function hash(text) {
    let h = 0x811c9dc5;
    for (let i = 0; i < text.length; i++) h = Math.imul(h ^ text.charCodeAt(i), 0x01000193);
    return h >>> 0;
}

/**
 * Where a walk stands at server time `at`, or null while it is on the way: before its start the walker stands at the
 * path's first point (where it stood when it set off), after its arrival at the last one.
 */
export function standingAt(walk, at) {
    const path = walk?.path;
    if (!path?.length) return null;
    if (at < (walk.startedAt ?? 0)) return path[0];
    if (at >= (walk.arrivesAt ?? 0)) return path[path.length - 1];
    return null;
}

/** Candidate groups of a slot: { key, hash, ids } with the ids sorted. */
function candidates(slot, walkers) {
    const at = slot * SLOT_MS;
    const standing = walkers
        .filter((w) => w.item === 'beer')
        .map((w) => ({ id: w.id, p: standingAt(w.walk, at) }))
        .filter((w) => w.p)
        .sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
    const parent = standing.map((_, i) => i);
    const root = (i) => (parent[i] === i ? i : (parent[i] = root(parent[i])));
    for (let i = 0; i < standing.length; i++) {
        for (let j = i + 1; j < standing.length; j++) {
            const dx = (standing[i].p.x - standing[j].p.x) / NEAR.x;
            const dy = (standing[i].p.y - standing[j].p.y) / NEAR.y;
            if (dx * dx + dy * dy <= 1) parent[root(j)] = root(i);
        }
    }
    const groups = new Map();
    standing.forEach((w, i) => {
        const r = root(i);
        if (!groups.has(r)) groups.set(r, []);
        groups.get(r).push(w.id);
    });
    const out = [];
    for (const ids of groups.values()) {
        if (ids.length < 2) continue;
        const key = ids.join('|');
        const h = hash(key);
        if ((slot + mix(h, 0x51)) % COOLDOWN === 0 && mix(h, slot) % 100 < CHANCE) out.push({ key, hash: h, ids });
    }
    return out;
}

/** Groups that clink in a slot: the candidates no two of whose walkers shared a candidate in the slots before. */
export function clinksOf(slot, walkers) {
    const earlier = [];
    for (let k = 1; k < COOLDOWN; k++) earlier.push(...candidates(slot - k, walkers).map((g) => new Set(g.ids)));
    return candidates(slot, walkers)
        .filter((g) => !earlier.some((set) => g.ids.filter((id) => set.has(id)).length >= 2))
        .map((g) => ({ ...g, slot, at: slot * SLOT_MS + START_MS, toast: toastOf(g.hash, slot) }));
}

/** How long a toast line stays up from CLINK.toast: long enough to read, within the slot. */
const toastUntil = (lines) => Math.min(SLOT_MS - START_MS - TOAST_GAP, CLINK.toast + 2000 + lines.join(' ').length * 65 + (lines.length > 1 ? 1600 : 0));

/**
 * The planner the scene keeps: `at(now, walkers)` gives the clinks of the slot `now` (server ms) falls in, while
 * their toast may still be up — [{ key, ids, at, toast, until }] — or an empty list. A slot is worked out once and
 * again only when a walker's path or item changes (the walk objects are compared by reference).
 */
export function createClinks() {
    let memo = { slot: null, refs: [], groups: [] };
    return {
        at(now, walkers) {
            const slot = Math.floor(now / SLOT_MS);
            const list = [...walkers];
            const refs = list.flatMap((w) => [w.id, w.item, w.walk]);
            if (memo.slot !== slot || refs.length !== memo.refs.length || refs.some((r, i) => r !== memo.refs[i])) {
                memo = { slot, refs, groups: clinksOf(slot, list).map((g) => ({ ...g, until: g.at + toastUntil(g.toast) })) };
            }
            return now >= slot * SLOT_MS + START_MS ? memo.groups : [];
        },
    };
}
