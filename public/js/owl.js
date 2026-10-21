// The owl's lines (docs/spec/scenario.md, «Модератор и сова») and the speech bubble over the scene.
// The server sends a line id with params, or the moderator's own text; the words live here.

/**
 * id → { text, scenes?: {[scene]: text} — the line in that scene instead, hoot?: a dramatic line, the owl puffs up
 * first; button?: label on the moderator's console; bar?: the button shows only in the bar (scene 0); auto?: the server
 * says it by itself; then?: the next part said right after this one, or a list of parts said one after another;
 * rapier?: the part (0 — the first) on which the owl draws its rapier }. A
 * text without a scene key is the day's: a line
 * said in the bar has its own key 0. `{player}` is the player's name from `params.player.name`, `{attempt}` — `params.attempt`.
 * Lines with `{player}` are never on the console: there is no one to name by hand.
 */
export const LINES = {
    'bar-intro': { auto: true, hoot: true, text: 'Ребята, сегодня Хэллоуин! Ух-ху!', then: 'И RoadRunner можно воскресить! Я знаю одно магическое место неподалёку… За мной!' },
    intro: { button: 'Начало', hoot: true, text: 'Ух-ху! Печальные вести: RoadRunner мёртв. Но есть древний ритуал на тыквах — он его вернёт. Беритесь за ножи!' },
    rules: { button: 'Правила', text: 'Кликайте по тыквам — каждый клик видят все. Летунов можно сбивать: очки идут в таблицу.' },
    hurry: { button: 'Поторопить', text: 'Тыквы сами себя не вырежут. Ну же, ещё чуть-чуть!', scenes: { 0: 'Пиво само себя не выпьет!', 2: 'Свечи сами не зажгутся!', 3: 'Магия сама себя не наколдует!', 4: 'Круг сам себя не нарисует!' } },
    clues: { button: 'Улики', text: 'Странно… Тыквы отзываются слишком быстро — будто кто-то обрабатывает каждый ваш клик.' },
    flyers: { button: 'Про летунов', text: 'Летуны в ритуале не участвуют. Но сбивать их всё равно приятно.', scenes: { 0: 'В баре летунов нет — тут пьют.', 2: 'На закате птицы разлетелись. Все смотрят на тыквы.', 3: 'Летучие мыши! Сбивайте — но не забывайте про тыквы.', 4: 'Червяки… Даже земля здесь ползает. Давите!' } },
    outro: { button: 'Прощание', text: 'Спасибо, что пришли! Дверь не закрывайте: тут теперь живут зомби.' },
    'scene-ready': { auto: true, hoot: true, text: 'Все тыквы готовы. Ждём знака… ух-ху.', scenes: { 2: 'Костёр пылает. Ждём знака… ух-ху.', 3: 'Достаточно… сейчас рванёт!', 4: 'Круг замкнут. Сейчас что-то поднимется…' } },
    'bar-who': { button: 'Кому ещё?', bar: true, text: 'Кому ещё?' },
    'bar-crowd': { button: 'Не толпитесь', bar: true, text: 'Не толпитесь — наливаю!' },
    'bar-clink': { button: 'Сначала чокнись', bar: true, text: 'Сначала чокнись, потом пей!' },
    'bar-empty': { auto: true, text: 'Пусто! Подождите, наливаю.' },
    'scene-1': { auto: true, hoot: true, text: 'Вот оно — магическое место! Ух-ху!', then: 'Но сначала всё подготовим к ритуалу: вырезайте тыквам мордашки и вычищайте их!' },
    'scene-2': { auto: true, hoot: true, text: 'Солнце садится! Берите свечи — поджигайте тыквы!', then: 'Пусть горят до самой ночи.' },
    'scene-3': { auto: true, hoot: true, text: 'А теперь берите магические книги и начинайте колдовать!', then: 'Что может пойти не так?' },
    'scene-4': { auto: true, hoot: true, text: 'Кровавая луна! Всё идёт по плану, ух-ху! Рисуйте круг на пепелище — RoadRunner почти здесь!' },
    'last-pumpkin': { auto: true, text: 'Последнюю тыкву добил {player}! Запомним героя.' },
    'bonfire-open': { auto: true, hoot: true, text: 'Тыквы готовы! А теперь все вместе — разжигайте костёр!' },
    'bonfire-done': { auto: true, text: 'Костёр разжёг {player}! Ух, как пылает.' },
    'circle-done': { auto: true, hoot: true, text: 'Круг замкнул {player}! Земля дрожит…' },
    'witch-down': { auto: true, text: '{player} сбивает ведьму! Метлу можно оставить себе.' },
    'finale-1': { auto: true, hoot: true, text: 'Вот он! Он возвращается!', then: 'Чтобы он восстал, нужна версия, которой никогда не было… Бросайте кости! Нужно шесть-шесть-шесть!' },
    'dice-win': { auto: true, hoot: true, text: '{player} выбросил 6-6-6 с {attempt}-й попытки! PHP 6 всё-таки вышел!' },
    'dice-owl-win': { auto: true, hoot: true, text: 'Шесть-шесть-шесть! С третьей попытки — как и положено.' },
    'finale-2': { auto: true, hoot: true, text: 'Что это? Что мы… ЧТО ВЫ НАДЕЛАЛИ?' },
    'finale-3': { auto: true, hoot: true, text: 'Походу… ошибочка вышла.', then: 'RoadRunner никогда не умирал. Кто, по-вашему, весь вечер обрабатывал ваши клики?' },
    'finale-4': { auto: true, hoot: true, text: 'Вот что прошло через RoadRunner за вечер. Каждый ваш клик — через него, а лишние он отбросил, чтобы ни одна тыква не проскочила стадию.', then: 'Видите аптайм? Он не падал ни на секунду. Это мы тут чуть не умерли.' },
    'finale-5': { auto: true, hoot: true, text: 'Живите теперь в этом проклятом мире, который создали сами.' },
    'cursed-ready': { auto: true, text: 'Готово… ждём.' },
    'finale-6': { auto: true, hoot: true, text: 'Веселитесь?', then: ['Я знаю, как положить этому конец.', 'Молви слово — и получишь оружие.'], rapier: 1 },
    'boss-down': { auto: true, hoot: true, text: '{player} нанёс последний удар!' },
    'finale-7': { auto: true, hoot: true, text: 'Рассвет! Проклятие снято. Рапиры оставьте себе — утки сами себя не собьют.' },
    'bar-again': { auto: true, hoot: true, text: 'Ну что, по пиву?' },
    zombies: { auto: true, hoot: true, text: 'Из грядки лезут воркеры! Не спят, не едят — только обрабатывают.' },
};

