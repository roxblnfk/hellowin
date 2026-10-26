// Toasts of the cursed world (docs/spec/scenario.md, «Проклятый мир», «Чоканье»): what the walkers say over their
// clinking glasses. The whole room reads the same line over the same glasses: an automatic clink takes its toast from
// the group and the server clock (pixel/clink.js), a clink by click from the number the server sends (`clink` event).

import { pick } from './i18n.js';

/**
 * The catalog; add toasts at the end, so the ones already said keep their indexes. A toast is a line, or two voices
 * [line, reply]: the first over one walker of the group, the reply over another (or over the pumpkin it clinks with).
 * Every catalog here but REFUSALS is picked by index: item N of `en` adapts item N of `ru`, two-voice where it is.
 */
export const TOASTS = pick({ ru: [
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
], en: [
    'To RR!',
    'To RoadRunner — alive and kicking!',
    'To DLoad!',
    'To Testo!',
    'To Spiral!',
    'To Temporal — may the party survive a restart!',
    'To Centrifugo — everyone heard us!',
    "To Buggregator — may it catch only other people's bugs!",
    'To Cycle ORM!',
    'To Valkey!',
    'To idempotency! To idempotency!',
    'To the workers — living and dead!',
    "To PHP — it's alive too!",
    'Shall we raise nginx next?',
    'Has anyone seen php-fpm?',
    "Let's summon Apache too, while we're at it",
    'Poor owl…',
    'Whoever lit the bonfire buys the round!',
    'Meep meep!',
    'Franken, come join us!',
    'I knew he was alive all along',
    'Next year we resurrect Laravel Octane?',
    'To absent friends — and dropped clicks!',
    'To locks — may they acquire on the first try!',
    'To the queue — may every job arrive!',
    'To KV — what you set is what you get!',
    'To prod with no Friday deploys!',
    ['Shall we deploy?', 'Not today!'],
    'To Halloween!',
    'Hoo-hoo!',
    'To gRPC — may contracts never lie!',
    'To .rr.yaml — short and sweet!',
    'To metrics — may Grafana turn red only for pumpkins!',
    ['Anyone remember why we brought him back?', 'For the metrics!'],
    'To the pumpkins — they worked all night too!',
] });

/**
 * Toasts of the bar (scene 0; docs/spec/scenario.md, «Сцена 0 — «Бар»»): to PHP. Same rules as TOASTS — add at the end;
 * the scene picks the catalog, the index comes the same way.
 */
export const BAR_TOASTS = pick({ ru: [
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
], en: [
    'To PHP!',
    'To PHP — 100 years and not dead yet!',
    'To the elePHPant!',
    'To declare(strict_types=1)!',
    'To composer install with zero errors!',
    'To PSR!',
    'To everyone still on PHP 5!',
    'To JIT!',
    'To match over switch!',
    'To readonly!',
    'To enums!',
    'To the pipe operator |>!',
    'To Fibers!',
    'To the PHP Foundation!',
    "To PHP 9 — we'll live to see it!",
    'To property hooks!',
    ['Upgrade to the latest version?', 'Right after the .1 release!'],
    'To those who write tests!',
    'To RoadRunner — may the workers never tire!',
] });

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
export const REFUSALS = pick({ ru: [
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
], en: [
    "I don't drink without a clink!",
    'What am I, a barfly?',
    'I never drink alone — I have manners.',
    "Without a toast it's just liquid.",
    'Clink first, talk later.',
    "Who's with me?",
    'Drink without a clink, get a bug in prod.',
    'Mom said: only in good company.',
    'Hey, anyone! Clink?',
    "Won't go down without a toast.",
    'Bad omen — a memory leak ahead.',
    "I'll take any toast. Even one to PHP 5.",
    'No clink, no deploy.',
    'A toast is a contract. No toast, no drink.',
    "Clinking is idempotent. Drinking isn't.",
] });

/** A refusal line for `not clinked`. */
export const refusal = (random = Math.random) => REFUSALS[Math.floor(random() * REFUSALS.length)];

/**
 * RoadRunner's toasts behind the bar's counter after the dawn (docs/spec/scenario.md, «RoadRunner в баре»). Same rule:
 * add at the end, so the slots keep their toasts.
 */
export const RR_TOASTS = pick({ ru: [
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
], en: [
    'Meep meep! To you!',
    'To uptime!',
    'To those who never went down!',
    'To the workers — living and restarted!',
    'Rumours of my death were greatly exaggerated!',
    'To Spiral!',
    "To PHP — it's alive and kicking too!",
    'To Centrifugo — everyone heard you!',
    "To Temporal — we'll survive any restart!",
    'To idempotency — pour twice, I drink once!',
    'Franken, no hard feelings!',
    'To nginx — may it rest in peace!',
    'To everyone who spent all night raising me!',
    "Meep meep! (that's a toast)",
] });

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
