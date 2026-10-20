// The reveal of finale step 4 (docs/spec/scenario.md, «Финал»): the evening's real numbers from the engine, the
// server's uptime and the way to «Как это работает».

import { formatUptime } from './client.js';

const STEP = 4;
const NUMBER = new Intl.NumberFormat('ru-RU');

// Noun forms for 1, 2–4 and 5+ (accusative: the number is always the object), the rest of the caption, and a short
// note on the mechanism behind the number.
const CARDS = [
    ['clicks', ['клик', 'клика', 'кликов'], 'обработал RoadRunner', 'каждый — через WebSocket и RPC'],
    ['jobs', ['задачу', 'задачи', 'задач'], 'выполнила очередь', 'переходы тыкв, костра, круга и сцен'],
    ['burned', ['клик', 'клика', 'кликов'], 'он отбросил на идемпотентности', 'чтобы тыква не перешла дважды'],
    ['shot', ['летуна', 'летунов', 'летунов'], 'сбили', 'каждого — первый успевший, под локом'],
];

function plural(n, [one, few, many]) {
    const tens = n % 100;
    const units = n % 10;
    if (tens >= 11 && tens <= 14) return many;
    if (units === 1) return one;
    return units >= 2 && units <= 4 ? few : many;
}

/**
 * The panel opens by itself on step 4 and stays until closed; from step 5 on it is closed, and the HUD button
 * `[data-reveal-open]` brings it back.
 *
 * @param {HTMLElement} root  #app
 * @returns {{update: (finale: {step: number, reveal?: object}|null, uptime?: number|null) => void}}
 */
export function createReveal(root) {
    const panel = document.createElement('section');
    panel.className = 'reveal';
    panel.dataset.reveal = '';
    panel.setAttribute('aria-labelledby', 'reveal-title');
    panel.hidden = true;
    panel.innerHTML = `
        <button type="button" class="reveal__close" data-reveal-close aria-label="Закрыть">×</button>
        <p class="reveal__kicker">Разоблачение</p>
        <h2 class="reveal__title" id="reveal-title">RoadRunner не умирал</h2>
        <p class="reveal__lead">Весь вечер RoadRunner обеспечивал инфраструктуру вашего ритуала. Вот что через него прошло:</p>
        <dl class="reveal__grid"></dl>
        <p class="reveal__last" data-reveal-last></p>
        <a class="reveal__link" href="/how-it-works" target="_blank" rel="noopener">Как это работает →</a>`;
    const grid = panel.querySelector('.reveal__grid');
    for (const [key] of CARDS) {
        const card = document.createElement('div');
        card.className = 'reveal__card';
        card.innerHTML = `<dt class="reveal__label" data-reveal-label="${key}"></dt><dd class="reveal__number" data-reveal-${key}></dd><dd class="reveal__note" data-reveal-note="${key}"></dd>`;
        grid.append(card);
    }
    const uptimeCard = document.createElement('div');
    uptimeCard.className = 'reveal__card reveal__card--uptime';
    uptimeCard.innerHTML = '<dt class="reveal__label">аптайм RoadRunner</dt><dd class="reveal__number" data-reveal-uptime></dd><dd class="reveal__note">он не умирал ни на секунду</dd>';
    grid.append(uptimeCard);
    root.append(panel);
    const opener = root.querySelector('[data-reveal-open]');
    const closer = panel.querySelector('[data-reveal-close]');

    let step = null;
    let open = false;
    let shown = false;

    function paint() {
        panel.hidden = !shown || !open;
        if (opener) opener.hidden = !shown || open;
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

    return {
        update(finale, uptime = null) {
            const reveal = finale?.reveal;
            shown = Boolean(reveal) && (finale?.step ?? 0) >= STEP;
            if (!shown) {
                step = null;
                open = false;
                paint();
                return;
            }
            // A new step decides afresh: open on the reveal itself, closed in the cursed world.
            if (finale.step !== step) {
                step = finale.step;
                open = step === STEP;
            }
            for (const [key, forms, caption, note] of CARDS) {
                const n = Math.max(0, Math.trunc(Number(reveal[key]) || 0));
                panel.querySelector(`[data-reveal-${key}]`).textContent = NUMBER.format(n);
                panel.querySelector(`[data-reveal-label="${key}"]`).textContent = `${plural(n, forms)} ${caption}`;
                panel.querySelector(`[data-reveal-note="${key}"]`).textContent = note;
            }
            uptimeCard.hidden = uptime === null;
            panel.querySelector('[data-reveal-uptime]').textContent = uptime === null ? '' : formatUptime(uptime);
            const name = reveal.lastPumpkin?.name;
            panel.querySelector('[data-reveal-last]').textContent = reveal.lastPumpkin
                ? `Последний элемент ритуала добил ${name || 'кто-то без имени'}.`
                : 'Последний элемент ритуала добил кто-то неизвестный.';
            paint();
        },
    };
}
