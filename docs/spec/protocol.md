# Протокол клиент ↔ сервер

Статус: подключение, RPC `click` и `state`, события `click`, `pumpkin`, `scene`, `reset`, снимок состояния
(со `stageMax` и `size`), игроки (`playerSecret` при подключении, RPC `register` и `me`, `player` в `click` и `pumpkin`) —
**реализовано** на backend; разметка главной на переменных снимка — у фронтенда; таблица лидеров (событие `leaderboard`, `top` в `me`) и летуны (RPC `shoot`, события `flyer`, `shot`,
`bushes` в снимке) — тоже; персонаж и прогулка (`look` в `register` и `Player`, RPC `move`, события `walker`,
`move`, `leave`, `walkers` в снимке, `item` в `scene`) — тоже; модератор и сюжет (секрет модератора, RPC пульта, события
`owl`, `ready`, `finale`, `pause`, `zombies`, летуны по сценам, `ready`/`finale`/`paused`/`zombies` в снимке) —
**запланировано**.

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
| `register` | `{name, look?}` | `{ok:true, player: <Player>}`; с `look` игрок выходит на поле или переодевается (событие `walker`); без `look` вид не меняется | реализовано |
| `me` | `{}` | `{ok:true, player: <Player>, top: [<Player>], moderator}` — своя статистика и последняя таблица лидеров при загрузке; `player` — `null` у анонима и у игрока без действий; `moderator` — `true` у подключения модератора | реализовано |
| `reset` | `{round}` — раунд, который клиент видит сейчас | `{ok:true, round}` — новый раунд; повторный вызов с тем же `round` сброс не повторяет (сравнение раунда под локами), а возвращает текущий раунд. Ошибки: `invalid round`; не модератору — `forbidden` (RPC пульта, см. «Модератор») | реализовано |
| `move` | `{x, y}` — куда идти, проценты экрана | `{ok:true, walk: <Walk>}` — цель приведена в зону, путь огибает грядку; без `look` — `no character` | реализовано |
| `shoot` | `{flyer}` | `{ok:true, hit:true, points}` или `{ok:true, hit:false}` (уже сбит, улетел или такого нет) | реализовано |

### Модератор

**Запланировано.** RPC пульта — только подключению модератора, остальным — `forbidden`. Каждый — с ответом
`{ok:true, …}`; повтор с теми же данными безопасен (идемпотентен по текущему состоянию).

| Метод | Данные | Ответ, эффект |
|---|---|---|
| `scene.next` | `{scene, force?}` — сцена, которую видит модератор | переход `scene` → `scene+1` и событие `scene`; без `force` — только из готовой сцены, иначе `not ready`; `scene` не текущая — ответ с текущей сценой без перехода; из финала — `final scene` |
| `finale.step` | `{step}` — шаг, на который перейти (текущий + 1) | событие `finale`; шаг не следующий — ответ с текущим без изменений; вне финальной сцены — `not final` |
| `owl.say` | `{line, params?}` или `{text}` | событие `owl`; `line` — id из каталога ([`scenario.md`](scenario.md), «Модератор и сова»; сервер проверяет формат slug, не список), `text` — 1–200 символов после обрезки, иначе `invalid text` |
| `flyer.special` | `{kind}` — `witch`, `roadrunner` | запуск летуна этого вида сейчас (событие `flyer`), даже на паузе |
| `zombies` | `{on}` | событие `zombies`; зомби запускаются только в проклятом мире (`finale` = 5) |
| `pause` | `{on}` | событие `pause`; на паузе летуны и зомби не запускаются, уже запущенные долетают |
| `reset` | как выше | сброс ритуала (**реализовано**) |

Коды ошибок: `unknown method`, `unknown element`, `busy` (не удалось взять лок), `internal error`; для `register` —
`invalid name`, `invalid look` и `anonymous` (подключение без секрета); для `move` — `anonymous`, `invalid point` (`x`/`y` не числа),
`no character` (проверяются в этом порядке).

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
| `pumpkin` | `element`, `stage`, `player?` | тыква перешла в новую стадию (ровно одно событие на переход) |
| `scene` | `scene`, `stageMax`, `item` (предмет фигурок в новой сцене) | сцена сменилась (ровно одно событие на смену) |
| `reset` | `item` (предмет фигурок в сцене 0) | сброс; новый `round` в самом событии |
| `flyer` | `flyer`, `kind`, `depth`, `bush?`, `from:{x,y}`, `via?:{x,y}`, `to:{x,y}`, `duration`, `points`, `launchedAt`, `expiresAt` | запущен летун |
| `shot` | `flyer`, `points`, `player?` | летун сбит, ровно одно событие на летуна |
| `walker` | `walker: <Walker>` | игрок вышел на поле (подключился с `look` — в пределах секунды, или прошёл экран входа) или сменил вид / имя |
| `move` | `player: {id}`, `walk: <Walk>` | фигурка пошла |
| `leave` | `player: {id}` | у игрока нет подключений дольше `HELLOWIN_WALK_LEAVE_AFTER` мс — фигурка уходит |
| `ready` | `scene` | все тыквы сцены дошли до `stageMax` — сцена ждёт модератора (ровно одно событие на сцену) |
| `finale` | `step`, `reveal?` | шаг финала; на шаге 4 и дальше — `reveal` (см. ниже) |
| `owl` | `line?`, `params?`, `text?` | реплика совы: каталожная (`line` + `params`, например `{player: {id, name}}`) или свободный текст модератора (`text`) |
| `pause` | `on` | модератор включил или снял паузу нечисти |
| `zombies` | `on` | модератор включил или выключил зомби |
| `leaderboard` | `top: [<Player>]` — лучшие первыми | раз в `HELLOWIN_LEADERBOARD_INTERVAL` мс, даже без изменений |

