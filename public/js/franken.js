// FrankenPHP's phrases in the cursed world (docs/spec/scenario.md, «Проклятый мир»): his only voice. No message comes
// from the server: the phrase and its moment follow from the server clock, so the whole room sees the same line.

import { pick } from './i18n.js';

/**
 * The catalog; add lines at the end, so the ones already said keep their slots' indexes for long. Item N of `en` adapts
 * item N of `ru`: one slot, one joke in either language.
 */
export const PHRASES = pick({ ru: [
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
], en: [
    'Behold the power of CGO!',
    "I'm official!",
    "Why don't you love me?",
    'One binary — the server, PHP, and me.',
    'Worker mode: I pull all-nighters too.',
    'Caddy inside. Automatic HTTPS. Automatic ritual next?',
    "103 Early Hints… Here's a hint: I'm already here.",
    'You called? I came just in case.',
    'Green is my color. The pumpkins seem to agree.',
    'Who said "just a Go wrapper"? I hear everything.',
    'PHP lives. I live. Everyone lives — except your plans.',
    "Bolts in. Current on. IT'S ALIVE! (It's me.)",
    "Don't be scared, I only handle requests.",
    'RoadRunner? The one with the workers? Cute.',
    "Meep meep? Seriously? That's his whole marketing?",
    "I don't need a .rr.yaml. I don't need anything.",
    'Starting me: one command. Your ritual: six plugins. Do the math.',
    "You clicked pumpkins for six hours. I'd do it in one request.",
    'RoadRunner fooled you. So did I, but officially.',
    "I'm not a monster. I'm a SAPI.",
    'Your pumpkins run on my runtime now.',
    "Star me on GitHub. Or I'll visit you at night.",
    "Dead? Me? I'm the liveliest thing here.",
    'Stitched from Caddy, Go and PHP. Handsome, right?',
    "Plain PHP died after every request. I don't. Now neither do you.",
    "Locks, queue, KV… All for one pumpkin? I'd just keep it in memory.",
    'Nice idempotency key. I never do anything twice — key or no key.',
    'Duck hunt over WebSocket? I do Mercure. Out of the box.',
    "Restart workers? Never heard of it. I don't restart, I reincarnate.",
    'The owl flew off. Owls always fly off when it hits prod.',
    "Don't like me? Install php-fpm and weep.",
] });

/** Time slots of this length; FrankenPHP speaks in most of them. */
export const SLOT_MS = 7000;
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
export const phraseAt = (now) => pickAt(now, PHRASES, SLOT_MS, 85, SEED);

/**
 * The boss's phrases (docs/spec/scenario.md, «Шаг 6 — босс FrankenPHP»): while the boss stands they replace PHRASES and
 * come more often. Add lines at the end, like PHRASES.
 */
export const BOSS_PHRASES = pick({ ru: [
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
], en: [
    "I'm the only one!",
    "I'm official!",
    'Without me PHP dies!',
    "You can't stop me!",
    'A rapier? Seriously? Against a Go binary?',
    'The Foundation backs me!',
    'I restart faster than you click!',
    "That's not a vulnerability, it's a feature!",
    "Shades? So I can't see your RoadRunner.",
    "Worker mode isn't zombies, it's architecture!",
    'Caddy, cover me!',
    "I'm in every Docker image!",
    'One hit, one lock. I counted!',
    'Your rapiers fail my health check!',
    "I don't fall. I shut down gracefully!",
] });

/** Time slots of the boss's phrases; he speaks in almost all of them. */
export const BOSS_SLOT_MS = 6500;

/** The boss's phrase at server time `now`, like phraseAt: { text, at, until } or null. */
export const bossPhraseAt = (now) => pickAt(now, BOSS_PHRASES, BOSS_SLOT_MS, 90, BOSS_SEED);
