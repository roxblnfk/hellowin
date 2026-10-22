// The players' chat (docs/spec/frontend.md, «Чат»): the zombie lines, the bubble's text and time, the field over the
// HUD and the moderator's log on the console. No PixiJS: the Node tests load it.

import { hash } from './pixel/clink.js';

/**
 * What a zombie worker says instead of its words (protocol.md, «Чат»: the server sends `zombie: true` without the
 * text). Add new ones at the end: the hash picks by index, and every viewer must get the same line.
 */
export const ZOMBIE_LINES = [
    'Мммм… мозги…',
    'Ммм-ааа…',
    'Мозгиии…',
    'Ыыыы… обработать…',
    'Ммм… ещё запрос…',
    'Ааа… воркер… голоден…',
    'Хрррр… мозги…',
    'Ммм… пинг… понг…',
];

/** The zombie line of a `say` event: by the FNV hash of `{player.id}:{at}`, the same in every browser. */
export const zombieLine = (playerId, at) => ZOMBIE_LINES[hash(`${playerId}:${at}`) % ZOMBIE_LINES.length];

/** The bubble's text of a `say` event (or a history message): the zombie line, else the text; null for neither. */
export function sayText(event) {
    if (event?.zombie === true) return zombieLine(event.player?.id ?? '', event.at);
    const text = typeof event?.text === 'string' ? event.text.trim() : '';
    return text === '' ? null : text;
}

// Shorter than the owl's (owl.js, showMs): a player's line is read by those around it, not by the whole room.
export const CHAT_MIN_MS = 4000;
export const CHAT_MAX_MS = 12000;

/** How long a chat bubble stays up, ms: by the text's length, CHAT_MIN_MS…CHAT_MAX_MS. */
export const chatShowMs = (text) => Math.min(CHAT_MAX_MS, Math.max(CHAT_MIN_MS, 2500 + String(text).length * 80));

// HELLOWIN_CHAT_MAX's default, for a page whose template did not say.
const CHAT_MAX = 120;
// HELLOWIN_CHAT_HISTORY's default: the console keeps at least this many rows (more if `chat.history` brings more).
export const CHAT_LOG_MAX = 50;
const NOTE_MS = 3000;
const SHAKE_MS = 400;
const MUTED_NOTE = 'Сова отобрала у тебя слово';
const NOTES = { 'rate limited': 'Не так быстро', 'invalid text': (max) => `От 1 до ${max} знаков` };
// Where Enter belongs to the control under focus: it types, presses or follows it, the field does not open.
const ENTER_TAKERS = 'input, textarea, select, button, a[href], [contenteditable]:not([contenteditable="false"]), [role="button"]';

/**
 * The chat field (`[data-chat]`): «Чат» in the HUD or Enter opens it, Enter sends RPC `say {text}`, Esc closes it and
 * keeps the text. The bubble comes back as the `say` event, like everyone's. Whether «Чат» shows is the client's
 * (`paintChat`); a field open when my walker leaves is closed by it.
 *
 * @param {{onField: () => boolean}} client
 * @param {(text: string) => Promise<{ok: boolean, error?: string}|undefined>} say  RPC `say`
 * @returns {null|{open: () => void, close: () => void}}
 */
