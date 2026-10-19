# Hellowin — контракт между частями

Демо вебсокетов и каналов: одна страница, на ней элементы (тыквы и, в будущем, другие). Клик по элементу → над ним
всплывает emoji у ВСЕХ клиентов. Клики считаются в метриках RoadRunner (Prometheus → Grafana, публичный read-only дашборд).
Набралось достаточно кликов → переключается флаг «стадии», страница немного меняется у всех.

## Поток клика

1. Браузер подключён к Centrifugo по WebSocket (centrifuge-js). На connect сервер (ConnectService) автоматически
   подписывает клиента на канал `hellowin` — клиент сам `subscribe` не делает, слушает `centrifuge.on('publication', ...)`.
2. Клик → `centrifuge.rpc('click', {element: 'pumpkin-3'})`.
3. Centrifugo проксирует RPC в RoadRunner (плагин centrifuge, gRPC proxy) → PHP `RPCService`:
   - валидирует `element` по реестру элементов (неизвестный → RPC-ответ `{ok:false, error:'unknown element'}`);
   - `metrics->add('hellowin_clicks_total', 1, [elementType, elementId])`;
   - `GameState::registerClick()` — под локом RR (lock plugin) инкрементит счётчик в KV (RR kv, driver memory),
     при достижении порога ставит флаг стадии; лок отпускается;
   - публикует в канал `hellowin` через `BroadcastInterface` (драйвер centrifugo) событие `emoji`, а если стадия
     сменилась — ещё событие `state`;
   - RPC-ответ `{ok:true}`.
4. Все клиенты получают publication и рисуют emoji над элементом / меняют страницу.

## Сообщения в канале `hellowin` (JSON)

```json
{"type":"emoji","element":"pumpkin-3","emoji":"🎃","clicks":1234,"threshold":5000}
{"type":"state","stage":1,"clicks":5000,"threshold":5000}
```

- emoji выбирает сервер случайно из набора emoji этого элемента (конфиг).
- `stage` — целое; 0 — начальная, 1 — «порог достигнут». Движок допускает несколько порогов (список), стадия = число
  пройденных порогов. `threshold` — следующий порог (или последний, если пройдены все).

## Реестр элементов

Конфиг `app/config/hellowin.php`: список элементов `{id, type, x, y, emojis[]}` (x/y — проценты экрана), список
порогов, имя канала. Тыкв ~8–12, раскиданы по экрану.

## Страница

`GET /` (HomeController) рендерит view `home` с переменными:
- `elements` — массив `{id,type,x,y}`;
- `state` — `{stage, clicks, threshold}` (начальное состояние из KV, чтобы новый клиент сразу видел стадию);
- `centrifugoUrl` — URL WebSocket, из env `CENTRIFUGO_WS_URL`, по умолчанию относительный `/connection/websocket`
  (фронт строит ws(s)://host + путь).
Каждый элемент в разметке — элемент с `data-element="{id}"` (и `data-type`). `<body data-stage="N">` — стадия как атрибут, чтобы CSS мог менять страницу; на событие `state` фронт меняет атрибут.

## Инфраструктура (docker compose)

| Сервис | Образ | Порты внутри | Наружу |
|---|---|---|---|
| `proxy` | nginx | 80 | `${HTTP_PORT:-8080}` |
| `app` | свой Dockerfile: PHP 8.4 CLI + rr 3 | http 8080, centrifuge proxy 10001, metrics 2112, rpc 6001 (внутр.) | — |
| `centrifugo` | centrifugo/centrifugo v6 | 8000 (ws, /metrics), gRPC API 10000 | — |
| `prometheus` | prom/prometheus | 9090 | — |
| `grafana` | grafana/grafana | 3000 | через proxy `/grafana/` |

nginx: `/connection/` → centrifugo:8000 (с upgrade), `/grafana/` → grafana:3000, `/` → app:8080.
Prometheus скрейпит `app:2112` и `centrifugo:8000/metrics`.
Grafana: provisioning datasource + dashboard «Hellowin»; anonymous auth, роль Viewer (публичный read-only), админ —
из env. `serve_from_sub_path` для `/grafana/`.

`.rr.yaml` (владелец — backend): http `0.0.0.0:8080`; rpc `tcp://127.0.0.1:6001`; metrics `0.0.0.0:2112`;
centrifuge `proxy_address: tcp://0.0.0.0:10001`, `grpc_api_address: ${CENTRIFUGO_GRPC_API:-centrifugo:10000}`;
kv `hellowin: {driver: memory}`; lock — без конфига.
Centrifugo (владелец — infra): connect proxy + rpc proxy на `grpc://app:10001`, gRPC API на 10000,
`allowed_origins` из env, канал `hellowin` — клиентам публиковать в него нельзя (публикует только сервер).

## Метрики

- `hellowin_clicks_total` counter, labels `type`, `element`.
- `hellowin_stage` gauge — текущая стадия.
Плюс встроенные метрики RR и Centrifugo (подключения, сообщения) — для дашборда про вебсокеты.

## Владение файлами (для параллельной работы)

- backend: `app/src/**`, `app/config/**`, `.rr.yaml`, `composer.*`, `tests/**`, `.env.sample`.
- frontend: `app/views/**`, `public/**`.
- infra: `docker/**`, `docker-compose.yml`, `Dockerfile`, `.dockerignore`.
