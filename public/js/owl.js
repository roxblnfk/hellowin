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
    hurry: { button: 'Поторопить', text: 'Тыквы сами себя не вырежут. Ну же, ещё чуть-чуть!' },
    clues: { button: 'Улики', text: 'Странно… Тыквы отзываются слишком быстро — будто кто-то обрабатывает каждый ваш клик.' },
    flyers: { button: 'Про летунов', text: 'Летуны в ритуале не участвуют. Но сбивать их всё равно приятно.' },
    outro: { button: 'Прощание', text: 'Спасибо, что пришли! Дверь не закрывайте: тут теперь живут зомби.' },
    'scene-ready': { auto: true, hoot: true, text: 'Все тыквы готовы. Ждём знака… ух-ху.', scenes: { 1: 'Костёр пылает. Ждём знака… ух-ху.', 2: 'Достаточно… сейчас рванёт!', 3: 'Круг замкнут. Сейчас что-то поднимется…' } },
    'scene-1': { auto: true, hoot: true, text: 'Солнце садится. Поджигайте тыквы — пусть горят до самой ночи!' },
    'scene-2': { auto: true, hoot: true, text: 'Ночь. Вот теперь начинается настоящее колдовство… Что может пойти не так?' },
    'scene-3': { auto: true, hoot: true, text: 'Ой. Луна… она что, покраснела? Кажется, ритуал пошёл не совсем по плану.' },
    'last-pumpkin': { auto: true, text: 'Последнюю тыкву добил {player}! Запомним героя.' },
    'bonfire-open': { auto: true, hoot: true, text: 'Тыквы готовы! А теперь все вместе — разжигайте костёр!' },
    'bonfire-done': { auto: true, text: 'Костёр разжёг {player}! Ух, как пылает.' },
    'circle-done': { auto: true, hoot: true, text: 'Круг замкнул {player}! Земля дрожит…' },
    'witch-down': { auto: true, text: '{player} сбивает ведьму! Метлу можно оставить себе.' },
    'finale-1': { auto: true, hoot: true, text: 'Вот он! Он возвращается!' },
    'finale-2': { auto: true, hoot: true, text: 'Что это? Что мы… ЧТО ВЫ НАДЕЛАЛИ?' },
    'finale-3': { auto: true, hoot: true, text: 'Походу… ошибочка вышла.', then: 'Он никогда не умирал. Кто, по-вашему, весь вечер обрабатывал ваши клики?' },
    'finale-4': { auto: true, hoot: true, text: 'Вот числа вечера. Каждый клик прошёл через него, а лишние он отбросил — чтобы тыква не перешла дважды.' },
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

// Long enough to read twice at a calm pace, never stuck for good.
export const showMs = (text) => Math.min(12000, Math.max(3500, 2000 + text.length * 70));

// A line has the bubble at least this long before the next one takes over: the server says `last-pumpkin` and
// `scene-ready` back to back, and the first must not flash by unread.
const MIN_MS = 2500;
const OWL_HEIGHT = 18;
const GAP = 4;
const EDGE = 8;

/**
 * Speech bubble over the scene, anchored at the owl on the branch (`scene.perch()`); without a canvas it sits at
 * the top of the screen. A new line replaces the shown one once that has been up for MIN_MS; until then the newest
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

    let shownAt = -1e9;
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
        shownAt = now();
        textEl.textContent = text;
        const ms = showMs(text);
        // An owl that has flown away comes back to the branch first: the bubble waits for it.
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
            const left = MIN_MS - (now() - shownAt);
            // A clock that went back (the lab replaying a frozen run) counts as a free bubble too.
            if (bubble.hidden || left <= 0 || left > MIN_MS) {
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
