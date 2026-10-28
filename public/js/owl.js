// The owl's lines (docs/spec/scenario.md, «Модератор и сова») and the speech bubble over the scene.
// The server sends a line id with params, or the moderator's own text; the words live here.

import { pick } from './i18n.js';
import { speechPlan } from './owl-speech.js';

/**
 * id → { text, scenes?: {[scene]: text} — the line in that scene instead, steps?: {[step]: text} — the line at that
 * finale step instead (in the final scene, before `scenes`), hoot?: a dramatic line, the owl puffs up first; button?:
 * label on the moderator's console (where it shows — MENU); auto?: the server says it by itself; then?: the next part
 * said right after this one, or a list of parts said one after another; rapier?: the part (0 — the first) on which the
 * owl draws its rapier }. A text without a scene key is the day's: a line said in the bar has its own key 0. `{player}`
 * is the player's name from `params.player.name`, `{attempt}` — `params.attempt`.
 * Lines with `{player}` are never on the console: there is no one to name by hand.
 */
const RU_LINES = {
    'bar-intro': { auto: true, hoot: true, text: 'Ребята, сегодня Хэллоуин! Ух-ху!', then: 'И RoadRunner можно воскресить! Я знаю одно магическое место неподалёку… За мной!' },
    // Off the console since `bar-intro` opens the evening; kept for a server or a stale console that still sends it.
    intro: { hoot: true, text: 'Ух-ху! Печальные вести: RoadRunner мёртв. Но есть древний ритуал на тыквах — он его вернёт. Беритесь за ножи!' },
    rules: { button: 'Правила', text: 'Кликайте по тыквам — каждый клик видят все. Летунов можно сбивать: очки идут в таблицу.', scenes: { 0: 'Кружку берите у стойки. Сначала чокнитесь с соседом — потом глоток. Пьём, пока шкала не полна!' } },
    hurry: {
        button: 'Поторопить',
        text: 'Тыквы сами себя не вырежут. Ну же, ещё чуть-чуть!',
        scenes: { 0: 'Пиво само себя не выпьет!', 2: 'Свечи сами не зажгутся!', 3: 'Магия сама себя не наколдует!', 4: 'Круг сам себя не нарисует!' },
        steps: {
            0: 'Круг сам себя не нарисует!',
            1: 'Бросайте кости! Нужно шесть-шесть-шесть!',
            5: 'Кружки сами себя не выпьют, а зомби сами не упокоятся! Шевелитесь!',
            6: 'Молвите слово, берите рапиру — и бейте FrankenPHP!',
            7: 'Утки сами себя не собьют! Рапиры наголо!',
        },
    },
    clues: { button: 'Улики', text: 'Странно… Тыквы отзываются слишком быстро — будто кто-то обрабатывает каждый ваш клик.' },
    flyers: { button: 'Про летунов', text: 'Летуны в ритуале не участвуют. Но сбивать их всё равно приятно.', scenes: { 0: 'В баре летунов нет — тут пьют.', 2: 'На закате птицы разлетелись. Все смотрят на тыквы.', 3: 'Летучие мыши! Сбивайте — но не забывайте про тыквы.', 4: 'Червяки… Даже земля здесь ползает. Давите!' } },
    outro: { button: 'Прощание', text: 'Спасибо, что пришли! Дверь не закрывайте: тут теперь живут зомби.' },
    'scene-ready': { auto: true, hoot: true, text: 'Все тыквы готовы. Ждём знака… ух-ху.', scenes: { 2: 'Костёр пылает. Ждём знака… ух-ху.', 3: 'Достаточно… сейчас рванёт!', 4: 'Круг замкнут! Ещё немного — и он придёт… Ждём знака!' } },
    'bar-who': { button: 'Кому ещё?', text: 'Кому ещё?' },
    'bar-crowd': { button: 'Не толпитесь', text: 'Не толпитесь — наливаю!' },
    'bar-clink': { button: 'Сначала чокнись', text: 'Сначала чокнись, потом пей!' },
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
    // The first shot at the elephant: the name of the PHP Fart Time channel, the same in both languages.
    'fart-time': { auto: true, text: 'Fart Time!' },
    'finale-1': { auto: true, hoot: true, text: 'Вот он! Он возвращается!', then: 'Чтобы он восстал, нужна версия, которой никогда не было… Бросайте кости! Нужно шесть-шесть-шесть!' },
    'dice-win': { auto: true, hoot: true, text: '{player} выбросил 6-6-6 с {attempt}-й попытки! PHP 6 всё-таки вышел!' },
    'dice-owl-win': { auto: true, hoot: true, text: 'Шесть-шесть-шесть! С третьей попытки — как и положено.' },
    'finale-2': { auto: true, hoot: true, text: 'Что это?.. Это разве RoadRunner?!', then: 'Что мы… ЧТО ВЫ НАДЕЛАЛИ?' },
    'finale-3': { auto: true, hoot: true, text: 'Походу… ошибочка вышла.', then: 'RoadRunner никогда не умирал. Кто, по-вашему, весь вечер обрабатывал ваши клики?' },
    'finale-4': { auto: true, hoot: true, text: 'Вот что прошло через RoadRunner за вечер. Каждый ваш клик — через него, а лишние он отбросил, чтобы ни одна тыква не проскочила стадию.', then: 'Видите аптайм? Он не падал ни на секунду. Это мы тут чуть не умерли.' },
    'finale-5': { auto: true, hoot: true, text: 'Живите теперь в этом проклятом мире, который создали сами.' },
    'cursed-ready': { auto: true, text: 'Готово… ждём.' },
    'finale-6': { auto: true, hoot: true, text: 'Веселитесь?', then: ['Я знаю, как положить этому конец.', 'Молви слово — и получишь оружие.'], rapier: 1 },
    // Nobody has the word HELLOWIN_WORD_HINT_MS after the field opened: the owl holds the rapier up by then.
    'word-hint': { auto: true, text: 'Это оружие у меня в руке. Как оно называется?' },
    'boss-down': { auto: true, hoot: true, text: '{player} нанёс последний удар!' },
    'finale-7': { auto: true, hoot: true, text: 'Рассвет! Проклятие снято. Рапиры оставьте себе — утки сами себя не собьют.' },
    'bar-again': { auto: true, hoot: true, text: 'Ну что, по пиву?' },
    zombies: { auto: true, hoot: true, text: 'Из грядки лезут воркеры! Не спят, не едят — только обрабатывают.' },
};

