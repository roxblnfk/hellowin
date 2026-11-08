# Hellowin

Хеллоуин-демо вебсокетов и каналов на Spiral Framework 3 + RoadRunner 3 + Centrifugo v6. Сюжет: RoadRunner «умер»,
посетители кликами по тыквам проводят ритуал воскрешения; все видят действия всех в реальном времени, метрики уходят
в публичную Grafana. Цель проекта — показать механику (каналы, RPC через WebSocket, KV, локи, очередь,
идемпотентность, метрики), поэтому каждое решение должно быть наглядным и объяснимым на странице «Как это работает».

## Спецификации

Контракт между частями — `docs/spec/`. Читай нужную до изменений в своей зоне и обновляй её в том же коммите,
если меняешь поведение:

- [`scenario.md`](docs/spec/scenario.md) — сюжет и правила игры: сцена, стадии тыкв, раунды, сброс, игроки.
- [`protocol.md`](docs/spec/protocol.md) — клиент ↔ сервер: подключение, RPC, события канала, начальное состояние страницы.
- [`engine.md`](docs/spec/engine.md) — backend: хранилище, локи, очередь, ключи идемпотентности, метрики.
- [`players.md`](docs/spec/players.md) — идентификация игроков и статистика.
- [`frontend.md`](docs/spec/frontend.md) — зона фронтенда: файлы, правила рендеринга, DOM-хуки, проверка.
- [`infra.md`](docs/spec/infra.md) — docker compose, порты, HTTP в RoadRunner, Prometheus, Grafana.

У каждой спеки есть статус разделов (реализовано / в работе / запланировано). Расхождение кода и спеки — баг одной
из сторон: выясни, какой, а не подгоняй молча.

## Карта репозитория

| Путь | Что |
|---|---|
| `app/src/Domain/` | доменная модель игры, без зависимостей от транспорта |
| `app/src/Application/` | бутлоадеры, конфиги, сервисы приложения (обработка клика, публикация, метрики) |
| `app/src/Endpoint/Centrifugo/` | обработчики connect / subscribe / RPC от Centrifugo |
| `app/src/Endpoint/Web/` | HTTP-контроллеры (`/`, `/how-it-works`) |
| `app/config/` | конфиги Spiral; `hellowin.php` — элементы, канал, пороги из env |
| `app/views/`, `public/` | шаблоны Stempler, CSS, JS — зона фронтенда |
| `.rr.yaml` | плагины RoadRunner: http, centrifuge, kv, lock, jobs, metrics |
| `docker/`, `docker-compose*.yml`, `Dockerfile` | инфраструктура |
| `tests/` | Testo + spiral/testing 4; `Unit/` и `Feature/` |
| `tools/` | psalm и php-cs-fixer как bamarni-bin инструменты |

Зоны владения при параллельной работе: backend — `app/src`, `app/config`, `.rr.yaml`, `composer.*`, `tests`;
frontend — `app/views`, `public`; infra — `docker*`, `Dockerfile`. Изменение чужой зоны согласуй через спеку.

## Команды

- Тесты, статанализ, стиль: `composer test`, `composer psalm`, `composer cs:diff` / `composer cs:fix`.
- Глобальный composer-плагин `llm/skills` ждёт ввода: любой composer-вызов запускай с `< /dev/null`.
- В Git Bash `php` может не быть в PATH — запускай PHP-команды через PowerShell.
- Стек: `docker compose up -d --build` → `http://localhost:8080/`, Grafana — `http://localhost:3000/`, WebSocket —
  `ws://localhost:8000/connection/websocket`. Прод-образ копирует код при
  сборке: после правок `docker compose up -d --build app`. Для правок без пересборки — dev-override из README.
- Скриншоты страниц: headless Edge
  (`"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" --headless=new --screenshot=… --window-size=W,H URL`).

## Правила

- **Коммиты** — скилл `git-commit`: conventional commits, тело только «почему», абзац одной строкой, сообщение через
  `git commit -F <file>`, трейлер `Assisted-By: <модель>`. Коммиты подписываются GPG; при таймауте подписи повтори
  один раз, затем коммить без подписи (`git -c commit.gpgsign=false commit …`) и назови в отчёте неподписанные
  коммиты — пользователь переподпишет их сам. Опубликованную историю не переписывай.
- **Тесты** пишутся на Testo (скиллы `spiral-testing`, `testo-write-tests`); новое поведение приходит с тестом.
- **Зависимости в dev-версиях** — осознанные: `spiral/roadrunner-bridge ^5.0` (5.x-dev по minimum-stability), `spiral/testing ^4.0@dev`; пакеты
  RoadRunner — новые имена `roadrunner/*`. Не «чини» их на стабильные без задачи.
- **Числа игры** (пороги, количество стадий) задаются только env; в доках и текстах формулируй через имя переменной.
