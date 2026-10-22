// The moderator's console (docs/spec/frontend.md, «Пульт модератора») in the debug panel of the home page and
// public/dev/lab.html. Shown only to the moderator connection; the effects of every button come back as channel
// events, nothing changes locally. ?debug=1 / ?debug=0 and the backquote key expand and collapse it (remembered).

import { LINES, lineText, menuOf } from './owl.js';
import { createChatConsole } from './chat.js';
import { SCENE, isBar } from './layout.js';

const STORAGE_KEY = 'hellowin:console';
const CONFIRM_MS = 3000;
const NOTE_MS = 3000;
const DONE_MS = 900;
// The finale's steps (docs/spec/scenario.md, «Кости 6-6-6», «Рапира»): the dice at 1, the cursed world's goal at 5, the
// boss at 6, the dawn at 7.
const DICE_STEP = 1;
// The owl's third roll is the 6-6-6 (scenario.md, «Кости 6-6-6»).
const OWL_ROLLS = 3;
const CURSED_STEP = 5;
const BOSS_STEP = 6;
const LAST_STEP = 7;
// The limits' multiplier (scenario.md, «Модератор и сова»): its range and the step of the ÷ / × buttons.
const K_MIN = 0.1;
const K_MAX = 10;
const K_STEP = 1.25;
// The moderator's additions on top of the formula (protocol.md, «Лимиты и баланс») live until the round ends.
const BONUS = 'надбавка до конца раунда';
// The current context's limits as the console says them, in this order; only the keys the server sent show.
const LIMIT_TEXTS = [
    ['pumpkin', (n) => `тыква ${n} кл./стадию`],
    ['bonfire', (n) => `костёр ${n} кл./стадию`],
    ['circle', (n) => `круг ${n} кл./стадию`],
    ['bar', (n) => `бар ${n} ${plural(n, 'кружка', 'кружки', 'кружек')}`],
    ['drinks', (n) => `кружки ${n}`],
    ['zombies', (n) => `зомби ${n}`],
    ['boss', (n) => `HP босса ${n}`],
];
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
    'no flyers': 'Нет летунов',
};

/** Russian plural: 1 кружка, 2 кружки, 5 кружек. */
function plural(n, one, few, many) {
    const tens = n % 100;
    const last = n % 10;
    if (tens >= 11 && tens <= 14) return many;
    return last === 1 ? one : last >= 2 && last <= 4 ? few : many;
}

