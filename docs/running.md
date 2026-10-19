# Запуск

Всё поднимается одной командой через Docker Compose. Наружу публикуется только nginx (`proxy`):

| URL | Что |
|---|---|
| `http://localhost:8080/` | страница Hellowin (RoadRunner, `app:8080`) |
| `http://localhost:8080/connection/websocket` | WebSocket Centrifugo |
| `http://localhost:8080/grafana/` | дашборд «Hellowin» (публичный, только чтение) |

Prometheus, Centrifugo (gRPC API, admin UI, `/metrics`) и порты RoadRunner (10001, 2112, 6001) доступны только внутри
сети compose.

## Быстрый старт

1. Создайте `.env` в корне репозитория из примера и задайте в нём `GF_SECURITY_ADMIN_PASSWORD` (Compose читает
   `.env` для подстановки переменных; в контейнер `app` попадают только переменные, явно перечисленные в
   `docker-compose.yml`):

   ```bash
   cp .env.docker.example .env
   ```

   Если `.env` уже занят под локальный запуск без Docker (`.env.sample`), держите переменные Compose в отдельном
   файле и передавайте его в каждую команду: `docker compose --env-file .env.docker up -d --build`. Режим
   приложения в контейнере от `.env` не зависит: `docker-compose.yml` всегда задаёт `APP_ENV=prod`, `DEBUG=false`.

   Ключ шифрования можно сгенерировать собранным образом:

   ```bash
   docker compose build app
   docker compose run --rm --no-deps app php app.php encrypt:key
   ```

2. Запустите:

   ```bash
   docker compose up -d --build
   docker compose ps          # все сервисы должны стать healthy
   ```

3. Откройте `http://localhost:8080/` и `http://localhost:8080/grafana/`.

Остановить: `docker compose down` (данные Prometheus/Grafana остаются в volumes `hellowin_prometheus-data` и
`hellowin_grafana-data`; `docker compose down -v` удалит и их).

## Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `HTTP_PORT` | `8080` | внешний порт nginx |
| `PUBLIC_URL` | `http://localhost:8080` | внешний адрес сайта; Grafana строит от него `root_url` (`$PUBLIC_URL/grafana/`) |
| `CENTRIFUGO_ALLOWED_ORIGINS` | `http://localhost:8080 http://127.0.0.1:8080` | список Origin через пробел, с которых браузер может подключаться к WebSocket. При смене порта/домена поменяйте |
| `GF_SECURITY_ADMIN_PASSWORD` | — (обязательна) | пароль `admin` в Grafana |
| `GF_SECURITY_ADMIN_USER` | `admin` | логин администратора Grafana |
| `HELLOWIN_THRESHOLDS` | `100,500,2000` | пороги кликов для смены стадии, по возрастанию через запятую. Счётчик живёт в памяти RoadRunner и сбрасывается при перезапуске `app` |
| `ENCRYPTER_KEY` | пусто | ключ шифрования Spiral |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | путь (или полный URL) WebSocket для фронта |
| `CENTRIFUGO_ADMIN_ENABLED` | `false` | включить admin UI Centrifugo (нужны ещё `CENTRIFUGO_ADMIN_PASSWORD` и `CENTRIFUGO_ADMIN_SECRET`) |
| `PROMETHEUS_RETENTION` | `15d` | срок хранения метрик |

## Публичная Grafana

Grafana работает под `/grafana/` (`serve_from_sub_path`) с анонимным доступом в роли **Viewer**: любой посетитель
видит дашборд «Hellowin» (он же домашний), но не может ничего редактировать, а дашборд и datasource заведены через
provisioning и в UI не сохраняются. Администратор входит через `/grafana/login` с `admin` /
`$GF_SECURITY_ADMIN_PASSWORD`. Пароль применяется при первом запуске (дальше он хранится в volume `grafana-data`);
сменить потом — через UI или `docker compose exec grafana grafana cli admin reset-admin-password <new>`.

Чтобы открыть демо в интернет, достаточно опубликовать порт `proxy` (или поставить перед ним TLS-терминатор) и
выставить `PUBLIC_URL=https://your.domain` и `CENTRIFUGO_ALLOWED_ORIGINS=https://your.domain`.

## Режим разработки

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -V
```

Override собирает образ с dev-зависимостями, монтирует код в `/app`, включает `APP_ENV=local`/`DEBUG=true` и
публикует на `127.0.0.1` Centrifugo (`:8000`, admin UI при `CENTRIFUGO_ADMIN_ENABLED=true`) и Prometheus (`:9090`).
После изменения PHP-кода: `docker compose exec app rr reset`.

## Состав

| Сервис | Образ | Конфиг |
|---|---|---|
| `proxy` | `nginx:1.31-alpine` | `docker/nginx/default.conf` |
| `app` | `Dockerfile` (PHP 8.4 CLI + RoadRunner 3.0.0) | `.rr.yaml` |
| `centrifugo` | `centrifugo/centrifugo:v6.9.7` | `docker/centrifugo/config.json` |
| `prometheus` | `prom/prometheus:v3.15.0` | `docker/prometheus/prometheus.yml` (scrape каждые 5s) |
| `grafana` | `grafana/grafana:13.2.4` | `docker/grafana/` (provisioning + дашборд) |
