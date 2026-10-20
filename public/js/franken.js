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
const indexOf = (slot) => (mix(SEED, slot) >>> 8) % PHRASES.length;

/** How long a phrase stays up: long enough to read, within its slot. */
export const phraseMs = (text) => Math.min(SLOT_MS - START_MS - END_GAP, 2400 + text.length * 65);

/**
 * The phrase FrankenPHP is saying at server time `now`: { text, at, until } in server ms, or null when he is silent.
 * A slot speaks when its hash says so; the phrase is the hash's pick, moved one on when it would repeat the slot
 * before, so the same line never comes twice in a row.
 */
export function phraseAt(now) {
    const slot = Math.floor(now / SLOT_MS);
    if (mix(SEED ^ 0x55, slot) % 100 >= 50) return null;
    let index = indexOf(slot);
    if (index === indexOf(slot - 1)) index = (index + 1) % PHRASES.length;
    const text = PHRASES[index];
    const at = slot * SLOT_MS + START_MS;
    const until = at + phraseMs(text);
    return now >= at && now < until ? { text, at, until } : null;
}
