# Протокол клиент ↔ сервер

Статус: подключение, RPC `click` и `state`, события `click`, `pumpkin`, `scene`, `reset`, снимок состояния
(со `stageMax` и `size`), игроки (`playerSecret` при подключении, RPC `register` и `me`, `player` в `click` и `pumpkin`) —
**реализовано** на backend; разметка главной на переменных снимка — у фронтенда; таблица лидеров (событие `leaderboard`, `top` в `me`) и летуны (RPC `shoot`, события `flyer`, `shot`,
`bushes` в снимке) — тоже; персонаж и прогулка (`look` в `register` и `Player`, RPC `move`, события `walker`,
`move`, `leave`, `walkers` в снимке, `item` в `scene`) — тоже; модератор и сюжет (секрет модератора, RPC пульта, события
`owl`, `ready`, `finale`, `pause`, `zombies`, `ready`/`finale`/`paused`/`zombies` в снимке) — тоже; летуны по сценам,
особые летуны и зомби — тоже; костёр (элемент `bonfire` в снимке, ошибка `locked`, `bonfireStageMax` в `scene`,
реплики `bonfire-open` / `bonfire-done`) и `wobble` летучих мышей — тоже; магический круг (элемент `circle` в снимке,
`circleStageMax` в `scene`, реплика `circle-done`, `force` и `not ready` у `finale.step`) и червяки (`worm`) — тоже;
`startedAt` в снимке и разбегание фигурок (`move`) на шаге финала 2 — тоже; набор летунов проклятого мира и светлячки
(`firefly`) — тоже.

Все сообщения — JSON. Клиент — centrifuge-js (CDN, версия зафиксирована в `app/views/home.dark.php`).

## Подключение

1. Браузер открывает WebSocket к Centrifugo: URL из `data-centrifugo-url` (по умолчанию `/connection/websocket`,
   относительный путь достраивается до `ws(s)://<host>`).
2. Centrifugo вызывает connect proxy → `ConnectService`. Сервер сам подписывает клиента на канал `hellowin`
   (server-side subscription): клиент **не** вызывает `subscribe`, а слушает `centrifuge.on('publication', …)`.
3. Клиентам запрещено публиковать в канал и подписываться на другие каналы.
4. В `data` подключения браузер передаёт `{playerSecret}` — секрет из `localStorage`
   (`new Centrifuge(url, {data: {playerSecret}})`). Сервер отвечает `user: publicId`; без секрета подключение анонимное.
   Подробности — [`players.md`](players.md).
5. Модератор дополнительно передаёт `moderatorSecret` (`{playerSecret, moderatorSecret}`) — секрет из `localStorage`,
   куда страница кладёт его из `?moderator=<секрет>` (и убирает параметр из адреса). Совпал с
   `HELLOWIN_MODERATOR_SECRET` — подключение получает роль модератора (backend хранит её в meta/info подключения);
   не совпал или в env пусто — обычное подключение, без ошибки. Роль сообщает `me` (`moderator: true`).

## RPC

Вызов: `centrifuge.rpc(method, data)`. Ответ: `{ok: true, …}` или `{ok: false, error: <code>}`.

| Метод | Данные | Ответ | Статус |
|---|---|---|---|
| `click` | `{element}` | `{ok:true}` | реализовано |
| `state` | `{}` | `{ok:true, state: <Snapshot>}` — для пересинхронизации после переподключения | реализовано |
| `time` | `{}` | `{ok:true, now}` — серверное время в мс, без локов и KV, любому подключению (и анониму); клиент синхронизирует часы несколькими вызовами и берёт замер с минимальным RTT, на `unknown method` откатывается на `state` | реализовано |
| `register` | `{name, look?}` | `{ok:true, player: <Player>}`; с `look` игрок выходит на поле или переодевается (событие `walker`); без `look` вид не меняется | реализовано |
| `me` | `{}` | `{ok:true, player: <Player>, top: [<Player>], moderator}` — своя статистика и последняя таблица лидеров при загрузке; `player` — `null` у анонима и у игрока без действий; `moderator` — `true` у подключения модератора | реализовано |
| `reset` | `{round}` — раунд, который клиент видит сейчас | `{ok:true, round}` — новый раунд; повторный вызов с тем же `round` сброс не повторяет (сравнение раунда под локами), а возвращает текущий раунд. Ошибки: `invalid round`; не модератору — `forbidden` (RPC пульта, см. «Модератор») | реализовано |
| `move` | `{x, y}` — куда идти, проценты экрана | `{ok:true, walk: <Walk>}` — цель приведена в зону, путь огибает грядку; без `look` — `no character` | реализовано |
| `shoot` | `{flyer}` | `{ok:true, hit:true, points}` или `{ok:true, hit:false}` (уже сбит, улетел или такого нет) | реализовано |

