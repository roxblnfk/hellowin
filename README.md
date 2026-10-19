# Hellowin

Демо вебсокетов и каналов на Spiral Framework, RoadRunner 3 и Centrifugo. Контекст для агентов — [`AGENTS.md`](AGENTS.md), спецификации — в
[`docs/spec/`](docs/spec/).

## Запуск

Всё поднимается через Docker Compose. Наружу публикуется только nginx (`proxy`):

| URL | Что |
|---|---|
| `http://localhost:8080/` | страница Hellowin |
| `http://localhost:8080/connection/websocket` | WebSocket Centrifugo |
| `http://localhost:8080/grafana/` | дашборд «Hellowin» (публичный, только чтение) |

1. Для локального запуска настраивать ничего не нужно. Переменные Compose (см. ниже) берутся из `.env`, пример —
   `.env.docker.example`. Режим приложения в контейнере от `.env` не зависит: `docker-compose.yml` всегда задаёт
   `APP_ENV=prod`, `DEBUG=false`.

2. Запустите:

   ```bash
   docker compose up -d --build
   docker compose ps          # все сервисы должны стать healthy
   ```

3. Откройте `http://localhost:8080/` и `http://localhost:8080/grafana/`.

Остановить: `docker compose down`. Данные Valkey, Prometheus и Grafana остаются в volumes; `docker compose down -v`
удалит и их.

Ритуал (раунд, сцена, стадии и клики тыкв) хранится в Valkey (сервис `valkey`, AOF на volume) и переживает рестарт `app`
и `valkey`. Правила — [`docs/spec/scenario.md`](docs/spec/scenario.md). Начать ритуал заново:
`docker compose exec app php app.php hellowin:reset`. Чтобы быстро пройти ритуал, перезапустите `app` с маленькими
числами: `HELLOWIN_CLICKS_PER_STAGE=2 HELLOWIN_SCENE_STAGE_MAX=1,2 docker compose up -d app`, после проверки —
`docker compose up -d app`. Ключи в Valkey: `docker compose exec valkey valkey-cli --scan`.

### Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `GF_SECURITY_ADMIN_PASSWORD` | `admin` | пароль администратора Grafana, применяется при первом запуске. Для публичного развёртывания задайте обязательно |
| `GF_SECURITY_ADMIN_USER` | `admin` | логин администратора Grafana |
| `HTTP_PORT` | `8080` | внешний порт nginx |
| `PUBLIC_URL` | `http://localhost:8080` | внешний адрес сайта; от него Grafana строит `root_url` |
| `CENTRIFUGO_ALLOWED_ORIGINS` | `http://localhost:8080 http://127.0.0.1:8080` | Origin через пробел, с которых браузер может подключаться к WebSocket |
| `HELLOWIN_CLICKS_PER_STAGE` | `5` | сколько кликов по тыкве переводят её в следующую стадию, целое > 0 |
| `HELLOWIN_SCENE_STAGE_MAX` | `4,6,8` | последняя стадия тыквы по сценам через запятую, накопительно и по возрастанию; длина списка — номер финальной сцены |
| `HELLOWIN_LEADERBOARD_INTERVAL` | `5000` | раз во сколько миллисекунд тикер собирает и рассылает таблицу лидеров |
| `HELLOWIN_LEADERBOARD_SIZE` | `10` | сколько игроков в таблице лидеров |
| `HELLOWIN_FLYER_INTERVAL` | `3000-8000` | пауза между запусками летунов, мс: диапазон (случайно внутри) или число |
| `HELLOWIN_FLYER_DURATION` | `9000,6500,4000` | время полёта по глубинам от дальней (0) к ближней, мс; длина списка — число глубин |
| `HELLOWIN_FLYER_POINTS` | `3,2,1` | очки за попадание по тем же глубинам; длина как у `HELLOWIN_FLYER_DURATION` |
| `HELLOWIN_FLYER_BUSH_CHANCE` | `0.4` | вероятность, что летун взлетает из куста, а не влетает из-за края |
| `HELLOWIN_FLYER_ARC_CHANCE` | `0.5` | вероятность полёта дугой |
| `HELLOWIN_FLYER_KINDS` | `duck,chicken` | виды летунов, сервер выбирает случайно |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | путь или полный URL WebSocket для фронта |
| `CENTRIFUGO_ADMIN_ENABLED` | `false` | admin UI Centrifugo (нужны ещё `CENTRIFUGO_ADMIN_PASSWORD` и `CENTRIFUGO_ADMIN_SECRET`) |
| `PROMETHEUS_RETENTION` | `15d` | срок хранения метрик |

Адрес Valkey приложение берёт из `VALKEY_ADDR` (RoadRunner KV, `host:port`) и `VALKEY_DSN` (хранилище
идемпотентности, по умолчанию `tcp://valkey:6379`); в `docker-compose.yml` они указывают на сервис `valkey`.

Чтобы открыть демо в интернет, опубликуйте порт `proxy` (или поставьте перед ним TLS-терминатор) и выставьте
`PUBLIC_URL=https://your.domain`, `CENTRIFUGO_ALLOWED_ORIGINS=https://your.domain` и свой `GF_SECURITY_ADMIN_PASSWORD`.

### Grafana

Анонимный доступ в роли Viewer: любой посетитель видит дашборд «Hellowin», но ничего не может редактировать. Дашборд
и datasource заведены через provisioning. Администратор входит через `/grafana/login`; сменить пароль потом —
`docker compose exec grafana grafana cli admin reset-admin-password <new>`.

### Режим разработки

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -V
```

Образ с dev-зависимостями, код смонтирован в `/app`, `APP_ENV=local`, `DEBUG=true`. На `127.0.0.1` открыты Centrifugo
(`:8000`) и Prometheus (`:9090`). После изменения PHP-кода: `docker compose exec app rr reset`.

### Тесты и проверки

```bash
composer install
composer bin all install
composer test     # Testo
composer psalm
composer cs:fix
```
