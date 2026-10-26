// The dice of finale step 1 (docs/spec/frontend.md, «Кости»): the dice state of the snapshot, the attempt counter of
// RPC `roll`, and my own roll drawn big at the top of the page in DOM pixels. No PixiJS here: client.js and the node
// tests load it; the little dice over the walkers are the scene's (pixel/finale.js, DICE).

import { PALETTE } from './pixel/palette.js';
import { pick } from './i18n.js';

/** Pips of a face on a 3×3 grid, [column, row]. */
export const PIPS = {
    1: [[1, 1]],
    2: [[0, 0], [2, 2]],
    3: [[0, 0], [1, 1], [2, 2]],
    4: [[0, 0], [2, 0], [0, 2], [2, 2]],
    5: [[0, 0], [2, 0], [1, 1], [0, 2], [2, 2]],
    6: [[0, 0], [0, 1], [0, 2], [2, 0], [2, 1], [2, 2]],
};

// My big roll: the dice tumble and land one after another by ROLL_LAND_MS, hold until ROLL_HOLD_MS, then dim.
export const ROLL_LAND_MS = 1600;
export const ROLL_HOLD_MS = 2000;
const FACE_MS = 70;
const LAND_STAGGER_MS = 200;
const SETTLE_MS = 90;
// The little dice over a walker: tumbling for ROLL_TUMBLE_MS, standing until ROLL_SHOW_MS, blinking out at the end.
export const ROLL_TUMBLE_MS = 300;
export const ROLL_SHOW_MS = 2000;
export const ROLL_BLINK_MS = 400;
// A `roll` older than this by its `at` is not drawn.
export const ROLL_STALE_MS = 2000;
// How long my RPC reply waits for its own `roll` event before it counts as a repeat (no event comes for one).
export const REPEAT_WAIT_MS = 800;

/** Whether three dice are 6-6-6. */
export const isSix = (dice) => Array.isArray(dice) && dice.length === 3 && dice.every((d) => d === 6);

/** Whether a `roll` event or reply is the win: 6-6-6 counted — on the pause of the progress it is not (`counted: false`). */
export const winsRoll = (roll) => isSix(roll?.dice) && roll?.counted !== false;

const isFace = (d) => Number.isInteger(d) && d >= 1 && d <= 6;

/** Three faces of an event or a reply, or null when they are not three numbers 1–6. */
export const facesOf = (dice) => (Array.isArray(dice) && dice.length === 3 && dice.every(isFace) ? [...dice] : null);

/** `dice` of the snapshot or the event: {on, winner?}, or null without dice. */
export function diceOf(dice) {
    if (!dice || typeof dice !== 'object' || typeof dice.on !== 'boolean') return null;
    const w = dice.winner;
    const winner = w && Number.isInteger(w.attempt) && (w.owl === true || w.player?.id) ? (w.owl === true ? { owl: true, attempt: w.attempt } : { player: { id: w.player.id, name: w.player.name ?? null }, attempt: w.attempt }) : null;
    return winner ? { on: dice.on, winner } : { on: dice.on };
}

// Non-breaking hyphens: a narrow panel must not break «6-6-6» or «3-й» across lines.
const SIX_TEXT = '6‑6‑6';
const SUFFIX = { one: 'st', two: 'nd', few: 'rd', other: 'th' };
const ORDINAL = new Intl.PluralRules('en', { type: 'ordinal' });
const TEXT = pick({
    ru: {
        owl: (attempt) => `${SIX_TEXT} выбросила сова, с ${attempt}‑й попытки`,
        player: (name, attempt) => `${SIX_TEXT} выбросил ${name} с ${attempt}‑й попытки`,
        someone: 'кто-то',
        roll: `Бросайте кости! Нужно ${SIX_TEXT}`,
    },
    en: {
        owl: (attempt) => `The owl rolled ${SIX_TEXT} on the ${attempt}${SUFFIX[ORDINAL.select(attempt)]} try`,
        player: (name, attempt) => `${name} rolled ${SIX_TEXT} on the ${attempt}${SUFFIX[ORDINAL.select(attempt)]} try`,
        someone: 'someone',
        roll: `Roll the dice! You need ${SIX_TEXT}`,
    },
});