/**
 * What the owl says while it pours in the bar (docs/spec/scenario.md, «Сцена 0 — «Бар»»): the server sends a number
 * (`rumor` of `pour`), the line is RUMORS[rumor % length]. Add new ones at the end, so the indexes keep their lines.
 */
export const RUMORS = [
    'Пивка для рывка!',
    'Ходят слухи, что RoadRunner умер…',
    'А вас CVE-2026-109618 / CVE-2026-109619 не задели?',
    'Почём нынче токены?',
    'Слышали? PHP опять хоронят. В который раз уже?',
    'Говорят, nginx уволили. Тут, мол, и без него справляются.',
    'Один воркер шепнул: память течёт. Но это между нами.',
    'Кто-то опять задеплоил в пятницу. Не вы?',
    'Говорят, тыквы в этом году особенно сочные…',
    'Кто-то видел на горизонте странную птицу. Бред, конечно.',
    'FrankenPHP? Не слышала. Наливаю дальше.',
    'Composer опять тянет полинтернета. Налить ещё, пока качается?',
    'Говорят, в очереди задач кто-то живёт. Тихо так шуршит.',
    'Слыхали? Кто-то клик дважды отправил — а засчитали один. Колдовство!',
    'Тут один спрашивал, где у нас nginx. Налила ему за счёт заведения.',
    'Кружек на всех хватит. Наверное. Скорее всего.',
];

/** The rumour of a `pour` event's number (the catalog wraps around). */
export const rumorAt = (n) => RUMORS[(Number.isInteger(n) && n >= 0 ? n : 0) % RUMORS.length];

// A rumour stays up at least this long, longer by its length, never past RUMOR_MAX_MS.
const RUMOR_MIN_MS = 4500;
const RUMOR_MAX_MS = 9000;

