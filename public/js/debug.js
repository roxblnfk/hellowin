// The moderator's console (docs/spec/frontend.md, «Пульт модератора») in the debug panel of the home page and
// public/dev/lab.html. Shown only to the moderator connection; the effects of every button come back as channel
// events, nothing changes locally. ?debug=1 / ?debug=0 and the backquote key expand and collapse it (remembered).

import { LINES, lineText, menuOf } from './owl.js';
import { createChatConsole } from './chat.js';
import { SCENE, isBar } from './layout.js';
import { pick } from './i18n.js';

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

/** Russian plural: 1 кружка, 2 кружки, 5 кружек. */
function plural(n, one, few, many) {
    const tens = n % 100;
    const last = n % 10;
    if (tens >= 11 && tens <= 14) return many;
    return last === 1 ? one : last >= 2 && last <= 4 ? few : many;
}

const TEXT = pick({
    ru: {
        // The moderator's additions on top of the formula (protocol.md, «Лимиты и баланс») live until the round ends.
        bonus: 'надбавка до конца раунда',
        bar: (n) => `бар ${n} ${plural(n, 'кружка', 'кружки', 'кружек')}`,
        perStage: (what, n) => `${{ pumpkin: 'тыква', bonfire: 'костёр', circle: 'круг' }[what]} ${n} кл./стадию`,
        drinks: 'кружки',
        zombies: 'зомби',
        bossHp: 'HP босса',
        errors: {
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
        },
        failed: 'Не вышло',
        decimal: ',',
        noLimits: 'в этой сцене лимитов нет',
        console: '🦉 Пульт',
        paused: 'пауза',
        groups: { story: 'Сюжет', bar: 'Бар', cursed: 'Проклятый мир', boss: 'Босс', dice: 'Кости', owl: 'Сова', night: 'Нечисть', limits: 'Лимиты', chat: 'Чат', tools: 'Отладка' },
        next: 'Следующая сцена',
        nextReady: 'Следующая сцена ›',
        toBar: 'В бар',
        step: 'Шаг финала',
        reset: 'Заново',
        pauseTitle: 'Задача сцены замирает: клики, кружки, удары и кости не засчитываются; летуны и зомби летают. Снимается и сбросом',
        pauseOn: 'Пауза прогресса: вкл',
        pauseOff: 'Пауза прогресса: выкл',
        barGoal: (up) => `Цель бара ${up ? 'больше' : 'меньше'}`,
        glasses: 'бокалов',
        nudgeTitle: 'Бар готов, не дожидаясь шкалы',
        nudge: 'Отмашка',
        mugs: 'кружек',
        cursedGoal: (goal, up) => `Цель «${goal === 'drinks' ? 'кружки' : 'зомби'}» ${up ? 'больше' : 'меньше'}`,
        bossGoal: (up) => `Здоровье FrankenPHP ${up ? 'больше' : 'меньше'}`,
        owlDiceTitle: 'Третий бросок совы — 6-6-6',
        owlDice: 'Сова бросает',
        owlText: 'Свой текст совы',
        say: 'Сказать',
        witch: 'Ведьма',
        runner: 'Бегун',
        burstTitle: 'Внеочередная волна летунов: больше игроков — больше летунов',
        burst: 'Пуск летунов',
        kDown: 'Множитель меньше: все лимиты меньше',
        k: 'Множитель лимитов',
        kUp: 'Множитель больше: все лимиты больше',
        kInput: 'Множитель лимитов, от 0,1 до 10',
        clickAll: 'Клик по всем',
        statsReset: 'Сбросить статистику',
        statsResetTitle: 'Обнулить клики, очки, кружки, урон и броски всех игроков; имена и персонажи остаются',
        statsSure: 'Стереть у всех. Точно?',
        statsDone: (n) => `обнулено: ${n}`,
        sure: 'Точно?',
        notReady: 'Не готово. Точно?',
        circleOpen: 'Круг не замкнут. Точно?',
        noSix: 'Нет 6-6-6. Точно?',
        goalsOpen: 'Цели не выполнены. Точно?',
        bossAlive: 'Босс жив. Точно?',
        launched: (n) => `+${n} ${plural(n, 'летун', 'летуна', 'летунов')}`,
        round: 'раунд',
        scene: 'сцена',
        stepWord: 'шаг',
        pub: (target, drunk) => `цель ${target} · выпито ${drunk}`,
        noGoals: 'целей ещё нет',
        online: (players, connections) => `онлайн: ${players} · подключений: ${connections}`,
        offline: 'онлайн: —',
        field: (n) => `на поле: ${n}`,
        noLimitsYet: 'лимиты ещё не пришли',
        dawn: 'рассвет',
        rapier: 'рапира',
        cursed: 'проклятый мир',
        finale: 'финал',
        barName: 'бар',
        ready: 'готова',
        autoOn: 'Автопилот: вкл',
        autoOff: 'Автопилот: выкл',
        autoTitle: 'Сценарий идёт сам: переход — когда зал выполнил задачу, сова договорила и прошла пауза; на рассвете — через 5–10 минут «В бар», и по кругу. Ведёт сервер, и при закрытом пульте; ручные кнопки работают',
        autoBadge: 'автопилот',
        autoDo: {
            'bar.ready': () => 'отмашка бара',
            'scene.next': (to, final) => (to >= final ? 'финал' : ['бар', 'день', 'закат', 'ночь'][to] ?? `сцена ${to}`),
            'finale.step': (to) => `шаг финала ${to}`,
            'dice.owl': () => 'сова бросает кости',
            again: () => 'в бар',
        },
        autoForce: 'без готовности',
        autoIn: (left) => `через ${left}`,
        autoNow: 'сейчас',
        autoPause: 'стоит: пауза прогресса',
        autoWait: {
            bar: 'ждёт зал: цель бара',
            pumpkins: 'ждёт зал: тыквы',
            bonfire: 'ждёт зал: тыквы и костёр',
            circle: 'ждёт зал: круг',
            dice: 'ждёт зал: 6-6-6',
            cursed: 'ждёт зал: цели проклятого мира',
            boss: 'ждёт зал: FrankenPHP',
            room: 'ждёт зал',
        },
    },
    en: {
        bonus: 'bonus until the round ends',
        bar: (n) => `bar ${n} ${n === 1 ? 'mug' : 'mugs'}`,
        perStage: (what, n) => `${what} ${n} clicks/stage`,
        drinks: 'mugs',
        zombies: 'zombies',
        bossHp: 'boss HP',
        errors: {
            forbidden: 'Not allowed',
            'not ready': 'Not ready',
            'final scene': 'Already the finale',
            'not final': 'Not the finale',
            'unknown kind': 'No such thing',
            'invalid text': 'Empty text',
            busy: 'Busy',
            'invalid k': '0.1–10',
            'not bar': 'Not the bar',
            'not cursed': 'Not step 5',
            'not now': 'Not now',
            'no flyers': 'No flyers',
        },
        failed: 'Failed',
        decimal: '.',
        noLimits: 'no limits in this scene',
        console: '🦉 Console',
        paused: 'paused',
        groups: { story: 'Story', bar: 'Bar', cursed: 'Cursed world', boss: 'Boss', dice: 'Dice', owl: 'Owl', night: 'Creatures', limits: 'Limits', chat: 'Chat', tools: 'Debug' },
        next: 'Next scene',
        nextReady: 'Next scene ›',
        toBar: 'To the bar',
        step: 'Finale step',
        reset: 'Start over',
        pauseTitle: 'The scene\'s task freezes: clicks, mugs, hits and dice do not count; flyers and zombies keep flying. A reset lifts it too',
        pauseOn: 'Progress pause: on',
        pauseOff: 'Progress pause: off',
        barGoal: (up) => `Bar goal ${up ? 'up' : 'down'}`,
        glasses: 'glasses',
        nudgeTitle: 'The bar is ready without waiting for the meter',
        nudge: 'Go ahead',
        mugs: 'mugs',
        cursedGoal: (goal, up) => `“${goal === 'drinks' ? 'Mugs' : 'Zombies'}” goal ${up ? 'up' : 'down'}`,
        bossGoal: (up) => `FrankenPHP health ${up ? 'up' : 'down'}`,
        owlDiceTitle: 'The owl\'s third roll is 6-6-6',
        owlDice: 'Owl rolls',
        owlText: 'The owl\'s own text',
        say: 'Say',
        witch: 'Witch',
        runner: 'Runner',
        burstTitle: 'An extra wave of flyers: more players, more flyers',
        burst: 'Launch flyers',
        kDown: 'Smaller multiplier: all limits lower',
        k: 'Limits multiplier',
        kUp: 'Bigger multiplier: all limits higher',
        kInput: 'Limits multiplier, 0.1 to 10',
        clickAll: 'Click everything',
        statsReset: 'Reset player stats',
        statsResetTitle: "Zero every player's clicks, points, mugs, damage and rolls; names and characters stay",
        statsSure: 'Wipe for everyone. Sure?',
        statsDone: (n) => `zeroed: ${n}`,
        sure: 'Sure?',
        notReady: 'Not ready. Sure?',
        circleOpen: 'Circle not closed. Sure?',
        noSix: 'No 6-6-6. Sure?',
        goalsOpen: 'Goals not met. Sure?',
        bossAlive: 'Boss is alive. Sure?',
        launched: (n) => `+${n} ${n === 1 ? 'flyer' : 'flyers'}`,
        round: 'round',
        scene: 'scene',
        stepWord: 'step',
        pub: (target, drunk) => `goal ${target} · drunk ${drunk}`,
        noGoals: 'no goals yet',
        online: (players, connections) => `online: ${players} · connections: ${connections}`,
        offline: 'online: —',
        field: (n) => `on the field: ${n}`,
        noLimitsYet: 'no limits yet',
        dawn: 'dawn',
        rapier: 'rapier',
        cursed: 'cursed world',
        finale: 'finale',
        barName: 'bar',
        ready: 'ready',
        autoOn: 'Autopilot: on',
        autoOff: 'Autopilot: off',
        autoTitle: 'The story runs by itself: it moves on once the room has done its task, the owl has finished talking and a pause has passed; at the dawn, after 5–10 minutes, «To the bar», and round again. The server runs it, the console may be closed; the buttons still work',
        autoBadge: 'autopilot',
        autoDo: {
            'bar.ready': () => 'bar go-ahead',
            'scene.next': (to, final) => (to >= final ? 'finale' : ['bar', 'day', 'sunset', 'night'][to] ?? `scene ${to}`),
            'finale.step': (to) => `finale step ${to}`,
            'dice.owl': () => 'the owl rolls the dice',
            again: () => 'to the bar',
        },
        autoForce: 'without readiness',
        autoIn: (left) => `in ${left}`,
        autoNow: 'now',
        autoPause: 'holding: progress paused',
        autoWait: {
            bar: 'waits for the room: the bar goal',
            pumpkins: 'waits for the room: pumpkins',
            bonfire: 'waits for the room: pumpkins and bonfire',
            circle: 'waits for the room: the circle',
            dice: 'waits for the room: 6-6-6',
            cursed: 'waits for the room: the cursed world goals',
            boss: 'waits for the room: FrankenPHP',
            room: 'waits for the room',
        },
    },
});
const BONUS = TEXT.bonus;
// The current context's limits as the console says them, in this order; only the keys the server sent show.
const LIMIT_TEXTS = [
    ['pumpkin', (n) => TEXT.perStage('pumpkin', n)],
    ['bonfire', (n) => TEXT.perStage('bonfire', n)],
    ['circle', (n) => TEXT.perStage('circle', n)],
    ['bar', TEXT.bar],
    ['drinks', (n) => `${TEXT.drinks} ${n}`],
    ['zombies', (n) => `${TEXT.zombies} ${n}`],
    ['boss', (n) => `${TEXT.bossHp} ${n}`],
];
const ERRORS = TEXT.errors;