/** The line over the dice: the call to roll while the dice are on, who rolled 6-6-6 after it; null — nothing to say. */
export function diceNote(dice) {
    if (dice?.winner) {
        const { attempt } = dice.winner;
        if (dice.winner.owl) return TEXT.owl(attempt);
        return TEXT.player(dice.winner.player.name?.trim() || TEXT.someone, attempt);
    }
    return dice?.on ? TEXT.roll : null;
}

/**
 * The attempts of RPC `roll {attempt}` (protocol.md): the first is 1, a successful reply makes the next one its
 * `attempt` + 1, and `bad attempt` with `last` resyncs to `last + 1` and rolls again — once, never in a loop. The
 * snapshot does not carry my last attempt: a reload starts from 1 and lands here.
 *
 * @param {(attempt: number) => Promise<object|undefined>} send  RPC `roll`, resolving with the reply's data
 */
export function createRoller(send) {
    let attempt = 1;
    let pending = false;
    return {
        get attempt() {
            return attempt;
        },
        get pending() {
            return pending;
        },
        /** Rolls once; resolves with the reply (null while a roll is already on its way). */
        async roll() {
            if (pending) return null;
            pending = true;
            try {
                let reply = await send(attempt);
                if (reply?.ok === false && reply.error === 'bad attempt' && Number.isInteger(reply.last) && reply.last >= 0) {
                    attempt = reply.last + 1;
                    reply = await send(attempt);
                }
                if (reply?.ok && Number.isInteger(reply.attempt)) attempt = reply.attempt + 1;
                return reply ?? null;
            } finally {
                pending = false;
            }
        },
        /** A new round: the attempts start again from 1. */
        forget() {
            attempt = 1;
        },
    };
}

// Integer hash for the faces flashing by while a die tumbles: the same roll tumbles the same way on a replay.
function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

/**
 * Die `i` of my big roll `t` ms in: {face} — standing on a face (while tumbling, a passing one), or {tumble: 0|1} — a
 * frame of it turning over; `dy` — how high it bounces, in art px (negative is up); `landed`.
 */
export function bigDieAt(dice, i, t, seed = 0) {
    const stop = ROLL_LAND_MS - (2 - i) * LAND_STAGGER_MS;
    if (t >= stop) return { face: dice[i], dy: t - stop < SETTLE_MS ? -1 : 0, landed: true };
    const k = Math.floor(Math.max(0, t) / FACE_MS);
    const height = 6 * (1 - t / stop);
    const dy = -Math.round(height * Math.abs(Math.sin((t / stop) * Math.PI * 4)));
    if (k % 3 === 2) return { tumble: Math.floor(k / 3) % 2, dy, landed: false };
    return { face: 1 + (mix(seed + i * 7919, k) % 6), dy, landed: false };
}

// The big die: 12×12 art px. O outline, W the face, s its shade, P a pip (2×2), h the top face turned away.
const BIG_BODY = [
    '..OOOOOOOO..',
    '.OWWWWWWWWO.',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    'OWWWWWWWWWsO',
    '.OssssssssO.',
    '..OOOOOOOO..',
];
const BIG_TUMBLE = [
    [
        '.....OO.....',
        '....OWWO....',
        '...OWWWWO...',
        '..OWWWWWWO..',
        '.OWWWWWWWWO.',
        'OWWWWPPWWWsO',
        'OWWWWPPWWWsO',
        '.OWWWWWWWsO.',
        '..OWWWWWsO..',
        '...OWWWsO...',
        '....OWsO....',
        '.....OO.....',
    ],
    [
        '............',
        '..OOOOOOOO..',
        '.OhhhhhhhhO.',
        'OhhhhhhhhhhO',
        'OOOOOOOOOOOO',
        'OWWWWWWWWWsO',
        'OWPPWWWPPWsO',
        'OWPPWWWPPWsO',
        'OWWWWWWWWWsO',
        '.OssssssssO.',
        '..OOOOOOOO..',
        '............',
    ],
];
const PLAIN = { O: 'outline', W: 'cream', s: 'hayLight', P: 'outline', h: 'grayLight' };
const FLARED = { O: 'outline', W: 'crimson', s: 'crimsonDark', P: 'cream', h: 'crimsonDark' };

