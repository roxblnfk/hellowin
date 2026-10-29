// The evening's top shooters (docs/spec/frontend.md, «Топ стрелков»): `top` of the snapshot and the event (`dawn` from a
// server before the rename), my place in the board, and the pixel table at the side with rows that slide when someone is
// overtaken. No PixiJS here: client.js and the node tests load it.

import { pick } from './i18n.js';

/** Rows of the unfolded table on a wide screen. */
export const DAWN_TOP = 10;
/** Rows of the unfolded table on a phone at the dawn. */
export const DAWN_TOP_PHONE = 5;
/** Leaders of the folded table (and of a phone's unfolded one outside the dawn); my row goes under them. */
export const DAWN_FOLDED = 3;
// How long a row slides from its old place to the new one.
export const DAWN_MOVE_MS = 300;
const FLASH_MS = 600;
const STORAGE_KEY = 'hellowin:dawn-top';
const SHUT_KEY = 'hellowin:top-shut';
const TEXT = pick({
    ru: { empty: 'Сбивайте уток рапирой!', someone: 'кто-то', me: 'ты', top: 'топ', title: 'Топ стрелков', all: 'весь топ', fold: 'свернуть' },
    en: { empty: 'Knock the ducks down with the rapier!', someone: 'someone', me: 'you', top: 'top', title: 'Top shooters', all: 'show all', fold: 'fold' },
});
export const DAWN_EMPTY = TEXT.empty;

/**
 * `top` (or the older `dawn`) of the snapshot or the event: {board} with the rows in the server's order, or null without
 * one. A row's score is the player's points (`points`; `shots` from a server that still counts flyers).
 */
export function dawnOf(dawn) {
    if (!dawn || typeof dawn !== 'object' || !Array.isArray(dawn.board)) return null;
    const board = dawn.board
        .filter((row) => row?.player?.id && Number.isInteger(row.points ?? row.shots))
        .map((row) => ({ player: { id: String(row.player.id), name: row.player.name ?? null }, shots: row.points ?? row.shots }));
    return { board };
}

/** A shooter's name as the table prints it. */
export const nameOf = (name) => (typeof name === 'string' && name.trim()) || TEXT.someone;

/**
 * The table's rows: the first `top` of the board, mine marked. When I am in the board but below the top, `mine` is my
 * own row (null otherwise): in the last slot instead of the top's last (`below` false — the unfolded ten), or under the
 * whole top (`below` true — the folded three). The server's order is the ranking, so the place is the index + 1.
 */
export function dawnRows(board, me, top = DAWN_TOP, below = false) {
    const index = me === null || me === undefined ? -1 : board.findIndex((row) => row.player.id === me);
    const rowOf = (row, i) => ({ id: row.player.id, name: nameOf(row.player.name), shots: row.shots, place: i + 1, me: i === index });
    if (index < top) return { rows: board.slice(0, top).map(rowOf), mine: null };
    return { rows: board.slice(0, below ? top : top - 1).map(rowOf), mine: rowOf(board[index], index) };
}

/** How many rows a view shows and where my row goes: {top, below}. */
export function shapeOf({ compact, folded, atDawn }) {
    if (compact) return atDawn ? { top: DAWN_TOP_PHONE, below: false } : { top: DAWN_FOLDED, below: true };
    return folded ? { top: DAWN_FOLDED, below: true } : { top: DAWN_TOP, below: false };
}

/** The folded strip of a phone: «#3 · ты · 7 · топ», or a plain call when I am not in the board. */
export function stripText(board, me) {
    const index = me === null || me === undefined ? -1 : board.findIndex((row) => row.player.id === me);
    if (index >= 0) return `#${index + 1} · ${TEXT.me} · ${board[index].shots} · ${TEXT.top}`;
    return board.length ? TEXT.title : DAWN_EMPTY;
}

// The player's «Топ» in the HUD: whether the table is put away (`'1'`), remembered like the fold.
function readShut(storage) {
    try {
        return storage?.getItem(SHUT_KEY) === '1';
    } catch {
        return false;
    }
}

function writeShut(storage, shut) {
    try {
        storage?.setItem(SHUT_KEY, shut ? '1' : '0');
    } catch {
        // Private mode or blocked storage: the table just shows next time.
    }
}