/** «тыква 12 кл./стадию · костёр 59 кл./стадию» from `limits` of the model; null — the server has not said. */
export function limitsText(limits) {
    if (!limits) return null;
    const parts = LIMIT_TEXTS.filter(([key]) => Number.isInteger(limits.limits?.[key])).map(([key, text]) => text(limits.limits[key]));
    return parts.length ? parts.join(' · ') : TEXT.noLimits;
}

/** m:ss of the ms left, rounded up so the line never says 0:00 before the move. */
const clock = (ms) => {
    const s = Math.ceil(Math.max(0, ms) / 1000);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
};

// What the room is doing while the autopilot waits for it, by the scene or the finale step of the model.
function roomOf(model) {
    if (isBar(model.scene)) return TEXT.autoWait.bar;
    if (model.scene < model.sceneStages) {
        const bonfire = model.elements?.find((e) => e.type === 'bonfire');
        return bonfire?.stageMax > 0 ? TEXT.autoWait.bonfire : TEXT.autoWait.pumpkins;
    }
    return { 0: TEXT.autoWait.circle, [DICE_STEP]: TEXT.autoWait.dice, [CURSED_STEP]: TEXT.autoWait.cursed, [BOSS_STEP]: TEXT.autoWait.boss }[model.finale?.step ?? 0] ?? TEXT.autoWait.room;
}

