# Движок (backend)

Статус: **реализовано** — сцена и стадии тыкв, очередь переходов с идемпотентностью, сброс, RPC `state`, метрики.
Кто запускает сброс и игроки — **запланировано** ([`scenario.md`](scenario.md), [`players.md`](players.md)).

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

## Поток клика

1. RPC `click {element}` → `RPCService` проверяет элемент по реестру.
2. Метрика `hellowin_clicks_total{type,element}`.
3. Под RR-локом `pumpkin:{id}`: `clicks + 1` в KV; под тем же локом читаются `round` и `scene`.
4. Публикация `click` в канал.
5. Если политика разрешает переход (`clicks >= HELLOWIN_CLICKS_PER_STAGE` и `stage < stageMax(scene)`) — задача
   `TransitionPumpkin {key, round, pumpkin, toStage}` в очередь, метрика
   `hellowin_transition_jobs_pushed_total{kind="pumpkin"}`. Ставится на **каждом** таком клике, пока переход
   не выполнен: дубли намеренные.

## Переходы

- **Тыква.** Обработчик `TransitionPumpkin` помечен `#[Idempotent(storage: 'transitions', key: 'payload.key')]`
  (пакет `spiral/idempotency`), ключ в payload — `{round}:{pumpkin}:{toStage}`. Внутри: RR-лок `pumpkin:{id}` →
  доменная проверка (`round` текущий и `stage == toStage - 1`) → `stage = toStage`, `clicks = 0` → лок отпущен →
  публикация `pumpkin` → `hellowin_transitions_total{kind="pumpkin"}`, `hellowin_pumpkin_stage{element}` → проверка
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
| Маркеры идемпотентности `hellowin:amo:*` | Valkey | `spiral/idempotency`, `RedisAtMostOnceConfig`, predis (`VALKEY_DSN`); alias `transitions` |
| Локи `pumpkin:{id}`, `scene` | память RoadRunner | RR lock-плагин; корректно для одного экземпляра `app` |
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

`pushed − transitions` — дубли, отсечённые идемпотентностью; это главная панель демонстрации в Grafana. Счётчики живут
в процессе RoadRunner и обнуляются при его рестарте; gauge при старте воркеров выставляются из состояния в Valkey.
Плюс встроенные `rr_*` и `centrifugo_*`. Ошибки метрик клик не ломают.

## Env

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `HELLOWIN_CLICKS_PER_STAGE` | 5 | кликов по тыкве для перехода в следующую стадию |
| `HELLOWIN_SCENE_STAGE_MAX` | `4,6,8` | последняя стадия тыквы по сценам, накопительно; длина списка — номер финальной сцены |
| `VALKEY_ADDR` | `valkey:6379` | Valkey для RR KV (`.rr.yaml`) |
| `VALKEY_DSN` | `tcp://valkey:6379` | Valkey для idempotency (predis) |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | URL WebSocket для фронта |
| `CENTRIFUGO_GRPC_API` | `centrifugo:10000` | gRPC API Centrifugo для публикации |