### Модератор

**Реализовано**. RPC пульта — только подключению модератора, остальным —
`forbidden` (проверяется до разбора данных). Каждый — с ответом `{ok:true, …}`; повтор с теми же данными безопасен
(идемпотентен по текущему состоянию): событие уходит только при изменении.

| Метод | Данные | Ответ, эффект |
|---|---|---|
| `scene.next` | `{scene, force?}` — сцена, которую видит модератор | `{ok:true, scene}` — сцена, которую модератор увидит: `scene+1` (переход поставлен в очередь, событие `scene` придёт следом) или текущая, если `scene` уже не текущая (без перехода). Без `force: true` — только из готовой сцены, иначе `not ready`; из финала — `final scene`; `scene` не целое ≥ 0 — `invalid scene` |
| `finale.step` | `{step, force?}` — шаг, на который перейти (текущий + 1); шаг 1 — только из готовой финальной сцены (круг замкнут), иначе `not ready`; с `force: true` — без проверки | `{ok:true, step}` — текущий шаг после вызова; событие `finale` и реплика `finale-{step}`; шаг не следующий (в т. ч. повтор и шаг > 5) — ответ с текущим без изменений; вне финальной сцены — `not final`; `step` не целое — `invalid step` |
| `owl.say` | `{line, params?}` или `{text}` | `{ok:true}`, событие `owl`; `line` — id из каталога ([`scenario.md`](scenario.md), «Модератор и сова»; сервер проверяет формат slug `a-z 0-9 -` до 32 символов, не список), иначе `invalid line`; `params` — объект (не список), иначе `invalid params`; `text` — 1–200 символов после обрезки, иначе `invalid text`; если пришли и `text`, и `line` — говорится `text` |
| `flyer.special` | `{kind}` — `witch`, `roadrunner` | `{ok:true, flyer}` — id запущенного летуна этого вида (событие `flyer`), даже на паузе; другой вид — `unknown kind` |
| `zombies` | `{on}` — `true` / `false` | `{ok:true, on}`, событие `zombies` (при включении — и реплика `zombies`); зомби запускаются только в проклятом мире (`finale` = 5); `on` не bool — `invalid on` |
| `pause` | `{on}` — `true` / `false` | `{ok:true, on}`, событие `pause`; на паузе летуны и зомби не запускаются, уже запущенные долетают; `on` не bool — `invalid on` |
| `reset` | как выше | сброс ритуала |
| `thresholds` | `{k}` — множитель порогов, число от 0,1 до 10 | `{ok:true, k, thresholds: {pumpkin, bonfire, circle}}` — действующие пороги; событие `thresholds`; `k` вне диапазона или не число — `invalid k` (**запланировано**) |

Коды ошибок: `unknown method`, `unknown element`, `locked` (клик по костру, пока тыквы сцены не готовы или когда костёр уже на своей последней стадии сцены; по кругу — вне финальной сцены, после шага финала 0 или когда круг замкнут: клик не
считается и не рассылается),
`busy` (не удалось взять лок), `internal error`; для `register` —
`invalid name`, `invalid look` и `anonymous` (подключение без секрета); для `move` — `anonymous`, `invalid point` (`x`/`y` не числа),
`no character` (проверяются в этом порядке); для пульта — `forbidden` и коды из таблицы выше.

`Player` — `{id, name, look, clicks, finished, flyers, points}`: `id` — публичный id, `name` и `look` — `null` до
`register`; `look` — `{body, cloak, face, eyes, hat}` ([`players.md`](players.md), «Персонаж»).

`Walker` — фигурка на поле: `{id, name, look, item, walk: <Walk>}`. `Walk` — `{path: [{x,y}, …], startedAt, arrivesAt}`:
ломаная от текущей точки до цели (первая точка — откуда, последняя — куда), серверное время в мс. Фигурка идёт по
ломаной с постоянной скоростью; стоящая — `path` из одной точки. Где фигурка сейчас, клиент считает сам по `now`
(«Время летунов» — те же часы).