function readFolded(storage) {
    try {
        return storage?.getItem(STORAGE_KEY) !== 'open';
    } catch {
        return true;
    }
}

function writeFolded(storage, folded) {
    try {
        storage?.setItem(STORAGE_KEY, folded ? 'closed' : 'open');
    } catch {
        // Private mode or blocked storage: the table just starts folded next time.
    }
}

/**
 * The table in `[data-dawn-top]`, shown whenever there is a board (an empty one only at the dawn, as its call). Folded
 * by default — the three leaders and my row; the toggle unfolds it to the ten, and the choice is remembered. Entering
 * the dawn unfolds it afresh: its contest is the stage's; leaving it brings the remembered choice back. «Топ» in the HUD
 * (`[data-top-open]`, `aria-pressed` — the table is out) puts it away altogether and brings it back, remembered too;
 * the dawn takes it out afresh the same way.
 *
 * Rows are keyed by player id, so a repaint moves the same nodes: their old y is read before the update and each slides
 * from there (FLIP); a row that climbed flashes, a new one rides in from the right, one pushed out of the top drops away.
 * Folding and unfolding just redraw. Under reduced motion the new order simply stands.
 *
 * @param {{reduced?: () => boolean, storage?: Storage|null}} options
 */
export function createDawnTable(root, { reduced = () => false, storage = globalThis.localStorage ?? null } = {}) {
    const el = root.querySelector('[data-dawn-top]');
    if (!el) return { paint() {} };
    const doc = el.ownerDocument ?? globalThis.document;
    const list = el.querySelector('[data-dawn-list]');
    const empty = el.querySelector('[data-dawn-empty]');
    const mineBox = el.querySelector('[data-dawn-mine]');
    const toggle = el.querySelector('[data-dawn-toggle]');
    const strip = el.querySelector('[data-dawn-strip]');
    const rows = new Map();
    const cellsOf = new WeakMap();
    const flashes = new Map();
    const opener = root.querySelector('[data-top-open]');
    let folded = readFolded(storage);
    let shut = readShut(storage);
    // Whether the last paint was at the dawn (null before the first): entering it unfolds, leaving it restores.
    let wasDawn = null;
    let lastShape = null;
    let last = null;

    const animate = (node, frames, options) => (typeof node.animate === 'function' ? node.animate(frames, options) : null);

    // place · name · score, built once per node.
    function cells(node) {
        let found = cellsOf.get(node);
        if (found) return found;
        const span = (className, hook) => {
            const cell = doc.createElement('span');
            cell.className = className;
            if (hook) cell.setAttribute(hook, '');
            node.append(cell);
            return cell;
        };
        found = { place: span('dawn-top__place'), name: span('dawn-top__name', 'data-dawn-name'), shots: span('dawn-top__shots', 'data-dawn-shots') };
        cellsOf.set(node, found);
        return found;
    }

    function rowNode(id) {
        let li = rows.get(id);
        if (li) return li;
        li = doc.createElement('li');
        li.className = 'dawn-top__row';
        li.setAttribute('data-dawn-row', '');
        li.dataset.player = id;
        rows.set(id, li);
        return li;
    }

    function fill(node, { place, name, shots }) {
        const c = cells(node);
        c.place.textContent = place;
        c.name.textContent = name;
        c.shots.textContent = String(shots);
    }

    function clear() {
        rows.forEach((li) => li.remove());
        rows.clear();
        paintMine(null);
    }

    // A row pushed out of the top keeps its old spot for the moment it falls away.
    function drop(li, top) {
        li.removeAttribute('data-dawn-row');
        li.removeAttribute('data-dawn-me');
        li.classList.add('dawn-top__row--gone');
        li.style.top = `${top}px`;
        list.append(li);
        const run = animate(li, [{ transform: 'none', opacity: 1 }, { transform: 'translateY(16px)', opacity: 0 }], { duration: DAWN_MOVE_MS, easing: 'ease-in' });
        if (run) run.onfinish = () => li.remove();
        else li.remove();
    }

    function paintRows(next, motion) {
        const before = new Map();
        if (motion) rows.forEach((li, id) => before.set(id, { y: li.getBoundingClientRect().top, top: li.offsetTop, place: Number(li.dataset.place) }));
        const keep = new Set(next.map((row) => row.id));
        for (const [id, li] of rows) {
            if (keep.has(id)) continue;
            rows.delete(id);
            if (motion) drop(li, before.get(id).top);
            else li.remove();
        }
        for (const row of next) {
            const li = rowNode(row.id);
            li.dataset.place = row.place;
            fill(li, row);
            li.toggleAttribute('data-dawn-me', row.me);
            list.append(li);
        }
        if (!motion) return;
        for (const row of next) {
            const li = rows.get(row.id);
            const was = before.get(row.id);
            if (!was) {
                animate(li, [{ transform: 'translateX(110%)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: DAWN_MOVE_MS, easing: 'ease-out' });
                continue;
            }
            const dy = was.y - li.getBoundingClientRect().top;
            if (dy !== 0) animate(li, [{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: DAWN_MOVE_MS, easing: 'ease-out' });
            if (row.place < was.place) {
                li.removeAttribute('data-dawn-up');
                // A reflow between off and on: else a second overtake within the flash would not restart it.
                void li.offsetWidth;
                li.setAttribute('data-dawn-up', '');
                clearTimeout(flashes.get(li));
                flashes.set(li, setTimeout(() => li.removeAttribute('data-dawn-up'), FLASH_MS));
            }
        }
    }

    function paintMine(mine) {
        if (!mineBox) return;
        mineBox.hidden = mine === null;
        mineBox.toggleAttribute('data-dawn-me', mine !== null);
        if (!mine) return;
        mineBox.dataset.place = mine.place;
        fill(mineBox, { place: `#${mine.place}`, name: TEXT.me, shots: mine.shots });
    }

    // The toggle: a phone's strip «#3 · ты · 7 · топ ▾»; on a wide screen «весь топ ▾» / «свернуть ▴» under the rows,
    // gone while the board has nothing beyond the folded three.
    function paintToggle(board, me, compact) {
        if (!toggle) return;
        toggle.hidden = !compact && board.length <= DAWN_FOLDED;
        toggle.setAttribute('aria-expanded', String(!folded));
        if (strip) strip.textContent = compact ? stripText(board, me) : folded ? TEXT.all : TEXT.fold;
    }

    opener?.addEventListener('click', (event) => {
        event.preventDefault();
        shut = !shut;
        writeShut(storage, shut);
        if (last) paint(last);
    });

    toggle?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        folded = !folded;
        writeFolded(storage, folded);
        if (last) paint(last);
    });

    /**
     * @param {{dawn: {board: Array}|null, me: string|null, atDawn: boolean, compact: boolean}} view  dawn — the board
     *   (`top`); atDawn — finale step 7 on screen; compact — a phone, folded into a strip until tapped open
     */
    function paint(view) {
        last = view;
        const { dawn, me, atDawn, compact } = view;
        if (atDawn && wasDawn !== true) {
            folded = false;
            shut = false;
        }
        if (!atDawn && wasDawn === true) {
            folded = readFolded(storage);
            shut = readShut(storage);
        }
        wasDawn = atDawn;
        const none = !dawn || (!dawn.board.length && !atDawn);
        if (opener) {
            opener.hidden = none;
            opener.setAttribute('aria-pressed', String(!shut));
        }
        if (none || shut) {
            el.hidden = true;
            lastShape = null;
            clear();
            return;
        }
        const shape = shapeOf({ compact, folded, atDawn });
        const key = `${compact}|${folded}|${shape.top}|${shape.below}`;
        // Rows move only within the same shape of a shown table: a fold, a width change or the table's appearance redraws.
        const motion = !reduced() && !el.hidden && !(compact && folded) && key === lastShape;
        lastShape = key;
        const { rows: next, mine } = dawnRows(dawn.board, me, shape.top, shape.below);
        el.toggleAttribute('data-folded', folded);
        el.toggleAttribute('data-compact', compact);
        el.toggleAttribute('data-dawn-stage', atDawn);
        paintToggle(dawn.board, me, compact);
        if (empty) empty.hidden = dawn.board.length > 0;
        paintRows(next, motion);
        paintMine(mine);
        el.hidden = false;
    }

    return { paint };
}
