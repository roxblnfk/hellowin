// FrankenPHP's phrases in the cursed world (docs/spec/scenario.md, «Проклятый мир»): his only voice. No message comes
// from the server: the phrase and its moment follow from the server clock, so the whole room sees the same line.

/** The catalog; add lines at the end, so the ones already said keep their slots' indexes for long. */
export const PHRASES = [
    'Познай мощь CGO!',
    'Я официальный!',
    'Почему вы меня не любите?',
    'Один бинарник — и сервер, и PHP, и я.',
    'Worker mode: я тоже не сплю всю ночь.',
    'Caddy внутри. HTTPS сам. Ритуал — тоже сам?',
    '103 Early Hints… Намекаю: я уже здесь.',
    'Меня вызывали? Я на всякий случай пришёл.',
    'Зелёный — мой цвет. Тыквы, кажется, согласны.',
    'Кто сказал «обёртка на Go»? Я всё слышу.',
    'PHP жив. Я жив. Все живы — кроме ваших планов.',
    'Болты на месте. Ток пошёл. Можно работать.',
    'Не бойтесь, я только обрабатываю запросы.',
    'RoadRunner? Это который на воркерах? Мило.',
    'Мип-мип? Серьёзно? Это весь его маркетинг?',
    'Мне не нужен .rr.yaml. Мне вообще ничего не нужно.',
    'Запустить меня — одна команда. Ваш ритуал — шесть плагинов. Делайте выводы.',
    'Вы шесть часов кликали по тыквам. Я бы справился за один запрос.',
    'RoadRunner вас обманул. Я тоже, но официально.',
    'Я не монстр. Я — SAPI.',
    'Ваши тыквы теперь работают на моём рантайме.',
    'Ставьте звёздочку на GitHub. Иначе приду ночью.',
    'Кто тут мёртвый? Я тут самый живой.',
    'Сшит из Caddy, Go и PHP. Красивый, правда?',
    'Обычный PHP умирал после каждого запроса. Я — нет. Вы теперь тоже.',
    'Локи, очередь, KV… Столько плагинов ради одной тыквы? Я бы просто запомнил.',
    'Ваш идемпотентный ключ я видел. Я и без ключа не перехожу дважды.',
    'Курошлёпка на WebSocket? А я умею Mercure. Из коробки.',
    'Перезапуск воркеров? Не слышал. Я не перезапускаюсь, я перерождаюсь.',
    'Сова улетела. Совы всегда улетают, когда начинается прод.',
    'Не нравлюсь? Поставьте php-fpm и плачьте.',
];

/** Time slots of this length; FrankenPHP speaks in about half of them. */
export const SLOT_MS = 10000;
// The line starts this far into its slot and leaves at least END_GAP of the slot empty.
const START_MS = 900;
const END_GAP = 1200;

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

const SEED = 0xf4a7c3;
const BOSS_SEED = 0xb055ed;

/** How long a phrase stays up: long enough to read, within its slot. */
export const phraseMs = (text, slotMs = SLOT_MS) => Math.min(slotMs - START_MS - END_GAP, 2400 + text.length * 65);

// A slot speaks when its hash says so (`share` of 100); the phrase is the hash's pick, moved one on when it would repeat
// the slot before, so the same line never comes twice in a row.
function pickAt(now, list, slotMs, share, seed) {
    const slot = Math.floor(now / slotMs);
    if (mix(seed ^ 0x55, slot) % 100 >= share) return null;
    const indexOf = (n) => (mix(seed, n) >>> 8) % list.length;
    let index = indexOf(slot);
    if (index === indexOf(slot - 1)) index = (index + 1) % list.length;
    const text = list[index];
    const at = slot * slotMs + START_MS;
    const until = at + phraseMs(text, slotMs);
    return now >= at && now < until ? { text, at, until } : null;
}

/** The phrase FrankenPHP is saying at server time `now`: { text, at, until } in server ms, or null when he is silent. */
export const phraseAt = (now) => pickAt(now, PHRASES, SLOT_MS, 50, SEED);

/**
 * The boss's phrases (docs/spec/scenario.md, «Шаг 6 — босс FrankenPHP»): while the boss stands they replace PHRASES and
 * come more often. Add lines at the end, like PHRASES.
 */
export const BOSS_PHRASES = [
    'Я единственный!',
    'Я официальный!',
    'Без меня PHP умрёт!',
    'Вы не в силах меня остановить!',
    'Рапира? Серьёзно? Против бинарника на Go?',
    'Меня поддерживает Foundation!',
    'Я перезапускаюсь быстрее, чем вы кликаете!',
    'Это была не уязвимость, это фича!',
    'Очки? Чтобы не видеть ваш RoadRunner.',
    'Worker mode — это не зомби, это архитектура!',
    'Caddy, прикрой меня!',
    'Я в каждом докер-образе!',
    'Один удар — один лок. Я считал!',
    'Ваши рапиры не проходят мой health check!',
    'Я не падаю. У меня graceful shutdown!',
];

/** Time slots of the boss's phrases; he speaks in about two thirds of them. */
export const BOSS_SLOT_MS = 7000;

/** The boss's phrase at server time `now`, like phraseAt: { text, at, until } or null. */
export const bossPhraseAt = (now) => pickAt(now, BOSS_PHRASES, BOSS_SLOT_MS, 67, BOSS_SEED);