// English words of the same ids, the flags stay in RU_LINES: every text key there (text, then and its parts, scenes,
// steps, button) has its English here, same scene and step keys, same number of parts.
const EN_LINES = {
    'bar-intro': { text: "Folks, it's Halloween! Hoo-hoo!", then: 'And tonight RoadRunner can be brought back! I know a magic place nearby… Follow me!' },
    intro: { text: 'Hoo-hoo! Sad news: RoadRunner is dead. But an ancient pumpkin ritual can bring him back. Grab your knives!' },
    rules: { button: 'Rules', text: 'Click the pumpkins — everyone sees every click. Shoot down flyers: points go to the board.', scenes: { 0: 'Grab a mug at the bar. Clink with a neighbour first — then sip. Drink till the bar fills up!' } },
    hurry: {
        button: 'Hurry up',
        text: "Pumpkins won't carve themselves. Come on, almost there!",
        scenes: { 0: "This beer won't drink itself!", 2: "Candles won't light themselves!", 3: "Spells won't cast themselves!", 4: "The circle won't draw itself!" },
        steps: {
            0: "The circle won't draw itself!",
            1: 'Roll the dice! We need six-six-six!',
            5: "Mugs won't drain themselves, zombies won't lay themselves to rest! Move it!",
            6: 'Speak the word, take a rapier — and go get FrankenPHP!',
            7: "Ducks won't skewer themselves! Rapiers out!",
        },
    },
    clues: { button: 'Clues', text: 'Odd… The pumpkins respond too fast — as if someone is handling every single click.' },
    flyers: { button: 'About flyers', text: "Flyers aren't part of the ritual. Shooting them down is still fun.", scenes: { 0: 'No flyers in the bar — we drink here.', 2: 'At sunset the birds flew off. All eyes on the pumpkins.', 3: "Bats! Shoot them — but don't forget the pumpkins.", 4: 'Worms… Even the ground is crawling here. Squash them!' } },
    outro: { button: 'Goodbye', text: 'Thanks for coming! Leave the door open: zombies live here now.' },
    'scene-ready': { text: 'All pumpkins are ready. Awaiting a sign… hoo-hoo.', scenes: { 2: 'The bonfire is roaring. Awaiting a sign… hoo-hoo.', 3: "That's enough… it's gonna blow!", 4: "The circle is closed! Soon he'll come… Wait for the sign!" } },
    'bar-who': { button: "Who's next?", text: "Who's next?" },
    'bar-crowd': { button: "Don't crowd", text: "Don't crowd the bar — I'm pouring!" },
    'bar-clink': { button: 'Clink first', text: 'Clink first, then drink!' },
    'bar-empty': { text: "Empty! Hang on, I'm pouring." },
    'scene-1': { text: 'Here it is — the magic place! Hoo-hoo!', then: 'But first, prep for the ritual: carve faces into the pumpkins and scoop them out!' },
    'scene-2': { text: 'The sun is setting! Grab candles — light the pumpkins!', then: 'Let them burn all night long.' },
    'scene-3': { text: 'Now grab your spellbooks and start casting!', then: 'What could possibly go wrong?' },
    'scene-4': { text: 'Blood moon! All going to plan, hoo-hoo! Draw the circle on the ashes — RoadRunner is almost here!' },
    'last-pumpkin': { text: "{player} finished off the last pumpkin! We'll remember this hero." },
    'bonfire-open': { text: 'The pumpkins are ready! Now all together — light the bonfire!' },
    'bonfire-done': { text: '{player} lit the bonfire! Whoa, look at it blaze.' },
    'circle-done': { text: '{player} closed the circle! The ground trembles…' },
    'witch-down': { text: "{player} shoots down the witch! The broom's yours to keep." },
    'fart-time': { text: 'Fart Time!' },
    'finale-1': { text: "There he is! He's coming back!", then: 'To rise, he needs a version that never existed… Roll the dice! We need six-six-six!' },
    'dice-win': { text: '{player} rolled 6-6-6 on try #{attempt}! PHP 6 finally shipped!' },
    'dice-owl-win': { text: "Six-six-six! Third time's the charm — as it should be." },
    'finale-2': { text: 'What is that…? Is that RoadRunner?!', then: 'What have we… WHAT HAVE YOU DONE?' },
    'finale-3': { text: 'Well… looks like we shipped a bug.', then: 'RoadRunner never died. Who do you think handled your clicks all night?' },
    'finale-4': { text: "Here's everything RoadRunner handled tonight. Every click passed through him, and he dropped the extras so no pumpkin skipped a stage.", then: "See the uptime? Not a second down. We're the ones who nearly died here." },
    'finale-5': { text: 'Now live in this cursed world you made yourselves.' },
    'cursed-ready': { text: 'All set… now we wait.' },
    'finale-6': { text: 'Having fun?', then: ['I know how to put an end to this.', 'Speak the word, and a weapon is yours.'] },
    'word-hint': { text: "It's the weapon in my hand. What's it called?" },
    'boss-down': { text: '{player} landed the final blow!' },
    'finale-7': { text: "Dawn! The curse is lifted. Keep the rapiers — ducks won't skewer themselves." },
    'bar-again': { text: 'So… another round?' },
    zombies: { text: "Workers crawling out of the pumpkin patch! They don't sleep, don't eat — they just process." },
};