export function bindChat(root, client, say, doc = globalThis.document) {
    const form = root.querySelector('[data-chat]');
    const input = form?.querySelector('[data-chat-input]');
    if (!form || !input) return null;
    const note = form.querySelector('[data-chat-note]');
    const submit = form.querySelector('[data-chat-submit]');
    const opener = root.querySelector('[data-chat-open]');
    const max = Number.parseInt(root.dataset.chatMax ?? '', 10) > 0 ? Number.parseInt(root.dataset.chatMax, 10) : CHAT_MAX;
    input.maxLength = max;
    let sending = false;
    let noteTimer = 0;
    let closeTimer = 0;

    const tell = (text) => {
        if (!note) return;
        note.textContent = text;
        clearTimeout(noteTimer);
        noteTimer = setTimeout(() => (note.textContent = ''), NOTE_MS);
    };
    const shake = () => {
        form.classList.remove('chat--shake');
        void form.offsetWidth;
        form.classList.add('chat--shake');
        setTimeout(() => form.classList.remove('chat--shake'), SHAKE_MS);
    };
    function open() {
        if (!client.onField()) return;
        clearTimeout(closeTimer);
        form.classList.remove('chat--told');
        form.hidden = false;
        opener?.setAttribute('aria-expanded', 'true');
        input.focus();
    }
    // The focus leaves with the field: Space and the other game keys are the game's again.
    function close() {
        clearTimeout(closeTimer);
        form.hidden = true;
        form.classList.remove('chat--told');
        opener?.setAttribute('aria-expanded', 'false');
        if (form.contains(doc.activeElement)) doc.activeElement.blur();
    }

    opener?.addEventListener('click', (event) => {
        event.preventDefault();
        if (form.hidden) open();
        else close();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        close();
    });
    doc.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.repeat || event.isComposing || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return;
        if (!form.hidden || event.target?.closest?.(ENTER_TAKERS) || event.target?.isContentEditable || doc.querySelector?.('dialog[open]')) return;
        if (!client.onField()) return;
        event.preventDefault();
        open();
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (sending) return;
        const text = input.value.trim();
        if (!text) {
            close();
            return;
        }
        sending = true;
        if (submit) submit.disabled = true;
        try {
            const reply = await say(text);
            if (reply?.ok) {
                input.value = '';
                if (note) note.textContent = '';
                close();
            } else if (reply?.error === 'muted') {
                // The note outlives the field's input: the field shows only it for a moment, then closes.
                tell(MUTED_NOTE);
                form.classList.add('chat--told');
                input.blur();
                closeTimer = setTimeout(close, NOTE_MS);
            } else if (reply?.error in NOTES) {
                const text = NOTES[reply.error];
                tell(typeof text === 'function' ? text(max) : text);
                shake();
            } else {
                console.warn('say rejected', reply?.error);
            }
        } catch (error) {
            console.warn('say rpc failed', error);
        } finally {
            sending = false;
            if (submit) submit.disabled = false;
        }
    });
    return { open, close };
}

