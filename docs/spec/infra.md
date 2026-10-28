# Инфраструктура

Статус: **реализовано**, включая сервис `valkey`, очередь RR jobs, процесс `ticker` и стек без nginx: HTTP
отдаёт сам RoadRunner.

## Сервисы (`docker-compose.yml`)

| Сервис | Порты внутри | Роль |
|---|---|---|
| `app` (Dockerfile: PHP 8.4 + RoadRunner 3) | http 8080 → наружу `${HTTP_PORT:-8080}`; centrifuge proxy 10001, metrics 2112, rpc 6001 (локально) | приложение: страницы и статика |
| `centrifugo` (v6) | 8000 (WebSocket) → наружу `${CENTRIFUGO_PORT:-8000}`; внутренний 9000 (`/metrics`, `/health`); gRPC API 10000 | WebSocket-соединения и каналы |
| `valkey` | 6379 | состояние игры (RR KV) и ключи идемпотентности; AOF + volume |
| `prometheus` | 9090 | сбор метрик |
| `grafana` | 3000 → наружу `${GRAFANA_PORT:-3000}`, без sub-path | публичный дашборд |

Наружу три порта: сайт `http://localhost:8080/`, WebSocket `ws://localhost:8000/connection/websocket`, Grafana
`http://localhost:3000/`. Реверс-прокси нет: RoadRunner им не является, поэтому Centrifugo и Grafana публикуются
своими портами, а страница ссылается на них через `CENTRIFUGO_WS_URL` и `GRAFANA_URL` вида `:<порт>/путь` — сервер
достраивает их хостом, по которому открыта страница (`localhost`, `127.0.0.1`, адрес в LAN). Браузер подключается к
Centrifugo с Origin страницы (`http://localhost:8080`), он и должен быть в `CENTRIFUGO_ALLOWED_ORIGINS`. Grafana
строит свои ссылки от `GRAFANA_ROOT_URL` (по умолчанию `http://localhost:3000/`). У Centrifugo `/metrics` и
`/health` вынесены на внутренний порт 9000 (`http_server.internal_port`), чтобы публикация 8000 не открывала их.

## Связи

- Centrifugo → RoadRunner: connect и RPC proxy по gRPC на `app:10001`. Subscribe proxy не включён: подписки
  ограничивает конфиг Centrifugo (канал `hellowin` без namespace, клиентам — ни publish, ни subscribe). У канала
  включён presence (клиентам он не виден): по нему тикер узнаёт, кто подключён ([`engine.md`](engine.md), «Фигурки на
  поле»).
- RoadRunner → Centrifugo: публикация и запрос presence через gRPC API `centrifugo:10000` (`CENTRIFUGO_GRPC_API`).
- PHP-воркеры → плагины RoadRunner (kv, lock, jobs, metrics, centrifuge) по goridge RPC `127.0.0.1:6001`.
- RoadRunner KV (драйвер `redis`) → `valkey:6379` (`VALKEY_ADDR`); PHP → `valkey` напрямую только для ключей
  идемпотентности (predis, `VALKEY_DSN`). `app` стартует после healthy `valkey`.
- Пулы воркеров: http (2), centrifuge (`RR_CENTRIFUGE_WORKERS`, по умолчанию 4: каждый клик, выстрел и ход зала ждёт одного из них, около двух на ядро), jobs (2, pipeline `transitions`). Отдельно плагин `service` держит один
  долгоживущий процесс `ticker` (`php app.php hellowin:ticker`): летуны и топ стрелков, см. `engine.md`.
