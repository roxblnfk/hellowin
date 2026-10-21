// The moderator's console (docs/spec/frontend.md, «Пульт модератора») in the debug panel of the home page and
// public/dev/lab.html. Shown only to the moderator connection; the effects of every button come back as channel
// events, nothing changes locally. ?debug=1 / ?debug=0 and the backquote key expand and collapse it (remembered).

import { LINES } from './owl.js';
import { isBar } from './layout.js';

const STORAGE_KEY = 'hellowin:console';
const CONFIRM_MS = 3000;
const NOTE_MS = 3000;
const DONE_MS = 900;
// The finale's steps (docs/spec/scenario.md, «Рапира»): the cursed world's goal at 5, the boss at 6, the dawn at 7.
const CURSED_STEP = 5;
const BOSS_STEP = 6;
const LAST_STEP = 7;
// The threshold multiplier (scenario.md, «Множитель порогов»): its range and the step of the ÷ / × buttons.
const K_MIN = 0.1;
const K_MAX = 10;
const K_STEP = 1.25;
const ERRORS = {
    forbidden: 'Нет прав',
    'not ready': 'Не готово',
    'final scene': 'Уже финал',
    'not final': 'Не финал',
    'unknown kind': 'Нет такого',
    'invalid text': 'Пустой текст',
    busy: 'Занято',
    'invalid k': '0,1–10',
    'not bar': 'Не бар',
    'not cursed': 'Не шаг 5',
    'not now': 'Не сейчас',
};

const roundK = (k) => Math.round(k * 100) / 100;
const clampK = (k) => Math.min(K_MAX, Math.max(K_MIN, roundK(k)));
const formatK = (k) => k.toFixed(2).replace('.', ',');

function rememberedOpen() {
    const param = new URLSearchParams(location.search).get('debug');
    try {
        if (param === '1') localStorage.setItem(STORAGE_KEY, 'open');
        if (param === '0') localStorage.setItem(STORAGE_KEY, 'closed');
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) return stored === 'open';
    } catch {
        if (param) return param === '1';
    }
    // A phone gets the scene first; the console is one tap away.
    return window.innerWidth > 600;
}

function remember(open) {
    try {
        localStorage.setItem(STORAGE_KEY, open ? 'open' : 'closed');
    } catch {
        // Not remembered; the toggle still works for this page.
    }
}

const el = (tag, attrs = {}, text = '') => {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
    if (text) node.textContent = text;
    return node;
};

/**
 * @param {HTMLElement} panel  `[data-debug-panel]`, emptied and filled with the console
 * @param {object} actions
 * @param {(method: string, data: object) => Promise<object>} actions.call  console RPC; rejects with Error(code)
 * @param {() => void} actions.clickAll  RPC `click` on every pumpkin
 * @param {boolean} [actions.open]  start expanded or collapsed instead of the remembered state
 * @returns {null|{update: (model: object) => void}}  update repaints the console from the client model
 */
