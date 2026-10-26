// The reveal of finale step 4 (docs/spec/scenario.md, «Финал»): the evening's real numbers from the engine, the
// server's uptime and the way to «Как это работает».

import { AFTER_STORY, formatUptime } from './client.js';
import { lang, pick } from './i18n.js';

const STEP = 4;
// While the panel is open its numbers are asked for afresh this often (RPC `reveal`, docs/spec/protocol.md).
const REFRESH_MS = 2000;
const NUMBER = new Intl.NumberFormat(lang);

// Noun forms (ru: 1, 2–4 and 5+, accusative: the number is always the object; en: 1 and the rest), the rest of the
// caption, and a short note on the mechanism behind the number.
const CARDS = pick({ ru: [
    ['clicks', ['действие', 'действия', 'действий'], 'игроков обработал RoadRunner', 'клики, шаги, выстрелы, кружки — каждое через WebSocket и RPC'],
    ['jobs', ['задачу', 'задачи', 'задач'], 'выполнила очередь', 'каждая — шаг тыквы, костра, круга или сцены'],
    ['burned', ['повтор', 'повтора', 'повторов'], 'отбросила идемпотентность', 'лишние клики, дубли переходов, повторы бросков — ничего не случилось дважды'],
    ['shot', ['летуна', 'летунов', 'летунов'], 'сбили', 'каждого — первый успевший, под локом'],
    ['drinks', ['кружку', 'кружки', 'кружек'], 'выпили за вечер', 'последнюю — первый дошедший до стойки'],
    ['slain', ['зомби-воркера', 'зомби-воркера', 'зомби-воркеров'], 'упокоили', 'каждого — первый успевший, под локом'],
    ['damage', ['урона', 'урона', 'урона'], 'FrankenPHP нанесли рапирами', 'весь зал бьёт в один элемент под одним локом'],
    ['rolls', ['попытку', 'попытки', 'попыток'], 'выбросить PHP 6', 'кости случайны, повтор попытки получит сохранённый ответ'],
], en: [
    ['clicks', ['player action', 'player actions'], 'handled by RoadRunner', 'clicks, steps, shots, mugs — each over WebSocket and RPC'],
    ['jobs', ['job', 'jobs'], 'run by the queue', 'each one a step of a pumpkin, the bonfire, the circle or a scene'],
    ['burned', ['repeat', 'repeats'], 'dropped by idempotency', 'extra clicks, doubled transitions, repeated rolls — nothing happened twice'],
    ['shot', ['flyer', 'flyers'], 'shot down', 'each by whoever was first, under a lock'],
    ['drinks', ['mug', 'mugs'], 'drunk tonight', 'the last one by whoever reached the counter first'],
    ['slain', ['zombie worker', 'zombie workers'], 'laid to rest', 'each by whoever was first, under a lock'],
    ['damage', ['damage', 'damage'], 'dealt to FrankenPHP with rapiers', 'the whole hall hits one element under one lock'],
    ['rolls', ['try', 'tries'], 'to roll PHP 6', 'the dice are random, a retried roll gets the saved answer'],
] });
const TEXT = pick({
    ru: {
        close: 'Закрыть',
        kicker: 'Разоблачение',
        title: 'RoadRunner не умирал',
        lead: 'Весь вечер RoadRunner обеспечивал инфраструктуру вашего ритуала. Вот что через него прошло:',
        link: 'Как это работает →',
        uptime: 'аптайм RoadRunner',
        uptimeNote: 'он не умирал ни на секунду',
        last: (name) => `Последний элемент ритуала добил ${name || 'кто-то без имени'}.`,
        lastUnknown: 'Последний элемент ритуала добил кто-то неизвестный.',
    },
    en: {
        close: 'Close',
        kicker: 'The reveal',
        title: 'RoadRunner never died',
        lead: 'All evening RoadRunner ran the infrastructure of your ritual. Here is what went through it:',
        link: 'How it works →',
        uptime: 'RoadRunner uptime',
        uptimeNote: 'not a second of downtime',
        last: (name) => `The last element of the ritual was finished by ${name || 'someone nameless'}.`,
        lastUnknown: 'The last element of the ritual was finished by someone unknown.',
    },
});
// The rapier's and the dice's numbers: their cards show only once there is something to count.
const SKIP_ZERO = new Set(['slain', 'damage', 'rolls']);
// The card «обработал RoadRunner» counts every action (`calls`); a server without it gives the accepted clicks.
const valueOf = (reveal, key) => (key === 'clicks' ? reveal.calls ?? reveal.clicks : reveal[key]);

const plural = pick({ ru: pluralRu, en: (n, [one, many]) => (n === 1 ? one : many) });

function pluralRu(n, [one, few, many]) {
    const tens = n % 100;
    const units = n % 10;
    if (tens >= 11 && tens <= 14) return many;
    if (units === 1) return one;
    return units >= 2 && units <= 4 ? few : many;
}

/**
 * The panel opens by itself on step 4 and stays until closed; from step 5 on, and in the bar after the story
 * (AFTER_STORY), it is closed, and the HUD button `[data-reveal-open]` brings it back. While open it asks `request`
 * for fresh numbers on opening and every REFRESH_MS after the previous answer; a refusal or an error keeps the numbers
 * it shows.
 *
 * @param {HTMLElement} root  #app
 * @param {object} [options]
 * @param {(() => Promise<object>)|null} [options.request]  the evening's numbers now, like `reveal` of the snapshot
 * @param {(fn: Function, ms: number) => void} [options.later]  timer (the lab's frozen clock)
 * @returns {{update: (finale: {step: number, reveal?: object}|null, uptime?: number|null) => void}}
 */
