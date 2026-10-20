# Движок (backend)

Статус: **реализовано** — сцена и стадии тыкв, очередь переходов с идемпотентностью, сброс, RPC `state`, метрики,
игроки и их статистика, периодический процесс (тикер), таблица лидеров, летуны, фигурки игроков на поле, роль
модератора (сброс — только ему).

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
| Летуны: траектории, KV, выстрел | `Domain/Flyer/FlyerFactory`, `FlyerStore`, `Flyer`, `Application/Flyer/FlyerService` (выстрел — RPC `shoot`) |
| Метрики игроков и летунов | `Application/Hellowin/PlayMetrics` |
| Фигурки: вид, путь, зона, KV | `Domain/Player/Look`, `Domain/Walk/Walk`, `WalkZone`, `Walker`, `WalkerStore`, `Application/Walk/WalkService` (RPC `move`, `register`, тикер) |
| Кто подключён | `Application/Walk/Presence`, `CentrifugoPresence` (presence канала через API Centrifugo) |

## Периодический процесс (тикер)

Летунов и таблицу лидеров двигает время, а не запросы игроков, — нужен процесс с расписанием.
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
| Запуск летуна | случайно раз в `HELLOWIN_FLYER_INTERVAL` мс; первый — через интервал после старта | `FlyerFactory` → ключ летуна + индекс → событие `flyer` → `hellowin_flyers_launched_total{depth}` |
| Учёт улетевших | каждый тик | просроченные из индекса → под локом `flyer:{id}` ключ ещё есть → удалить, `hellowin_flyers_escaped_total{depth}` |
| Фигурки: вход и уход | раз в секунду | presence канала → новые игроки с `look` выходят на поле (`walker`), давно отключённые уходят (`leave`) → gauge `hellowin_walkers`; см. «Фигурки на поле» |
| Таблица лидеров | раз в `HELLOWIN_LEADERBOARD_INTERVAL` мс | игроки по индексу → `Leaderboard::top` (`HELLOWIN_LEADERBOARD_SIZE`) → ключ `hellowin:leaderboard` → событие `leaderboard` → gauge `hellowin_players`, `hellowin_players_registered` |

Ранжирование — очки за летунов, затем «добитые» тыквы, затем клики; игроки без единого действия не попадают. Событие
публикуется на каждой сборке, даже без изменений: новый клиент получает таблицу в пределах интервала, а сразу —
из RPC `me` (последняя сборка из ключа).

## Летуны

Правила — [`scenario.md`](scenario.md), «Летуны»; события и RPC — [`protocol.md`](protocol.md).

- **Запуск** (тикер): глубина — равновероятно из `0..N-1` (летун из куста — глубина куста), N — длина `HELLOWIN_FLYER_DURATION`; время полёта и очки
  берутся по глубине (`HELLOWIN_FLYER_DURATION`, `HELLOWIN_FLYER_POINTS`: дальше — медленнее и дороже). С вероятностью
  `HELLOWIN_FLYER_BUSH_CHANCE` летун взлетает из случайного куста (`bushes` в `app/config/hellowin.php`; нет кустов —
  только край): из точки куста вверх и за верхний или боковой край через экран. Иначе — из-за левого или правого края
  (четверть — из верхнего угла) за противоположный, в небе. С вероятностью `HELLOWIN_FLYER_ARC_CHANCE` полёт — дуга
  через `via` (квадратичная кривая), иначе прямая. Точки за краем — `-8` / `108`. Генератор — `FlyerFactory`
  с инъекцией `Randomizer`: в тестах траектории детерминированы.
- **Хранение**: `hellowin:flyer:{id}` — летун со временем запуска, TTL = полёт + 10 с; индекс `hellowin:flyers`
  (id → момент окончания полёта) пишет только тикер, поэтому без лока.
- **Выстрел** — RPC `shoot {flyer}`: под RR-локом `flyer:{id}` ключ есть и полёт не кончился → удалить ключ → попадание:
  `flyers + 1`, `points + очки` игроку, одно событие `shot`, `hellowin_flyers_shot_total{depth}`. Иначе промах.
  Выстрел принимается до 500 мс после конца полёта: клиент начинает рисовать летуна, когда событие дошло.
- **Улетевшие** (тикер, каждый тик): летуны из индекса, чей полёт кончился больше 500 мс назад, — под тем же локом:
  ключ ещё есть → удалить, `hellowin_flyers_escaped_total{depth}`; ключа нет — его сбили. Из индекса — в любом случае.
  Ключ удаляет ровно один из двоих (выстрел или учёт), поэтому летун не бывает и сбит, и улетел, и не считается дважды.
  Если тикер лежал дольше запаса TTL, ключи истекают сами и такие летуны не попадут ни в одну метрику.