/** The catalog in each language (the autopilot's speech table takes the longer of the two). */
export const LINES_BY_LANG = { ru: RU_LINES, en: Object.fromEntries(Object.entries(RU_LINES).map(([id, line]) => [id, { ...line, ...EN_LINES[id] }])) };

export const LINES = pick(LINES_BY_LANG);

/**
 * The owl's quick menu on the console (docs/spec/scenario.md, «Модератор и сова»): catalog ids by scene before the
 * finale (0 — the bar … 3 — the night) and by finale step; a step not listed (2–4, the film) has none. The moderator's
 * own text is always there; lines the server says by itself are never in the menu.
 */
export const MENU = {
    scenes: { 0: ['bar-who', 'bar-crowd', 'bar-clink', 'rules'], 1: ['rules', 'hurry', 'flyers'], 2: ['hurry', 'clues'], 3: ['hurry', 'flyers'] },
    steps: { 0: ['hurry'], 1: ['hurry'], 5: ['hurry'], 6: ['hurry'], 7: ['hurry', 'outro'] },
};

/** The menu's ids for the scene, or for the finale step in the final scene (`step` — null outside it). */
export const menuOf = (scene, step = null) => (step === null ? MENU.scenes[scene] : MENU.steps[step]) ?? [];

/**
 * What the owl says while it pours in the bar (docs/spec/scenario.md, «Сцена 0 — «Бар»»): the server sends a number
 * (`rumor` of `pour`), the line is RUMORS[rumor % length]. Add new ones at the end, so the indexes keep their lines;
 * item N of `en` adapts item N of `ru`, so viewers in either language hear the same rumour.
 */
