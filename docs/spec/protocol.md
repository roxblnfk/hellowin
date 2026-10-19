# Протокол клиент ↔ сервер

Статус: подключение, RPC `click` и `state`, события `click`, `pumpkin`, `scene`, `reset`, снимок состояния
(со `stageMax` и `size`), игроки (`playerSecret` при подключении, RPC `register` и `me`, `player` в `click` и `pumpkin`) —
**реализовано** на backend; разметка главной на переменных снимка — у фронтенда; таблица лидеров (событие `leaderboard`, `top` в `me`) и летуны (RPC `shoot`, события `flyer`, `shot`,
`bushes` в снимке) — тоже.

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

## RPC

Вызов: `centrifuge.rpc(method, data)`. Ответ: `{ok: true, …}` или `{ok: false, error: <code>}`.

| Метод | Данные | Ответ | Статус |
|---|---|---|---|
| `click` | `{element}` | `{ok:true}` | реализовано |
| `state` | `{}` | `{ok:true, state: <Snapshot>}` — для пересинхронизации после переподключения | реализовано |
| `register` | `{name}` | `{ok:true, player: <Player>}` | реализовано |
| `me` | `{}` | `{ok:true, player: <Player>, top: [<Player>]}` — своя статистика и последняя таблица лидеров при загрузке; `player` — `null` у анонима и у игрока без действий | реализовано |
| `reset` | `{round}` — раунд, который клиент видит сейчас | `{ok:true, round}` — новый раунд; повторный вызов с тем же `round` сброс не повторяет (ключ `reset:{round}`), а возвращает текущий раунд | запланировано |
| `shoot` | `{flyer}` | `{ok:true, hit:true, points}` или `{ok:true, hit:false}` (уже сбит, улетел или такого нет) | реализовано |

Коды ошибок: `unknown method`, `unknown element`, `busy` (не удалось взять лок), `internal error`; для `register` —
`invalid name` и `anonymous` (подключение без секрета).

`Player` — `{id, name, clicks, finished, flyers, points}`: `id` — публичный id, `name` — `null` до `register`.

```json
{"ok":true,"player":{"id":"3f9c0a7d12b4e856","name":"Jack","clicks":42,"finished":3,"flyers":0,"points":0}}
```

## События канала `hellowin`

Каждое событие несёт `type` и `round`. Событие с `round`, отличным от текущего у клиента, означает пропущенный сброс:
клиент запрашивает `state`.

| `type` | Поля | Когда |
|---|---|---|
| `click` | `element`, `emoji`, `clicks`, `threshold`, `stage`, `player?` | каждый принятый клик |
| `pumpkin` | `element`, `stage`, `player?` | тыква перешла в новую стадию (ровно одно событие на переход) |
| `scene` | `scene`, `stageMax` | сцена сменилась (ровно одно событие на смену) |
| `reset` | — | сброс; новый `round` в самом событии |
| `flyer` | `flyer`, `kind`, `depth`, `bush?`, `from:{x,y}`, `via?:{x,y}`, `to:{x,y}`, `duration`, `points`, `launchedAt`, `expiresAt` | запущен летун |
| `shot` | `flyer`, `points`, `player?` | летун сбит, ровно одно событие на летуна |
| `leaderboard` | `top: [<Player>]` — лучшие первыми | раз в `HELLOWIN_LEADERBOARD_INTERVAL` мс, даже без изменений |

`player` — `{id, name}` игрока, совершившего действие (`name` может быть `null`); у анонимных действий поля нет.
`emoji` сервер выбирает из набора emoji элемента — фронтенд
может использовать его или игнорировать.

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
{"type":"scene","round":1,"scene":1,"stageMax":7}
{"type":"reset","round":2}
{"type":"leaderboard","round":1,"top":[{"id":"3f9c0a7d12b4e856","name":"Jack","clicks":42,"finished":3,"flyers":2,"points":5}]}
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
  "bushes": [{"id":"bush-1","x":4,"y":66}],
  "elements": [{"id":"pumpkin-1","type":"pumpkin","x":50,"y":20,"size":"l","stage":0,"clicks":2}],
  "now": 1760900001500
}
```

`bush` — id куста, из которого взлетает летун (тогда `from` — его точка); `via` — контрольная точка дуги
(квадратичная кривая), без неё полёт прямой. `x`/`y` — проценты экрана (на узких экранах фронтенд растягивает раскладку тыкв по x от центра — `spreadX`
в `public/js/layout.js`; и у тыкв, и в траекториях летунов: `from`/`to` могут выходить за 0–100 — летун влетает из-за края); `size` — `s` / `m` / `l`; `sceneStages` — номер финальной сцены (длина списка
`HELLOWIN_SCENE_STAGE_MAX`); `stageMax` — последняя стадия тыквы в текущей сцене, в финальной — та же, что в сцене
перед ней (см. [`scenario.md`](scenario.md)). `clicks` — клики с момента перехода тыквы в текущую стадию. Страница
`GET /` отдаёт снимок в шаблон `home` (переменные — см. [`frontend.md`](frontend.md)) вместе с `centrifugoUrl`.

### Время летунов

`launchedAt` и `expiresAt` в событии `flyer` — серверное Unix-время в мс; `now` в снимке — серверное время его сборки.
Клиент считает смещение часов `offset = now − Date.now()` (по снимку страницы или RPC `state`) и **не рисует летуна**,
если `Date.now() + offset >= expiresAt`; уже нарисованного убирает к `expiresAt`. Так свёрнутая вкладка, получившая
пачку отложенных публикаций при разворачивании, не выпускает птиц, которых уже нельзя сбить (сервер принимает
выстрел не позже `expiresAt` + 500 мс). Если смещение неизвестно — считать его 0.