- Летуны не зависят от ритуала: летают в любой сцене, включая финальную; `round` в событиях — только потому, что его
  несёт каждое событие. Есть ли подключённые клиенты, тикер для летунов не проверяет: запуск без зрителей стоит одного
  ключа в KV и одной публикации.

## Фигурки на поле

Правила — [`players.md`](players.md), «Персонаж», «На поле»; события и RPC — [`protocol.md`](protocol.md).

- **Хранение**: один ключ `hellowin:walkers` — игрок → `{name, look, walk, seenAt}`, меняется под RR-локом `walkers`.
  Один ключ — снимок читает фигурки одним запросом; сброс раунда его не трогает, позиции переживают сброс. `name` и
  `look` копируются из записи игрока при выходе на поле и повторном `register`.
- **Выход на поле**: `register` с `look` (или повторный `register` игрока, у которого `look` уже есть) — фигурка
  появляется или переодевается на месте, событие `walker`. Новая фигурка стоит в случайной точке полосы `walk.spawn`
  (`app/config/hellowin.php`) у нижнего края зоны, приведённой в зону. `move` игрока с `look`, чьей фигурки ещё нет
  на поле, сначала ставит её (`walker`), потом ведёт (`move`).
- **Ход** (`move {x, y}`): цель приводится в зону — в прямоугольник `walk.area`, из эллипса `walk.patch` на его край
  по лучу из центра; если край там вне прямоугольника — в ближайшую точку, где эллипс пересекает сторону
  прямоугольника. Начало — где фигурка сейчас по её `Walk` и серверным часам. Если отрезок не заходит внутрь эллипса —
  путь прямой; иначе — по касательной к эллипсу, увеличенному на 3 %, по его дуге ломаной с шагом не больше 15° и по
  касательной к цели; из двух сторон — короче, из тех, что не выходят из прямоугольника больше чем на 2 % (такие
  вершины прижимаются к краю). Запас 3 % держит хорды дуги вне грядки. Координаты — до сотых. Длительность — длина
  ломаной / `HELLOWIN_WALK_SPEED`. Под локом `walkers`: два `move` одного игрока не теряют друг друга.
- **Уход — presence Centrifugo + тикер.** У канала `hellowin` включён presence (`docker/centrifugo/config.json`):
  серверная подписка каждого подключения попадает в presence с `user` = `publicId`, при закрытии соединения запись
  сразу пропадает. Раз в секунду тикер спрашивает presence канала через API Centrifugo (RR-плагин `centrifuge`,
  `CentrifugoApiInterface::presence`), сводит записи к набору игроков и сравнивает с фигурками: у присутствующих
  обновляет `seenAt`, игроки с `look` без фигурки выходят на поле (`walker`), фигурки, чьих игроков нет дольше
  `HELLOWIN_WALK_LEAVE_AFTER` мс, уходят (`leave`). Несколько вкладок — несколько записей presence одного `user`:
  фигурка одна, уходит, когда закрыта последняя. Задержка ухода нужна, чтобы перезагрузка страницы не роняла фигурку
  и не переносила её к нижнему краю.
- Почему так: presence — готовый в Centrifugo учёт подключений, который сам чистится при разрыве; приложению не нужно
  держать свои счётчики клиентов или heartbeat. Альтернатива — refresh proxy (connect отдаёт `expire_at`, Centrifugo
  периодически продлевает подключение через приложение, тикер удаляет непродлённых) — работает и без presence, но уход
  виден только по истечении срока, а это лишний прокси и запись в KV на каждое продление. Подключение с `look` выходит
  на поле не в connect proxy, а по presence: в момент connect клиент ещё не подписан на канал и своё событие `walker`
  не получил бы.
- **Предмет в руке** — `items` в `app/config/hellowin.php`, по номеру сцены (`null` — пустые руки, за концом списка —
  тоже `null`). `item` фигурки не хранится: в снимке и событии `walker` это предмет текущей сцены. При смене сцены
  `walker` не рассылаются: событие `scene` несёт `item` новой сцены, и клиент меняет предмет у всех фигурок сразу.
- Сбой опроса presence (Centrifugo недоступен) — ошибка в лог, фигурки не трогаются: недоступный presence не значит,
  что все ушли.

## Модератор

- **Роль.** `ConnectService` сравнивает `moderatorSecret` из `data` подключения с `HELLOWIN_MODERATOR_SECRET`
  (`hash_equals`) и при совпадении кладёт `{moderator: true}` в **meta** подключения. Meta хранит Centrifugo, клиенту
  она не видна; в каждый RPC proxy Centrifugo добавляет её сам (`rpc.proxy.include_connection_meta` в
  `docker/centrifugo/config.json`), поэтому клиент не может назначить себе роль запросом. `RPCService` читает роль из
  meta и отвечает на RPC пульта не модератору `forbidden`. Пустой секрет в env — роли нет ни у кого.
