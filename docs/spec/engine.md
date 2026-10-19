# Движок (backend)

Статус: **в работе** — переход с одного глобального порога на сцену и стадии тыкв с очередью и идемпотентностью.
Сверяй с кодом; после завершения работы раздел «Статус» обновляется.

## Поток клика

1. RPC `click {element}` → `RPCService` проверяет элемент по реестру.
2. Метрика `hellowin_clicks_total{type,element}`.
3. Под RR-локом `pumpkin:{id}`: `clicks + 1` в KV.
4. Публикация `click` в канал.
5. Если `clicks >= HELLOWIN_CLICKS_PER_STAGE` и политика разрешает переход в `scene + 1` — задача
   `TransitionPumpkin {round, pumpkinId, toStage}` в очередь. Ставится на **каждом** таком клике: дубли намеренные.

## Переходы

- **Тыква.** Обработчик `TransitionPumpkin` помечен `#[Idempotent]` (пакет `spiral/idempotency`), ключ
  `{round}:{pumpkinId}:{toStage}` из payload. Внутри: RR-лок тыквы → доменная проверка `stage == toStage - 1` →
  `stage = toStage`, `clicks = 0` → публикация `pumpkin` → проверка политики сцены → при необходимости задача
  `TransitionScene`.
- **Сцена.** `TransitionScene {round, toScene}`, ключ `{round}:scene:{toScene}`, RR-лок `scene`, публикация `scene`.
- **Сброс.** `ResetService` (консоль `hellowin:reset`): `round + 1`, сцена и тыквы в 0, публикация `reset`; защищён
  от двойного выполнения ключом `reset:{round}`.

Гарантия для переходов — **AtMostOnce** («не более одного раза»): маркер ключа пишется до эффекта, дубль не вызывает
обработчик. Цена — если воркер упадёт между маркером и эффектом, переход потерян, а новые задачи с тем же ключом
отсекаются, пока ключ жив: тыква «застревает» на пороге. Поэтому TTL ключа короткий (минуты, а не сутки): после
истечения следующий клик снова запускает переход, а от двойного перехода защищает доменная проверка `stage`.

## Хранилища

| Что | Где | Через |
|---|---|---|
| Состояние игры: `round`, `scene`, стадии и клики тыкв | Valkey | RR KV-плагин, драйвер `redis`, storage `hellowin` (PSR-16 в приложении) |
| Ключи идемпотентности | Valkey | `spiral/idempotency`, predis; alias `transitions` |
| Локи | память RoadRunner | RR lock-плагин; корректно для одного экземпляра `app` |
| Очередь переходов | память RoadRunner | RR jobs, драйвер memory, pipeline `transitions` |

Alias `transitions` сейчас на `RedisLeaseConfig` (AtLeastOnce): AtMostOnce-драйвер для Redis/Valkey
(`RedisAtMostOnceConfig`) готовится в `spiral/idempotency`; переключение — одна строка в `app/config/idempotency.php`.
Valkey с AOF и volume — состояние переживает рестарт `app` и `valkey`.

Почему не SQL: данных мало, живут одно мероприятие, сложных выборок нет.

## Метрики

| Метрика | Тип | Смысл |
|---|---|---|
| `hellowin_clicks_total{type,element}` | counter | принятые клики |
| `hellowin_transition_jobs_pushed_total{kind}` | counter | задачи перехода в очереди; `kind` = `pumpkin` / `scene` |
| `hellowin_transitions_total{kind}` | counter | реально выполненные переходы |
| `hellowin_scene_stage` | gauge | текущая сцена |

`pushed − transitions` — дубли, отсечённые идемпотентностью; это главная панель демонстрации в Grafana. Плюс
встроенные `rr_*` и `centrifugo_*`. Метрики отправляются в RR metrics-плагин; ошибки метрик клик не ломают.

## Env

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `HELLOWIN_CLICKS_PER_STAGE` | 5 | кликов по тыкве для перехода |
| `HELLOWIN_SCENE_STAGES` | 3 | номер финальной сцены |
| `VALKEY_DSN` | `tcp://valkey:6379` | Valkey для idempotency (predis) |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | URL WebSocket для фронта |
| `CENTRIFUGO_GRPC_API` | `centrifugo:10000` | gRPC API Centrifugo для публикации |