`player` — `{id, name}` игрока, совершившего действие (`name` может быть `null`); у анонимных действий поля нет.
`emoji` сервер выбирает из набора emoji элемента — фронтенд
может использовать его или игнорировать.

Особые летуны (**запланировано**): `roadrunner` — бежит по горизонту (`depth` 0, `from`/`to` на линии горизонта
`horizon` из `app/config/hellowin.php`), `points` 0, выстрел по нему всегда `hit:false`, без реплик
совы; `witch` — редкий, очки `HELLOWIN_FLYER_RARE_POINTS`; `zombie` — наземная цель проклятого мира: путь по
лужайке (зона `walk`) от края грядки наружу, `depth` 1, `via` нет, очки `HELLOWIN_ZOMBIE_POINTS`, частота
`HELLOWIN_ZOMBIE_INTERVAL`.

Летун: `flyer` — id вида `f-` + 8 hex, его же клиент шлёт в `shoot`; `depth` — от 0 (дальний) до N−1, где N — длина
`HELLOWIN_FLYER_DURATION` (по умолчанию 3); `duration` — мс от `from` до `to`, отсчёт — от `launchedAt` (см. «Время летунов»); `kind` —
случайный из `HELLOWIN_FLYER_KINDS` (по умолчанию `duck`, `chicken`). Сервер принимает выстрел до `expiresAt` + 500 мс. Летун без попадания исчезает сам:
отдельного события «улетел» нет. Промах мимо летуна на сервер не шлётся.

Примеры:

```json
{"type":"click","round":1,"element":"pumpkin-3","emoji":"🕸️","clicks":4,"threshold":5,"stage":0}
{"type":"click","round":1,"element":"pumpkin-3","emoji":"🎃","clicks":5,"threshold":5,"stage":0,"player":{"id":"3f9c0a7d12b4e856","name":"Jack"}}
{"type":"pumpkin","round":1,"element":"pumpkin-3","stage":1}
{"type":"pumpkin","round":1,"element":"pumpkin-3","stage":2,"player":{"id":"3f9c0a7d12b4e856","name":null}}
{"type":"scene","round":1,"scene":1,"stageMax":7,"item":"torch"}
{"type":"reset","round":2,"item":null}
{"type":"walker","round":1,"walker":{"id":"3f9c0a7d12b4e856","name":"Jack","look":{"body":"skeleton","cloak":"cape","face":"grin","eyes":"green","hat":"pumpkin"},"item":null,"walk":{"path":[{"x":40,"y":94}],"startedAt":1760900001000,"arrivesAt":1760900001000}}}
{"type":"move","round":1,"player":{"id":"3f9c0a7d12b4e856"},"walk":{"path":[{"x":40,"y":94},{"x":66,"y":90},{"x":82,"y":74}],"startedAt":1760900002000,"arrivesAt":1760900006100}}
{"type":"leave","round":1,"player":{"id":"3f9c0a7d12b4e856"}}
{"type":"leaderboard","round":1,"top":[{"id":"3f9c0a7d12b4e856","name":"Jack","look":null,"clicks":42,"finished":3,"flyers":2,"points":5}]}
{"type":"flyer","round":1,"flyer":"f-3a9c01d2","kind":"duck","depth":0,"from":{"x":-8,"y":18.4},"to":{"x":108,"y":10.2},"duration":9000,"points":3,"launchedAt":1760900000000,"expiresAt":1760900009000}
{"type":"flyer","round":1,"flyer":"f-77b0e415","kind":"duck","depth":2,"bush":"bush-1","from":{"x":4,"y":86},"via":{"x":21.5,"y":18.3},"to":{"x":70.2,"y":-8},"duration":4000,"points":1,"launchedAt":1760900002000,"expiresAt":1760900006000}
{"type":"shot","round":1,"flyer":"f-3a9c01d2","points":3,"player":{"id":"3f9c0a7d12b4e856","name":"Jack"}}
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
  "elements": [{"id":"pumpkin-1","type":"pumpkin","x":50,"y":20,"size":"l","stage":0,"clicks":2}],
  "walkers": [{"id":"3f9c0a7d12b4e856","name":"Jack","look":{"body":"skeleton","cloak":"cape","face":"grin","eyes":"green","hat":"pumpkin"},"item":null,"walk":{"path":[{"x":40,"y":94}],"startedAt":1760900001000,"arrivesAt":1760900001000}}],
  "ready": false,
  "finale": null,
  "paused": false,
  "zombies": false,
  "now": 1760900001500
}
```

`ready` — сцена готова и ждёт модератора; `finale` — `null` вне финальной сцены, в ней `{step, reveal?}`;
`paused`, `zombies` — переключатели пульта (**запланировано**). `reveal` — числа вечера с начала раунда для экрана
разоблачения: `{clicks, jobs, burned, shot, lastPumpkin: {id, name} | null}` — принятые клики, выполненные задачи
перехода, сгоревшие клики (сверх порога и после готовности тыквы), сбитые летуны, кто добил последнюю тыкву.

`bush` — id куста, из которого взлетает летун (тогда `from` — его точка, а `depth` летуна — `depth` куста, см.
[`scenario.md`](scenario.md), «Летуны»; `depth` в `bushes` снимка — **реализовано**); `via` — контрольная точка дуги
(квадратичная кривая), без неё полёт прямой. `x`/`y` — проценты экрана (на узких экранах фронтенд растягивает раскладку тыкв по x от центра — `spreadX`
в `public/js/layout.js`; и у тыкв, и в траекториях летунов: `from`/`to` могут выходить за 0–100 — летун влетает из-за края); `size` — `s` / `m` / `l`; `sceneStages` — номер финальной сцены (длина списка
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
