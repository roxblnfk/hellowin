// Toasts of the cursed world (docs/spec/scenario.md, «Проклятый мир», «Чоканье»): what the walkers say over their
// clinking glasses. No message comes from the server: the toast follows from the group and the server clock
// (pixel/clink.js), so the whole room reads the same line over the same glasses.

/**
 * The catalog; add toasts at the end, so the ones already said keep their indexes. A toast is a line, or two voices
 * [line, reply]: the first over one walker of the group, the reply over another.
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

const SEED = 0x70a57;

function mix(a, b) {
    let h = Math.imul(a ^ Math.imul(b, 0x9e3779b1), 0x85ebca6b);
    h ^= h >>> 13;
    h = Math.imul(h, 0xc2b2ae35);
    return (h ^ (h >>> 16)) >>> 0;
}

/** The toast of a group (its hash) in a time slot: [line] or [line, reply]. */
export function toastOf(groupHash, slot) {
    const toast = TOASTS[(mix(groupHash ^ SEED, slot) >>> 4) % TOASTS.length];
    return Array.isArray(toast) ? toast : [toast];
}