- Prometheus скрейпит `app:2112` и `centrifugo:9000/metrics` раз в 5 с.
- Grafana: datasource и дашборд «Hellowin» через provisioning (`docker/grafana/`), анонимы — роль Viewer (публичный
  read-only), дашборд домашний. Входа по паролю нет вовсе: ни формы (`GF_AUTH_DISABLE_LOGIN_FORM`, по умолчанию
  `true`), ни basic auth на API (`GF_AUTH_BASIC_ENABLED: false`) — пароль админа по умолчанию (`admin`) снаружи не
  открывает ничего. Чтобы зайти админом, в `.env` на время `GF_AUTH_DISABLE_LOGIN_FORM=false` и сильный
  `GF_SECURITY_ADMIN_PASSWORD`; пароль применяется только при первом старте тома `grafana-data`, потом —
  `grafana cli admin reset-admin-password`. Разделы дашборда: игра (клики, сцена, раунд), переходы (задачи в очереди против выполненных
  по `kind`, «отсечённые дубли» = pushed − transitions, стадии тыкв), игроки (и «Beer drunk, L» —
  `max(hellowin_beer_litres)`, литры с одним знаком, плюс кружки в минуту по `place`), летуны (запущено / сбито / улетело по
  глубине, процент попаданий), Centrifugo, RPC в PHP (вызовы в секунду по методу, ошибки по коду, p95 времени по
  методу), RoadRunner (и отказы лимитов: RPC по группам, HTTP 429).

## HTTP в RoadRunner

RoadRunner (3.0) смотрит в браузер сам. Middleware оборачивают друг друга слева направо:
`http_metrics`, `headers`, `gzip`, `static`, `rate_limiter`.