export function initDebugPanel(panel, { call, clickAll, open }) {
    if (!panel) return null;
    panel.hidden = true;
    panel.classList.add('console');
    panel.replaceChildren();
    let model = null;

    const toggle = el('button', { type: 'button', class: 'console__toggle', 'data-console-toggle': '', 'aria-expanded': 'true' });
    const toggleName = el('span', { class: 'console__name' }, '🦉 Пульт');
    const summary = el('span', { class: 'console__summary', 'data-console-summary': '' });
    toggle.append(toggleName, summary);
    const body = el('div', { class: 'console__body', 'data-console-body': '' });
    toggle.setAttribute('aria-controls', body.id = 'console-body');
    panel.append(toggle, body);

    const group = (title) => {
        const g = el('div', { class: 'console__group', role: 'group', 'aria-label': title });
        g.append(el('span', { class: 'console__title' }, title));
        body.append(g);
        return g;
    };
    const button = (parent, attrs, text) => {
        const b = el('button', { type: 'button', class: 'debug__button', ...attrs }, text);
        b.dataset.label = text;
        parent.append(b);
        return b;
    };

    const story = group('Сюжет');
    const next = button(story, { 'data-action': 'scene-next' }, 'Следующая сцена');
    const step = button(story, { 'data-action': 'finale-step' }, 'Шаг финала');
    const reset = button(story, { 'data-action': 'reset' }, 'Заново');

    // The bar's goal (scenario.md, «Сцена 0 — «Бар»»): mugs the hall drinks before going out; the nudge goes without it.
    const pub = group('Бар');
    const more = button(pub, { 'data-action': 'bar-more', title: 'Цель бара больше' }, '+5 бокалов');
    const less = button(pub, { 'data-action': 'bar-less', title: 'Цель бара меньше' }, '−5 бокалов');
    const nudge = button(pub, { 'data-action': 'bar-ready', title: 'Бар готов, не дожидаясь шкалы' }, 'Отмашка');
    const pubInfo = el('span', { class: 'console__info', 'data-console-bar': '' });
    pub.append(pubInfo);
    pub.hidden = true;

    // The cursed world's goal (scenario.md, «Шаг 5»): the moderator's addition to each target, ±5.
    const cursedGroup = group('Проклятый мир');
    for (const [goal, delta, text] of [['drinks', 5, '+5 кружек'], ['drinks', -5, '−5 кружек'], ['zombies', 5, '+5 зомби'], ['zombies', -5, '−5 зомби']]) {
        const b = button(cursedGroup, { 'data-action': `cursed-${goal}-${delta > 0 ? 'up' : 'down'}` }, text);
        b.addEventListener('click', () => send(b, 'cursed.target', { goal, delta }, { done: true }));
    }
    const cursedInfo = el('span', { class: 'console__info', 'data-console-cursed': '' });
    cursedGroup.append(cursedInfo);
    cursedGroup.hidden = true;

    // FrankenPHP's health (scenario.md, «Шаг 6 — босс FrankenPHP»), ±10; nothing to change before he wakes up.
    const bossGroup = group('Босс');
    const hpButtons = [[10, 'HP +10'], [-10, 'HP −10']].map(([delta, text]) => {
        const b = button(bossGroup, { 'data-action': `boss-hp-${delta > 0 ? 'up' : 'down'}` }, text);
        b.addEventListener('click', () => send(b, 'boss.hp', { delta }, { done: true }));
        return b;
    });
    const bossInfo = el('span', { class: 'console__info', 'data-console-boss': '' });
    bossGroup.append(bossInfo);
    bossGroup.hidden = true;

    const owl = group('Сова');
    for (const [id, line] of Object.entries(LINES)) {
        if (line.button) button(owl, { 'data-owl-line': id, title: line.text, ...(line.bar ? { 'data-owl-bar': '' } : {}) }, line.button);
    }
    // Lines the server says by itself, for saying again by hand; those naming a player are left out.
    const repeat = el('select', { class: 'console__select', 'data-owl-repeat': '', 'aria-label': 'Повторить реплику' });
    repeat.append(el('option', { value: '' }, 'Повторить…'));
    for (const [id, line] of Object.entries(LINES)) {
        if (line.auto && !line.text.includes('{player}')) repeat.append(el('option', { value: id, title: line.text }, id));
    }
    owl.append(repeat);
    const form = el('form', { class: 'console__say', 'data-owl-form': '' });
    const input = el('input', { class: 'console__input', 'data-owl-input': '', maxlength: '200', placeholder: 'Свой текст совы', 'aria-label': 'Свой текст совы', autocomplete: 'off' });
    const say = el('button', { type: 'submit', class: 'debug__button', 'data-action': 'owl-text' }, 'Сказать');
    say.dataset.label = 'Сказать';
    form.append(input, say);
    owl.append(form);

    const night = group('Нечисть');
    const witch = button(night, { 'data-action': 'witch' }, 'Ведьма');
    const runner = button(night, { 'data-action': 'roadrunner' }, 'Бегун');
    const zombies = button(night, { 'data-action': 'zombies', 'aria-pressed': 'false', title: 'Бредут только в проклятом мире' }, 'Зомби');
    const pause = button(night, { 'data-action': 'pause', 'aria-pressed': 'false', title: 'Летуны и зомби не запускаются' }, 'Пауза');

    // The thresholds shown are the server's, from the model: the client does not know the base values.
    const limits = group('Пороги');
    const kForm = el('form', { class: 'console__k', 'data-k-form': '', novalidate: '' });
    const kDown = button(kForm, { 'data-action': 'k-down', title: 'Пороги меньше' }, `÷${formatK(K_STEP)}`);
    const kValue = el('output', { class: 'console__value', 'data-k-value': '', 'aria-label': 'Множитель порогов' });
    kForm.append(kValue);
    const kUp = button(kForm, { 'data-action': 'k-up', title: 'Пороги больше' }, `×${formatK(K_STEP)}`);
    // Text, not number: a comma is typed on a phone, and an out-of-range value goes to the server, which says no.
    const kInput = el('input', { class: 'console__input console__input--k', 'data-k-input': '', inputmode: 'decimal', 'aria-label': 'Множитель порогов, от 0,1 до 10', autocomplete: 'off' });
    const kSet = el('button', { type: 'submit', class: 'debug__button', 'data-action': 'k-set' }, 'OK');
    kSet.dataset.label = 'OK';
    kForm.append(kInput, kSet);
    limits.append(kForm);
    const limitsInfo = el('span', { class: 'console__info', 'data-console-thresholds': '' });
    const online = el('span', { class: 'console__info', 'data-console-online': '' });
    limits.append(limitsInfo, online);

    const tools = group('Отладка');
    button(tools, { 'data-action': 'click-all' }, 'Клик по всем').addEventListener('click', () => clickAll());

    // Labels change with the state, but a note on a button (an error, «Точно?») holds until it times out.
    const setLabel = (b, text) => {
        b.dataset.label = text;
        if (!b.dataset.note && !('armed' in b.dataset)) b.textContent = text;
    };
    const note = (b, text, ms = NOTE_MS) => {
        clearTimeout(Number(b.dataset.noteTimer));
        b.dataset.note = text;
        b.textContent = text;
        b.dataset.noteTimer = String(setTimeout(() => {
            delete b.dataset.note;
            b.textContent = b.dataset.label;
        }, ms));
    };
    const disarm = (b) => {
        clearTimeout(Number(b.dataset.armTimer));
        delete b.dataset.armed;
        b.textContent = b.dataset.label;
    };
    // Two presses instead of confirm(): the first arms the button for a few seconds, the second goes through.
    const armed = (b, question) => {
        if ('armed' in b.dataset) {
            disarm(b);
            return true;
        }
        b.dataset.armed = '';
        b.textContent = question;
        b.dataset.armTimer = String(setTimeout(() => disarm(b), CONFIRM_MS));
        return false;
    };

    async function send(b, method, data, { done = false } = {}) {
        b.disabled = true;
        try {
            const reply = await call(method, data);
            if (done) note(b, '✓', DONE_MS);
            return reply;
        } catch (error) {
            console.warn(`${method} failed`, error);
            note(b, ERRORS[error?.message] ?? 'Не вышло');
            return null;
        } finally {
            b.disabled = false;
        }
    }

    next.addEventListener('click', () => {
        if (!model) return;
        // At the dawn it is «В бар»: a new round of the evening, always ready.
        if (model.scene >= model.sceneStages) {
            if ((model.finale?.step ?? 0) >= LAST_STEP) send(next, 'scene.next', { scene: model.scene });
            return;
        }
        // Forcing an unready scene is for a room that got stuck: it asks first.
        if (!model.ready && !armed(next, 'Не готово. Точно?')) return;
        send(next, 'scene.next', model.ready ? { scene: model.scene } : { scene: model.scene, force: true });
    });
    step.addEventListener('click', () => {
        const current = model?.finale?.step ?? 0;
        if (!model || current >= LAST_STEP) return;
        // Step 1 waits for the closed circle, step 6 for the cursed world's goals: going without them asks first, like
        // an unready scene. Step 7 comes by itself when the boss falls, so pressing it always forces.
        const question = current === 0 && !model.ready ? 'Круг не замкнут. Точно?'
            : current === CURSED_STEP && !model.ready ? 'Цели не выполнены. Точно?'
            : current === BOSS_STEP ? 'Босс жив. Точно?'
            : null;
        if (question && !armed(step, question)) return;
        send(step, 'finale.step', question ? { step: current + 1, force: true } : { step: current + 1 });
    });
    reset.addEventListener('click', () => {
        if (model && armed(reset, 'Точно?')) send(reset, 'reset', { round: model.round });
    });
    more.addEventListener('click', () => send(more, 'bar.target', { delta: 5 }, { done: true }));
    less.addEventListener('click', () => send(less, 'bar.target', { delta: -5 }, { done: true }));
    nudge.addEventListener('click', () => send(nudge, 'bar.ready', {}, { done: true }));
    owl.addEventListener('click', (event) => {
        const b = event.target.closest('[data-owl-line]');
        if (b) send(b, 'owl.say', { line: b.dataset.owlLine }, { done: true });
    });
    repeat.addEventListener('change', () => {
        const line = repeat.value;
        repeat.value = '';
        if (line) call('owl.say', { line }).catch((error) => console.warn('owl.say failed', error));
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = input.value.trim();
        if (!text) {
            note(say, ERRORS['invalid text']);
            return;
        }
        if (await send(say, 'owl.say', { text }, { done: true })) input.value = '';
    });
    witch.addEventListener('click', () => send(witch, 'flyer.special', { kind: 'witch' }, { done: true }));
    runner.addEventListener('click', () => send(runner, 'flyer.special', { kind: 'roadrunner' }, { done: true }));
    zombies.addEventListener('click', () => model && send(zombies, 'zombies', { on: !model.zombies }));
    pause.addEventListener('click', () => model && send(pause, 'pause', { on: !model.paused }));
    kDown.addEventListener('click', () => model && send(kDown, 'thresholds', { k: clampK(model.k / K_STEP) }));
    kUp.addEventListener('click', () => model && send(kUp, 'thresholds', { k: clampK(model.k * K_STEP) }));
    kForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const k = Number(kInput.value.trim().replace(',', '.'));
        if (!kInput.value.trim() || !Number.isFinite(k)) {
            note(kSet, ERRORS['invalid k']);
            return;
        }
        if (await send(kSet, 'thresholds', { k: roundK(k) }, { done: true })) kInput.value = '';
    });

    const setOpen = (on) => {
        body.hidden = !on;
        toggle.setAttribute('aria-expanded', String(on));
        panel.classList.toggle('console--closed', !on);
    };
    setOpen(open ?? rememberedOpen());
    toggle.addEventListener('click', () => {
        setOpen(body.hidden);
        remember(!body.hidden);
    });
    document.addEventListener('keydown', (event) => {
        if (event.code !== 'Backquote' || panel.hidden || event.target.closest?.('input, textarea, select')) return;
        setOpen(body.hidden);
        remember(!body.hidden);
    });

    return {
        update(state) {
            model = state;
            panel.hidden = !model.moderator;
            const final = model.scene >= model.sceneStages;
            const current = model.finale?.step ?? 0;

            const dawn = final && current >= LAST_STEP;
            next.hidden = final && !dawn;
            const bar = isBar(model.scene);
            next.toggleAttribute('data-ready', model.ready || dawn);
            owl.querySelectorAll('[data-owl-bar]').forEach((b) => { b.hidden = !bar; });
            pub.hidden = !bar;
            pubInfo.textContent = `цель ${model.bar?.target ?? 0} · выпито ${model.bar?.drunk ?? 0}`;
            nudge.disabled = model.ready;
            setLabel(next, dawn ? 'В бар' : model.ready ? 'Следующая сцена ›' : 'Следующая сцена');
            step.hidden = !final || dawn;
            step.toggleAttribute('data-ready', final && (current === 0 || current === CURSED_STEP) && model.ready);
            setLabel(step, `Шаг финала ${current + 1}`);
            cursedGroup.hidden = !(final && current === CURSED_STEP);
            const cursed = model.cursed;
            cursedInfo.textContent = cursed ? `кружки ${cursed.drunk}/${cursed.drinksTarget} · зомби ${cursed.slain}/${cursed.zombiesTarget}` : 'целей ещё нет';
            const boss = model.boss;
            bossGroup.hidden = !(final && current === BOSS_STEP);
            hpButtons.forEach((b) => { b.disabled = !(boss?.hp > 0); });
            bossInfo.textContent = boss ? `HP ${boss.hp} / ${boss.max}` : 'босс спит';
            zombies.setAttribute('aria-pressed', String(model.zombies));
            setLabel(zombies, model.zombies ? 'Зомби: вкл' : 'Зомби: выкл');
            pause.setAttribute('aria-pressed', String(model.paused));
            setLabel(pause, model.paused ? 'Пауза: вкл' : 'Пауза: выкл');

            const k = Number(model.k ?? 1);
            kValue.textContent = `×${formatK(k)}`;
            kInput.placeholder = formatK(k);
            const bonfire = model.elements.find((e) => e.type === 'bonfire');
            const circle = model.elements.find((e) => e.type === 'circle');
            limitsInfo.textContent = [`тыква ${model.threshold}`, bonfire && `костёр ${bonfire.threshold}`, circle && `круг ${circle.threshold}`]
                .filter(Boolean).join(' · ');
            online.textContent = `онлайн: ${model.online ?? 0}`;

            const finaleName = current >= LAST_STEP ? 'рассвет' : current === BOSS_STEP ? 'рапира' : current === CURSED_STEP ? 'проклятый мир' : `финал · шаг ${current}`;
            const parts = [final ? finaleName : isBar(model.scene) ? 'бар' : `сцена ${model.scene}`];
            // In the final scene readiness waits for the moderator only at step 0 (the circle) and step 5 (the goals).
            if (model.ready && !isBar(model.scene) && (!final || current === 0 || current === CURSED_STEP)) parts.push('готова');
            if (model.paused) parts.push('пауза');
            if (k !== 1) parts.push(`×${formatK(k)}`);
            summary.textContent = parts.join(' · ');
        },
    };
}