export const RUMORS = pick({ ru: [
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
], en: [
    'A pint for the sprint!',
    'Rumour has it RoadRunner is dead…',
    'Did CVE-2026-109618 / CVE-2026-109619 get you too?',
    'How much are tokens these days?',
    "PHP's being buried again. Hundredth time, isn't it?",
    'Word is nginx got laid off. Turns out we manage fine without it.',
    "A worker whispered: memory's leaking. But that's between us.",
    'Someone deployed on a Friday again. Not you, was it?',
    'They say the pumpkins are extra juicy this year…',
    'Someone saw a strange bird on the horizon. Nonsense, of course.',
    'FrankenPHP? Never heard of it. Next pint coming up.',
    "Composer's pulling half the internet. Refill while it downloads?",
    'They say something lives in the job queue. Rustles quietly.',
    'Heard? Someone double-clicked — only one counted. Witchcraft!',
    'Some guy asked where our nginx is. Gave him one on the house.',
    'Enough mugs for everyone. Probably. Most likely.',
] });

/** The rumour of a `pour` event's number (the catalog wraps around). */
export const rumorAt = (n) => RUMORS[(Number.isInteger(n) && n >= 0 ? n : 0) % RUMORS.length];

// A rumour stays up at least this long, longer by its length, never past RUMOR_MAX_MS.
const RUMOR_MIN_MS = 4500;
const RUMOR_MAX_MS = 9000;

const SOMEONE = pick({ ru: 'кто-то', en: 'someone' });

/**
 * Text of an `owl` event: the moderator's text as is, a catalog line with its params (in its finale `step` variant, else
 * its `scene` variant, if it has one), or null for an unknown id. `step` — the finale step, null outside the final
 * scene: every viewer has applied the same events before this one, so all pick the same text.
 */
export function lineText(event, scene = 0, step = null) {
    if (typeof event?.text === 'string' && event.text.trim() !== '') return event.text.trim();
    const line = LINES[event?.line];
    if (!line) return null;
    const name = event.params?.player?.name;
    const attempt = Number.isInteger(event.params?.attempt) ? String(event.params.attempt) : '?';
    return ((step === null ? undefined : line.steps?.[step]) ?? line.scenes?.[scene] ?? line.text).replaceAll('{player}', typeof name === 'string' && name.trim() !== '' ? name.trim() : SOMEONE).replaceAll('{attempt}', attempt);
}