export function createReveal(root, { request = null, later = (fn, ms) => setTimeout(fn, ms) } = {}) {
    const panel = document.createElement('section');
    panel.className = 'reveal';
    panel.dataset.reveal = '';
    panel.setAttribute('aria-labelledby', 'reveal-title');
    panel.hidden = true;
    panel.innerHTML = `
        <button type="button" class="reveal__close" data-reveal-close aria-label="${TEXT.close}">×</button>
        <p class="reveal__kicker">${TEXT.kicker}</p>
        <h2 class="reveal__title" id="reveal-title">${TEXT.title}</h2>
        <p class="reveal__lead">${TEXT.lead}</p>
        <dl class="reveal__grid"></dl>
        <p class="reveal__last" data-reveal-last></p>
        <a class="reveal__link" href="/how-it-works" target="_blank" rel="noopener">${TEXT.link}</a>`;
    const grid = panel.querySelector('.reveal__grid');
    for (const [key] of CARDS) {
        const card = document.createElement('div');
        card.className = 'reveal__card';
        card.dataset.revealCard = key;
        card.innerHTML = `<dt class="reveal__label" data-reveal-label="${key}"></dt><dd class="reveal__number" data-reveal-${key}></dd><dd class="reveal__note" data-reveal-note="${key}"></dd>`;
        grid.append(card);
    }
    const uptimeCard = document.createElement('div');
    uptimeCard.className = 'reveal__card reveal__card--uptime';
    uptimeCard.innerHTML = `<dt class="reveal__label">${TEXT.uptime}</dt><dd class="reveal__number" data-reveal-uptime></dd><dd class="reveal__note">${TEXT.uptimeNote}</dd>`;
    grid.append(uptimeCard);
    root.append(panel);
    const opener = root.querySelector('[data-reveal-open]');
    const closer = panel.querySelector('[data-reveal-close]');

    let step = null;
    let open = false;
    let shown = false;
    let finale = null;
    let uptime = null;
    // Numbers of the `reveal` RPC for this step; a step's own event or snapshot brings newer ones and drops them.
    let fresh = null;
    // One refresh loop per opening: `loop` numbers them, so a timer of an earlier opening finds itself stale.
    let loop = 0;
    let live = false;
    let asking = false;

    function paint() {
        panel.hidden = !shown || !open;
        if (opener) opener.hidden = !shown || open;
        const wanted = shown && open && request !== null;
        if (wanted === live) return;
        live = wanted;
        loop++;
        if (live) refresh(loop);
    }

    // A request still out when the panel reopens is waited for, not doubled: its end starts the new loop at once.
    function refresh(mine) {
        if (mine !== loop || asking) return;
        asking = true;
        const asked = step;
        new Promise((resolve) => resolve(request()))
            .then((reveal) => {
                if (!reveal || typeof reveal !== 'object' || asked !== step || !shown) return;
                fresh = reveal;
                draw();
            })
            .catch(() => {})
            .finally(() => {
                asking = false;
                if (!live) return;
                const current = loop;
                later(() => refresh(current), mine === current ? REFRESH_MS : 0);
            });
    }

    closer.addEventListener('click', () => {
        open = false;
        paint();
        opener?.focus();
    });
    opener?.addEventListener('click', () => {
        open = true;
        paint();
        closer.focus();
    });

    function draw() {
        const reveal = fresh ?? finale.reveal;
        for (const [key, forms, caption, note] of CARDS) {
            // A server without the bar sends no `drinks`: its card stays out.
            const value = valueOf(reveal, key);
            panel.querySelector(`[data-reveal-card="${key}"]`).hidden = value === undefined || (SKIP_ZERO.has(key) && !(Number(value) > 0));
            const n = Math.max(0, Math.trunc(Number(value) || 0));
            panel.querySelector(`[data-reveal-${key}]`).textContent = NUMBER.format(n);
            panel.querySelector(`[data-reveal-label="${key}"]`).textContent = `${plural(n, forms)} ${caption}`;
            panel.querySelector(`[data-reveal-note="${key}"]`).textContent = note;
        }
        uptimeCard.hidden = uptime === null;
        panel.querySelector('[data-reveal-uptime]').textContent = uptime === null ? '' : formatUptime(uptime);
        const name = reveal.lastPumpkin?.name;
        // Not known yet: the bar after the story waits for its first answer.
        panel.querySelector('[data-reveal-last]').textContent = reveal.lastPumpkin === undefined ? '' : reveal.lastPumpkin
            ? TEXT.last(name)
            : TEXT.lastUnknown;
    }

    return {
        update(next, up = null) {
            shown = next === AFTER_STORY || (Boolean(next?.reveal) && (next?.step ?? 0) >= STEP);
            if (!shown) {
                step = null;
                open = false;
                fresh = null;
                paint();
                return;
            }
            // A new step decides afresh: open on the reveal itself, closed in the cursed world.
            if (next.step !== step) {
                step = next.step;
                open = step === STEP;
            }
            // A new step or snapshot carries its own numbers; the uptime tick hands back the same object.
            if (next !== finale && next.reveal !== finale?.reveal) fresh = null;
            finale = next;
            uptime = up;
            draw();
            paint();
        },
    };
}
