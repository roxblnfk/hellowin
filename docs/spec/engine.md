# Движок (backend)

Статус: **реализовано** — сцена и стадии тыкв, очередь переходов с идемпотентностью, сброс, RPC `state`, метрики,
игроки и их статистика, периодический процесс (тикер) и таблица лидеров. Кто запускает сброс и летуны —
**запланировано** ([`scenario.md`](scenario.md), [`players.md`](players.md)).

## Классы

| Что | Где |
|---|---|
| Правило переходов (интерфейс + реализация) | `Domain/Ritual/RitualPolicy`, `Domain/Ritual/LockstepPolicy` |
| Состояние в KV и локи | `Domain/Ritual/RitualStore` |
| Клик | `Application/Hellowin/ClickHandler` (из `Endpoint/Centrifugo/RPCService`) |
| Постановка задач | `Application/Hellowin/TransitionQueue` |
| Задачи и их обработка | `Application/Hellowin/Job/TransitionPumpkin`, `Job/TransitionScene` → `TransitionHandler` |
| Сброс | `Application/Hellowin/ResetService`, консоль `hellowin:reset` (`Endpoint/Console/ResetCommand`) |
| Снимок для страницы и RPC `state` | `Application/Hellowin/SnapshotFactory` |
| События канала | `Application/Hellowin/ChannelPublisher` |
| Игроки: id из секрета, имя, запись в KV | `Domain/Player/PlayerId`, `PlayerName`, `PlayerStore`, `Application/Player/PlayerService` |
| Локи нескольких ресурсов | `Domain/Lock/Locks` |
| Таблица лидеров | `Domain/Player/Leaderboard` (ранжирование), `Application/Player/LeaderboardService` |
| Периодический процесс | `Application/Ticker/Ticker`, `TickerLease`, консоль `hellowin:ticker` (`Endpoint/Console/TickerCommand`) |

## Периодический процесс (тикер)

Таблицу лидеров (и летунов, когда они появятся) двигает время, а не запросы игроков, — нужен процесс с расписанием.
Это плагин RoadRunner `service` (`.rr.yaml`, `service.ticker`): RoadRunner держит один долгоживущий процесс
`php app.php hellowin:ticker` и перезапускает его при падении (`restart_sec`). Процесс — то же приложение Spiral
в консольном режиме: KV, локи, метрики и публикация в Centrifugo идут по goridge RPC к плагинам, как из воркеров.

Цикл: раз в 200 мс (`--step`) — удержать лизинг, выполнить всё, что пора (`Ticker::tick(now)`), уснуть. Расписание —
в памяти процесса: после рестарта начинается заново, а не догоняет пропущенное. Сбой одной работы пишется в лог и не
останавливает цикл.

Почему не дублируется:

- процесс вне пулов воркеров: `rr reset`, рестарт или увеличение пулов его не трогают и вторую копию не создают;
- **лизинг** — RR-лок `ticker` с TTL 5 с, продлевается раз в секунду. Тикает только его владелец; вторая копия
  (перекрытие при рестарте, ручной запуск `hellowin:ticker`) ждёт, пока первая перестанет продлевать.

Альтернатива — самоперепланирующаяся задача RR jobs с задержкой: годится, но цепочка рвётся при потере задачи
(memory-очередь теряет её на рестарте) и требует внешнего «запала» и ключа идемпотентности тика против двух цепочек.
`service` проще объяснить и показывает ещё один плагин RoadRunner.

Работы тикера:

| Работа | Когда | Что |
|---|---|---|
| Таблица лидеров | раз в `HELLOWIN_LEADERBOARD_INTERVAL` мс | игроки по индексу → `Leaderboard::top` (`HELLOWIN_LEADERBOARD_SIZE`) → ключ `hellowin:leaderboard` → событие `leaderboard` → gauge `hellowin_players`, `hellowin_players_registered` |

Ранжирование — очки за летунов, затем «добитые» тыквы, затем клики; игроки без единого действия не попадают. Событие
публикуется на каждой сборке, даже без изменений: новый клиент получает таблицу в пределах интервала, а сразу —
из RPC `me` (последняя сборка из ключа).

