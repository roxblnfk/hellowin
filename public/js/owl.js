// The owl's lines (docs/spec/scenario.md, «Модератор и сова») and the speech bubble over the scene.
// The server sends a line id with params, or the moderator's own text; the words live here.

/**
 * id → { text, scenes?: {[scene]: text} — the line in that scene instead, hoot?: a dramatic line, the owl puffs up
 * first; button?: label on the moderator's console; auto?: the server says it by itself; then?: a second line said
 * right after this one }. `{player}` is the player's name from `params.player.name`.
 * Lines with `{player}` are never on the console: there is no one to name by hand.
 */
export const LINES = {
    intro: { button: 'Начало', hoot: true, text: 'Ух-ху! Печальные вести: RoadRunner мёртв. Но есть древний ритуал на тыквах — он его вернёт. Беритесь за ножи!' },
    rules: { button: 'Правила', text: 'Кликайте по тыквам — каждый клик видят все. Летунов можно сбивать: очки идут в таблицу.' },
    hurry: { button: 'Поторопить', text: 'Тыквы сами себя не вырежут. Ну же, ещё чуть-чуть!', scenes: { 1: 'Свечи сами не зажгутся!', 2: 'Магия сама себя не наколдует!', 3: 'Круг сам себя не нарисует!' } },
    clues: { button: 'Улики', text: 'Странно… Тыквы отзываются слишком быстро — будто кто-то обрабатывает каждый ваш клик.' },
    flyers: { button: 'Про летунов', text: 'Летуны в ритуале не участвуют. Но сбивать их всё равно приятно.', scenes: { 1: 'На закате птицы разлетелись. Все смотрят на тыквы.', 2: 'Летучие мыши! Сбивайте — но не забывайте про тыквы.', 3: 'Червяки… Даже земля здесь ползает. Давите!' } },
    outro: { button: 'Прощание', text: 'Спасибо, что пришли! Дверь не закрывайте: тут теперь живут зомби.' },
    'scene-ready': { auto: true, hoot: true, text: 'Все тыквы готовы. Ждём знака… ух-ху.', scenes: { 1: 'Костёр пылает. Ждём знака… ух-ху.', 2: 'Достаточно… сейчас рванёт!', 3: 'Круг замкнут. Сейчас что-то поднимется…' } },
    'scene-1': { auto: true, hoot: true, text: 'Солнце садится. Поджигайте тыквы — пусть горят до самой ночи!' },
    'scene-2': { auto: true, hoot: true, text: 'Ночь. Открывайте книги — заколдуйте тыквы! Что может пойти не так?' },
    'scene-3': { auto: true, hoot: true, text: 'Кровавая луна! Всё идёт по плану, ух-ху! Рисуйте круг на пепелище — он почти здесь!' },
    'last-pumpkin': { auto: true, text: 'Последнюю тыкву добил {player}! Запомним героя.' },
    'bonfire-open': { auto: true, hoot: true, text: 'Тыквы готовы! А теперь все вместе — разжигайте костёр!' },
    'bonfire-done': { auto: true, text: 'Костёр разжёг {player}! Ух, как пылает.' },
    'circle-done': { auto: true, hoot: true, text: 'Круг замкнул {player}! Земля дрожит…' },
    'witch-down': { auto: true, text: '{player} сбивает ведьму! Метлу можно оставить себе.' },
    'finale-1': { auto: true, hoot: true, text: 'Вот он! Он возвращается!' },
    'finale-2': { auto: true, hoot: true, text: 'Что это? Что мы… ЧТО ВЫ НАДЕЛАЛИ?' },
    'finale-3': { auto: true, hoot: true, text: 'Походу… ошибочка вышла.', then: 'RoadRunner никогда не умирал. Кто, по-вашему, весь вечер обрабатывал ваши клики?' },
    'finale-4': { auto: true, hoot: true, text: 'Вот что прошло через RoadRunner за вечер. Каждый ваш клик — через него, а лишние он отбросил, чтобы тыква не перешла дважды.', then: 'Видите аптайм? Он не падал ни на секунду. Это мы тут чуть не умерли.' },
    'finale-5': { auto: true, hoot: true, text: 'Живите теперь в этом проклятом мире, который создали сами.' },
    zombies: { auto: true, hoot: true, text: 'Из грядки лезут воркеры! Не спят, не едят — только обрабатывают.' },
};

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
    return (line.scenes?.[scene] ?? line.text).replaceAll('{player}', typeof name === 'string' && name.trim() !== '' ? name.trim() : SOMEONE);
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
const OWL_HEIGHT = 18;
const GAP = 4;
const EDGE = 8;

/**
 * Speech bubble over the scene, anchored at the owl on the branch (`scene.perch()`); without a canvas it sits at
 * the top of the screen. A new line replaces the shown one once its hold is over (HOLD_MS); until then the newest
 * waits (one slot, so a burst of lines does not queue up a minute of speech).
 *
 * @param {HTMLElement} root  #app
 * @param {object|null} scene API of pixel/scene.js
 */
export function createOwl(root, scene, { now = () => performance.now(), later = (fn, ms) => setTimeout(fn, ms) } = {}) {
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
        const below = top < EDGE;
        if (below) top = perch.y + 4 * px + 24;
        bubble.classList.toggle('owl-bubble--below', below);
        bubble.style.left = `${Math.round(left)}px`;
        bubble.style.top = `${Math.round(top)}px`;
        bubble.style.setProperty('--tail-x', `${Math.round(Math.min(Math.max(12, perch.x - left), w - 16))}px`);
    }

    function show({ text, hoot, then = null }) {
        const mine = ++token;
        textEl.textContent = text;
        const ms = showMs(text);
        holdUntil = now() + (then ? ms + holdOf(then) : holdOf(text));
        // After the owl has flown away the scene anchors the bubble over FrankenPHP (scene.perch()).
        const delay = Number(scene?.owl?.({ ms, hoot })) || 0;
        const open = () => {
            bubble.hidden = false;
            place();
        };
        bubble.hidden = true;
        if (delay > 0) later(() => mine === token && open(), delay);
        else open();
        later(() => {
            if (mine !== token) return;
            bubble.hidden = true;
            // A two-part line goes on at once, unless another line has taken the bubble meanwhile.
            if (then) show({ text: then, hoot: false });
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
            const line = { text, hoot: Boolean(catalog?.hoot), then: catalog?.then ?? null };
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

        /** Re-anchors the bubble, e.g. after the scene rebuilt for a new size. */
        place,
    };
}