// Long: the room watches the scene, not the bubble, and reads the line between clicks; never stuck for good. The
// server's autopilot counts by the same numbers (public/autopilot.json, `showMs`): change both together.
export const SHOW = { base: 4500, perChar: 158, min: 7900, max: 27000 };
export const showMs = (text) => Math.min(SHOW.max, Math.max(SHOW.min, SHOW.base + text.length * SHOW.perChar));

// A line holds the bubble at least this long (or its whole time, if shorter) before the next one takes over: the
// server says `last-pumpkin` and `scene-ready` back to back, and the first must not flash by unread. A two-part line
// holds it through its first part and the hold of its second, so a burst never cuts off the `then`.
export const HOLD_MS = 5600;
const holdOf = (text) => Math.min(showMs(text), HOLD_MS);
// A hold this far ahead means the clock went back (the lab replaying a frozen run): the bubble counts as free.
const MAX_HOLD_MS = SHOW.max + HOLD_MS;
// The countdown before finale step 1 (docs/spec/frontend.md, «Призыв»): «Призыв через 3…», then «2…», «1…».
const SUMMON = pick({ ru: 'Призыв через', en: 'Summoning in' });
export const summonText = (left, first) => (first ? `${SUMMON} ${left}…` : `${left}…`);
// The dice's aside lasts about as long as the throw it comes with.
const ASIDE_MS = 2600;
const OWL_HEIGHT = 18;
// The open reveal panel is kept clear too, and when it leaves the bubble no room, the line moves into its inset.
const PANEL = '.reveal:not([hidden])';
const AVOID = `.dice:not([hidden]), .word:not([hidden]), ${PANEL}`;
const GAP = 4;
const EDGE = 8;
// Clearance around a panel: its frame is a box-shadow up to 8 px wide (14 px down), the bubble's is 6 px.
const MARGIN = 14;
// The tail reaches the owl only this far in from the bubble's edges, so its two squares stay under the box.
const TAIL_LEFT = 12;
const TAIL_RIGHT = 16;
const TAIL_H = 22;
// A bubble narrowed to fit beside a panel is never narrower than this: a column of three words is not a speech.
const MIN_WIDTH = 200;

/**
 * Where the owl's bubble goes: up and to the right of the owl, else under it, else slid sideways — wherever the box with
 * its tail stays on screen, clear of every panel in `avoid`, and the tail still reaches the owl. Null when there is no
 * such place, or a panel covers the owl itself (a tail into a panel would point at nothing).
 *
 * @param {{x: number, y: number, px?: number}} perch  the owl's talons on screen (scene.perch()), px — the art's pixel
 * @param {{w: number, h: number}} size  the bubble without its tail
 * @param {{width: number, height: number}} view  the scene's box
 * @param {{left: number, top: number, right: number, bottom: number}[]} avoid  panels, in the scene's coordinates
 * @returns {{left: number, top: number, below: boolean}|null}
 */
export function bubbleSpot(perch, { w, h }, { width, height }, avoid = []) {
    const px = perch.px ?? 2;
    const head = perch.y - OWL_HEIGHT * px;
    const middle = (head + perch.y) / 2;
    if (avoid.some((r) => perch.x > r.left && perch.x < r.right && middle > r.top && middle < r.bottom)) return null;
    const clampLeft = (left) => Math.min(Math.max(EDGE, left), Math.max(EDGE, width - w - EDGE));
    const lefts = [perch.x - 24, perch.x - TAIL_LEFT, perch.x - w + TAIL_RIGHT];
    for (const r of avoid) lefts.push(r.left - MARGIN - w, r.right + MARGIN);
    const tops = [[head - GAP * px - h - 24, false], [perch.y + 4 * px + 24, true]];
    const fits = (left, top, below) => {
        const tail = perch.x - left;
        if (tail < TAIL_LEFT || tail > w - TAIL_RIGHT || left < EDGE || left + w > width - EDGE) return false;
        if (top < EDGE || top + h > height - EDGE) return false;
        const y0 = below ? top - TAIL_H : top;
        const y1 = below ? top + h : top + h + TAIL_H;
        return !avoid.some((r) => left < r.right + MARGIN && left + w > r.left - MARGIN && y0 < r.bottom + MARGIN && y1 > r.top - MARGIN);
    };
    // The usual place above, then under the owl, before any slide sideways.
    for (const left of lefts.map((l) => Math.round(clampLeft(l)))) {
        for (const [top, below] of tops) {
            if (fits(left, top, below)) return { left, top: Math.round(top), below };
        }
    }
    return null;
}

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
 * @param {() => void} [options.onHide]  the bubble has closed with nothing after it (the sound cuts the speech off)
 */