## Поток клика

1. RPC `click {element}` → `RPCService` проверяет элемент по реестру; игрок — `user` подключения (`publicId` или пусто).
2. Метрика `hellowin_clicks_total{type,element}`.
3. Под RR-локом `pumpkin:{id}`: `clicks + 1` в KV; под тем же локом читаются `round` и `scene`.
4. Статистика игрока: под RR-локом `player:{id}` `clicks + 1` (анонимный клик — пропуск). Сбой статистики пишется
   в лог и не ломает клик.
5. Публикация `click` в канал (с `player`, если он есть).
6. Если политика разрешает переход (`clicks >= HELLOWIN_CLICKS_PER_STAGE` и `stage < stageMax(scene)`) — задача
   `TransitionPumpkin {key, round, pumpkin, toStage, player}` в очередь, метрика
   `hellowin_transition_jobs_pushed_total{kind="pumpkin"}`. Ставится на **каждом** таком клике, пока переход
   не выполнен: дубли намеренные. `player` — вне ключа: из дублей выполняется один, его игрок и «добил» тыкву.

## Переходы

- **Тыква.** Обработчик `TransitionPumpkin` помечен `#[Idempotent(storage: 'transitions', key: 'payload.key')]`
  (пакет `spiral/idempotency`), ключ в payload — `{round}:{pumpkin}:{toStage}`. Внутри: RR-лок `pumpkin:{id}` →
  доменная проверка (`round` текущий и `stage == toStage - 1`) → `stage = toStage`, `clicks = 0` → лок отпущен →
  `finished + 1` игроку из payload → публикация `pumpkin` (с `player`) → `hellowin_transitions_total{kind="pumpkin"}`, `hellowin_pumpkin_stage{element}` → проверка
  политики сцены по снимку → при необходимости задача `TransitionScene` (`pushed{kind="scene"}`).
- **Сцена.** `TransitionScene {key, round, toScene}`, ключ `{round}:scene:{toScene}`, тоже `#[Idempotent]`. RR-лок
  `scene` → проверка (`round` текущий и `scene == toScene - 1`) → `scene = toScene` → публикация `scene` (со `stageMax`
  новой сцены) → `hellowin_transitions_total{kind="scene"}`, `hellowin_scene_stage`.
- **Сброс.** `ResetService::reset(?int $fromRound)` (консоль `hellowin:reset [--round=N]`): под локами `scene`
  и всех `pumpkin:{id}` сравнивает текущий `round` с `fromRound` (по умолчанию — текущий); совпал — `round + 1`,
  сцена 0, тыквы `stage 0`/`clicks 0`, публикация `reset`; не совпал — раунд уже сброшен, ничего не делает. Это
  идемпотентность по `reset:{round}` без пакета: состояние и так защищено этими локами, а сравнение `round` под ними
  отсекает второй сброс того же раунда; ключ в Valkey дал бы второй источник правды о раунде. Сервис вызывается из любого
  места (будущее голосование) — достаточно передать раунд, который хотят завершить.

Ключи идемпотентности в Valkey: `hellowin:amo:` + область (`Класс::handle`) + ключ из payload, например
`hellowin:amo:App\Application\Hellowin\Job\TransitionPumpkin::handle:1:pumpkin-3:2`. `round` в ключе обязателен:
без него после сброса те же переходы были бы отсечены, пока живы старые маркеры.

Гарантия для переходов — **AtMostOnce** («не более одного раза»): маркер ключа пишется до эффекта, дубль не вызывает
обработчик, задача подтверждается (ACK). Цена — если воркер упадёт между маркером и эффектом, переход потерян, а новые
задачи с тем же ключом отсекаются, пока маркер жив: тыква «застревает» на пороге. Поэтому TTL маркера короткий — 300 с:
после истечения следующий клик снова запускает переход, а от двойного перехода защищает доменная проверка `stage`.