- Почему meta, а не `user`: модератор может быть и игроком (у него свой `playerSecret`), а `user` — это публичный id
  игрока в событиях и presence.

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
`RetryPolicyInterceptor`, `IdempotencyInterceptor` (retry снаружи). У AtMostOnce-хранилища занятый ключ — это дубль:
claim возвращает nil, обработчик не вызывается, задача подтверждается. Путь `LockedException` →
`RetryableLockException` → повтор с задержкой относится к lease-хранилищам (AtLeastOnce) и сработает, только если
alias `transitions` переключить обратно на `RedisLeaseConfig`.

## Хранилища

| Что | Где | Через |
|---|---|---|
| Состояние игры: `hellowin:round`, `hellowin:scene`, `hellowin:pumpkin:{id}` (`{stage, clicks}`) | Valkey | RR KV-плагин, драйвер `redis` (`VALKEY_ADDR`), storage `hellowin` (PSR-16 в приложении) |
| Игроки: `hellowin:player:{id}`, индекс `hellowin:players`, таблица лидеров `hellowin:leaderboard` | Valkey | тот же storage `hellowin`; подробности — [`players.md`](players.md) |
| Летуны: `hellowin:flyer:{id}` (TTL), индекс `hellowin:flyers` | Valkey | тот же storage `hellowin` |
| Фигурки: `hellowin:walkers` | Valkey | тот же storage `hellowin` |
| Маркеры идемпотентности `hellowin:amo:*` | Valkey | `spiral/idempotency`, `RedisAtMostOnceConfig`, predis (`VALKEY_DSN`); alias `transitions` |
| Локи `pumpkin:{id}`, `scene`, `player:{id}`, `players`, `flyer:{id}`, `walkers`, лизинг `ticker` | память RoadRunner | RR lock-плагин; корректно для одного экземпляра `app` |
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
| `hellowin_flyers_launched_total{depth}` | counter | запущенные летуны (тикер) |
| `hellowin_flyers_shot_total{depth}` | counter | сбитые летуны (RPC `shoot`) |
| `hellowin_flyers_escaped_total{depth}` | counter | улетевшие несбитыми (тикер) |
| `hellowin_walkers` | gauge | фигурки на поле; выставляет тикер раз в секунду |

`pushed − transitions` — дубли, отсечённые идемпотентностью; это главная панель демонстрации в Grafana. Счётчики живут
в процессе RoadRunner и обнуляются при его рестарте; gauge при старте воркеров выставляются из состояния в Valkey.
Плюс встроенные `rr_*` и `centrifugo_*`. Ошибки метрик клик не ломают.

## Env

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `HELLOWIN_CLICKS_PER_STAGE` | 5 | кликов по тыкве для перехода в следующую стадию |
| `HELLOWIN_MODERATOR_SECRET` | пусто | секрет модератора (`moderatorSecret` в `data` подключения); пусто — пульт выключен, RPC пульта всем отвечают `forbidden` |
| `HELLOWIN_SCENE_STAGE_MAX` | `4,6,8` | последняя стадия тыквы по сценам, накопительно; длина списка — номер финальной сцены |
| `HELLOWIN_LEADERBOARD_INTERVAL` | 5000 | мс между сборками таблицы лидеров (`app/config/players.php`) |
| `HELLOWIN_LEADERBOARD_SIZE` | 10 | игроков в таблице лидеров |
| `HELLOWIN_FLYER_INTERVAL` | `3000-8000` | мс между запусками летунов: диапазон (случайно внутри) или число (`app/config/flyers.php`) |
| `HELLOWIN_FLYER_DURATION` | `16000,6500,4000` | мс полёта по глубинам, от дальней (0) к ближней |
| `HELLOWIN_FLYER_POINTS` | `3,2,1` | очки за попадание по глубинам; длина — как у `HELLOWIN_FLYER_DURATION` |
| `HELLOWIN_FLYER_BUSH_CHANCE` | 0.4 | вероятность взлёта из куста |
| `HELLOWIN_FLYER_ARC_CHANCE` | 0.5 | вероятность полёта дугой |
| `HELLOWIN_FLYER_KINDS` | `duck,chicken` | виды летунов через запятую (`[a-z]+`), выбираются равновероятно |
| `HELLOWIN_WALK_SPEED` | 12 | скорость фигурок, процентов экрана в секунду по длине пути |
| `HELLOWIN_WALK_LEAVE_AFTER` | 5000 | мс без подключений, после которых фигурка уходит с поля |
| `VALKEY_ADDR` | `valkey:6379` | Valkey для RR KV (`.rr.yaml`) |
| `VALKEY_DSN` | `tcp://valkey:6379` | Valkey для idempotency (predis) |
| `CENTRIFUGO_WS_URL` | `/connection/websocket` | URL WebSocket для фронта |
| `CENTRIFUGO_GRPC_API` | `centrifugo:10000` | gRPC API Centrifugo для публикации |
