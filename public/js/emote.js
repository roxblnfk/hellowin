// The players' emotes (docs/spec/frontend.md, «Эмоции»): the set, how long one shows, the panel over the HUD. No PixiJS:
// the Node tests load it.

import { pick } from './i18n.js';

/** HELLOWIN_EMOTES' default (protocol.md, «Эмоции»), for a page whose snapshot brings no set (the lab). */
export const DEFAULT_EMOTES = ['🎃', '👻', '💀', '😂', '😱', '😍', '👍', '👏', '🔥', '🍺', '🤘', '🙏'];
/** How long an emote stays over its walker, ms from the event's `at` on the server clock. */
export const EMOTE_MS = 3000;
/** HELLOWIN_EMOTE_COOLDOWN_MS' default: the panel rests this long after a press, so it never asks for a `busy`. */
export const EMOTE_COOLDOWN_MS = 1000;

const TEXT = pick({
    ru: {
        panel: 'Эмоции',
        named: { '🎃': 'тыква', '👻': 'привидение', '💀': 'череп', '😂': 'смех', '😱': 'ужас', '😍': 'восторг', '👍': 'класс', '👏': 'аплодисменты', '🔥': 'огонь', '🍺': 'пиво', '🤘': 'рок', '🙏': 'спасибо' },
        label: (name) => `Эмоция: ${name}`,
    },
    en: {
        panel: 'Emotes',
        named: { '🎃': 'pumpkin', '👻': 'ghost', '💀': 'skull', '😂': 'laughing', '😱': 'scream', '😍': 'love', '👍': 'thumbs up', '👏': 'applause', '🔥': 'fire', '🍺': 'beer', '🤘': 'rock on', '🙏': 'thanks' },
        label: (name) => `Emote: ${name}`,
    },
});

/** The panel's set: the snapshot's `emotes` (non-empty strings, no repeats), else DEFAULT_EMOTES. */
export function emotesOf(list) {
    const set = Array.isArray(list) ? [...new Set(list.filter((e) => typeof e === 'string' && e.trim() !== ''))] : [];
    return set.length ? set : DEFAULT_EMOTES;
}

/** A button's aria-label: the emoji's name in the page's language; one outside the default set is named by itself. */
export const emoteLabel = (emoji) => TEXT.label(TEXT.named[emoji] ?? emoji);

/**
 * The emote of an `emote` event while it shows: { id, emoji, at, until } on the server clock; null for a broken event
 * or one already over at `serverNow` (a late joiner, a background tab).
 */
export function emoteOf(event, serverNow) {
    const id = event?.player?.id;
    const emoji = typeof event?.emoji === 'string' ? event.emoji : '';
    if (!id || emoji === '') return null;
    const at = event.at > 0 ? event.at : serverNow;
    return at + EMOTE_MS > serverNow ? { id, emoji, at, until: at + EMOTE_MS } : null;
}

/**
 * The emote panel (`[data-emote-panel]`): a button per emoji of the snapshot's `#app[data-emotes]`, opened by «😀»
 * in the HUD (`[data-emote-open]`), closed by it, by Esc or by the chat field opening. A press sends RPC
 * `emote {emoji}` and rests the buttons for EMOTE_COOLDOWN_MS; the emote comes back as the event, like everyone's.
 * Whether «😀» shows is the client's (`paintChat`), like «Чат».
 *
 * @param {(emoji: string) => Promise<{ok: boolean, error?: string}|undefined>} send  RPC `emote`
 * @param {object} [options]
 * @param {() => void} [options.onOpen]  the row opens: the chat field closes (bindChat's `close`)
 * @returns {null|{open: () => void, close: () => void}}
 */
export function bindEmotes(root, send, { onOpen = () => {}, later = (fn, ms) => setTimeout(fn, ms), doc = globalThis.document } = {}) {
    const panel = root.querySelector('[data-emote-panel]');
    const opener = root.querySelector('[data-emote-open]');
    if (!panel) return null;
    let set = [];
    try {
        set = JSON.parse(root.dataset.emotes ?? '[]');
    } catch {
        // A broken attribute is no set: the defaults stand in.
    }
    panel.setAttribute('aria-label', TEXT.panel);
    const buttons = emotesOf(set).map((emoji) => {
        const b = doc.createElement('button');
        b.type = 'button';
        b.className = 'emotes__button';
        b.textContent = emoji;
        b.dataset.emote = emoji;
        b.setAttribute('aria-label', emoteLabel(emoji));
        b.title = emoteLabel(emoji);
        return b;
    });
    panel.replaceChildren(...buttons);
    let resting = false;

    // aria-disabled, not disabled: a disabled button drops the keyboard's focus to the page.
    const rest = (on) => {
        resting = on;
        buttons.forEach((b) => b.setAttribute('aria-disabled', String(on)));
    };
    function open() {
        onOpen();
        panel.hidden = false;
        opener?.setAttribute('aria-expanded', 'true');
    }
    function close() {
        panel.hidden = true;
        opener?.setAttribute('aria-expanded', 'false');
        if (panel.contains(doc.activeElement)) doc.activeElement.blur();
    }

    opener?.addEventListener('click', (event) => {
        event.preventDefault();
        if (panel.hidden) open();
        else close();
    });
    panel.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        opener?.focus();
        close();
    });
    // Both sit over the HUD in the middle: the field opening (by «Чат» or Enter) takes the place. Watched by its
    // `hidden`, not by focus: a page without the window's focus gets no focus events.
    const field = root.querySelector('[data-chat]');
    if (field && globalThis.MutationObserver) {
        new MutationObserver(() => field.hidden || close()).observe(field, { attributes: true, attributeFilter: ['hidden'] });
    }
    panel.addEventListener('click', async (event) => {
        const emoji = event.target?.closest?.('[data-emote]')?.dataset.emote;
        if (!emoji || resting) return;
        rest(true);
        later(() => rest(false), EMOTE_COOLDOWN_MS);
        try {
            const reply = await send(emoji);
            if (reply && !reply.ok && reply.error !== 'busy') console.warn('emote rejected', reply.error);
        } catch (error) {
            console.warn('emote rpc failed', error);
        }
    });
    return { open, close };
}