/**
 * The console's line of the autopilot (protocol.md, «Автопилот»): «→ ночь · через 0:12», «→ шаг финала 1 · ждёт зал:
 * круг», «→ в бар · стоит: пауза прогресса»; null while it is off or has not said its next move. `now` — server time.
 */
export function autoText(model, now) {
    const next = model?.auto?.on ? model.auto.next : null;
    if (!next) return null;
    const what = [`→ ${TEXT.autoDo[next.action](next.to, model.sceneStages)}`, next.force && TEXT.autoForce].filter(Boolean).join(' ');
    const when = next.wait === 'pause' ? TEXT.autoPause
        : next.at !== undefined ? (next.at > now ? TEXT.autoIn(clock(next.at - now)) : TEXT.autoNow)
            : roomOf(model);
    return `${what} · ${when}`;
}

const roundK = (k) => Math.round(k * 100) / 100;
const clampK = (k) => Math.min(K_MAX, Math.max(K_MIN, roundK(k)));
const formatK = (k) => k.toFixed(2).replace('.', TEXT.decimal);

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
 * @param {() => number} [actions.serverNow]  the server's clock, for the autopilot's countdown
 * @returns {null|{update: (model: object) => void, say: (event: object) => void}}  update repaints the console from the client
 *   model; say adds a `say` event to the chat log
 */