const pad = (n) => String(n).padStart(2, '0');
/** «чч:мм» of a server time, in the viewer's time zone. */
export const clockOf = (at) => {
    const d = new Date(at);
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

/**
 * One row of the moderator's log as text parts: time, name, the bubble's text; a zombie's row is marked «зомби» and,
 * when the message carries the original (`chat.history`), tells what it wanted to say.
 */
export function chatRow(message) {
    const zombie = message?.zombie === true;
    const said = sayText(message) ?? '';
    const wanted = zombie && typeof message.text === 'string' && message.text.trim() !== '' ? message.text.trim() : null;
    return { time: clockOf(message?.at ?? 0), name: message?.player?.name ?? '?', zombie, said, wanted };
}

const MUTE_ERRORS = { forbidden: 'Нет прав', 'no target': 'Нет такого' };

/**
 * The «Чат» group of the moderator's console: the log (`[data-chat-log]`, at most CHAT_LOG_MAX rows, the oldest go)
 * filled by `chat.history` and then by `say` events, a mute button per row and the muted players under the log.
 * Every text goes in as textContent.
 *
 * @param {(method: string, data: object) => Promise<object>} call  console RPC; rejects with Error(code)
 * @returns {{node: HTMLElement, history: () => Promise<void>, say: (event: object) => void}}
 */
export function createChatConsole(call, doc = globalThis.document) {
    const make = (tag, attrs = {}, text = '') => {
        const node = doc.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
        if (text) node.textContent = text;
        return node;
    };
    const node = make('div', { class: 'chat-log' });
    const log = make('ol', { class: 'chat-log__list', 'data-chat-log': '', 'aria-label': 'История чата' });
    const empty = make('p', { class: 'chat-log__empty' }, 'Пока молчат');
    const mutedTitle = make('span', { class: 'chat-log__title' }, 'Без слова');
    const mutedList = make('ul', { class: 'chat-log__muted', 'data-chat-muted': '' });
    node.append(log, empty, mutedTitle, mutedList);
    let messages = [];
    let muted = [];
    let cap = CHAT_LOG_MAX;
    // `say` events that arrive while `chat.history` is on its way: the reply may already hold them, or not yet.
    let pending = null;

    const isMuted = (id) => muted.some((p) => p.id === id);
    function muteButton(player, on) {
        const b = make('button', { type: 'button', class: 'debug__button chat-log__mute', 'data-chat-mute': '', 'data-player': player.id }, on ? 'заглушить' : 'вернуть слово');
        b.dataset.on = on ? '1' : '0';
        b.addEventListener('click', async () => {
            b.disabled = true;
            try {
                const reply = await call('chat.mute', { player: player.id, on });
                if (Array.isArray(reply?.muted)) {
                    muted = reply.muted;
                    paint();
                }
            } catch (error) {
                console.warn('chat.mute failed', error);
                b.textContent = MUTE_ERRORS[error?.message] ?? 'Не вышло';
                setTimeout(() => (b.textContent = on ? 'заглушить' : 'вернуть слово'), NOTE_MS);
            } finally {
                b.disabled = false;
            }
        });
        return b;
    }

    function paint() {
        const stick = log.scrollTop + log.clientHeight >= log.scrollHeight - 8;
        log.replaceChildren(...messages.map((m) => {
            const row = chatRow(m);
            const li = make('li', { class: 'chat-log__row', 'data-chat-row': '', 'data-player': m.player?.id ?? '' });
            if (row.zombie) li.dataset.zombie = '';
            li.append(make('span', { class: 'chat-log__time' }, row.time), make('span', { class: 'chat-log__name' }, row.name));
            if (row.zombie) li.append(make('span', { class: 'chat-log__tag' }, 'зомби'));
            li.append(make('span', { class: 'chat-log__text' }, row.said));
            if (m.player?.id) li.append(muteButton(m.player, !isMuted(m.player.id)));
            if (row.wanted) li.append(make('span', { class: 'chat-log__wanted' }, `хотел: ${row.wanted}`));
            return li;
        }));
        empty.hidden = messages.length > 0;
        mutedTitle.hidden = muted.length === 0;
        mutedList.replaceChildren(...muted.map((p) => {
            const li = make('li', { class: 'chat-log__row' });
            li.append(make('span', { class: 'chat-log__name' }, p.name ?? p.id), muteButton(p, false));
            return li;
        }));
        if (stick) log.scrollTop = log.scrollHeight;
    }

    const add = (message) => {
        messages = [...messages, message].slice(-cap);
    };

    paint();
    return {
        node,

        /** RPC `chat.history`: the log and the muted list as the server keeps them (the zombies' originals too). */
        async history() {
            if (pending) return;
            pending = [];
            try {
                const reply = await call('chat.history', {});
                const list = Array.isArray(reply?.messages) ? reply.messages : [];
                cap = Math.max(CHAT_LOG_MAX, list.length);
                const last = list.reduce((at, m) => Math.max(at, m.at ?? 0), 0);
                messages = list.slice(-cap);
                pending.filter((m) => (m.at ?? 0) > last).forEach(add);
                if (Array.isArray(reply?.muted)) muted = reply.muted;
            } catch (error) {
                console.warn('chat.history failed', error);
                pending.forEach(add);
            } finally {
                pending = null;
                paint();
            }
        },

        /** A `say` event: one more row (a zombie's without its original until the next `chat.history`). */
        say(event) {
            if (!event?.player?.id) return;
            const message = { player: event.player, at: event.at, ...(event.zombie === true ? { zombie: true } : { text: event.text }) };
            if (pending) pending.push(message);
            else {
                add(message);
                paint();
            }
        },
    };
}
