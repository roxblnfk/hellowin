// The reveal of finale step 4 (docs/spec/scenario.md, «Финал»): the evening's real numbers from the engine and the
// way to «Как это работает». A plain pixel panel for now; the finale visuals come with the scene art.

const STEP = 4;
const NUMBER = new Intl.NumberFormat('ru-RU');

// Noun forms for 1, 2–4 and 5+ (accusative: the number is always the object), then the rest of the sentence.
const ROWS = [
    ['clicks', ['клик', 'клика', 'кликов'], 'обработал RoadRunner'],
    ['jobs', ['задачу', 'задачи', 'задач'], 'выполнила очередь'],
    ['burned', ['клик', 'клика', 'кликов'], 'он отбросил на идемпотентности — чтобы тыква не перешла дважды'],
    ['shot', ['летуна', 'летунов', 'летунов'], 'сбили — каждого первый успевший, под локом'],
];

function plural(n, [one, few, many]) {
    const tens = n % 100;
    const units = n % 10;
    if (tens >= 11 && tens <= 14) return many;
    if (units === 1) return one;
    return units >= 2 && units <= 4 ? few : many;
}

/**
 * @param {HTMLElement} root  #app
 * @returns {{update: (finale: {step: number, reveal?: object}|null) => void}}
 */
export function createReveal(root) {
    const panel = document.createElement('section');
    panel.className = 'reveal';
    panel.dataset.reveal = '';
    panel.setAttribute('aria-labelledby', 'reveal-title');
    panel.hidden = true;
    panel.innerHTML = `
        <button type="button" class="reveal__close" data-reveal-close aria-label="Закрыть">×</button>
        <h2 class="reveal__title" id="reveal-title">Разоблачение</h2>
        <dl class="reveal__list"></dl>
        <p class="reveal__last" data-reveal-last></p>
        <a class="reveal__link" href="/how-it-works">Как это работает →</a>`;
    const list = panel.querySelector('.reveal__list');
    for (const [key] of ROWS) {
        const row = document.createElement('div');
        row.className = 'reveal__row';
        row.innerHTML = `<dt class="reveal__number" data-reveal-${key}></dt><dd class="reveal__label" data-reveal-label="${key}"></dd>`;
        list.append(row);
    }
    root.append(panel);

    // Closed by hand, the panel stays closed until the finale moves to another step.
    let closedAt = null;
    let step = null;
    panel.querySelector('[data-reveal-close]').addEventListener('click', () => {
        closedAt = step;
        panel.hidden = true;
    });

    return {
        update(finale) {
            step = finale?.step ?? null;
            const reveal = finale?.reveal;
            if (step === null || step < STEP || !reveal) {
                panel.hidden = true;
                closedAt = null;
                return;
            }
            for (const [key, forms, rest] of ROWS) {
                const n = Math.max(0, Math.trunc(Number(reveal[key]) || 0));
                panel.querySelector(`[data-reveal-${key}]`).textContent = NUMBER.format(n);
                panel.querySelector(`[data-reveal-label="${key}"]`).textContent = `${plural(n, forms)} ${rest}`;
            }
            const name = reveal.lastPumpkin?.name;
            panel.querySelector('[data-reveal-last]').textContent = reveal.lastPumpkin
                ? `Последнюю тыкву добил ${name || 'кто-то без имени'}.`
                : 'Последнюю тыкву добил кто-то неизвестный.';
            panel.hidden = closedAt === step;
        },
    };
}