const SOMEONE = 'кто-то';

/**
 * Text of an `owl` event: the moderator's text as is, a catalog line with its params (in its `scene` variant if it
 * has one), or null for an unknown id.
 */
export function lineText(event, scene = 0) {
    if (typeof event?.text === 'string' && event.text.trim() !== '') return event.text.trim();
    const line = LINES[event?.line];
    if (!line) return null;
    const name = event.params?.player?.name;
    const attempt = Number.isInteger(event.params?.attempt) ? String(event.params.attempt) : '?';
    return (line.scenes?.[scene] ?? line.text).replaceAll('{player}', typeof name === 'string' && name.trim() !== '' ? name.trim() : SOMEONE).replaceAll('{attempt}', attempt);
}

// Long: the room watches the scene, not the bubble, and reads the line between clicks; never stuck for good.
export const showMs = (text) => Math.min(36000, Math.max(10500, 6000 + text.length * 210));

// A line holds the bubble at least this long (or its whole time, if shorter) before the next one takes over: the
// server says `last-pumpkin` and `scene-ready` back to back, and the first must not flash by unread. A two-part line
// holds it through its first part and the hold of its second, so a burst never cuts off the `then`.
const HOLD_MS = 7500;
const holdOf = (text) => Math.min(showMs(text), HOLD_MS);
// A hold this far ahead means the clock went back (the lab replaying a frozen run): the bubble counts as free.
const MAX_HOLD_MS = 36000 + HOLD_MS;
// The dice's aside lasts about as long as the throw it comes with.
const ASIDE_MS = 2600;
const OWL_HEIGHT = 18;
const AVOID = '.dice:not([hidden]), .word:not([hidden])';
const GAP = 4;
const EDGE = 8;

/**
 * Speech bubble over the scene, anchored at the owl on the branch (`scene.perch()`); without a canvas it sits at
 * the top of the screen. A new line replaces the shown one once its hold is over (HOLD_MS); until then the newest
 * waits (one slot, so a burst of lines does not queue up a minute of speech).
 *
 * @param {HTMLElement} root  #app
 * @param {object|null} scene API of pixel/scene.js
 * @param {object} [options]
 * @param {(line: {text: string, hoot: boolean, x: number|null}) => void} [options.onShow]  the bubble has just opened with
 *   a line (the sound voices it); x — the speaker in percent of the width, null without a canvas
 */