- `http_metrics` первым — считает все ответы, включая 429 лимитера.
- `headers` — ко всем ответам, и статике, и PHP: `X-Powered-By: RoadRunner 3` (страницы PHP добавляют второй — `Spiral 3`, `PoweredByMiddleware`), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (камера, микрофон, геолокация — нет) и
  `Content-Security-Policy` только из директив, не ломающих страницы: `frame-ancestors 'none'; base-uri 'self';
  object-src 'none'; form-action 'self'`. `script-src`/`style-src` нет: centrifuge-js и Pixi грузятся с CDN, SVG
  страницы «Как это работает» стилизован inline. `nosniff` требует верных MIME: `static` берёт их по расширению
  (`.js` — `text/javascript`, это нужно module worker'ам звука).
- `gzip` перед `static` — сжимает и статику, и HTML от PHP.
- `static` — `public/`, кроме `.php` и `.htaccess`; `calculate_etag` (сильный ETag по содержимому) и
  `Cache-Control: no-cache`: имена файлов без хеша, поэтому браузер каждый раз сверяет ETag и получает 304, пока файл
  не изменился. Несуществующий файл уходит дальше, в PHP (404).
  Язык страницы статике не важен: `Vary: Cookie, Accept-Language` ставит только PHP ([`engine.md`](engine.md), «Язык
  страниц»).
- `rate_limiter` последним — token bucket по IP клиента только для запросов в PHP (`rate: 5` в секунду, `burst: 20`);
  сверх — 429 с `Retry-After`, до PHP запрос не доходит. Вёдра — в памяти процесса RoadRunner. `proxy_ip_parser` не
  нужен: прокси нет, IP — адрес TCP-соединения. В Docker Desktop все запросы с хоста приходят с одного адреса шлюза и
  делят ведро; на Linux без userland-proxy видны настоящие IP. Если перед стеком встанет прокси или TLS-терминатор,
  перед `rate_limiter` нужен `proxy_ip_parser` с доверенными подсетями прокси.
- `max_request_size: 1` (МБ): приложение ничего не принимает телом.
- Пул http: `allocate_timeout: 10s`, `max_queue_size: 100` — запрос не ждёт воркера бесконечно, очередь не растёт без
  края; `supervisor.exec_ttl: 30s` — зависший запрос убивает воркер. Пул centrifuge: `allocate_timeout: 3s` — столько
  же Centrifugo ждёт ответа proxy.

Лимиты RPC (основная нагрузка) — в PHP, см. [`engine.md`](engine.md), «Лимиты запросов». Centrifugo OSS ограничивает
подключения: `client.user_connection_limit: 10` на игрока и `client.connection_rate_limit: 100` новых в секунду на
узел; лимиты команд в Centrifugo — только PRO.

## Режимы

- Прод (`docker-compose.yml`): `APP_ENV=prod`, `DEBUG=false` заданы жёстко и не зависят от `.env`.
- Dev (`docker-compose.dev.yml`): код смонтирован, `APP_ENV=local`, `DEBUG=true`, Prometheus открыт на
  `127.0.0.1:9090`; после правок PHP и каталогов `app/locale` — `docker compose exec app rr reset`
  (каталоги переводчик читает один раз на воркер).
- Состояние локов и очереди — в памяти RoadRunner: `app` в одном экземпляре.

## Деплой

Push в `master` запускает `.github/workflows/deploy.yml`: CI отдаёт `git archive HEAD` по SSH на сервер
(`DEPLOY_HOST`). У ключа CI (секрет `DEPLOY_SSH_KEY`) в `authorized_keys` стоят `restrict` и forced command
`/usr/local/bin/hellowin-deploy`, то есть копия `docker/deploy.sh`. Поэтому ключ умеет только деплоить, а серверу
не нужен доступ к GitHub. Скрипт разворачивает архив в `/opt/hellowin` через rsync, оставляя на месте `.env` сервера
(порты, домен, `CENTRIFUGO_ALLOWED_ORIGINS`, пароли), выполняет `docker compose up -d --build` и до двух минут ждёт,
пока все сервисы станут healthy. Centrifugo, Prometheus и Grafana он перезапускает, если изменились их конфиги в
`docker/`. Себя, `docker/certs.sh` и таймер из `docker/systemd/` скрипт ставит на сервер из каждого релиза, поэтому
правка `docker/deploy.sh` действует со следующего деплоя.

## HTTPS на домене

Включается в `.env` сервера: `COMPOSE_FILE=docker-compose.yml:docker-compose.tls.yml`, `DOMAIN`, `ACME_EMAIL`,
`CENTRIFUGO_ALLOWED_ORIGINS=https://<домен>`, `GRAFANA_ROOT_URL=https://<домен>:3000/`.

- TLS сайта терминирует сам RoadRunner: `app` запускается с `.rr.tls.yaml`. В репозитории это фрагмент
  (`http.ssl` и плагин `status`), а при сборке образа Dockerfile накладывает его на `.rr.yaml`. Собственный `include`
  RoadRunner (как и `-o`) заменяет секцию `http` целиком, а не сливает её, поэтому слияние делается при сборке.
  HTTPS слушает 443, HTTP на `${HTTP_PORT}` только редиректит на него (308). Чтобы www-data мог занять 443, контейнеру
  выставлен sysctl `net.ipv4.ip_unprivileged_port_start=0`. Healthcheck идёт через `status`
  (`127.0.0.1:2114/health?plugin=http`): страницы теперь за редиректом.
- Сертификат Let's Encrypt RoadRunner получает при старте и продлевает сам (certmagic, проверка tls-alpn-01 на 443;
  http-01 на 80 съел бы редирект). Хранится в volume `acme-data`, поэтому перезапуск сертификат не перевыпускает.
  Плагин http в RoadRunner 3.0 заказывает через issuer certmagic по умолчанию: production и без email, что бы ни
  стояло в `use_production_endpoint` и `email`, поэтому staging через конфиг не включить.
- Centrifugo (`wss` на 8000, `tls_external`: внутренний порт 9000 остаётся HTTP) и Grafana (HTTPS на 3000) берут
  тот же сертификат. Копию в `/opt/hellowin-tls` кладёт `docker/certs.sh`: его запускает деплой и раз в сутки
  `hellowin-certs.timer`. Если сертификат изменился, скрипт перезапускает оба сервиса, потому что файлы они читают
  только на старте.

## E2E без браузера

Node-скрипт с двумя клиентами centrifuge (`centrifuge` + `ws`, заголовок `Origin` из `CENTRIFUGO_ALLOWED_ORIGINS`)
подключается через `ws://localhost:8000/connection/websocket`, вызывает RPC и проверяет события второго клиента.
Для быстрых проверок порогов поднимай `app` с маленькими значениями env и возвращай дефолты после.