```json
{"ok":true,"player":{"id":"3f9c0a7d12b4e856","name":"Jack","look":null,"clicks":42,"finished":3,"flyers":0,"points":0}}
```

## События канала `hellowin`

Каждое событие несёт `type` и `round`. Событие с `round`, отличным от текущего у клиента, означает пропущенный сброс:
клиент запрашивает `state`.

| `type` | Поля | Когда |
|---|---|---|
| `click` | `element`, `emoji`, `clicks`, `threshold`, `stage`, `player?` | каждый принятый клик |
| `pumpkin` | `element`, `stage`, `player?` | элемент — тыква, костёр (`element: "bonfire"`) или магический круг (`element: "circle"`) — перешёл в новую стадию (ровно одно событие на переход) |
| `scene` | `scene`, `stageMax`, `bonfireStageMax` (последняя стадия костра в новой сцене), `circleStageMax` (последняя стадия магического круга в новой сцене: `HELLOWIN_CIRCLE_STAGES` в финальной, 0 до неё), `item` (предмет фигурок в новой сцене) | сцена сменилась (ровно одно событие на смену) |
| `reset` | `item` (предмет фигурок в сцене 0) | сброс; новый `round` в самом событии |
| `flyer` | `flyer`, `kind`, `depth`, `bush?`, `from:{x,y}`, `via?:{x,y}`, `to:{x,y}`, `wobble?:{amp,waves}`, `duration`, `points`, `launchedAt`, `expiresAt` | запущен летун |
| `shot` | `flyer`, `points`, `player?` | летун сбит, ровно одно событие на летуна |
| `walker` | `walker: <Walker>` | игрок вышел на поле (подключился с `look` — в пределах секунды, или прошёл экран входа) или сменил вид / имя |
| `move` | `player: {id}`, `walk: <Walk>` | фигурка пошла |
| `leave` | `player: {id}` | у игрока нет подключений дольше `HELLOWIN_WALK_LEAVE_AFTER` мс — фигурка уходит |
| `ready` | `scene` | все тыквы сцены дошли до `stageMax`, а костёр — до своей последней стадии сцены (если она выше той, на которой он был) — сцена ждёт модератора (ровно одно событие на сцену); следом реплики совы `last-pumpkin` (если последней была тыква) или `bonfire-done` (если костёр), `circle-done` (если замкнут магический круг — в финальной сцене на шаге 0 готовность даёт он) — только если добил игрок — и `scene-ready`. Если сцене нужен костёр, последняя тыква события `ready` не даёт: вместо него реплики `last-pumpkin` и `bonfire-open` |
| `finale` | `step`, `reveal?` (на шаге 2 следом — `move` каждой фигурке к краю поля: от центра грядки наружу, путь тем же роутером, что у RPC `move`) | шаг финала; на шаге 4 и дальше — `reveal` (см. «Снимок»). Шаг 0 приходит вместе с `scene` при входе в финальную сцену |
| `owl` | `line?`, `params?`, `text?` | реплика совы: каталожная (`line`, `params` — только если есть подстановки, например `{player: {id, name}}`) или свободный текст модератора (`text`, показывать как текст, не HTML). Авто-реплики: `scene-ready`, `last-pumpkin`, `bonfire-open`, `bonfire-done`, `circle-done`, `scene-{N}` (после события `scene`), `finale-{N}` (после `finale`, кроме шага 0 — на нём `scene-{N}` финальной сцены), `witch-down`, `zombies` |
| `pause` | `on` | модератор включил или снял паузу нечисти |
| `zombies` | `on` | модератор включил или выключил зомби |
| `thresholds` | `k`, `thresholds: {pumpkin, bonfire, circle}` | модератор сменил множитель порогов (**запланировано**): клиент обновляет `threshold` элементов и `data-ready`; следующие `click` несут новый порог |
| `leaderboard` | `top: [<Player>]` — лучшие первыми | раз в `HELLOWIN_LEADERBOARD_INTERVAL` мс, даже без изменений |

`player` — `{id, name}` игрока, совершившего действие (`name` может быть `null`); у анонимных действий поля нет.
`emoji` сервер выбирает из набора emoji элемента — фронтенд
может использовать его или игнорировать.