export function createOwl(root, scene, { now = () => performance.now(), later = (fn, ms) => setTimeout(fn, ms), onShow = () => {} } = {}) {
    const bubble = document.createElement('div');
    bubble.className = 'owl-bubble';
    bubble.dataset.owlBubble = '';
    bubble.setAttribute('role', 'status');
    bubble.setAttribute('aria-live', 'polite');
    bubble.hidden = true;
    const textEl = document.createElement('p');
    textEl.className = 'owl-bubble__text';
    textEl.dataset.owlText = '';
    bubble.append(textEl);
    root.append(bubble);

    let holdUntil = -1e9;
    // Until when the line on show is up (scene clock of `now`): a rumour waits for nothing and takes a free bubble only.
    let shown = { until: -1e9 };
    let pending = null;
    let waiting = false;
    let token = 0;

    function place() {
        if (bubble.hidden) return;
        const perch = scene?.perch?.();
        const width = root.clientWidth;
        const w = bubble.offsetWidth;
        const h = bubble.offsetHeight;
        if (!perch) {
            bubble.style.left = `${Math.max(EDGE, (width - w) / 2)}px`;
            bubble.style.top = `${EDGE + 56}px`;
            bubble.style.setProperty('--tail-x', `${w / 2}px`);
            bubble.classList.add('owl-bubble--free');
            return;
        }
        bubble.classList.remove('owl-bubble--free');
        const px = perch.px ?? 2;
        const head = perch.y - OWL_HEIGHT * px;
        // Up and to the right of the owl, the tail reaching down to its head; below it when the sky has no room.
        const left = Math.min(Math.max(EDGE, perch.x - 24), Math.max(EDGE, width - w - EDGE));
        let top = head - GAP * px - h - 24;
        // The panels at the top in the middle (the dice, the word form) keep the bubble out: it goes under the owl.
        const covers = [...root.querySelectorAll(AVOID)].some((panel) => {
            const r = panel.getBoundingClientRect();
            const box = root.getBoundingClientRect();
            return left < r.right - box.left && left + w > r.left - box.left && top < r.bottom - box.top && top + h > r.top - box.top;
        });
        const below = top < EDGE || covers;
        if (below) top = perch.y + 4 * px + 24;
        bubble.classList.toggle('owl-bubble--below', below);
        bubble.style.left = `${Math.round(left)}px`;
        bubble.style.top = `${Math.round(top)}px`;
        bubble.style.setProperty('--tail-x', `${Math.round(Math.min(Math.max(12, perch.x - left), w - 16))}px`);
    }

    // `ms` and `hold` override the line's own times: a rumour is short and holds nothing up. `then` — the parts still to
    // come; `line` and `part` tell the scene which catalog line is on, `rapier` — the part the owl draws its rapier on.
    function show({ text, hoot, then = [], line = null, part = 0, ms = showMs(text), hold = then.length ? ms + holdOf(then[0]) : holdOf(text) }) {
        const mine = ++token;
        textEl.textContent = text;
        holdUntil = now() + hold;
        shown = { until: now() + ms };
        // After the owl has flown away the scene anchors the bubble over FrankenPHP (scene.perch()).
        const delay = Number(scene?.owl?.({ ms, hoot, line, part, rapier: line !== null && LINES[line]?.rapier === part })) || 0;
        const open = () => {
            bubble.hidden = false;
            place();
            const perch = scene?.perch?.();
            const width = root.clientWidth;
            onShow({ text, hoot, x: perch && width ? (perch.x / width) * 100 : null });
        };
        bubble.hidden = true;
        if (delay > 0) later(() => mine === token && open(), delay);
        else open();
        later(() => {
            if (mine !== token) return;
            bubble.hidden = true;
            // A line in parts goes on at once, unless another line has taken the bubble meanwhile.
            if (then.length) show({ text: then[0], hoot: false, then: then.slice(1), line, part: part + 1 });
        }, delay + ms);
    }

    function flush() {
        waiting = false;
        if (!pending) return;
        const next = pending;
        pending = null;
        show(next);
    }

    // After the scene has rebuilt for the new size, so the perch is current.
    new ResizeObserver(() => requestAnimationFrame(place)).observe(root);

    return {
        /** Channel event `owl` said in `scene`; unknown catalog ids are ignored. */
        say(event, scene = 0) {
            const text = lineText(event, scene);
            if (!text) return;
            const catalog = event.text ? null : LINES[event.line];
            const then = catalog?.then ?? [];
            const line = { text, hoot: Boolean(catalog?.hoot), then: Array.isArray(then) ? then : [then], line: catalog ? event.line : null };
            const left = holdUntil - now();
            // The moderator's own text is the host speaking live: it never waits behind a hold.
            if (event.text || left <= 0 || left > MAX_HOLD_MS) {
                pending = null;
                show(line);
                return;
            }
            pending = line;
            if (!waiting) {
                waiting = true;
                later(flush, left);
            }
        },

        /**
         * A rumour of the pour (`rumor` of `pour`), for at most `left` ms (the rest of the pour, longer if short):
         * said only while the bubble is free — it never cuts off a line or jumps a waiting one — and holds nothing up.
         */
        rumor(n, left = RUMOR_MAX_MS) {
            const text = rumorAt(n);
            const t = now();
            if (!text || pending || waiting || t < shown.until || t < holdUntil) return;
            const ms = Math.min(RUMOR_MAX_MS, Math.max(RUMOR_MIN_MS, left, 2500 + text.length * 60));
            show({ text, hoot: false, ms, hold: 0 });
        },

        /**
         * A short local line of the owl's own (the dice's «Дайте я попробую!», said on the `roll` event, not by the
         * server): only while the bubble is free — a line still within its hold or one waiting keeps the bubble — and it
         * holds nothing up. Returns whether it was said.
         */
        aside(text) {
            const t = now();
            if (!text || pending || waiting || t < holdUntil) return false;
            show({ text, hoot: false, ms: ASIDE_MS, hold: 0 });
            return true;
        },

        /** Re-anchors the bubble, e.g. after the scene rebuilt for a new size. */
        place,
    };
}
