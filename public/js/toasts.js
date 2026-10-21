// Toasts of the cursed world (docs/spec/scenario.md, «Проклятый мир», «Чоканье»): what the walkers say over their
// clinking glasses. The whole room reads the same line over the same glasses: an automatic clink takes its toast from
// the group and the server clock (pixel/clink.js), a clink by click from the number the server sends (`clink` event).

/**
 * The catalog; add toasts at the end, so the ones already said keep their indexes. A toast is a line, or two voices
 * [line, reply]: the first over one walker of the group, the reply over another (or over the pumpkin it clinks with).
 */
export const TOASTS = [
    'За RR!',
    'За RoadRunner — живее всех живых!',
    'За DLoad!',
    'За Testo!',
    'За Spiral!',
    'За Temporal — чтобы вечеринка пережила рестарт!',
    'За Centrifugo — нас все слышали!',
    'За Buggregator — пусть ловит только чужие баги!',
    'За Cycle ORM!',
    'За Valkey!',
    'За идемпотентность! За идемпотентность!',
    'За воркеров — живых и мёртвых!',
    'За PHP — он тоже живой!',
    'Может, nginx воскресим?',
    'А php-fpm кто-нибудь видел?',
    'Давайте ещё Apache призовём, раз уж начали',
    'Сову жалко…',
    'Кто добил костёр — тот и платит!',
    'Мип-мип!',
    'Франкен, иди к нам!',
    'Я сразу понял, что он живой',
    'В следующем году воскрешаем Laravel Octane?',
    'За тех, кто клики сверх порога!',
    'За локи — чтобы брались с первого раза!',
    'За очередь — пусть всё дойдёт!',
    'За KV — что положил, то и взял!',
    'За прод без пятничных деплоев!',
    ['Задеплоим?', 'Не сегодня!'],
    'За Хеллоуин!',
    'Ух-ху!',
    'За gRPC — чтобы контракты не врали!',
    'За .rr.yaml — короткий и понятный!',
    'За метрики — пусть Grafana краснеет только от тыкв!',
    ['Кто-нибудь помнит, зачем мы его воскрешали?', 'Ради метрик!'],
    'За тыквы — они тоже работали всю ночь!',
];

/**
 * Toasts of the bar (scene 0; docs/spec/scenario.md, «Сцена 0 — «Бар»»): to PHP. Same rules as TOASTS — add at the end;
 * the scene picks the catalog, the index comes the same way.
 */
export const BAR_TOASTS = [
    'За PHP!',
    'За PHP — сто лет без смерти!',
    'За elePHPant!',
    'За declare(strict_types=1)!',
    'За composer install без ошибок!',
    'За PSR!',
    'За тех, кто ещё на PHP 5!',
    'За JIT!',
    'За match вместо switch!',
    'За readonly!',
    "За enum'ы!",
    'За пайп-оператор |>!',
    'За Fibers!',
    'За PHP Foundation!',
    'За PHP 9 — доживём!',
    'За property hooks!',
    ['Обновимся до свежей версии?', 'Сразу после релиза!'],
    'За тех, кто пишет тесты!',
    'За RoadRunner — пусть воркеры не устают!',
];

/** The toast of a `clink` event's index in a catalog (it wraps around): [line] or [line, reply]. */
export function toastAt(index, list = TOASTS) {
    const n = Number.isInteger(index) && index >= 0 ? index : 0;
    const toast = list[n % list.length];
    return Array.isArray(toast) ? toast : [toast];
}

const SEED = 0x70a57;

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

/** The toast of a group (its hash) in a time slot, from a catalog: [line] or [line, reply]. */
export function toastOf(groupHash, slot, list = TOASTS) {
    const toast = list[(mix(groupHash ^ SEED, slot) >>> 4) % list.length];
    return Array.isArray(toast) ? toast : [toast];
}

/**
 * What a walker says over its own head when the server refuses its drink (`not clinked`): only this browser sees it,
 * so a random pick is fine. Add lines at the end.
 */
export const REFUSALS = [
    'Не пью не чокаясь!',
    'Я же не алкаш!',
    'Один не пью — я культурный.',
    'Без тоста — это просто жидкость.',
    'Сначала чокнемся, потом поговорим.',
    'Кто со мной?',
    'Пить без чоканья — к багу в проде.',
    'Мама говорила: только за компанию.',
    'Эй, кто-нибудь! Чокнемся?',
    'Без тоста не лезет.',
    'Примета плохая — к утечке памяти.',
    'Я жду тост. Хоть за PHP 5.',
    'Без чоканья не задеплоится.',
    'Тост — это контракт. Без него не пью.',
    'Чокнуться — это идемпотентно. Выпить — нет.',
];

/** A refusal line for `not clinked`. */
export const refusal = (random = Math.random) => REFUSALS[Math.floor(random() * REFUSALS.length)];

/**
 * RoadRunner's toasts behind the bar's counter after the dawn (docs/spec/scenario.md, «RoadRunner в баре»). Same rule:
 * add at the end, so the slots keep their toasts.
 */
export const RR_TOASTS = [
    'Мип-мип! За вас!',
    'За аптайм!',
    'За тех, кто ни разу не упал!',
    'За воркеров — живых и перезапущенных!',
    'Слухи о моей смерти сильно преувеличены!',
    'За Spiral!',
    'За PHP — он тоже живее всех живых!',
    'За Centrifugo — вас все слышали!',
    'За Temporal — переживём любой рестарт!',
    'За идемпотентность — пью один раз, наливай дважды!',
    'Франкен, без обид!',
    'За nginx — пусть земля ему будет пухом!',
    'За тех, кто воскрешал меня всю ночь!',
    'Мип-мип! (это тост)',
];

/** RoadRunner's toast slots, ms; he speaks in about two thirds of them. */
export const RR_SLOT_MS = 8000;
// The toast starts this far into its slot and leaves at least RR_END_GAP of the slot empty.
const RR_START_MS = 600;
const RR_END_GAP = 1000;
const RR_SEED = 0x2b2b2b;

/** How long a toast of RoadRunner stays up: long enough to read, within its slot. */
export const rrToastMs = (text) => Math.min(RR_SLOT_MS - RR_START_MS - RR_END_GAP, 2000 + text.length * 65);

// The slot's toast, moved one on from the slot before's own pick (looked back a few slots, which settles it).
function rrIndex(slot, depth = 4) {
    const index = (mix(RR_SEED, slot) >>> 8) % RR_TOASTS.length;
    return depth > 0 && index === rrIndex(slot - 1, depth - 1) ? (index + 1) % RR_TOASTS.length : index;
}

/**
 * RoadRunner's toast at server time `now`: { text, at, until } in server ms, or null when he is silent. Like FrankenPHP's
 * phrases (franken.js): the slot's hash decides whether he speaks and picks the toast, moved one on when it would repeat
 * the slot before.
 */
export function rrToastAt(now) {
    const slot = Math.floor(now / RR_SLOT_MS);
    if (mix(RR_SEED ^ 0x55, slot) % 100 >= 67) return null;
    const text = RR_TOASTS[rrIndex(slot)];
    const at = slot * RR_SLOT_MS + RR_START_MS;
    const until = at + rrToastMs(text);
    return now >= at && now < until ? { text, at, until } : null;
}