Особые летуны (**реализовано**): `roadrunner` — бежит по горизонту (`depth` 0, `from`/`to` на линии горизонта
`horizon` из `app/config/hellowin.php`; `duration` — как у ближней глубины, без `via` и `bush`), `points` 0, выстрел по нему всегда `hit:false`, без реплик
совы; `witch` — редкий, очки `HELLOWIN_FLYER_RARE_POINTS`; `zombie` — наземная цель проклятого мира: путь по
лужайке (зона `walk`) от края грядки наружу, `depth` 1, `via` нет, очки `HELLOWIN_ZOMBIE_POINTS`, частота
`HELLOWIN_ZOMBIE_INTERVAL`. `firefly` (**реализовано**, проклятый мир, набор `HELLOWIN_FLYER_KINDS_CURSED`) —
летун: мелкий, путь как у обычных (край или куст, `via` по шансу дуги), `depth` 1 или 2 (`bush` — только куст этих
глубин), `wobble` как у мышей, но вялый (`amp` 2–5, `waves` 1,5–3), `duration` — по глубине, очки —
`HELLOWIN_FLYER_FIREFLY_POINTS` на любой глубине.
`worm` (**реализовано**, обычный летун сцены 3 из `HELLOWIN_FLYER_KINDS`) — наземная
цель: путь по лужайке (зона `walk`) из точки в точку внутри зоны — выползает из земли и уходит в землю, без краёв
экрана; обе точки вне эллипса грядки, и прямая между ними грядку не пересекает; извилистость — `wobble` (малый:
`amp` 0,8–2, `waves` 3–6); `depth` 1, `via` и `bush` нет, длительность и очки — как у летунов глубины 1. Червяк не зависит
от проклятого мира и переключателя `zombies` — обычный запуск тикера, пауза его останавливает, как всех летунов.

Летун: `flyer` — id вида `f-` + 8 hex, его же клиент шлёт в `shoot`; `depth` — от 0 (дальний) до N−1, где N — длина
`HELLOWIN_FLYER_DURATION` (по умолчанию 3); `duration` — мс от `from` до `to`, отсчёт — от `launchedAt` (см. «Время летунов»); `kind` —
случайный из набора текущей сцены `HELLOWIN_FLYER_KINDS` (по умолчанию: сцена 0 — `duck`, `chicken`; 1 — никого; 2 — `bat`; финал — `worm`), в проклятом мире (шаг финала 5) — из `HELLOWIN_FLYER_KINDS_CURSED` (по умолчанию `worm`, `bat`, `firefly`), или редкий летун сцены (по умолчанию только `roadrunner` в сцене 0; `witch` — с пульта). Сервер принимает выстрел до `expiresAt` + 500 мс. Летун без попадания исчезает сам:
отдельного события «улетел» нет. Промах мимо летуна на сервер не шлётся.

Примеры:

```json
{"type":"click","round":1,"element":"pumpkin-3","emoji":"🕸️","clicks":4,"threshold":5,"stage":0}
{"type":"click","round":1,"element":"pumpkin-3","emoji":"🎃","clicks":5,"threshold":5,"stage":0,"player":{"id":"3f9c0a7d12b4e856","name":"Jack"}}
{"type":"pumpkin","round":1,"element":"pumpkin-3","stage":1}
{"type":"pumpkin","round":1,"element":"pumpkin-3","stage":2,"player":{"id":"3f9c0a7d12b4e856","name":null}}
{"type":"pumpkin","round":1,"element":"bonfire","stage":3,"player":{"id":"3f9c0a7d12b4e856","name":"Jack"}}
{"type":"scene","round":1,"scene":1,"stageMax":8,"bonfireStageMax":4,"circleStageMax":0,"item":"candle"}
{"type":"reset","round":2,"item":"pistol"}
{"type":"walker","round":1,"walker":{"id":"3f9c0a7d12b4e856","name":"Jack","look":{"body":"skeleton","cloak":"cape","face":"grin","eyes":"green","hat":"pumpkin"},"item":"pistol","walk":{"path":[{"x":40,"y":94}],"startedAt":1760900001000,"arrivesAt":1760900001000}}}
{"type":"move","round":1,"player":{"id":"3f9c0a7d12b4e856"},"walk":{"path":[{"x":40,"y":94},{"x":66,"y":90},{"x":82,"y":74}],"startedAt":1760900002000,"arrivesAt":1760900006100}}
{"type":"leave","round":1,"player":{"id":"3f9c0a7d12b4e856"}}
{"type":"leaderboard","round":1,"top":[{"id":"3f9c0a7d12b4e856","name":"Jack","look":null,"clicks":42,"finished":3,"flyers":2,"points":5}]}
{"type":"flyer","round":1,"flyer":"f-3a9c01d2","kind":"duck","depth":0,"from":{"x":-8,"y":18.4},"to":{"x":108,"y":10.2},"duration":9000,"points":3,"launchedAt":1760900000000,"expiresAt":1760900009000}
{"type":"flyer","round":1,"flyer":"f-77b0e415","kind":"duck","depth":2,"bush":"bush-1","from":{"x":4,"y":86},"via":{"x":21.5,"y":18.3},"to":{"x":70.2,"y":-8},"duration":4000,"points":1,"launchedAt":1760900002000,"expiresAt":1760900006000}
{"type":"flyer","round":1,"flyer":"f-1c0de5a7","kind":"bat","depth":1,"from":{"x":108,"y":22.5},"to":{"x":-8,"y":14.1},"wobble":{"amp":5.4,"waves":3.7},"duration":6500,"points":2,"launchedAt":1760900003000,"expiresAt":1760900009500}
{"type":"shot","round":1,"flyer":"f-3a9c01d2","points":3,"player":{"id":"3f9c0a7d12b4e856","name":"Jack"}}
{"type":"ready","round":1,"scene":0}
{"type":"owl","round":1,"line":"last-pumpkin","params":{"player":{"id":"3f9c0a7d12b4e856","name":"Jack"}}}
{"type":"owl","round":1,"text":"Кто здесь?"}
{"type":"finale","round":1,"step":4,"reveal":{"clicks":5230,"jobs":312,"burned":1720,"shot":87,"lastPumpkin":{"id":"3f9c0a7d12b4e856","name":"Jack"}}}
{"type":"pause","round":1,"on":true}
```

## Снимок состояния (Snapshot)

Одна структура для начального рендера страницы и для RPC `state`:

```json
{
  "round": 1,
  "scene": 0,
  "sceneStages": 3,
  "stageMax": 4,
  "threshold": 5,
  "bushes": [{"id":"bush-1","x":4,"y":66,"depth":2}],
  "elements": [{"id":"pumpkin-1","type":"pumpkin","x":50,"y":20,"size":"l","stage":0,"clicks":2},
               {"id":"bonfire","type":"bonfire","x":50,"y":63,"stage":0,"clicks":0,"threshold":20,"stageMax":0},
               {"id":"circle","type":"circle","x":50,"y":63,"stage":0,"clicks":0,"threshold":20,"stageMax":0}],
  "walkers": [{"id":"3f9c0a7d12b4e856","name":"Jack","look":{"body":"skeleton","cloak":"cape","face":"grin","eyes":"green","hat":"pumpkin"},"item":"pistol","walk":{"path":[{"x":40,"y":94}],"startedAt":1760900001000,"arrivesAt":1760900001000}}],
  "ready": false,
  "finale": null,
  "paused": false,
  "k": 1,
  "online": 12,
  "zombies": false,
  "startedAt": 1760890000000,
  "now": 1760900001500
}
```

`startedAt` — серверное время запуска RoadRunner в мс (аптайм для финала: шаг 3 в HUD, шаг 4 в
разоблачении); клиент считает аптайм по часам с тем же смещением, что у летунов. Перезапуск воркеров его не сбрасывает,
перезапуск RoadRunner (контейнера `app`) — сбрасывает: это аптайм сервера, а не стека с Valkey ([`engine.md`](engine.md), «Аптайм»).

