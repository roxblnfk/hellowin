# Протокол клиент ↔ сервер

Статус: подключение, RPC `click` и `state`, события `click`, `pumpkin`, `scene`, `reset`, снимок состояния
(со `stageMax` и `size`) — **реализовано** на backend; разметка главной на переменных снимка — у фронтенда; всё про
игроков — **запланировано**.

Все сообщения — JSON. Клиент — centrifuge-js (CDN, версия зафиксирована в `app/views/home.dark.php`).

## Подключение

1. Браузер открывает WebSocket к Centrifugo: URL из `data-centrifugo-url` (по умолчанию `/connection/websocket`,
   относительный путь достраивается до `ws(s)://<host>`).
2. Centrifugo вызывает connect proxy → `ConnectService`. Сервер сам подписывает клиента на канал `hellowin`
   (server-side subscription): клиент **не** вызывает `subscribe`, а слушает `centrifuge.on('publication', …)`.
3. Клиентам запрещено публиковать в канал и подписываться на другие каналы.
4. Запланировано: в `data` подключения браузер передаёт `{playerSecret}` — см. [`players.md`](players.md).

## RPC

Вызов: `centrifuge.rpc(method, data)`. Ответ: `{ok: true, …}` или `{ok: false, error: <code>}`.

| Метод | Данные | Ответ | Статус |
|---|---|---|---|
| `click` | `{element}` | `{ok:true}` | реализовано |
| `state` | `{}` | `{ok:true, state: <Snapshot>}` — для пересинхронизации после переподключения | реализовано |
| `register` | `{name}` | `{ok:true, player:{id,name}}` | запланировано |
| `shoot` | `{flyer}` | `{ok:true, hit:true, points}` или `{ok:true, hit:false}` (уже сбит или улетел) | запланировано |

Коды ошибок: `unknown method`, `unknown element`, `busy` (не удалось взять лок), `internal error`; для `register` —
`invalid name`.

## События канала `hellowin`

Каждое событие несёт `type` и `round`. Событие с `round`, отличным от текущего у клиента, означает пропущенный сброс:
клиент запрашивает `state`.

| `type` | Поля | Когда |
|---|---|---|
| `click` | `element`, `emoji`, `clicks`, `threshold`, `stage`, `player?` | каждый принятый клик |
| `pumpkin` | `element`, `stage`, `player?` | тыква перешла в новую стадию (ровно одно событие на переход) |
| `scene` | `scene`, `stageMax` | сцена сменилась (ровно одно событие на смену) |
| `reset` | — | сброс; новый `round` в самом событии |
| `flyer` | `flyer`, `kind`, `depth`, `bush?`, `from:{x,y}`, `via?:{x,y}`, `to:{x,y}`, `duration`, `points` | запущен летун (запланировано) |
| `shot` | `flyer`, `points`, `player?` | летун сбит, ровно одно событие на летуна (запланировано) |
| `leaderboard` | `top: [{id, name, clicks, finished}]` | периодически; запланировано |

`player` — `{id, name}` публичного игрока (запланировано). `emoji` сервер выбирает из набора emoji элемента — фронтенд
может использовать его или игнорировать.

Примеры:

```json
{"type":"click","round":1,"element":"pumpkin-3","emoji":"🕸️","clicks":4,"threshold":5,"stage":0}
{"type":"pumpkin","round":1,"element":"pumpkin-3","stage":1}
{"type":"scene","round":1,"scene":1,"stageMax":7}
{"type":"reset","round":2}
{"type":"flyer","round":1,"flyer":"f-812","kind":"duck","depth":0,"from":{"x":-5,"y":18},"to":{"x":105,"y":10},"duration":9000,"points":3}
{"type":"flyer","round":1,"flyer":"f-813","kind":"duck","depth":2,"bush":"bush-2","from":{"x":12,"y":70},"via":{"x":30,"y":20},"to":{"x":70,"y":-10},"duration":3500,"points":1}
{"type":"shot","round":1,"flyer":"f-812","points":3}
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
  "elements": [{"id":"pumpkin-1","type":"pumpkin","x":50,"y":20,"size":"l","stage":0,"clicks":2}]
}
```

`bush` — id куста, из которого взлетает летун (тогда `from` — его точка); `via` — контрольная точка дуги
(квадратичная кривая), без неё полёт прямой. `x`/`y` — проценты экрана (и у тыкв, и в траекториях летунов: `from`/`to` могут выходить за 0–100 — летун влетает из-за края); `size` — `s` / `m` / `l`; `sceneStages` — номер финальной сцены (длина списка
`HELLOWIN_SCENE_STAGE_MAX`); `stageMax` — последняя стадия тыквы в текущей сцене, в финальной — та же, что в сцене
перед ней (см. [`scenario.md`](scenario.md)). `clicks` — клики с момента перехода тыквы в текущую стадию. Страница
`GET /` отдаёт снимок в шаблон `home` (переменные — см. [`frontend.md`](frontend.md)) вместе с `centrifugoUrl`.
