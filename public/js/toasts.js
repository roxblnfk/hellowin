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