Очередь подключена по скиллу пакета: `QueueIdempotencyBootloader`, `transports.queue` = `QueueRetryMiddleware`,
`QueueKeyMiddleware`; в `app/config/queue.php` `interceptors.consume` — `ErrorHandlerInterceptor`,
`RetryPolicyInterceptor`, `IdempotencyInterceptor` (retry снаружи: занятый ключ → `RetryableLockException` → задача
возвращается в очередь с задержкой).

## Хранилища

| Что | Где | Через |
|---|---|---|
| Состояние игры: `hellowin:round`, `hellowin:scene`, `hellowin:pumpkin:{id}` (`{stage, clicks}`) | Valkey | RR KV-плагин, драйвер `redis` (`VALKEY_ADDR`), storage `hellowin` (PSR-16 в приложении) |
| Игроки: `hellowin:player:{id}`, индекс `hellowin:players`, таблица лидеров `hellowin:leaderboard` | Valkey | тот же storage `hellowin`; подробности — [`players.md`](players.md) |
| Маркеры идемпотентности `hellowin:amo:*` | Valkey | `spiral/idempotency`, `RedisAtMostOnceConfig`, predis (`VALKEY_DSN`); alias `transitions` |
| Локи `pumpkin:{id}`, `scene`, `player:{id}`, `players`, лизинг `ticker` | память RoadRunner | RR lock-плагин; корректно для одного экземпляра `app` |
| Очередь переходов | память RoadRunner | RR jobs, драйвер memory, pipeline `transitions` |

`spiral/idempotency` подключён из ветки `feat/redis-at-most-once` (`dev-feat/redis-at-most-once as 0.5.0`) до релиза
0.5 с `RedisAtMostOnceConfig`; после релиза — обычная версия `^0.5`. Valkey с AOF и volume — состояние переживает
рестарт `app` и `valkey`; отсутствующий ключ `hellowin:round` означает раунд 1. Задачи в очереди при рестарте `app`
теряются — следующий клик сверх порога ставит переход снова.

Почему не SQL: данных мало, живут одно мероприятие, сложных выборок нет.

## Метрики

| Метрика | Тип | Смысл |
|---|---|---|
| `hellowin_clicks_total{type,element}` | counter | принятые клики |
| `hellowin_transition_jobs_pushed_total{kind}` | counter | задачи перехода в очереди, дубли включительно; `kind` = `pumpkin` / `scene` |
| `hellowin_transitions_total{kind}` | counter | реально выполненные переходы |
| `hellowin_scene_stage` | gauge | текущая сцена |
| `hellowin_round` | gauge | текущий раунд |
| `hellowin_pumpkin_stage{element}` | gauge | стадия каждой тыквы |
| `hellowin_players` | gauge | известные игроки (с секретом, с именем и без); выставляет тикер при сборке таблицы лидеров |
| `hellowin_players_registered` | gauge | игроки с именем; там же |

`pushed − transitions` — дубли, отсечённые идемпотентностью; это главная панель демонстрации в Grafana. Счётчики живут
в процессе RoadRunner и обнуляются при его рестарте; gauge при старте воркеров выставляются из состояния в Valkey.
Плюс встроенные `rr_*` и `centrifugo_*`. Ошибки метрик клик не ломают.

## Env

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `HELLOWIN_CLICKS_PER_STAGE` | 5 | кликов по тыкве для перехода в следующую стадию |
| `HELLOWIN_SCENE_STAGE_MAX` | `4,6,8` | последняя стадия тыквы по сценам, накопительно; длина списка — номер финальной сцены |
| `HELLOWIN_LEADERBOARD_INTERVAL` | 5000 | мс между сборками таблицы лидеров (`app/config/players.php`) |
| `HELLOWIN_LEADERBOARD_SIZE` | 10 | игроков в таблице лидеров |
| `VALKEY_ADDR` | `valkey:6379` | Valkey для RR KV (`.rr.yaml`) |
| `VALKEY_DSN` | `tcp://valkey:6379` | Valkey для idempotency (predis) |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | URL WebSocket для фронта |
| `CENTRIFUGO_GRPC_API` | `centrifugo:10000` | gRPC API Centrifugo для публикации |