function bigFace(face) {
    const g = BIG_BODY.map((r) => [...r]);
    for (const [cx, cy] of PIPS[face]) {
        const x = 2 + cx * 3;
        const y = 2 + cy * 3;
        g[y][x] = g[y][x + 1] = g[y + 1][x] = g[y + 1][x + 1] = 'P';
    }
    return g.map((r) => r.join(''));
}

const hex = (key) => `#${PALETTE[key].toString(16).padStart(6, '0')}`;
const svgCache = new Map();

/** A die as inline SVG: one rect per run of a colour in a row, crisp edges, so it scales like pixel art. */
export function dieSvg(art, flared = false) {
    const key = `${typeof art === 'number' ? art : `t${art.tumble}`}:${flared}`;
    if (svgCache.has(key)) return svgCache.get(key);
    const rows = typeof art === 'number' ? bigFace(art) : BIG_TUMBLE[art.tumble];
    const legend = flared ? FLARED : PLAIN;
    let rects = '';
    rows.forEach((row, y) => {
        for (let x = 0; x < row.length;) {
            const ch = row[x];
            let end = x + 1;
            while (end < row.length && row[end] === ch) end++;
            if (legend[ch]) rects += `<rect x="${x}" y="${y}" width="${end - x}" height="1" fill="${hex(legend[ch])}"/>`;
            x = end;
        }
    });
    const svg = `<svg viewBox="0 0 12 12" shape-rendering="crispEdges" aria-hidden="true">${rects}</svg>`;
    svgCache.set(key, svg);
    return svg;
}

/**
 * My roll, big, in `[data-dice]`: three `[data-dice-face]` (`data-value` — the face) tumbling and landing from the
 * moment the client calls `start`, `[data-dice-rolling]` on their row while they roll, `data-dice-win` for a 6-6-6 that wins.
 * Time comes from `now` (the client's clock), so the lab's frozen clock drives it too; `frame()` repaints.
 *
 * @param {HTMLElement} root  #app
 * @param {object} options
 * @param {() => number} options.now  ms
 * @param {() => boolean} [options.reduced]  prefers-reduced-motion: the result at once
 */
export function createDicePanel(root, { now, reduced = () => false }) {
    const row = root.querySelector('[data-dice-faces]');
    const faces = [...(root.querySelectorAll?.('[data-dice-face]') ?? [])];
    let roll = null;
    const drawn = faces.map(() => '');

    function paint() {
        if (!row) return false;
        row.hidden = roll === null;
        if (!roll) return false;
        const t = reduced() ? ROLL_HOLD_MS : now() - roll.at;
        const { win } = roll;
        const landed = t >= ROLL_LAND_MS;
        faces.forEach((el, i) => {
            const die = bigDieAt(roll.dice, i, t, roll.seed);
            const flared = win && landed && Math.floor((t - ROLL_LAND_MS) / 150) % 2 === 0;
            const art = die.face ?? { tumble: die.tumble };
            const key = `${die.face ?? `t${die.tumble}`}:${flared}`;
            if (drawn[i] !== key) {
                drawn[i] = key;
                el.innerHTML = dieSvg(art, flared);
            }
            el.dataset.value = String(landed || die.landed ? roll.dice[i] : die.face ?? '');
            el.style.setProperty('--dy', String(die.dy));
        });
        row.toggleAttribute('data-dice-rolling', !landed);
        row.toggleAttribute('data-dice-win', win && landed);
        row.toggleAttribute('data-dice-dim', t >= ROLL_HOLD_MS && !win);
        return t < ROLL_HOLD_MS + 300;
    }

    paint();
    return {
        /** My `roll` event: the dice start rolling now; `win` — a counted 6-6-6 (winsRoll). */
        start(dice, seed = 0, win = isSix(dice)) {
            roll = { dice, at: now(), seed, win };
            return paint();
        },
        /** A repeat answered without an event: the same dice, already landed. */
        settle(dice, win = isSix(dice)) {
            roll = { dice, at: now() - ROLL_HOLD_MS, seed: 0, win };
            return paint();
        },
        /** Whether my dice are still rolling (the button waits for them). */
        rolling() {
            return roll !== null && !reduced() && now() - roll.at < ROLL_LAND_MS;
        },
        clear() {
            roll = null;
            drawn.fill('');
            paint();
        },
        /** Repaints for the current time; true while something still moves. */
        frame: paint,
    };
}
