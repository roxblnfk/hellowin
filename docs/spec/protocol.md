# Протокол клиент ↔ сервер

Статус: подключение и RPC `click` — **реализовано**; события новой модели (`click`, `pumpkin`, `scene`, `reset`),
RPC `state` и начальное состояние со сценой и стадиями тыкв — **в работе**; `stageMax` и `size` — **запланировано**; всё про игроков — **запланировано**.

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
| `state` | `{}` | `{ok:true, state: <Snapshot>}` — для пересинхронизации после переподключения | в работе |
| `register` | `{name}` | `{ok:true, player:{id,name}}` | запланировано |

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
| `leaderboard` | `top: [{id, name, clicks, finished}]` | периодически; запланировано |

`player` — `{id, name}` публичного игрока (запланировано). `emoji` сервер выбирает из набора emoji элемента — фронтенд
может использовать его или игнорировать.

Примеры:

```json
{"type":"click","round":1,"element":"pumpkin-3","emoji":"🕸️","clicks":4,"threshold":5,"stage":0}
{"type":"pumpkin","round":1,"element":"pumpkin-3","stage":1}
{"type":"scene","round":1,"scene":1,"stageMax":7}
{"type":"reset","round":2}
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
  "elements": [{"id":"pumpkin-1","type":"pumpkin","x":50,"y":20,"size":"l","stage":0,"clicks":2}]
}
```

`x`/`y` — проценты экрана; `size` — `s` / `m` / `l`; `stageMax` — последняя стадия тыквы в текущей сцене
(см. [`scenario.md`](scenario.md)). Страница `GET /` отдаёт снимок в шаблон `home` (переменные — см.
[`frontend.md`](frontend.md)) вместе с `centrifugoUrl`.
