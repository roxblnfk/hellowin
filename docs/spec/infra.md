# Инфраструктура

Статус: **реализовано**; сервис `valkey` и очередь RR jobs — **в работе** вместе с движком.

## Сервисы (`docker-compose.yml`)

| Сервис | Порты внутри | Роль |
|---|---|---|
| `proxy` (nginx) | 80 → наружу `${HTTP_PORT:-8080}` | единственная точка входа |
| `app` (Dockerfile: PHP 8.4 + RoadRunner 3) | http 8080, centrifuge proxy 10001, metrics 2112, rpc 6001 (локально) | приложение |
| `centrifugo` (v6) | 8000 (WebSocket, `/metrics`), gRPC API 10000 | WebSocket-соединения и каналы |
| `valkey` | 6379 | состояние игры (RR KV) и ключи идемпотентности; AOF + volume |
| `prometheus` | 9090 | сбор метрик |
| `grafana` | 3000 → через nginx `/grafana/` | публичный дашборд |

nginx: `/connection/` → `centrifugo:8000` (upgrade), `/grafana/` → `grafana:3000`, `/` → `app:8080`.

## Связи

- Centrifugo → RoadRunner: connect и RPC proxy по gRPC на `app:10001`. Subscribe proxy не включён: подписки
  ограничивает конфиг Centrifugo (канал `hellowin` без namespace, клиентам — ни publish, ни subscribe).
- RoadRunner → Centrifugo: публикация через gRPC API `centrifugo:10000` (`CENTRIFUGO_GRPC_API`).
- PHP-воркеры → плагины RoadRunner (kv, lock, jobs, metrics, centrifuge) по goridge RPC `127.0.0.1:6001`.
- HTTP-middleware RoadRunner выполняются слева направо: `http_metrics`, `gzip`, `static` — `gzip` перед `static`,
  чтобы сжималась и статика.
- Prometheus скрейпит `app:2112` и `centrifugo:8000/metrics` раз в 5 с.
- Grafana: datasource и дашборд «Hellowin» через provisioning (`docker/grafana/`), анонимы — роль Viewer (публичный
  read-only), дашборд домашний; админ — `GF_SECURITY_ADMIN_PASSWORD` (по умолчанию `admin`, для публичного
  развёртывания задать).

## Режимы

- Прод (`docker-compose.yml`): `APP_ENV=prod`, `DEBUG=false` заданы жёстко и не зависят от `.env`.
- Dev (`docker-compose.dev.yml`): код смонтирован, `APP_ENV=local`, `DEBUG=true`, Centrifugo и Prometheus открыты на
  `127.0.0.1`; после правок PHP — `docker compose exec app rr reset`.
- Состояние локов и очереди — в памяти RoadRunner: `app` в одном экземпляре.

## E2E без браузера

Node-скрипт с двумя клиентами centrifuge (`centrifuge` + `ws`, заголовок `Origin` из `CENTRIFUGO_ALLOWED_ORIGINS`)
подключается через `http://localhost:8080/connection/websocket`, вызывает RPC и проверяет события второго клиента.
Для быстрых проверок порогов поднимай `app` с маленькими значениями env и возвращай дефолты после.
