# Hellowin

Демо вебсокетов и каналов на Spiral Framework, RoadRunner 3 и Centrifugo. Архитектура — в
[`docs/architecture.md`](docs/architecture.md).

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

Остановить: `docker compose down`. Данные Prometheus и Grafana остаются в volumes; `docker compose down -v` удалит и их.

Счётчик кликов и стадия живут в памяти RoadRunner и сбрасываются при перезапуске `app`. Чтобы быстро увидеть смену
стадии, задайте маленькие пороги: `HELLOWIN_THRESHOLDS=5,10`.

### Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `GF_SECURITY_ADMIN_PASSWORD` | `admin` | пароль администратора Grafana, применяется при первом запуске. Для публичного развёртывания задайте обязательно |
| `GF_SECURITY_ADMIN_USER` | `admin` | логин администратора Grafana |
| `HTTP_PORT` | `8080` | внешний порт nginx |
| `PUBLIC_URL` | `http://localhost:8080` | внешний адрес сайта; от него Grafana строит `root_url` |
| `CENTRIFUGO_ALLOWED_ORIGINS` | `http://localhost:8080 http://127.0.0.1:8080` | Origin через пробел, с которых браузер может подключаться к WebSocket |
| `HELLOWIN_THRESHOLDS` | `100,500,2000` | пороги кликов для смены стадии, по возрастанию через запятую |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | путь или полный URL WebSocket для фронта |
| `CENTRIFUGO_ADMIN_ENABLED` | `false` | admin UI Centrifugo (нужны ещё `CENTRIFUGO_ADMIN_PASSWORD` и `CENTRIFUGO_ADMIN_SECRET`) |
| `PROMETHEUS_RETENTION` | `15d` | срок хранения метрик |

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
