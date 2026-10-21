// The dawn's top shooters (docs/spec/frontend.md, «Топ рассвета»): `dawn` of the snapshot and the event, my place in
// the board, and the pixel table at the side with rows that slide when someone is overtaken. No PixiJS here: client.js
// and the node tests load it.

/** Rows of the table on a wide screen. */
export const DAWN_TOP = 10;
/** Rows of the unfolded table on a phone. */
export const DAWN_TOP_PHONE = 5;
// How long a row slides from its old place to the new one.
export const DAWN_MOVE_MS = 300;
const FLASH_MS = 600;
const STORAGE_KEY = 'hellowin:dawn-top';
export const DAWN_EMPTY = 'Сбивайте уток рапирой!';

/** `dawn` of the snapshot or the event: {board} with the rows in the server's order, or null without the contest. */
export function dawnOf(dawn) {
    if (!dawn || typeof dawn !== 'object' || !Array.isArray(dawn.board)) return null;
    const board = dawn.board
        .filter((row) => row?.player?.id && Number.isInteger(row.shots))
        .map((row) => ({ player: { id: String(row.player.id), name: row.player.name ?? null }, shots: row.shots }));
    return { board };
}

/** A shooter's name as the table prints it. */
export const nameOf = (name) => (typeof name === 'string' && name.trim()) || 'кто-то';

/**
 * The table's rows: the first `top` of the board, mine marked; `mine` — my own row under the line when I am in the
 * board but below the top (null otherwise). The server's order is the ranking, so the place is the index + 1.
 */
export function dawnRows(board, me, top = DAWN_TOP) {
    const index = me === null || me === undefined ? -1 : board.findIndex((row) => row.player.id === me);
    const rowOf = (row, i) => ({ id: row.player.id, name: nameOf(row.player.name), shots: row.shots, place: i + 1, me: i === index });
    const rows = board.slice(0, top).map(rowOf);
    return { rows, mine: index >= top ? rowOf(board[index], index) : null };
}

/** The folded strip of a phone: «#3 · ты · 7 · топ», or a plain call when I am not in the board. */
export function stripText(board, me) {
    const index = me === null || me === undefined ? -1 : board.findIndex((row) => row.player.id === me);
    if (index >= 0) return `#${index + 1} · ты · ${board[index].shots} · топ`;
    return board.length ? 'Топ рассвета' : DAWN_EMPTY;
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
        // Private mode or blocked storage: the strip just starts folded next time.
    }
}

/**
 * The table in `[data-dawn-top]`. Rows are keyed by player id, so a repaint moves the same nodes: their old y is read
 * before the update and each slides from there (FLIP); a row that climbed flashes, a new one rides in from the right, one
 * pushed out of the top drops away. Under reduced motion the new order simply stands.
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
    let folded = readFolded(storage);
    let last = null;

    const animates = () => !reduced() && !el.hidden && !el.hasAttribute('data-folded');
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

    function paintRows(next) {
        const motion = animates();
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
        fill(mineBox, { place: `#${mine.place}`, name: 'ты', shots: mine.shots });
    }

    function paintFold(compact) {
        const shut = compact && folded;
        el.toggleAttribute('data-folded', shut);
        el.toggleAttribute('data-compact', compact);
        if (toggle) toggle.setAttribute('aria-expanded', String(!shut));
    }

    toggle?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        folded = !folded;
        writeFolded(storage, folded);
        if (last) paint(last);
    });

    /**
     * @param {{dawn: {board: Array}|null, me: string|null, shown: boolean, compact: boolean}} view  compact — a phone:
     *   five rows, folded into a strip until tapped open
     */
    function paint(view) {
        last = view;
        const { dawn, me, shown, compact } = view;
        if (!shown || !dawn) {
            el.hidden = true;
            clear();
            return;
        }
        const { rows: next, mine } = dawnRows(dawn.board, me, compact ? DAWN_TOP_PHONE : DAWN_TOP);
        paintFold(compact);
        if (strip) strip.textContent = stripText(dawn.board, me);
        if (empty) empty.hidden = dawn.board.length > 0;
        paintRows(next);
        paintMine(mine);
        el.hidden = false;
    }

    return { paint };
}