export function createOwl(root, scene, { now = () => performance.now(), later = (fn, ms) => setTimeout(fn, ms), onShow = () => {}, onHide = () => {} } = {}) {
    const bubble = document.createElement('div');
    bubble.className = 'owl-bubble';
    bubble.dataset.owlBubble = '';
    bubble.setAttribute('role', 'status');
    bubble.setAttribute('aria-live', 'polite');
    bubble.hidden = true;
    // A line is on (its timers run) whether the bubble or the reveal panel's inset shows it.
    let speaking = false;
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
    // The countdown on (its number), 0 — none.
    let counting = 0;
    let countdowns = 0;

    function rectsOf(selector) {
        const box = root.getBoundingClientRect();
        return [...root.querySelectorAll(selector)].map((el) => {
            const r = el.getBoundingClientRect();
            return { left: r.left - box.left, top: r.top - box.top, right: r.right - box.left, bottom: r.bottom - box.top };
        });
    }

    // The bubble at the owl, or — when the open reveal panel leaves it no room (a phone, or the owl under the panel) —
    // the panel's inset `[data-reveal-owl]` with the same text; the line's timers do not care which.
    function place() {
        const inset = root.querySelector?.('[data-reveal-owl]') ?? null;
        const into = (where) => {
            bubble.hidden = where !== 'bubble';
            if (!inset) return;
            if (where === 'inset') inset.querySelector('[data-reveal-owl-text]').textContent = textEl.textContent;
            inset.hidden = where !== 'inset';
        };
        if (!speaking) return into(null);
        into('bubble');
        const perch = scene?.perch?.();
        const width = root.clientWidth;
        if (!perch) {
            const w = bubble.offsetWidth;
            bubble.style.left = `${Math.max(EDGE, (width - w) / 2)}px`;
            bubble.style.top = `${EDGE + 56}px`;
            bubble.style.setProperty('--tail-x', `${w / 2}px`);
            bubble.classList.add('owl-bubble--free');
            return;
        }
        bubble.classList.remove('owl-bubble--free');
        const view = { width, height: root.clientHeight };
        const avoid = rectsOf(AVOID);
        bubble.style.maxWidth = '';
        let spot = bubbleSpot(perch, { w: bubble.offsetWidth, h: bubble.offsetHeight }, view, avoid);
        // Beside a panel there may be room for a narrower bubble: each side's width is tried once, the widest first.
        const rooms = avoid.flatMap((r) => [r.left - MARGIN - EDGE, width - EDGE - r.right - MARGIN]);
        for (const room of rooms.filter((n) => n >= MIN_WIDTH && n < bubble.offsetWidth).sort((a, b) => b - a)) {
            if (spot) break;
            bubble.style.maxWidth = `${Math.floor(room)}px`;
            spot = bubbleSpot(perch, { w: bubble.offsetWidth, h: bubble.offsetHeight }, view, avoid);
        }
        if (!spot && inset && root.querySelector(PANEL)) return into('inset');
        if (!spot) {
            bubble.style.maxWidth = '';
            spot = fallbackSpot(perch);
        }
        bubble.classList.toggle('owl-bubble--below', spot.below);
        bubble.style.left = `${spot.left}px`;
        bubble.style.top = `${spot.top}px`;
        bubble.style.setProperty('--tail-x', `${Math.round(Math.min(Math.max(TAIL_LEFT, perch.x - spot.left), bubble.offsetWidth - TAIL_RIGHT))}px`);
    }

    // No clear place and no panel to move into (the dice or the word form over a short screen): up and to the right
    // of the owl, under it when the sky has no room or a panel covers that.
    function fallbackSpot(perch) {
        const px = perch.px ?? 2;
        const w = bubble.offsetWidth;
        const h = bubble.offsetHeight;
        const left = Math.round(Math.min(Math.max(EDGE, perch.x - 24), Math.max(EDGE, root.clientWidth - w - EDGE)));
        const top = perch.y - OWL_HEIGHT * px - GAP * px - h - 24;
        const covers = rectsOf(AVOID).some((r) => left < r.right && left + w > r.left && top < r.bottom && top + h > r.top);
        const below = top < EDGE || covers;
        return { left, top: Math.round(below ? perch.y + 4 * px + 24 : top), below };
    }

    // `ms` and `hold` override the line's own times: a rumour is short and holds nothing up. `then` — the parts still to
    // come; `line` and `part` tell the scene which catalog line is on, `rapier` — the part the owl draws its rapier on;
    // `count` — a number of the countdown (the sound beats it instead of a voice).
    function show({ text, hoot, then = [], line = null, part = 0, count = false, ms = showMs(text), hold = then.length ? ms + holdOf(then[0]) : holdOf(text) }) {
        const mine = ++token;
        textEl.textContent = text;
        holdUntil = now() + hold;
        shown = { until: now() + ms };
        // After the owl has flown away the scene anchors the bubble over FrankenPHP (scene.perch()).
        // `speech` — the syllables the beak moves to, the same plan the sound speaks (owl-speech.js); none for a number.
        const speech = count ? null : speechPlan(text);
        const delay = Number(scene?.owl?.({ ms, hoot, line, part, rapier: line !== null && LINES[line]?.rapier === part, speech })) || 0;
        const open = () => {
            speaking = true;
            place();
            const perch = scene?.perch?.();
            const width = root.clientWidth;
            onShow({ text, hoot, count, x: perch && width ? (perch.x / width) * 100 : null });
        };
        speaking = false;
        place();
        if (delay > 0) later(() => mine === token && open(), delay);
        else open();
        later(() => {
            if (mine !== token) return;
            speaking = false;
            place();
            // A line in parts goes on at once, unless another line has taken the bubble meanwhile.
            if (then.length) show({ text: then[0], hoot: false, then: then.slice(1), line, part: part + 1 });
            else onHide();
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
        /** Channel event `owl` said in `scene` (at finale `step`, null outside the finale); unknown catalog ids are ignored. */
        say(event, scene = 0, step = null) {
            const text = lineText(event, scene, step);
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

        /**
         * The countdown before finale step 1: a number a second over `ms` (summonText), each over whatever is on, the
         * bubble held to its end. The client holds the server's lines back meanwhile and says them after it.
         */
        countdown(ms) {
            const n = Math.max(1, Math.round(ms / 1000));
            const beat = ms / n;
            const mine = ++countdowns;
            counting = mine;
            pending = null;
            for (let k = 0; k < n; k++) {
                const tick = () => counting === mine && show({ text: summonText(n - k, k === 0), hoot: false, count: true, ms: beat, hold: ms - k * beat });
                if (k === 0) tick();
                else later(tick, k * beat);
            }
            later(() => counting === mine && (counting = 0), ms);
        },

        /** Stops the countdown on (a reset, a snapshot): its bubble goes at once and holds nothing up. */
        hush() {
            if (!counting) return;
            counting = 0;
            token++;
            speaking = false;
            place();
            onHide();
            holdUntil = -1e9;
            shown = { until: -1e9 };
        },

        /** Re-anchors the bubble, e.g. after the scene rebuilt for a new size or the reveal panel opened or closed. */
        place,
    };
}