export function initDebugPanel(panel, { call, clickAll, open, serverNow = () => Date.now() }) {
    if (!panel) return null;
    panel.hidden = true;
    panel.classList.add('console');
    panel.replaceChildren();
    let model = null;

    const toggle = el('button', { type: 'button', class: 'console__toggle', 'data-console-toggle': '', 'aria-expanded': 'true' });
    const toggleName = el('span', { class: 'console__name' }, TEXT.console);
    const summary = el('span', { class: 'console__summary', 'data-console-summary': '' });
    // The pause of the progress shows on the folded console too, brighter than the summary.
    const paused = el('span', { class: 'console__paused', 'data-console-paused': '' }, TEXT.paused);
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

    const story = group(TEXT.groups.story);
    const next = button(story, { 'data-action': 'scene-next' }, TEXT.next);
    const step = button(story, { 'data-action': 'finale-step' }, TEXT.step);
    const reset = button(story, { 'data-action': 'reset' }, TEXT.reset);
    // The pause of the progress (protocol.md, «Пауза прогресса»): the scene's task stands still, the flyers fly on.
    const pause = button(story, { 'data-action': 'pause', 'aria-pressed': 'false', title: TEXT.pauseTitle }, TEXT.pauseOff);
    // The autopilot (protocol.md, «Автопилот») lives on the server; here its switch and what it does next.
    const auto = button(story, { 'data-action': 'auto', 'aria-pressed': 'false', title: TEXT.autoTitle }, TEXT.autoOff);
    // The service numbers left the HUD for here: the round, the scene's number, the finale step.
    const storyInfo = el('span', { class: 'console__info', 'data-console-story': '' });
    const autoInfo = el('span', { class: 'console__info console__auto', 'data-console-auto': '', 'aria-live': 'off' });
    autoInfo.hidden = true;
    story.append(storyInfo, autoInfo);
    const paintAuto = () => {
        const text = autoText(model, serverNow());
        autoInfo.hidden = text === null;
        autoInfo.textContent = text ?? '';
    };
    // The countdown ticks between events; a hidden panel has nothing to tick.
    setInterval(() => {
        if (!panel.hidden && model?.auto?.next?.at !== undefined) paintAuto();
    }, 1000);

    // The bar's goal (scenario.md, «Сцена 0 — «Бар»»): mugs the hall drinks before going out; the nudge goes without it.
    const pub = group(TEXT.groups.bar);
    const more = button(pub, { 'data-action': 'bar-more', title: `${TEXT.barGoal(true)}: ${BONUS}` }, `+5 ${TEXT.glasses}`);
    const less = button(pub, { 'data-action': 'bar-less', title: `${TEXT.barGoal(false)}: ${BONUS}` }, `−5 ${TEXT.glasses}`);
    const nudge = button(pub, { 'data-action': 'bar-ready', title: TEXT.nudgeTitle }, TEXT.nudge);
    const pubInfo = el('span', { class: 'console__info', 'data-console-bar': '' });
    pub.append(pubInfo);
    pub.hidden = true;

    // The cursed world's goal (scenario.md, «Шаг 5»): the moderator's addition to each target, ±5.
    const cursedGroup = group(TEXT.groups.cursed);
    for (const [goal, delta, text] of [['drinks', 5, `+5 ${TEXT.mugs}`], ['drinks', -5, `−5 ${TEXT.mugs}`], ['zombies', 5, `+5 ${TEXT.zombies}`], ['zombies', -5, `−5 ${TEXT.zombies}`]]) {
        const title = `${TEXT.cursedGoal(goal, delta > 0)}: ${BONUS}`;
        const b = button(cursedGroup, { 'data-action': `cursed-${goal}-${delta > 0 ? 'up' : 'down'}`, title }, text);
        b.addEventListener('click', () => send(b, 'cursed.target', { goal, delta }, { done: true }));
    }
    const cursedInfo = el('span', { class: 'console__info', 'data-console-cursed': '' });
    cursedGroup.append(cursedInfo);
    cursedGroup.hidden = true;

    // FrankenPHP's health (scenario.md, «Шаг 6 — босс FrankenPHP»), ±10; nothing to change before he wakes up.
    const bossGroup = group(TEXT.groups.boss);
    for (const [delta, text] of [[10, 'HP +10'], [-10, 'HP −10']]) {
        const b = button(bossGroup, { 'data-action': `boss-hp-${delta > 0 ? 'up' : 'down'}`, title: `${TEXT.bossGoal(delta > 0)}: ${BONUS}` }, text);
        b.addEventListener('click', () => send(b, 'boss.hp', { delta }, { done: true }));
    }
    const bossInfo = el('span', { class: 'console__info', 'data-console-boss': '' });
    bossGroup.append(bossInfo);
    bossGroup.hidden = true;

    // The dice of step 1: the moderator's way out — the owl rolls, and its third roll is the 6-6-6.
    const diceGroup = group(TEXT.groups.dice);
    const owlDice = button(diceGroup, { 'data-console-dice': '', 'data-action': 'dice-owl', title: TEXT.owlDiceTitle }, TEXT.owlDice);
    owlDice.addEventListener('click', () => send(owlDice, 'dice.owl', {}, { done: true }));
    diceGroup.hidden = true;

    // The quick menu follows the scene or the finale step (owl.js, MENU), rebuilt when that changes; the title is the
    // text every viewer gets right now. The own text is always there.
    const owl = group(TEXT.groups.owl);
    const owlLines = el('div', { class: 'console__lines', 'data-owl-lines': '' });
    owl.append(owlLines);
    let menuKey = null;
    const form = el('form', { class: 'console__say', 'data-owl-form': '' });
    const input = el('input', { class: 'console__input', 'data-owl-input': '', maxlength: '200', placeholder: TEXT.owlText, 'aria-label': TEXT.owlText, autocomplete: 'off' });
    const say = el('button', { type: 'submit', class: 'debug__button', 'data-action': 'owl-text' }, TEXT.say);
    say.dataset.label = TEXT.say;
    form.append(input, say);
    owl.append(form);

    const night = group(TEXT.groups.night);
    const witch = button(night, { 'data-action': 'witch' }, TEXT.witch);
    const runner = button(night, { 'data-action': 'roadrunner' }, TEXT.runner);
    // A wave of the current set's flyers at once (protocol.md, `flyers.burst`): only where the set has any.
    const burst = button(night, { 'data-action': 'flyers-burst', title: TEXT.burstTitle }, TEXT.burst);

    // The limits shown are the server's, from the model (`limits`): the client does not know the formula's numbers.
    // The players are its n, held for a few seconds — not `online`, which jumps with every walker.
    const limits = group(TEXT.groups.limits);
    const kForm = el('form', { class: 'console__k', 'data-k-form': '', novalidate: '' });
    const kDown = button(kForm, { 'data-action': 'k-down', title: TEXT.kDown }, `÷${formatK(K_STEP)}`);
    const kValue = el('output', { class: 'console__value', 'data-k-value': '', 'aria-label': TEXT.k, title: TEXT.k });
    kForm.append(kValue);
    const kUp = button(kForm, { 'data-action': 'k-up', title: TEXT.kUp }, `×${formatK(K_STEP)}`);
    // Text, not number: a comma is typed on a phone, and an out-of-range value goes to the server, which says no.
    const kInput = el('input', { class: 'console__input console__input--k', 'data-k-input': '', inputmode: 'decimal', 'aria-label': TEXT.kInput, autocomplete: 'off' });
    const kSet = el('button', { type: 'submit', class: 'debug__button', 'data-action': 'k-set' }, 'OK');
    kSet.dataset.label = 'OK';
    kForm.append(kInput, kSet);
    limits.append(kForm);
    const players = el('span', { class: 'console__info', 'data-console-players': '' });
    const online = el('span', { class: 'console__info', 'data-console-online': '' });
    const limitsInfo = el('span', { class: 'console__info', 'data-console-limits': '' });
    limits.append(online, players, limitsInfo);

    // The players' chat (docs/spec/frontend.md, «Чат»): the log, re-read whenever the console opens, and the mutes.
    const chatGroup = group(TEXT.groups.chat);
    const chat = createChatConsole(call);
    chatGroup.append(chat.node);

    const tools = group(TEXT.groups.tools);
    button(tools, { 'data-action': 'click-all' }, TEXT.clickAll).addEventListener('click', () => clickAll());
    // Wipes every player's counters (players.md, «Статистика»): only on the second press.
    const statsReset = button(tools, { 'data-action': 'stats-reset', title: TEXT.statsResetTitle }, TEXT.statsReset);
    statsReset.addEventListener('click', () => {
        if (armed(statsReset, TEXT.statsSure)) send(statsReset, 'stats.reset', {}, { done: (reply) => TEXT.statsDone(reply?.players ?? 0) });
    });

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
            note(b, ERRORS[error?.message] ?? TEXT.failed);
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
        if (!model.ready && !armed(next, TEXT.notReady)) return;
        send(next, 'scene.next', model.ready ? { scene: model.scene } : { scene: model.scene, force: true });
    });
    step.addEventListener('click', () => {
        const current = model?.finale?.step ?? 0;
        if (!model || current >= LAST_STEP) return;
        // Step 1 waits for the closed circle, step 6 for the cursed world's goals: going without them asks first, like
        // an unready scene. Step 7 comes by itself when the boss falls, so pressing it always forces.
        const question = current === 0 && !model.ready ? TEXT.circleOpen
            : current === DICE_STEP && !model.ready ? TEXT.noSix
            : current === CURSED_STEP && !model.ready ? TEXT.goalsOpen
            : current === BOSS_STEP ? TEXT.bossAlive
            : null;
        if (question && !armed(step, question)) return;
        send(step, 'finale.step', question ? { step: current + 1, force: true } : { step: current + 1 });
    });
    reset.addEventListener('click', () => {
        if (model && armed(reset, TEXT.sure)) send(reset, 'reset', { round: model.round });
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
        return TEXT.launched(n);
    };
    burst.addEventListener('click', () => send(burst, 'flyers.burst', {}, { done: launched }));
    pause.addEventListener('click', () => model && send(pause, 'pause', { on: !model.paused }));
    auto.addEventListener('click', () => model && send(auto, 'auto', { on: !model.auto?.on }));
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
            storyInfo.textContent = [`${TEXT.round} ${model.round}`, `${TEXT.scene} ${model.scene}`, final && `${TEXT.stepWord} ${current}`].filter(Boolean).join(' · ');
            paintMenu(model.scene, final ? current : null);
            pub.hidden = !bar;
            pubInfo.textContent = TEXT.pub(model.bar?.target ?? 0, model.bar?.drunk ?? 0);
            nudge.disabled = model.ready;
            setLabel(next, dawn ? TEXT.toBar : model.ready ? TEXT.nextReady : TEXT.next);
            step.hidden = !final || dawn;
            step.toggleAttribute('data-ready', final && (current === 0 || current === DICE_STEP || current === CURSED_STEP) && model.ready);
            diceGroup.hidden = !(final && current === DICE_STEP && model.dice?.on === true);
            setLabel(owlDice, `${TEXT.owlDice} (${Math.min(OWL_ROLLS, model.owlRolls ?? 0)}/${OWL_ROLLS})`);
            setLabel(step, `${TEXT.step} ${current + 1}`);
            cursedGroup.hidden = !(final && current === CURSED_STEP);
            const cursed = model.cursed;
            cursedInfo.textContent = cursed ? `${TEXT.drinks} ${cursed.drunk}/${cursed.drinksTarget} · ${TEXT.zombies} ${cursed.slain}/${cursed.zombiesTarget}` : TEXT.noGoals;
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
            setLabel(pause, model.paused ? TEXT.pauseOn : TEXT.pauseOff);
            paused.hidden = !model.paused;
            // A server without the autopilot sends no `auto`: the switch stays away.
            auto.hidden = !model.auto;
            auto.setAttribute('aria-pressed', String(model.auto?.on === true));
            setLabel(auto, model.auto?.on ? TEXT.autoOn : TEXT.autoOff);
            paintAuto();

            const k = Number(model.k ?? 1);
            kValue.textContent = `×${formatK(k)}`;
            kInput.placeholder = formatK(k);
            online.textContent = model.presence ? TEXT.online(model.presence.players, model.presence.connections) : TEXT.offline;
            players.textContent = TEXT.field(model.limits?.players ?? '—');
            limitsInfo.textContent = limitsText(model.limits) ?? TEXT.noLimitsYet;

            const finaleName = current >= LAST_STEP ? TEXT.dawn : current === BOSS_STEP ? TEXT.rapier : current === CURSED_STEP ? TEXT.cursed : `${TEXT.finale} · ${TEXT.stepWord} ${current}`;
            const parts = [final ? finaleName : isBar(model.scene) ? TEXT.barName : `${TEXT.scene} ${model.scene}`];
            // In the final scene readiness waits for the moderator only at step 0 (the circle), step 1 (the 6-6-6) and
            // step 5 (the goals).
            if (model.ready && !isBar(model.scene) && (!final || current === 0 || current === DICE_STEP || current === CURSED_STEP)) parts.push(TEXT.ready);
            if (k !== 1) parts.push(`×${formatK(k)}`);
            if (model.auto?.on) parts.push(TEXT.autoBadge);
            summary.textContent = parts.join(' · ');
        },
    };
}