`ready` — сцена готова и ждёт модератора; `finale` — `null` вне финальной сцены, в ней `{step, reveal?}`;
`k` — множитель порогов (**запланировано**; `threshold` в снимке и у элементов — уже умноженный), `online` — сколько
игроков с персонажами сейчас на поле (**запланировано**; для пульта, ещё приходит в событии `leaderboard`); `paused`, `zombies` — переключатели пульта. `reveal` — числа вечера с начала раунда для экрана
разоблачения: `{clicks, jobs, burned, shot, lastPumpkin: {id, name} | null}` — принятые клики, выполненные задачи
перехода (переходы тыкв, костра и круга + переходы сцен), сгоревшие клики (сверх порога и после готовности тыквы;
клики `locked` по костру и кругу не принимаются и сюда не входят), сбитые летуны (и зомби), кто добил последний элемент
последней готовой сцены — тыкву, в сцене с костром — костёр, в финальной — круг (если элемент добит анонимно, остаётся
игрок прежней готовой сцены; `null`, если такого нет — добивали анонимно или сцены переключали `force`, не дождавшись;
имя поля осталось `lastPumpkin`). Как считаются — [`engine.md`](engine.md), «Сюжет».

Сброс (`reset`) возвращает и сюжет: `ready` — `false`, `finale` — `null`, `zombies` — `false`; `paused` сохраняется —
пауза про зал, а не про сюжет. Клиент применяет это сам по событию `reset`, без RPC.

`bush` — id куста, из которого взлетает летун (тогда `from` — его точка, а `depth` летуна — `depth` куста, см.
[`scenario.md`](scenario.md), «Летуны»; `depth` в `bushes` снимка — **реализовано**); `wobble` (только у `kind: bat`, `worm` и `firefly`, у остальных поля нет) — кривая поверх базовой траектории: смещение поперёк пути
`amp · sin(2π · waves · t)` (`amp` — проценты экрана, `t` — доля полёта 0…1; `waves` — дробное число полных колебаний
за полёт); без поля — без колебаний; петли — `waves` с большой `amp` или отдельное поле, если понадобится (согласовать).
Диапазоны — [`engine.md`](engine.md), «Летуны». `via` — контрольная точка дуги
(квадратичная кривая), без неё полёт прямой. `x`/`y` — проценты экрана (на узких экранах фронтенд растягивает раскладку тыкв по x от центра — `spreadX`
в `public/js/layout.js`; и у тыкв, и в траекториях летунов: `from`/`to` могут выходить за 0–100 — летун влетает из-за края); `size` — `s` / `m` / `l` (у тыкв); у магического круга (`type: circle`, id `circle`, без `size`) — как у костра:
свои `threshold` (`HELLOWIN_CIRCLE_CLICKS_PER_STAGE`) и `stageMax` (`HELLOWIN_CIRCLE_STAGES` в финальной сцене, 0 до неё),
кликабелен в финальной сцене на шаге 0, пока `stage` ниже `stageMax` (новую сцена приносит в `scene.circleStageMax`),
готовность финальной сцены — круг на `stageMax`, шаг финала 1 без готовности —
только `force`; у костра (`type: bonfire`, id `bonfire`, без `size`) — свои `threshold`
(`HELLOWIN_BONFIRE_CLICKS_PER_STAGE`) и `stageMax` (последняя стадия костра в текущей сцене, из
`HELLOWIN_BONFIRE_STAGE_MAX`; новую сцена приносит в `scene.bonfireStageMax`), кликабелен, когда все тыквы на
`stageMax` сцены, а `stage` костра ниже его `stageMax` — клиент вычисляет это сам по снимку и событиям `pumpkin`;
`threshold` в событии `click` по костру — тоже его порог; `sceneStages` — номер финальной сцены (длина списка
`HELLOWIN_SCENE_STAGE_MAX`); `stageMax` — последняя стадия тыквы в текущей сцене, в финальной — та же, что в сцене
перед ней (см. [`scenario.md`](scenario.md)). `clicks` — клики с момента перехода тыквы в текущую стадию. `walkers` — фигурки игроков на поле
(`Walker`, см. «RPC»). Страница
`GET /` отдаёт снимок в шаблон `home` (переменные — см. [`frontend.md`](frontend.md)) вместе с `centrifugoUrl`.

### Время летунов

`launchedAt` и `expiresAt` в событии `flyer` — серверное Unix-время в мс; `now` в снимке — серверное время его сборки.
Клиент считает смещение часов `offset = now − Date.now()` (по снимку страницы или RPC `state`) и **не рисует летуна**,
если `Date.now() + offset >= expiresAt`; уже нарисованного убирает к `expiresAt`. Так свёрнутая вкладка, получившая
пачку отложенных публикаций при разворачивании, не выпускает птиц, которых уже нельзя сбить (сервер принимает
выстрел не позже `expiresAt` + 500 мс). Если смещение неизвестно — считать его 0.