/** «тыква 12 кл./стадию · костёр 59 кл./стадию» from `limits` of the model; null — the server has not said. */
export function limitsText(limits) {
    if (!limits) return null;
    const parts = LIMIT_TEXTS.filter(([key]) => Number.isInteger(limits.limits?.[key])).map(([key, text]) => text(limits.limits[key]));
    return parts.length ? parts.join(' · ') : 'в этой сцене лимитов нет';
}

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
 * @returns {null|{update: (model: object) => void, say: (event: object) => void}}  update repaints the console from the client
 *   model; say adds a `say` event to the chat log
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
    // The pause of the progress shows on the folded console too, brighter than the summary.
    const paused = el('span', { class: 'console__paused', 'data-console-paused': '' }, 'пауза');
    paused.hidden = true;
    toggle.append(toggleName, paused, summary);
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
    // The pause of the progress (protocol.md, «Пауза прогресса»): the scene's task stands still, the flyers fly on.
    const pause = button(story, { 'data-action': 'pause', 'aria-pressed': 'false', title: 'Задача сцены замирает: клики, кружки, удары и кости не засчитываются; летуны и зомби летают. Снимается и сбросом' }, 'Пауза прогресса: выкл');
    // The service numbers left the HUD for here: the round, the scene's number, the finale step.
    const storyInfo = el('span', { class: 'console__info', 'data-console-story': '' });
    story.append(storyInfo);

    // The bar's goal (scenario.md, «Сцена 0 — «Бар»»): mugs the hall drinks before going out; the nudge goes without it.
    const pub = group('Бар');
    const more = button(pub, { 'data-action': 'bar-more', title: `Цель бара больше: ${BONUS}` }, '+5 бокалов');
    const less = button(pub, { 'data-action': 'bar-less', title: `Цель бара меньше: ${BONUS}` }, '−5 бокалов');
    const nudge = button(pub, { 'data-action': 'bar-ready', title: 'Бар готов, не дожидаясь шкалы' }, 'Отмашка');
    const pubInfo = el('span', { class: 'console__info', 'data-console-bar': '' });
    pub.append(pubInfo);
    pub.hidden = true;

    // The cursed world's goal (scenario.md, «Шаг 5»): the moderator's addition to each target, ±5.
    const cursedGroup = group('Проклятый мир');
    for (const [goal, delta, text] of [['drinks', 5, '+5 кружек'], ['drinks', -5, '−5 кружек'], ['zombies', 5, '+5 зомби'], ['zombies', -5, '−5 зомби']]) {
        const title = `Цель «${goal === 'drinks' ? 'кружки' : 'зомби'}» ${delta > 0 ? 'больше' : 'меньше'}: ${BONUS}`;
        const b = button(cursedGroup, { 'data-action': `cursed-${goal}-${delta > 0 ? 'up' : 'down'}`, title }, text);
        b.addEventListener('click', () => send(b, 'cursed.target', { goal, delta }, { done: true }));
    }
    const cursedInfo = el('span', { class: 'console__info', 'data-console-cursed': '' });
    cursedGroup.append(cursedInfo);
    cursedGroup.hidden = true;

    // FrankenPHP's health (scenario.md, «Шаг 6 — босс FrankenPHP»), ±10; nothing to change before he wakes up.
    const bossGroup = group('Босс');
    for (const [delta, text] of [[10, 'HP +10'], [-10, 'HP −10']]) {
        const b = button(bossGroup, { 'data-action': `boss-hp-${delta > 0 ? 'up' : 'down'}`, title: `Здоровье FrankenPHP ${delta > 0 ? 'больше' : 'меньше'}: ${BONUS}` }, text);
        b.addEventListener('click', () => send(b, 'boss.hp', { delta }, { done: true }));
    }
    const bossInfo = el('span', { class: 'console__info', 'data-console-boss': '' });
    bossGroup.append(bossInfo);
    bossGroup.hidden = true;

    // The dice of step 1: the moderator's way out — the owl rolls, and its third roll is the 6-6-6.
    const diceGroup = group('Кости');
    const owlDice = button(diceGroup, { 'data-console-dice': '', 'data-action': 'dice-owl', title: 'Третий бросок совы — 6-6-6' }, 'Сова бросает');
    owlDice.addEventListener('click', () => send(owlDice, 'dice.owl', {}, { done: true }));
    diceGroup.hidden = true;

    // The quick menu follows the scene or the finale step (owl.js, MENU), rebuilt when that changes; the title is the
    // text every viewer gets right now. The own text is always there.
    const owl = group('Сова');
    const owlLines = el('div', { class: 'console__lines', 'data-owl-lines': '' });
    owl.append(owlLines);
    let menuKey = null;
    const form = el('form', { class: 'console__say', 'data-owl-form': '' });
    const input = el('input', { class: 'console__input', 'data-owl-input': '', maxlength: '200', placeholder: 'Свой текст совы', 'aria-label': 'Свой текст совы', autocomplete: 'off' });
    const say = el('button', { type: 'submit', class: 'debug__button', 'data-action': 'owl-text' }, 'Сказать');
    say.dataset.label = 'Сказать';
    form.append(input, say);
    owl.append(form);

    const night = group('Нечисть');
    const witch = button(night, { 'data-action': 'witch' }, 'Ведьма');
    const runner = button(night, { 'data-action': 'roadrunner' }, 'Бегун');
    // A wave of the current set's flyers at once (protocol.md, `flyers.burst`): only where the set has any.
    const burst = button(night, { 'data-action': 'flyers-burst', title: 'Внеочередная волна летунов: больше игроков — больше летунов' }, 'Пуск летунов');

    // The limits shown are the server's, from the model (`limits`): the client does not know the formula's numbers.
    // The players are its n, held for a few seconds — not `online`, which jumps with every walker.
    const limits = group('Лимиты');
    const kForm = el('form', { class: 'console__k', 'data-k-form': '', novalidate: '' });
    const kDown = button(kForm, { 'data-action': 'k-down', title: 'Множитель меньше: все лимиты меньше' }, `÷${formatK(K_STEP)}`);
    const kValue = el('output', { class: 'console__value', 'data-k-value': '', 'aria-label': 'Множитель лимитов', title: 'Множитель лимитов' });
    kForm.append(kValue);
    const kUp = button(kForm, { 'data-action': 'k-up', title: 'Множитель больше: все лимиты больше' }, `×${formatK(K_STEP)}`);
    // Text, not number: a comma is typed on a phone, and an out-of-range value goes to the server, which says no.
    const kInput = el('input', { class: 'console__input console__input--k', 'data-k-input': '', inputmode: 'decimal', 'aria-label': 'Множитель лимитов, от 0,1 до 10', autocomplete: 'off' });
    const kSet = el('button', { type: 'submit', class: 'debug__button', 'data-action': 'k-set' }, 'OK');
    kSet.dataset.label = 'OK';
    kForm.append(kInput, kSet);
    limits.append(kForm);
    const players = el('span', { class: 'console__info', 'data-console-players': '' });
    const limitsInfo = el('span', { class: 'console__info', 'data-console-limits': '' });
    limits.append(players, limitsInfo);

    // The players' chat (docs/spec/frontend.md, «Чат»): the log, re-read whenever the console opens, and the mutes.
    const chatGroup = group('Чат');
    const chat = createChatConsole(call);
    chatGroup.append(chat.node);

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

    // `done` — «✓» on success, or a function of the reply for the note.
    async function send(b, method, data, { done = false } = {}) {
        b.disabled = true;
        try {
            const reply = await call(method, data);
            if (typeof done === 'function') note(b, done(reply));
            else if (done) note(b, '✓', DONE_MS);
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
            : current === DICE_STEP && !model.ready ? 'Нет 6-6-6. Точно?'
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
    const paintMenu = (scene, current) => {
        const ids = menuOf(scene, current);
        const key = `${scene}:${current}`;
        if (key !== menuKey) {
            menuKey = key;
            owlLines.replaceChildren();
            for (const id of ids) {
                const b = button(owlLines, { 'data-owl-line': id, title: lineText({ line: id }, scene, current) ?? '' }, LINES[id].button);
                b.addEventListener('click', () => send(b, 'owl.say', { line: id }, { done: true }));
            }
        }
        owlLines.hidden = ids.length === 0;
    };
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
    const launched = (reply) => {
        const n = Number.isInteger(reply?.count) ? reply.count : 0;
        return `+${n} ${plural(n, 'летун', 'летуна', 'летунов')}`;
    };
    burst.addEventListener('click', () => send(burst, 'flyers.burst', {}, { done: launched }));
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
    // The log is read on every opening: a zombie's original text comes only with `chat.history`.
    const reread = () => {
        if (!panel.hidden && !body.hidden) chat.history();
    };
    toggle.addEventListener('click', () => {
        setOpen(body.hidden);
        remember(!body.hidden);
        reread();
    });
    document.addEventListener('keydown', (event) => {
        if (event.code !== 'Backquote' || panel.hidden || event.target.closest?.('input, textarea, select')) return;
        setOpen(body.hidden);
        remember(!body.hidden);
        reread();
    });

    return {
        say: (event) => chat.say(event),

        update(state) {
            model = state;
            const wasHidden = panel.hidden;
            panel.hidden = !model.moderator;
            if (wasHidden && !panel.hidden) reread();
            const final = model.scene >= model.sceneStages;
            const current = model.finale?.step ?? 0;

            const dawn = final && current >= LAST_STEP;
            next.hidden = final && !dawn;
            const bar = isBar(model.scene);
            next.toggleAttribute('data-ready', model.ready || dawn);
            storyInfo.textContent = [`раунд ${model.round}`, `сцена ${model.scene}`, final && `шаг ${current}`].filter(Boolean).join(' · ');
            paintMenu(model.scene, final ? current : null);
            pub.hidden = !bar;
            pubInfo.textContent = `цель ${model.bar?.target ?? 0} · выпито ${model.bar?.drunk ?? 0}`;
            nudge.disabled = model.ready;
            setLabel(next, dawn ? 'В бар' : model.ready ? 'Следующая сцена ›' : 'Следующая сцена');
            step.hidden = !final || dawn;
            step.toggleAttribute('data-ready', final && (current === 0 || current === DICE_STEP || current === CURSED_STEP) && model.ready);
            diceGroup.hidden = !(final && current === DICE_STEP && model.dice?.on === true);
            setLabel(owlDice, `Сова бросает (${Math.min(OWL_ROLLS, model.owlRolls ?? 0)}/${OWL_ROLLS})`);
            setLabel(step, `Шаг финала ${current + 1}`);
            cursedGroup.hidden = !(final && current === CURSED_STEP);
            const cursed = model.cursed;
            cursedInfo.textContent = cursed ? `кружки ${cursed.drunk}/${cursed.drinksTarget} · зомби ${cursed.slain}/${cursed.zombiesTarget}` : 'целей ещё нет';
            const boss = model.boss;
            // Only while FrankenPHP is up: before he wakes and after he falls there is no health to change.
            bossGroup.hidden = !(final && current === BOSS_STEP && boss?.hp > 0);
            bossInfo.textContent = boss ? `HP ${boss.hp} / ${boss.max}` : '';
            // Each one where it works (docs/spec/frontend.md, «Пульт модератора»): the witch anywhere but the bar, the
            // runner in the day — the rare flyer of HELLOWIN_FLYER_RARE — the wave where the set has flyers.
            witch.hidden = bar;
            runner.hidden = model.scene !== SCENE.day;
            burst.hidden = model.limits?.burst !== true;
            pause.setAttribute('aria-pressed', String(model.paused));
            setLabel(pause, model.paused ? 'Пауза прогресса: вкл' : 'Пауза прогресса: выкл');
            paused.hidden = !model.paused;

            const k = Number(model.k ?? 1);
            kValue.textContent = `×${formatK(k)}`;
            kInput.placeholder = formatK(k);
            players.textContent = `игроков: ${model.limits?.players ?? '—'}`;
            limitsInfo.textContent = limitsText(model.limits) ?? 'лимиты ещё не пришли';

            const finaleName = current >= LAST_STEP ? 'рассвет' : current === BOSS_STEP ? 'рапира' : current === CURSED_STEP ? 'проклятый мир' : `финал · шаг ${current}`;
            const parts = [final ? finaleName : isBar(model.scene) ? 'бар' : `сцена ${model.scene}`];
            // In the final scene readiness waits for the moderator only at step 0 (the circle), step 1 (the 6-6-6) and
            // step 5 (the goals).
            if (model.ready && !isBar(model.scene) && (!final || current === 0 || current === DICE_STEP || current === CURSED_STEP)) parts.push('готова');
            if (k !== 1) parts.push(`×${formatK(k)}`);
            summary.textContent = parts.join(' · ');
        },
    };
}
