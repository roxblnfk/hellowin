<extends:layout.base title="Как это работает · Hellowin"/>

<stack:push name="styles">
    <link rel="stylesheet" href="/css/how-it-works.css">
</stack:push>

<stack:push name="scripts">
    <script src="/js/how-it-works.js"></script>
</stack:push>

<define:body>
    <main class="how">
        <header>
            <div class="how__nav">
                <a href="/">← к тыквам</a>
                <a href="{{ $grafanaUrl }}">Дашборд в Grafana →</a>
                <a href="?lang=en" data-lang-switch hreflang="en" lang="en">EN</a>
            </div>
            <h1>Как это работает</h1>
            <p class="how__lead">
                RoadRunner «умер», и посетители воскрешают его ритуалом на тыквенной грядке. Ритуал идёт сценами. В
                каждой сцене тыквы проходят несколько стадий: тыква переходит в следующую, когда по ней набрали порог кликов
                (он растёт с числом игроков на поле — <a href="#limits">«Лимиты»</a>), и останавливается на последней стадии сцены — её задаёт
                список <code>HELLOWIN_SCENE_STAGE_MAX</code>. Когда все тыквы дошли до неё, сцена готова, а следующую
                включает модератор — он же сова-рассказчик. В финале сова раскрывает реальные числа вечера. Один проход
                ритуала — раунд; сброс начинает следующий. Игроки ходят фигурками по лужайке и сбивают летунов. Все
                видят действия всех в реальном времени, а метрики уходят в публичную Grafana. Ниже — что происходит на
                самом деле, с именами классов, ключей, каналов и портов из кода.
            </p>
            <dl class="how__terms">
                <dt>Spiral Framework</dt>
                <dd>PHP-фреймворк: контроллеры, DI-контейнер, конфиги, очереди, шаблоны Stempler. Здесь он — бизнес-логика.</dd>
                <dt>RoadRunner</dt>
                <dd>Сервер приложений на Go. Держит пулы долгоживущих PHP-воркеров и даёт им плагины: HTTP, KV,
                    локи, очередь задач jobs, метрики, мост к Centrifugo. PHP обращается к плагинам по RPC.</dd>
                <dt>Centrifugo</dt>
                <dd>Сервер WebSocket-соединений и каналов (pub/sub). Держит соединения с браузерами, а решения
                    «пускать ли», «что делать с RPC» спрашивает у приложения через proxy.</dd>
                <dt>Valkey</dt>
                <dd>Redis-совместимое хранилище. В нём лежат состояние ритуала и сюжета, игроки, летуны, фигурки и
                    ключи идемпотентности переходов; AOF на volume — состояние переживает перезапуск.</dd>
                <dt>Prometheus, Grafana</dt>
                <dd>Prometheus сам забирает метрики с сервисов и хранит их; Grafana рисует по ним дашборд.</dd>
            </dl>
        </header>

        <nav class="tabs" aria-label="Разделы">
            <ul class="tabs__list" role="tablist">
                <li role="presentation"><a role="tab" id="tab-roadrunner" href="#roadrunner" aria-controls="roadrunner" aria-selected="true">RoadRunner</a></li>
                <li role="presentation"><a role="tab" id="tab-modules" href="#modules" aria-controls="modules" aria-selected="false">Модули</a></li>
                <li role="presentation"><a role="tab" id="tab-page-load" href="#page-load" aria-controls="page-load" aria-selected="false">Загрузка страницы</a></li>
                <li role="presentation"><a role="tab" id="tab-click" href="#click" aria-controls="click" aria-selected="false">Клик</a></li>
                <li role="presentation"><a role="tab" id="tab-stages" href="#stages" aria-controls="stages" aria-selected="false">Стадии и лок</a></li>
                <li role="presentation"><a role="tab" id="tab-idempotency" href="#idempotency" aria-controls="idempotency" aria-selected="false">Идемпотентный переход</a></li>
                <li role="presentation"><a role="tab" id="tab-dice" href="#dice" aria-controls="dice" aria-selected="false">Кости</a></li>
                <li role="presentation"><a role="tab" id="tab-live" href="#live" aria-controls="live" aria-selected="false">Модератор, фигурки, летуны</a></li>
                <li role="presentation"><a role="tab" id="tab-metrics" href="#metrics" aria-controls="metrics" aria-selected="false">Метрики</a></li>
            </ul>
        </nav>

        <section class="how__section" id="roadrunner" role="tabpanel" aria-labelledby="tab-roadrunner">
            <h2>RoadRunner: PHP, который не умирает</h2>
            <p>
                RoadRunner появился в конце 2017 года, версия 1.0 вышла в июне 2018-го. Он создавался, чтобы вывести PHP
                на уровень enterprise. Всё началось с вопроса: а что, если
                PHP-приложение не будет умирать после каждого запроса? Так работают enterprise-фреймворки в других
                языках, та же Java: приложение поднимается один раз и обслуживает запросы, пока его не остановят.
            </p>

            <h3>Идея: срезать bootstrap</h3>
            <p>
                В классической модели PHP на каждый запрос заново поднимает приложение: грузит конфиги, собирает
                контейнер, открывает соединения. После ответа всё это выбрасывается. Если же процесс живёт, bootstrap
                делается один раз, а дальше воркер только обслуживает запросы. Эту модель и держит RoadRunner: пул
                долгоживущих PHP-воркеров, которым он раздаёт запросы.
            </p>

            <h3>Spiral и Cycle: фреймворк и ORM, которые не текут</h3>
            <p>
                Новая модель потребовала и нового фреймворка. Тогдашние фреймворки не умели не умирать: в них утекала
                память, а привычные практики рассчитывали на то, что процесс всё равно сейчас умрёт. Так появился
                <a href="https://spiral.dev/">Spiral Framework</a>. Ему понадобилась ORM, которая не течёт: Doctrine и
                Eloquent для этого не подходили, и появилась <a href="https://cycle-orm.dev/">Cycle ORM</a>. Это демо
                написано на Spiral.
            </p>

            <h3>Плагины: единое API и IO на стороне Go</h3>
            <p>
                Первые версии доказали, что концепт работает, и на RoadRunner начали навешиваться новые возможности:
                очереди задач jobs, веб-сокеты, KV, локи, метрики, а потом и
                <a href="https://temporal.io/">Temporal</a>. RoadRunner даёт PHP единое API к разным хранилищам и
                брокерам сообщений и забирает на себя IO-bound работу. В этом демо задействованы плагины
                <code>http</code>, <code>centrifuge</code>, <code>kv</code>, <code>lock</code>, <code>jobs</code> и
                <code>metrics</code> — как именно, рассказывают остальные вкладки, начиная с
                <a href="#modules">«Модулей»</a>.
            </p>

            <h3>Go и Goridge</h3>
            <p>
                Единственным адекватным языком для такого сервера тогда был Go. Но Go плохо совместим с C, на котором
                написан PHP, поэтому пришлось разработать собственный протокол общения между PHP и RoadRunner —
                <a href="https://github.com/roadrunner-server/goridge">Goridge</a>. За него платится налог на
                сериализацию и десериализацию. Зато практика и время показали, что этот протокол — самый стабильный и
                безопасный вариант, в отличие от встраивания PHP в Go через CGO или связи по FastCGI
                (<a href="https://rapira.rs/blog/php2shell">разбор</a>).
            </p>

            <h3>Laravel и Symfony</h3>
            <p>
                Со временем Laravel и Symfony тоже научились работать в неумирающей модели, и для них появились
                интеграции с RoadRunner. Официальные либо работают неоптимально, либо почти ничего не берут от
                RoadRunner, кроме HTTP. Мы рекомендуем бриджи сообщества:
            </p>
            <ul>
                <li><a href="https://github.com/roadrunner-php/laravel-bridge">roadrunner-php/laravel-bridge</a> для
                    Laravel — HTTP, gRPC, очереди, KV и Temporal;</li>
                <li><a href="https://github.com/Baldinof/roadrunner-bundle">baldinof/roadrunner-bundle</a> для
                    Symfony — HTTP, gRPC и KV как кеш.</li>
            </ul>

            <h3>RoadRunner 3 и честные минусы</h3>
            <p>
                В сентябре 2026 года вышел RoadRunner 3. Он гибко расширяется плагинами и работает во многих
                enterprise-компаниях по всему миру. Но у него есть и проблемы:
            </p>
            <ul>
                <li><strong>Сложность в обращении.</strong> Не каждое приложение может работать в long-running режиме, а
                    чтобы писать под него, рядовому PHP-разработчику нужно дообучиться.</li>
                <li><strong>Память.</strong> Каждый воркер — отдельный процесс без общего opcache, поэтому
                    потребление памяти высокое.</li>
                <li><strong>Маркетинг.</strong></li>
            </ul>
            <p>
                И не всем это нужно: многие приложения на фреймворках общего назначения не работают под высокой
                нагрузкой.
            </p>

            <h3>Что дальше: Рапира</h3>
            <p>
                PHP по-прежнему нужен нормальный веб-сервер, способный запустить любое приложение в любом режиме:
                классическом, воркерном или даже асинхронном. Поэтому идею RoadRunner развили дальше — так появилась
                <a href="https://rapira.rs/">Рапира</a>, его продолжение.
            </p>
            <p class="callout">
                RoadRunner никто не бросает: на нём работают многие компании, и поддержка продолжается. Но в
                перспективе, когда Рапира перекроет все возможности RoadRunner, хочется, чтобы все перешли на неё.
            </p>
        </section>

        <section class="how__section" id="modules" role="tabpanel" aria-labelledby="tab-modules">
            <h2>Кто с кем разговаривает</h2>
            <p>
                Перед RoadRunner нет nginx: страницы и статику отдаёт сам HTTP-плагин на <code>localhost:8080</code>.
                Его middleware идут слева направо: <code>http_metrics</code>, <code>headers</code> (заголовки
                безопасности), <code>gzip</code>, <code>static</code> (файлы <code>public/</code> с ETag — повторный
                запрос получает 304) и <code>rate_limiter</code> (token bucket по IP — только для запросов, дошедших
                до PHP). RoadRunner не реверс-прокси, поэтому Centrifugo и Grafana открыты своими портами: WebSocket —
                <code>:8000</code>, дашборд — <code>:3000</code>. Адреса страница получает от сервера, достроенные
                хостом, по которому её открыли. Всё остальное живёт во внутренней сети docker compose.
            </p>
            <p>Так это записано в <code>.rr.yaml</code>:</p>
            <figure class="code">
                <figcaption><code>.rr.yaml</code> · <code>http</code></figcaption>
                <pre><code>http:
  # …
  middleware:
    - http_metrics
    - headers
    - gzip
    - static
    - rate_limiter
  headers:
    response:
      X-Powered-By: RoadRunner 3
      X-Content-Type-Options: nosniff
      X-Frame-Options: DENY
      # …</code></pre>
            </figure>
            <p>
                Основная нагрузка — не HTTP, а RPC через WebSocket, и HTTP-лимитер их не видит. Поэтому
                <code>RPCService</code> до любого метода берёт токен из ведра игрока: оно лежит в KV
                <code>process</code> (память RoadRunner) и меняется под локом <code>rate:{group}:{player}</code>, потому
                что атомарного обновления у KV нет. Быстрые клики проходят, поток из скрипта после <code>burst</code>
                получает <code>rate limited</code>; модератора лимит не касается.
            </p>
            <p>
                RoadRunner и Centrifugo связаны двумя gRPC-каналами в разные стороны. Centrifugo проксирует события
                клиентов (connect, rpc) в плагин <code>centrifuge</code> на <code>app:10001</code>, а приложение
                публикует сообщения через gRPC API Centrifugo на <code>centrifugo:10000</code>.
            </p>
            <p>
                У RoadRunner три пула PHP-воркеров — процессов <code>php app.php</code>, в <code>centrifuge</code> их
                <code>RR_CENTRIFUGE_WORKERS</code>, в остальных по два: <code>http</code> отдаёт
                страницы, <code>centrifuge</code> отвечает на connect и RPC, <code>jobs</code> выполняет задачи
                переходов из очереди <code>transitions</code> (драйвер <code>memory</code>). Запросы и задачи RoadRunner
                передаёт воркеру через pipes — stdin/stdout процесса. Обратно к плагинам <code>kv</code>,
                <code>lock</code>, <code>jobs</code>, <code>metrics</code> и <code>centrifuge</code> воркер ходит по
                goridge RPC на <code>127.0.0.1:6001</code>.
            </p>
            <p>Пулы и очередь — там же:</p>
            <figure class="code">
                <figcaption><code>.rr.yaml</code> · <code>centrifuge, jobs</code></figcaption>
                <pre><code>centrifuge:
  proxy_address: 'tcp://0.0.0.0:10001'
  grpc_api_address: '&#36;{CENTRIFUGO_GRPC_API:-centrifugo:10000}'
  pool:
    # Every click, shot and move of the room waits for one of these: about two per core
    num_workers: &#36;{RR_CENTRIFUGE_WORKERS:-4}
# …
jobs:
  consume: [transitions]
  pool:
    num_workers: 2
  pipelines:
    transitions:
      driver: memory</code></pre>
            </figure>
            <p>
                Летунов, фигурки и топ стрелков двигает время, а не запросы. Для этого есть плагин
                <code>service</code>: RoadRunner держит один долгоживущий процесс <code>php app.php hellowin:ticker</code>
                вне пулов воркеров и перезапускает его при падении. Тикер ходит к плагинам по тому же RPC, что и
                воркеры, а через gRPC API Centrifugo ещё и спрашивает presence канала. Вторую копию (перекрытие при
                рестарте) держит в стороне лок <code>ticker</code> — лизинг, который владелец продлевает раз в секунду.
            </p>
            <p>
                Состояние игры — в Valkey: ритуал и сюжет, игроки и топ стрелков, летуны, фигурки. Его читает и
                пишет KV-плагин RoadRunner: хранилище <code>hellowin</code> с драйвером <code>redis</code> на
                <code>VALKEY_ADDR</code>, в PHP это PSR-16 кэш <code>hellowin</code>.
                Ключи идемпотентности переходов <code>hellowin:amo:*</code> и сохранённые ответы костей
                <code>hellowin:lease:*</code> воркеры пишут в тот же Valkey напрямую,
                через predis (<code>VALKEY_DSN</code>): пакету <code>spiral/idempotency</code> нужен атомарный
                <code>SET NX EX</code>, которого у KV-плагина нет. Локи (<code>pumpkin:{id}</code>, <code>scene</code>,
                <code>player:{id}</code>, <code>flyer:{id}</code>, <code>walkers</code> и другие) и очередь живут в памяти
                процесса RoadRunner — этого хватает, пока <code>app</code> один.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1210 750" style="min-width: 871px" role="img" aria-label="Модули Hellowin: браузер ходит напрямую на три опубликованных порта: :8080 — HTTP-плагин RoadRunner, :8000 — WebSocket Centrifugo, :3000 — Grafana. В RoadRunner три плагина с пулами PHP-воркеров: http, centrifuge и jobs, и процесс тикера под плагином service. Воркеры и тикер по RPC обращаются к плагинам kv, lock, jobs, metrics и centrifuge. Состояние игры (kv, драйвер redis) и ключи идемпотентности (predis) лежат в Valkey. Prometheus собирает метрики с RoadRunner и Centrifugo.">
<defs><marker id="a-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-group g-rr" x="440" y="146" width="530" height="450" rx="10"/>
<text class="d-h" x="456" y="168" text-anchor="start">RoadRunner 3 · контейнер app · .rr.yaml</text>
<rect class="d-box g-edge" x="20" y="200" width="150" height="90" rx="6"/>
<text class="d-t" x="95" y="233.5" text-anchor="middle">Браузер</text>
<text class="d-m" x="95" y="248.5" text-anchor="middle">centrifuge-js</text>
<text class="d-s" x="95" y="263.5" text-anchor="middle">WebSocket</text>
<rect class="d-box g-edge" x="250" y="200" width="140" height="90" rx="6"/>
<text class="d-t" x="320" y="233.5" text-anchor="middle">порты хоста</text>
<text class="d-s" x="320" y="248.5" text-anchor="middle">без прокси</text>
<text class="d-m" x="320" y="263.5" text-anchor="middle">:8080 · :8000 · :3000</text>
<rect class="d-box g-cf" x="600" y="20" width="220" height="90" rx="6"/>
<text class="d-t" x="710" y="53.5" text-anchor="middle">Centrifugo v6</text>
<text class="d-m" x="710" y="68.5" text-anchor="middle">:8000 WebSocket · :9000 /metrics</text>
<text class="d-m" x="710" y="83.5" text-anchor="middle">:10000 gRPC API</text>
<rect class="d-box g-rr" x="460" y="186" width="150" height="50" rx="6"/>
<text class="d-t" x="535" y="207" text-anchor="middle">http</text>
<text class="d-m" x="535" y="222" text-anchor="middle">:8080 static · gzip</text>
<rect class="d-box g-rr" x="640" y="186" width="150" height="50" rx="6"/>
<text class="d-t" x="715" y="207" text-anchor="middle">centrifuge</text>
<text class="d-m" x="715" y="222" text-anchor="middle">proxy :10001</text>
<rect class="d-box g-rr" x="830" y="186" width="125" height="50" rx="6"/>
<text class="d-t" x="892.5" y="207" text-anchor="middle">jobs</text>
<text class="d-m" x="892.5" y="222" text-anchor="middle">transitions</text>
<rect class="d-box g-php" x="460" y="280" width="150" height="70" rx="6"/>
<text class="d-t" x="535" y="311" text-anchor="middle">http-воркеры ×2</text>
<text class="d-m" x="535" y="326" text-anchor="middle">HomeController</text>
<rect class="d-box g-php" x="640" y="280" width="150" height="70" rx="6"/>
<text class="d-t" x="715" y="303.5" text-anchor="middle">centrifuge ×2</text>
<text class="d-m" x="715" y="318.5" text-anchor="middle">ConnectService</text>
<text class="d-m" x="715" y="333.5" text-anchor="middle">RPCService</text>
<rect class="d-box g-php" x="830" y="280" width="125" height="70" rx="6"/>
<text class="d-t" x="892.5" y="303.5" text-anchor="middle">jobs ×2</text>
<text class="d-m" x="892.5" y="318.5" text-anchor="middle">TransitionPumpkin</text>
<text class="d-m" x="892.5" y="333.5" text-anchor="middle">TransitionScene</text>
<rect class="d-box g-rr" x="460" y="384" width="495" height="24" rx="6"/>
<text class="d-m" x="707.5" y="399.5" text-anchor="middle">rpc :6001 — kv · lock · jobs.Push · metrics · centrifuge.Broadcast</text>
<rect class="d-box g-rr" x="460" y="432" width="150" height="44" rx="6"/>
<text class="d-t" x="535" y="450" text-anchor="middle">kv</text>
<text class="d-m" x="535" y="465" text-anchor="middle">hellowin: redis</text>
<rect class="d-box g-rr" x="640" y="432" width="150" height="44" rx="6"/>
<text class="d-t" x="715" y="450" text-anchor="middle">lock</text>
<text class="d-m" x="715" y="465" text-anchor="middle">pumpkin:{id}, scene, …</text>
<rect class="d-box g-rr" x="830" y="432" width="125" height="44" rx="6"/>
<text class="d-t" x="892.5" y="450" text-anchor="middle">metrics</text>
<text class="d-m" x="892.5" y="465" text-anchor="middle">:2112 /metrics</text>
<rect class="d-box g-php" x="640" y="524" width="150" height="58" rx="6"/>
<text class="d-t" x="715" y="545" text-anchor="middle">service: ticker</text>
<text class="d-m" x="715" y="560" text-anchor="middle">hellowin:ticker</text>
<text class="d-s" x="715" y="574" text-anchor="middle">один процесс, лок ticker</text>
<rect class="d-box g-kv" x="1050" y="250" width="145" height="270" rx="6"/>
<text class="d-t" x="1122.5" y="276" text-anchor="middle">Valkey</text>
<text class="d-m" x="1122.5" y="291" text-anchor="middle">valkey:6379</text>
<text class="d-s" x="1122.5" y="306" text-anchor="middle">AOF + volume</text>
<text class="d-m" x="1122.5" y="341" text-anchor="middle">hellowin:round</text>
<text class="d-m" x="1122.5" y="356" text-anchor="middle">hellowin:scene</text>
<text class="d-m" x="1122.5" y="371" text-anchor="middle">hellowin:pumpkin:*</text>
<text class="d-m" x="1122.5" y="386" text-anchor="middle">hellowin:story</text>
<text class="d-m" x="1122.5" y="401" text-anchor="middle">hellowin:shot</text>
<text class="d-m" x="1122.5" y="416" text-anchor="middle">hellowin:player:*</text>
<text class="d-m" x="1122.5" y="431" text-anchor="middle">hellowin:top</text>
<text class="d-m" x="1122.5" y="446" text-anchor="middle">hellowin:flyer:*</text>
<text class="d-m" x="1122.5" y="461" text-anchor="middle">hellowin:walkers</text>
<text class="d-m" x="1122.5" y="491" text-anchor="middle">hellowin:amo:*</text>
<text class="d-m" x="1122.5" y="506" text-anchor="middle">hellowin:lease:*</text>
<rect class="d-box g-obs" x="250" y="650" width="140" height="60" rx="6"/>
<text class="d-t" x="320" y="676" text-anchor="middle">Grafana</text>
<text class="d-m" x="320" y="691" text-anchor="middle">:3000</text>
<rect class="d-box g-obs" x="800" y="650" width="170" height="60" rx="6"/>
<text class="d-t" x="885" y="676" text-anchor="middle">Prometheus</text>
<text class="d-m" x="885" y="691" text-anchor="middle">:9090</text>
<path class="d-e" d="M170 245 L250 245" marker-end="url(#a-ln)" marker-start="url(#a-lns)"/>
<text class="d-l" x="210" y="237" text-anchor="middle">HTTP</text>
<text class="d-l" x="210" y="262" text-anchor="middle">WS</text>
<path class="d-e" d="M320 200 L320 65 L600 65" marker-end="url(#a-ln)"/>
<text class="d-l" x="460" y="57" text-anchor="middle">:8000/connection/websocket</text>
<text class="d-s" x="460" y="82" text-anchor="middle">WebSocket</text>
<path class="d-e" d="M390 211 L460 211" marker-end="url(#a-ln)"/>
<text class="d-l" x="422" y="203" text-anchor="middle">:8080</text>
<path class="d-e" d="M320 290 L320 650" marker-end="url(#a-ln)"/>
<text class="d-l" x="328" y="470" text-anchor="start">:3000</text>
<path class="d-e d-e--acc" d="M680 110 L680 186" marker-end="url(#a-acc)"/>
<text class="d-l" x="674" y="122" text-anchor="end">gRPC proxy :10001</text>
<text class="d-s" x="674" y="136" text-anchor="end">connect, rpc</text>
<path class="d-e d-e--acc" d="M760 186 L760 110" marker-end="url(#a-acc)"/>
<text class="d-l" x="766" y="122" text-anchor="start">gRPC API :10000</text>
<text class="d-s" x="766" y="136" text-anchor="start">broadcast, presence</text>
<path class="d-e" d="M535 236 L535 280" marker-end="url(#a-ln)"/>
<text class="d-s" x="541" y="262" text-anchor="start">pipes</text>
<path class="d-e" d="M715 236 L715 280" marker-end="url(#a-ln)"/>
<text class="d-s" x="721" y="262" text-anchor="start">pipes</text>
<path class="d-e" d="M892 236 L892 280" marker-end="url(#a-ln)"/>
<text class="d-s" x="898" y="262" text-anchor="start">pipes</text>
<path class="d-e" d="M535 350 L535 384" marker-end="url(#a-ln)"/>
<path class="d-e" d="M715 350 L715 384" marker-end="url(#a-ln)"/>
<path class="d-e" d="M892 350 L892 384" marker-end="url(#a-ln)"/>
<text class="d-s" x="541" y="371" text-anchor="start">goridge RPC</text>
<path class="d-e" d="M535 408 L535 432" marker-end="url(#a-ln)"/>
<path class="d-e" d="M715 408 L715 432" marker-end="url(#a-ln)"/>
<path class="d-e" d="M892 408 L892 432" marker-end="url(#a-ln)"/>
<path class="d-e" d="M640 553 L625 553 L625 408" marker-end="url(#a-ln)"/>
<path class="d-e d-e--acc" d="M800 384 L800 222 L792 222" marker-end="url(#a-acc)"/>
<path class="d-e" d="M818 384 L818 200 L828 200" marker-end="url(#a-ln)"/>
<path class="d-e d-e--kv" d="M535 476 L535 505 L1050 505" marker-end="url(#a-kv)"/>
<text class="d-l" x="700" y="498" text-anchor="start">driver redis · VALKEY_ADDR</text>
<path class="d-e d-e--kv" d="M955 315 L1050 315" marker-end="url(#a-kv)"/>
<text class="d-l" x="1002" y="308" text-anchor="middle">predis</text>
<text class="d-s" x="1002" y="332" text-anchor="middle">VALKEY_DSN</text>
<path class="d-e d-e--ok" d="M892 650 L892 476" marker-end="url(#a-ok)"/>
<text class="d-l" x="900" y="625" text-anchor="start">scrape :2112 · 5s</text>
<path class="d-e d-e--ok" d="M970 680 L1203 680 L1203 65 L820 65" marker-end="url(#a-ok)"/>
<text class="d-l" x="1010" y="57" text-anchor="middle">scrape :9000/metrics</text>
<path class="d-e d-e--ok" d="M390 680 L800 680" marker-end="url(#a-ok)"/>
<text class="d-l" x="595" y="672" text-anchor="middle">PromQL → :9090</text>
</svg>
                </div>
                <figcaption>
                    <ul class="legend">
                        <li><span class="legend__swatch" style="--swatch: var(--d-cf)"></span>gRPC между Centrifugo и RoadRunner, centrifuge.Broadcast</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-kv)"></span>в Valkey: KV-плагин и predis</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-obs)"></span>метрики: Prometheus скрейпит, Grafana спрашивает</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-line)"></span>HTTP, WebSocket, pipes, RPC, jobs.Push</li>
                    </ul>
                </figcaption>
            </figure>
        </section>

        <section class="how__section" id="page-load" role="tabpanel" aria-labelledby="tab-page-load">
            <h2>Загрузка страницы и подключение</h2>
            <p>
                <code>GET /</code> — обычный HTTP. <code>HomeController::index()</code> берёт снимок состояния у
                <code>SnapshotFactory</code>: <code>RitualStore::snapshot()</code> одним <code>kv.MGet</code> читает
                <code>hellowin:round</code>, <code>hellowin:scene</code>, сюжет <code>hellowin:story</code> и
                <code>hellowin:pumpkin:{id}</code> всех тыкв, ещё один запрос — фигурки <code>hellowin:walkers</code>. Всё
                без локов: страница может отстать на клик, это не страшно. К ним добавляются раскладка из
                <code>app/config/hellowin.php</code> и числа политики, и шаблон получает снимок (Snapshot):
            </p>
            <ul>
                <li><code>round</code>, <code>scene</code> — текущий раунд и сцена;</li>
                <li><code>sceneStages</code> — номер финальной сцены (длина <code>HELLOWIN_SCENE_STAGE_MAX</code>), <code>stageMax</code> — последняя стадия тыквы в текущей сцене;</li>
                <li><code>threshold</code> — порог тыквы, лимит <code>pumpkin</code> (<a href="#limits">«Лимиты»</a>); <code>limits</code> — лимиты текущей сцены с числом игроков <code>players</code> и множителем <code>k</code>;</li>
                <li><code>elements</code> — тыквы: <code>id</code>, <code>type</code>, <code>x</code>, <code>y</code>, <code>size</code>, <code>stage</code> и <code>clicks</code> с момента перехода в текущую стадию; <code>bushes</code> — кусты, из которых взлетают летуны;</li>
                <li><code>walkers</code> — фигурки игроков на поле с их путями;</li>
                <li>сюжет: <code>ready</code> — сцена готова и ждёт модератора, <code>finale</code> — шаг финала (с шага разоблачения — и <code>reveal</code>), <code>paused</code> — пауза прогресса;</li>
                <li><code>now</code> — серверное время в мс: по нему клиент считает смещение своих часов для летунов и фигурок.</li>
            </ul>
            <p>
                Затем браузер открывает WebSocket и передаёт в <code>data</code> подключения секрет игрока из
                <code>localStorage</code>: <code>{playerSecret}</code>. Centrifugo сам о пользователях ничего не знает и
                на connect спрашивает приложение (connect proxy). <code>ConnectService</code> отвечает
                <code>user</code> — публичным id игрока, вычисленным из секрета (без секрета — анонимно), списком
                <code>channels: ['{{ $channel }}']</code> — это server-side подписка — и <code>meta</code> подключения
                (роль модератора, см. <a href="#live">«Модератор, фигурки, летуны»</a>). Дальше Centrifugo сам подставляет
                <code>user</code> в каждый RPC: клиент не шлёт свой id и не может выдать себя за другого. Клиент сам
                <code>subscribe</code> не вызывает: в <code>config.json</code> клиентские подписки и публикации
                запрещены, сообщения канала приходят в <code>centrifuge.on('publication')</code>.
            </p>
            <p>
                Тот же снимок отдаёт RPC <code>state</code>. Каждое событие канала несёт <code>round</code>: если он не
                совпал с раундом клиента, клиент пропустил сброс и перезапрашивает снимок; так же — после
                переподключения, когда события за время обрыва потеряны.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 974" style="min-width: 720px" role="img" aria-label="Загрузка страницы: GET / проходит middleware HTTP-плагина RoadRunner в пул воркеров и HomeController; SnapshotFactory без лока читает раунд, сцену, сюжет, тыквы и фигурки из KV и отдаёт снимок в шаблон. Затем браузер открывает WebSocket напрямую к Centrifugo на :8000 с секретом игрока в data, Centrifugo спрашивает ConnectService через connect proxy, получает user, meta и канал и подписывает клиента на канал hellowin на стороне сервера. При переподключении или событии с чужим раундом клиент вызывает RPC state и получает тот же снимок.">
<defs><marker id="b-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-life" d="M62 60 V974"/>
<path class="d-life" d="M180 60 V974"/>
<path class="d-life" d="M298 60 V974"/>
<path class="d-life" d="M422 60 V974"/>
<path class="d-life" d="M546 60 V974"/>
<path class="d-life" d="M670 60 V974"/>
<path class="d-life" d="M790 60 V974"/>
<path class="d-life" d="M925 60 V974"/>
<text class="d-h d-phase" x="14" y="88" text-anchor="start">1. HTML со снимком</text>
<path class="d-e " d="M64 116 L178 116" marker-end="url(#b-ln)"/>
<text class="d-l" x="121" y="110" text-anchor="middle">GET /</text>
<path class="d-e " d="M182 146 L296 146" marker-end="url(#b-ln)"/>
<text class="d-l" x="239" y="140" text-anchor="middle">мимо static</text>
<path class="d-e " d="M300 176 L420 176" marker-end="url(#b-ln)"/>
<text class="d-l" x="360" y="170" text-anchor="middle">pipes</text>
<path class="d-e " d="M424 206 L544 206" marker-end="url(#b-ln)"/>
<text class="d-l" x="484" y="200" text-anchor="middle">kv.MGet, без лока</text>
<path class="d-e d-e--ret" d="M544 236 L424 236" marker-end="url(#b-ln)"/>
<text class="d-l" x="484" y="230" text-anchor="middle">состояние игры</text>
<path class="d-e " d="M422 258 L446 258 L446 270 L424 270" marker-end="url(#b-ln)"/>
<text class="d-l" x="452" y="267" text-anchor="start">Snapshot: ритуал, сюжет, фигурки, раскладка, now</text>
<path class="d-e " d="M422 288 L446 288 L446 300 L424 300" marker-end="url(#b-ln)"/>
<text class="d-l" x="452" y="297" text-anchor="start">render('home', snapshot + centrifugoUrl от хоста)</text>
<path class="d-e d-e--ret" d="M420 326 L64 326" marker-end="url(#b-ln)"/>
<text class="d-l" x="242" y="320" text-anchor="middle">200 text/html (обратно через gzip и headers)</text>
<path class="d-e " d="M62 348 L86 348 L86 360 L64 360" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="357" text-anchor="start">сцена по снимку · new Centrifuge(url, {data: {playerSecret}})</text>
<text class="d-h d-phase" x="14" y="382" text-anchor="start">2. WebSocket</text>
<path class="d-e " d="M64 440 L668 440" marker-end="url(#b-ln)"/>
<text class="d-l" x="366" y="434" text-anchor="middle">ws://&lt;host&gt;:8000/connection/websocket</text>
<path class="d-e d-e--acc" d="M672 470 L788 470" marker-end="url(#b-acc)"/>
<text class="d-l" x="730" y="464" text-anchor="middle">connect proxy (gRPC)</text>
<path class="d-e " d="M792 500 L923 500" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="494" text-anchor="middle">pipes</text>
<path class="d-e d-e--ret" d="M923 530 L792 530" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="524" text-anchor="middle">user, channels, meta</text>
<path class="d-e d-e--ret" d="M788 560 L672 560" marker-end="url(#b-ln)"/>
<text class="d-l" x="730" y="554" text-anchor="middle">ответ</text>
<path class="d-e " d="M670 582 L694 582 L694 594 L672 594" marker-end="url(#b-ln)"/>
<text class="d-l" x="700" y="591" text-anchor="start">подписать на hellowin (server-side)</text>
<path class="d-e d-e--ret d-e--acc" d="M668 620 L64 620" marker-end="url(#b-acc)"/>
<text class="d-l" x="366" y="614" text-anchor="middle">connected + subs: hellowin</text>
<text class="d-h d-phase" x="14" y="646" text-anchor="start">3. Пересинхронизация: RPC state</text>
<path class="d-e " d="M62 666 L86 666 L86 678 L64 678" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="675" text-anchor="start">переподключился или пришло событие с чужим round</text>
<path class="d-e " d="M64 704 L668 704" marker-end="url(#b-ln)"/>
<text class="d-l" x="366" y="698" text-anchor="middle">rpc('state', {}) — по тому же WebSocket</text>
<path class="d-e d-e--acc" d="M672 734 L788 734" marker-end="url(#b-acc)"/>
<text class="d-l" x="730" y="728" text-anchor="middle">RPC proxy (gRPC)</text>
<path class="d-e " d="M792 764 L923 764" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="758" text-anchor="middle">pipes</text>
<path class="d-e " d="M923 794 L548 794" marker-end="url(#b-ln)"/>
<text class="d-l" x="735.5" y="788" text-anchor="middle">SnapshotFactory::create() → kv.MGet</text>
<path class="d-e d-e--ret" d="M548 824 L923 824" marker-end="url(#b-ln)"/>
<text class="d-l" x="735.5" y="818" text-anchor="middle">ритуал, сюжет, фигурки</text>
<path class="d-e d-e--ret" d="M923 854 L792 854" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="848" text-anchor="middle">{ok: true, state}</text>
<path class="d-e d-e--ret" d="M788 884 L672 884" marker-end="url(#b-ln)"/>
<text class="d-l" x="730" y="878" text-anchor="middle">ответ RPC</text>
<path class="d-e d-e--ret" d="M668 914 L64 914" marker-end="url(#b-ln)"/>
<text class="d-l" x="366" y="908" text-anchor="middle">{ok: true, state: Snapshot}</text>
<path class="d-e " d="M62 936 L86 936 L86 948 L64 948" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="945" text-anchor="start">перестроить сцену по снимку</text>
<rect class="d-box g-edge" x="5" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="62" y="34" text-anchor="middle">Браузер</text>
<text class="d-m" x="62" y="49" text-anchor="middle">centrifuge-js</text>
<rect class="d-box g-edge" x="123" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="180" y="34" text-anchor="middle">RR middleware</text>
<text class="d-m" x="180" y="49" text-anchor="middle">:8080</text>
<rect class="d-box g-rr" x="241" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="298" y="34" text-anchor="middle">RR http</text>
<text class="d-m" x="298" y="49" text-anchor="middle">пул воркеров</text>
<rect class="d-box g-php" x="359" y="16" width="126" height="44" rx="6"/>
<text class="d-t" x="422" y="34" text-anchor="middle">HomeController</text>
<text class="d-m" x="422" y="49" text-anchor="middle">→ SnapshotFactory</text>
<rect class="d-box g-rr" x="489" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="546" y="34" text-anchor="middle">RR kv → Valkey</text>
<text class="d-m" x="546" y="49" text-anchor="middle">hellowin</text>
<rect class="d-box g-cf" x="613" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="670" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="670" y="49" text-anchor="middle">:8000</text>
<rect class="d-box g-rr" x="733" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="790" y="34" text-anchor="middle">RR centrifuge</text>
<text class="d-m" x="790" y="49" text-anchor="middle">:10001</text>
<rect class="d-box g-php" x="857" y="16" width="136" height="44" rx="6"/>
<text class="d-t" x="925" y="34" text-anchor="middle">ConnectService</text>
<text class="d-m" x="925" y="49" text-anchor="middle">RPCService</text>
</svg>
                </div>
                <figcaption>Сплошные стрелки — запросы, пунктир — ответы. Фиолетовые — путь через Centrifugo.</figcaption>
            </figure>
        </section>

        <section class="how__section" id="click" role="tabpanel" aria-labelledby="tab-click">
            <h2>Что происходит при клике</h2>
            <p>
                Клик — не HTTP-запрос, а RPC поверх того же WebSocket:
                <code>centrifuge.rpc('click', {element: 'pumpkin-3'})</code>. Centrifugo проксирует его в
                RoadRunner, тот отдаёт вызов свободному воркеру из пула <code>centrifuge</code>, где
                <code>RPCService</code> передаёт его в <code>ClickHandler::click()</code>.
            </p>
            <p>Ответ уходит в Centrifugo через <code>$request-&gt;respond()</code>, а метрика вызова пишется в <code>finally</code> — даже если обработчик бросил исключение:</p>
            <figure class="code">
                <figcaption><code>app/src/Endpoint/Centrifugo/RPCService.php</code> · <code>RPCService::handle()</code></figcaption>
                <pre><code>public function handle(RequestInterface $request): void
{
    \assert($request instanceof RPC);

    $started = \hrtime(true);
    $method = (string) $request-&gt;method;
    $result = 'exception';
    try {
        $answer = $this-&gt;answer($request, $method);
        $request-&gt;respond(new RPCResponse($answer));
        $result = self::result($answer);
    } finally {
        $known = \in_array($method, self::PUBLIC, true) || \in_array($method, self::CONSOLE, true);
        $this-&gt;metrics-&gt;rpc($known ? $method : 'unknown', $result, (float) (\hrtime(true) - $started) / 1e9);
        // …
    }
}</code></pre>
            </figure>
            <ol>
                <li>Элемент проверяется по <code>ElementRegistry</code> (id из <code>app/config/hellowin.php</code>).</li>
                <li><code>RitualMetrics</code> добавляет 1 к <code>hellowin_clicks_total</code>.</li>
                <li><code>RitualStore::click()</code> под локом <code>pumpkin:{id}</code> увеличивает <code>clicks</code> тыквы в KV (и <code>accepted</code> — принятые клики раунда, для разоблачения в финале) и тут же, под тем же локом, читает раунд и сцену. Лок только на эту тыкву: клики по разным тыквам друг друга не ждут.</li>
                <li><code>PlayerService</code> под локом <code>player:{id}</code> добавляет клик в статистику игрока — <code>user</code> подключения; анонимный клик статистику пропускает. Сбой статистики пишется в лог и клик не ломает.</li>
                <li><code>ChannelPublisher</code> публикует в канал <code>{{ $channel }}</code> событие <code>click</code> (с <code>player</code>, если клик не анонимный) — всем подписчикам сразу.</li>
                <li><code>LockstepPolicy::pumpkinTransition()</code> решает, пора ли тыкве дальше: <code>clicks</code> не меньше порога тыквы (лимит <code>pumpkin</code>) и стадия ниже <code>stageMax</code> текущей сцены. Если да, <code>TransitionQueue</code> ставит в очередь задачу <code>TransitionPumpkin</code>, а <code>hellowin_transition_jobs_pushed_total{kind="pumpkin"}</code> растёт на 1.</li>
                <li>Ответ RPC — всегда данные: <code>{ok: true}</code> или <code>{ok: false, error: 'unknown element' | 'busy' | 'internal error'}</code>; <code>busy</code> — лок тыквы не удалось взять за 5 секунд.</li>
            </ol>
            <p class="callout">
                Сам клик стадию не меняет — он только ставит задачу. Пока задача не выполнена, <code>clicks</code>
                остаётся выше порога, и <strong>каждый</strong> следующий клик ставит ещё одну такую же задачу. Дубли
                намеренные: их отсекает идемпотентность, а клики сверх порога сгорают. Подробно — на вкладке
                <a href="#idempotency">«Идемпотентный переход»</a>.
            </p>
            <p class="callout">
                Свой клик браузер локально не рисует. Эффект появляется, только когда из канала приходит publication —
                у кликнувшего так же, как у всех. Поэтому на экране видна честная задержка круга
                браузер → сервер → браузер.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1100 874" style="min-width: 792px" role="img" aria-label="Клик: браузер вызывает RPC click через Centrifugo, RoadRunner передаёт его в RPCService и ClickHandler. ClickHandler пишет метрику, под локом pumpkin:{id} увеличивает clicks тыквы в KV и читает раунд и сцену, отпускает лок и рассылает событие click всем клиентам канала, включая кликнувшего. Затем LockstepPolicy решает, пора ли тыкве в следующую стадию; если да — в очередь jobs уходит задача TransitionPumpkin.">
<defs><marker id="c-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-crit" x="544" y="272" width="12" height="210" rx="2"/>
<path class="d-life" d="M60 60 V874"/>
<path class="d-life" d="M180 60 V874"/>
<path class="d-life" d="M298 60 V874"/>
<path class="d-life" d="M416 60 V874"/>
<path class="d-life" d="M550 60 V874"/>
<path class="d-life" d="M680 60 V874"/>
<path class="d-life" d="M796 60 V874"/>
<path class="d-life" d="M914 60 V874"/>
<path class="d-life" d="M1034 60 V874"/>
<path class="d-e " d="M180 84 L204 84 L204 96 L182 96" marker-end="url(#c-ln)"/>
<text class="d-l" x="210" y="93" text-anchor="start">клик: локально ничего не рисуем</text>
<path class="d-e " d="M182 122 L296 122" marker-end="url(#c-ln)"/>
<text class="d-l" x="239" y="116" text-anchor="middle">rpc('click', {element})</text>
<path class="d-e d-e--acc" d="M300 152 L414 152" marker-end="url(#c-acc)"/>
<text class="d-l" x="357" y="146" text-anchor="middle">RPC proxy (gRPC)</text>
<path class="d-e " d="M418 182 L548 182" marker-end="url(#c-ln)"/>
<text class="d-l" x="483" y="176" text-anchor="middle">pipes</text>
<path class="d-e " d="M550 204 L574 204 L574 216 L552 216" marker-end="url(#c-ln)"/>
<text class="d-l" x="580" y="213" text-anchor="start">ElementRegistry::find()</text>
<path class="d-e " d="M552 242 L678 242" marker-end="url(#c-ln)"/>
<text class="d-l" x="615" y="236" text-anchor="middle">clicks_total +1</text>
<path class="d-e " d="M552 272 L794 272" marker-end="url(#c-ln)"/>
<text class="d-l" x="673" y="266" text-anchor="middle">lock.Lock pumpkin:pumpkin-3</text>
<path class="d-e d-e--ret" d="M794 302 L552 302" marker-end="url(#c-ln)"/>
<text class="d-l" x="673" y="296" text-anchor="middle">lockId</text>
<path class="d-e " d="M552 332 L912 332" marker-end="url(#c-ln)"/>
<text class="d-l" x="732" y="326" text-anchor="middle">get hellowin:pumpkin:pumpkin-3</text>
<path class="d-e d-e--ret" d="M912 362 L552 362" marker-end="url(#c-ln)"/>
<text class="d-l" x="732" y="356" text-anchor="middle">{stage, clicks, accepted}</text>
<path class="d-e " d="M552 392 L912 392" marker-end="url(#c-ln)"/>
<text class="d-l" x="732" y="386" text-anchor="middle">set {stage, clicks + 1, accepted + 1}</text>
<path class="d-e " d="M552 422 L912 422" marker-end="url(#c-ln)"/>
<text class="d-l" x="732" y="416" text-anchor="middle">get hellowin:round, hellowin:scene</text>
<path class="d-e d-e--ret" d="M912 452 L552 452" marker-end="url(#c-ln)"/>
<text class="d-l" x="732" y="446" text-anchor="middle">round, scene</text>
<path class="d-e " d="M552 482 L794 482" marker-end="url(#c-ln)"/>
<text class="d-l" x="673" y="476" text-anchor="middle">lock.Release</text>
<path class="d-e d-e--acc" d="M548 512 L418 512" marker-end="url(#c-acc)"/>
<text class="d-l" x="483" y="506" text-anchor="middle">centrifuge.Broadcast</text>
<path class="d-e d-e--acc" d="M414 542 L300 542" marker-end="url(#c-acc)"/>
<text class="d-l" x="357" y="536" text-anchor="middle">gRPC API :10000</text>
<path class="d-e d-e--acc" d="M296 572 L182 572" marker-end="url(#c-acc)"/>
<text class="d-l" x="239" y="566" text-anchor="middle">publication type:'click'</text>
<path class="d-e d-e--acc" d="M296 602 L62 602" marker-end="url(#c-acc)"/>
<text class="d-l" x="179" y="596" text-anchor="middle">та же publication</text>
<path class="d-e " d="M60 624 L84 624 L84 636 L62 636" marker-end="url(#c-ln)"/>
<text class="d-l" x="90" y="633" text-anchor="start">эффект клика</text>
<path class="d-e " d="M180 624 L204 624 L204 636 L182 636" marker-end="url(#c-ln)"/>
<text class="d-l" x="210" y="633" text-anchor="start">эффект клика + прогресс</text>
<path class="d-e " d="M550 654 L574 654 L574 666 L552 666" marker-end="url(#c-ln)"/>
<text class="d-l" x="580" y="663" text-anchor="start">LockstepPolicy::pumpkinTransition(): clicks ≥ порог и stage &lt; stageMax?</text>
<path class="d-e " d="M552 692 L1032 692" marker-end="url(#c-ln)"/>
<text class="d-l" x="792" y="686" text-anchor="middle">да: jobs.Push TransitionPumpkin {key, round, pumpkin, toStage, player}</text>
<path class="d-e " d="M552 722 L678 722" marker-end="url(#c-ln)"/>
<text class="d-l" x="615" y="716" text-anchor="middle">jobs_pushed +1</text>
<path class="d-e d-e--ret" d="M548 752 L418 752" marker-end="url(#c-ln)"/>
<text class="d-l" x="483" y="746" text-anchor="middle">RPCResponse {ok: true}</text>
<path class="d-e d-e--ret" d="M414 782 L300 782" marker-end="url(#c-ln)"/>
<text class="d-l" x="357" y="776" text-anchor="middle">ответ RPC</text>
<path class="d-e d-e--ret" d="M296 812 L182 812" marker-end="url(#c-ln)"/>
<text class="d-l" x="239" y="806" text-anchor="middle">{ok: true}</text>
<rect class="d-note " x="248" y="828" width="836" height="24" rx="4"/>
<text class="d-n" x="666" y="845" text-anchor="middle">каждый следующий клик сверх порога — ещё один TransitionPumpkin с тем же key, пока переход не выполнен</text>
<rect class="d-box g-edge" x="4" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="60" y="34" text-anchor="middle">Другие вкладки</text>
<text class="d-s" x="60" y="49" text-anchor="middle">канал hellowin</text>
<rect class="d-box g-edge" x="124" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="180" y="34" text-anchor="middle">Браузер (клик)</text>
<text class="d-m" x="180" y="49" text-anchor="middle">centrifuge-js</text>
<rect class="d-box g-cf" x="244" y="16" width="108" height="44" rx="6"/>
<text class="d-t" x="298" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="298" y="49" text-anchor="middle">:8000</text>
<rect class="d-box g-rr" x="360" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="416" y="34" text-anchor="middle">RR centrifuge</text>
<text class="d-m" x="416" y="49" text-anchor="middle">:10001</text>
<rect class="d-box g-php" x="482" y="16" width="136" height="44" rx="6"/>
<text class="d-t" x="550" y="34" text-anchor="middle">RPCService</text>
<text class="d-m" x="550" y="49" text-anchor="middle">→ ClickHandler</text>
<rect class="d-box g-rr" x="628" y="16" width="104" height="44" rx="6"/>
<text class="d-t" x="680" y="34" text-anchor="middle">RR metrics</text>
<text class="d-m" x="680" y="49" text-anchor="middle">:2112</text>
<rect class="d-box g-rr" x="740" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="796" y="34" text-anchor="middle">RR lock</text>
<text class="d-m" x="796" y="49" text-anchor="middle">pumpkin:{id}</text>
<rect class="d-box g-rr" x="860" y="16" width="108" height="44" rx="6"/>
<text class="d-t" x="914" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="914" y="49" text-anchor="middle">Valkey</text>
<rect class="d-box g-rr" x="976" y="16" width="116" height="44" rx="6"/>
<text class="d-t" x="1034" y="34" text-anchor="middle">RR jobs</text>
<text class="d-m" x="1034" y="49" text-anchor="middle">transitions</text>
<text class="d-s" x="538" y="371" text-anchor="end">под локом тыквы:</text>
<text class="d-m" x="538" y="385" text-anchor="end">read-modify-write</text>
</svg>
                </div>
                <figcaption>
                    Оранжевая полоса на линии воркера — время, пока он держит лок <code>pumpkin:{id}</code>.
                    Метрики, публикация и постановка задачи — вне лока.
                </figcaption>
            </figure>
            <p>Событие клика собирает <code>ChannelPublisher::click()</code>:</p>
            <figure class="code">
                <figcaption><code>app/src/Application/Hellowin/ChannelPublisher.php</code> · <code>ChannelPublisher::click()</code></figcaption>
                <pre><code>public function click(int $round, Pumpkin $pumpkin, string $emoji, int $threshold, ?Player $player = null, bool $counted = true): void
{
    $this-&gt;publish(self::counted(self::withPlayer([
        'type' =&gt; 'click',
        'round' =&gt; $round,
        'element' =&gt; $pumpkin-&gt;id,
        'emoji' =&gt; $emoji,
        'clicks' =&gt; $pumpkin-&gt;clicks,
        'threshold' =&gt; $threshold,
        'stage' =&gt; $pumpkin-&gt;stage,
    ], $player), $counted));
}</code></pre>
            </figure>
            <p>В канал оно уходит JSON-строкой:</p>
            <pre><code>{"type":"click","round":…,"element":"pumpkin-3","emoji":"🕸️","clicks":…,"threshold":…,"stage":…}</code></pre>
        </section>

        <section class="how__section" id="stages" role="tabpanel" aria-labelledby="tab-stages">
            <h2>Сцены, стадии тыкв и локи</h2>
            <p>
                Движок знает только номера: <code>round</code>, <code>scene</code> и у каждой тыквы
                <code>{stage, clicks}</code>. Как это выглядит, решает фронтенд. Когда что двигается, решает политика —
                интерфейс <code>RitualPolicy</code>, реализация <code>LockstepPolicy</code>: одна точка в коде, её можно
                заменить, не трогая движок.
            </p>
            <ul>
                <li>Сцены нумеруются от 0 до финальной N — это длина списка <code>HELLOWIN_SCENE_STAGE_MAX</code>.</li>
                <li>У сцены s потолок стадий <code>stageMax(s)</code> — элемент номер s того же списка, накопительно; у финала — тот же, что у сцены перед ним.</li>
                <li>Если список начинается с 0, сцена 0 — <strong>бар</strong>: тыкв там нет (клик по ним — <code>locked</code>, <code>RitualPolicy::bar()</code>), сцена готова, когда зал выпил цель бара или модератор дал отмашку, — и он выводит всех на грядку. Подробно — <a href="#bar">«Бар»</a>.</li>
                <li>Тыква переходит в <code>stage + 1</code>, набрав порог кликов — лимит <code>pumpkin</code> (<a href="#limits">«Лимиты»</a>), — пока <code>stage &lt; stageMax(scene)</code>; переход обнуляет <code>clicks</code>.</li>
                <li>Когда <strong>все</strong> тыквы дошли до <code>stageMax</code>, сцена <strong>готова</strong>, но сама не переключается: событие <code>ready</code>, сова говорит «всё готово», и сцена ждёт модератора. Следующую включает кнопка пульта «Следующая сцена» (RPC <code>scene.next</code>); если зал застрял, модератор может переключить и неготовую сцену (<code>force</code>). Подробно — <a href="#scene-transition">«Переход сцены»</a>.</li>
                <li>В финальной сцене тыквы не переходят, клики только рассылаются. Модератор ведёт шаги финала, а после разоблачения начинается бесконечный «проклятый мир».</li>
            </ul>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 330" style="min-width: 720px" role="img" aria-label="Сцены и стадии: по горизонтали сцены от 0 до финальной N, по вертикали стадия тыквы. В каждой сцене тыквы поднимаются по стадиям до stageMax этой сцены и ждут там; когда все дошли, сцена готова, и по кнопке модератора она меняется, а потолок поднимается до stageMax следующей сцены. В финальной сцене переходов нет.">
<defs><marker id="d-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-axis" d="M170 50 V260 H980"/>
<path class="d-cap" d="M170 190 H380 V140 H590 V80 H980"/>
<text class="d-m" x="160" y="264" text-anchor="end">0</text>
<text class="d-m" x="160" y="194" text-anchor="end">stageMax(0)</text>
<text class="d-m" x="160" y="144" text-anchor="end">stageMax(1)</text>
<text class="d-m" x="160" y="84" text-anchor="end">stageMax(N−1)</text>
<text class="d-s" x="20" y="30" text-anchor="start">стадия тыквы</text>
<text class="d-h" x="275" y="30" text-anchor="middle">сцена 0</text>
<text class="d-h" x="485" y="30" text-anchor="middle">сцена 1</text>
<text class="d-h" x="690" y="30" text-anchor="middle">…</text>
<text class="d-h" x="885" y="30" text-anchor="middle">сцена N — финал</text>
<path class="d-life" d="M380 44 V260"/>
<path class="d-life" d="M590 44 V260"/>
<path class="d-life" d="M790 44 V260"/>
<circle class="d-pk" cx="200" cy="253" r="7"/>
<circle class="d-pk" cx="232" cy="218" r="7"/>
<circle class="d-pk" cx="264" cy="183" r="7"/>
<circle class="d-pk" cx="296" cy="183" r="7"/>
<circle class="d-pk" cx="328" cy="218" r="7"/>
<circle class="d-pk" cx="356" cy="183" r="7"/>
<path class="d-e" d="M232 222 L232 202" marker-end="url(#d-ln)"/>
<circle class="d-pk" cx="410" cy="183" r="7"/>
<circle class="d-pk" cx="442" cy="158" r="7"/>
<circle class="d-pk" cx="474" cy="133" r="7"/>
<circle class="d-pk" cx="506" cy="183" r="7"/>
<circle class="d-pk" cx="538" cy="158" r="7"/>
<circle class="d-pk" cx="566" cy="133" r="7"/>
<circle class="d-pk d-pk--done" cx="820" cy="73" r="7"/>
<circle class="d-pk d-pk--done" cx="852" cy="73" r="7"/>
<circle class="d-pk d-pk--done" cx="884" cy="73" r="7"/>
<circle class="d-pk d-pk--done" cx="916" cy="73" r="7"/>
<circle class="d-pk d-pk--done" cx="948" cy="73" r="7"/>
<text class="d-s" x="885" y="120" text-anchor="middle">переходов нет,</text>
<text class="d-s" x="885" y="135" text-anchor="middle">клики только рассылаются</text>
<text class="d-s" x="690" y="175" text-anchor="middle">тыквы идут к stageMax(s)</text>
<text class="d-s" x="690" y="190" text-anchor="middle">и ждут остальных</text>
<text class="d-s" x="275" y="282" text-anchor="middle">стадия +1 = порог кликов (лимит pumpkin)</text>
<text class="d-m" x="275" y="297" text-anchor="middle">→ TransitionPumpkin</text>
<text class="d-s" x="485" y="282" text-anchor="middle">все на stageMax(0) → ready, ждём</text>
<text class="d-m" x="485" y="297" text-anchor="middle">scene.next → TransitionScene</text>
<text class="d-s" x="790" y="282" text-anchor="middle">ready в сцене N−1</text>
<text class="d-m" x="790" y="297" text-anchor="middle">scene.next → финал</text>
<text class="d-s" x="575" y="318" text-anchor="middle">N = длина списка HELLOWIN_SCENE_STAGE_MAX, stageMax(s) — его элемент номер s; потолок финала = stageMax(N−1)</text>
</svg>
                </div>
                <figcaption>Оранжевая ступенька — потолок стадий <code>stageMax(s)</code>; кружки — тыквы.</figcaption>
            </figure>
            <p>Игрок один на поле, множитель — 1: порог — {{ $clicksPerStage }} кликов на каждую стадию, так проходит тыква внутри сцены:</p>
            <ol class="stages" start="0">
                <li class="stages__item"><b>0</b>старт раунда</li>
                <li class="stages__item"><b>1</b>с {{ $clicksPerStage }} кликов</li>
                <li class="stages__item"><b>2</b>ещё {{ $clicksPerStage }}</li>
                <li class="stages__item"><b>…</b>по {{ $clicksPerStage }}</li>
                <li class="stages__item"><b>stageMax</b>ждёт остальных</li>
            </ol>

            <h3 id="limits">Лимиты: база, игроки, множитель</h3>
            <p>
                Порог тыквы — не одно число на все случаи: один игрок прокликает его за минуту, а сорок — за секунду.
                Поэтому все числа ритуала — клики на стадию тыквы, костра и круга, цель бара, обе цели проклятого мира,
                здоровье FrankenPHP — считаются одной формулой от числа игроков на поле:
            </p>
            <p><code>лимит = max(1, ⌈k × (BASE + PER_PLAYER × (n − 1))⌉) + надбавка</code></p>
            <ul>
                <li><strong>BASE</strong> и <strong>PER_PLAYER</strong> — env элемента <code>HELLOWIN_&lt;X&gt;_BASE</code> и
                    <code>HELLOWIN_&lt;X&gt;_PER_PLAYER</code>, X — <code>PUMPKIN</code>, <code>BONFIRE</code>,
                    <code>CIRCLE</code>, <code>BAR</code>, <code>CURSED_DRINKS</code>, <code>CURSED_ZOMBIES</code>,
                    <code>BOSS_HP</code>: сколько нужно одному и сколько прибавляет каждый следующий. Число стадий формула
                    не трогает — только клики на стадию.</li>
                <li><strong>n</strong> — фигурки на поле, не меньше одной. <code>LimitsService</code> считает их тикером раз в
                    секунду и принимает новое n, когда оно продержалось <code>LimitsService::DEBOUNCE</code>: зашёл и вышел —
                    лимиты не дёргаются.</li>
                <li><strong>k</strong> — множитель модератора (RPC <code>thresholds</code>), один на все лимиты: подогнать
                    ритуал под зал на ходу.</li>
                <li><strong>Надбавка</strong> — ручная, с пульта: цель бара, цели проклятого мира, здоровье босса. Живёт до
                    конца раунда; у кликовых порогов её нет.</li>
            </ul>
            <p>
                Изменился любой из входов — <code>LimitsService::settle</code> сравнивает лимиты текущей сцены с последними
                сказанными и, если они разошлись, публикует событие <code>limits {players, k, burst, limits}</code>:
                браузеры обновляют пороги, пульт показывает получившиеся числа. Лимит, упавший ниже накопленного,
                прогресс не отнимает: сервер сам ставит переход стадии (тот же идемпотентный <code>TransitionPumpkin</code>,
                что и после клика) или готовность бара и проклятого мира, не дожидаясь клика. У FrankenPHP сохраняется урон:
                <code>hp = max(1, max − урон)</code>.
            </p>
            <p>
                Тем же числом игроков растёт волна летунов, которую модератор запускает кнопкой «Пуск летунов» (RPC
                <code>flyers.burst</code>): <code>⌈HELLOWIN_FLYER_BURST_BASE + HELLOWIN_FLYER_BURST_PER_PLAYER ×
                (n − 1)⌉</code> летунов текущего набора разом. Ни кулдауна, ни потолка: сколько раз нажал — столько волн.
            </p>

            <h3>Зачем лок на тыкву</h3>
            <p>
                Клики обрабатывают несколько воркеров параллельно (в пуле <code>centrifuge</code> их <code>RR_CENTRIFUGE_WORKERS</code>), и по одной
                тыкве кликают многие. Увеличение счётчика — read-modify-write: прочитать <code>{stage, clicks}</code>,
                +1, записать; атомарного инкремента у KV-плагина нет. Без лока два воркера прочитают одно и то же
                значение, и один клик пропадёт.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 442" style="min-width: 720px" role="img" aria-label="Слева без лока: два воркера читают clicks = 2 и оба пишут 3, один клик теряется. Справа с локом pumpkin:{id}: второй воркер ждёт, читает уже 3 и пишет 4.">
<defs><marker id="e-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-crit" x="595" y="96" width="10" height="148" rx="2"/>
<rect class="d-wait" x="935" y="124" width="10" height="140" rx="2"/>
<rect class="d-crit" x="935" y="264" width="10" height="120" rx="2"/>
<path class="d-life" d="M600 72 V302"/>
<path class="d-life" d="M770 72 V302"/>
<path class="d-life" d="M940 72 V302"/>
<path class="d-life" d="M80 72 V302"/>
<path class="d-life" d="M250 72 V302"/>
<path class="d-life" d="M420 72 V302"/>
<text class="d-h d-bad" x="20" y="18" text-anchor="start">Без лока: гонка</text>
<text class="d-h d-ok" x="540" y="18" text-anchor="start">С локом pumpkin:{id} (RitualStore::click)</text>
<path class="d-e " d="M82 104 L248 104" marker-end="url(#e-ln)"/>
<text class="d-l" x="165" y="98" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M248 132 L82 132" marker-end="url(#e-ln)"/>
<text class="d-l" x="165" y="126" text-anchor="middle">2</text>
<path class="d-e " d="M418 160 L252 160" marker-end="url(#e-ln)"/>
<text class="d-l" x="335" y="154" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M252 188 L418 188" marker-end="url(#e-ln)"/>
<text class="d-l" x="335" y="182" text-anchor="middle">2</text>
<path class="d-e " d="M82 216 L248 216" marker-end="url(#e-ln)"/>
<text class="d-l" x="165" y="210" text-anchor="middle">set 3</text>
<path class="d-e " d="M418 244 L252 244" marker-end="url(#e-ln)"/>
<text class="d-l" x="335" y="238" text-anchor="middle">set 3</text>
<rect class="d-note d-note--bad" x="40" y="258" width="420" height="24" rx="4"/>
<text class="d-n" x="250" y="275" text-anchor="middle">итог 3 вместо 4: клик потерян</text>
<rect class="d-box g-php" x="23" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="80" y="46" text-anchor="middle">Воркер 1</text>
<text class="d-s" x="80" y="61" text-anchor="middle">pumpkin-3</text>
<rect class="d-box g-rr" x="193" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="250" y="46" text-anchor="middle">RR kv</text>
<text class="d-m" x="250" y="61" text-anchor="middle">clicks</text>
<rect class="d-box g-php" x="363" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="420" y="46" text-anchor="middle">Воркер 2</text>
<text class="d-s" x="420" y="61" text-anchor="middle">pumpkin-3</text>
<path class="d-e " d="M600 96 L624 96 L624 108 L602 108" marker-end="url(#e-ln)"/>
<text class="d-l" x="630" y="105" text-anchor="start">lock.Lock → lockId</text>
<path class="d-e " d="M940 124 L916 124 L916 136 L938 136" marker-end="url(#e-ln)"/>
<text class="d-l" x="910" y="133" text-anchor="end">lock.Lock: ждёт</text>
<path class="d-e " d="M602 160 L768 160" marker-end="url(#e-ln)"/>
<text class="d-l" x="685" y="154" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M768 188 L602 188" marker-end="url(#e-ln)"/>
<text class="d-l" x="685" y="182" text-anchor="middle">2</text>
<path class="d-e " d="M602 216 L768 216" marker-end="url(#e-ln)"/>
<text class="d-l" x="685" y="210" text-anchor="middle">set 3</text>
<path class="d-e " d="M600 236 L624 236 L624 248 L602 248" marker-end="url(#e-ln)"/>
<text class="d-l" x="630" y="245" text-anchor="start">lock.Release</text>
<path class="d-e " d="M940 264 L916 264 L916 276 L938 276" marker-end="url(#e-ln)"/>
<text class="d-l" x="910" y="273" text-anchor="end">лок получен</text>
<path class="d-e " d="M938 300 L772 300" marker-end="url(#e-ln)"/>
<text class="d-l" x="855" y="294" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M772 328 L938 328" marker-end="url(#e-ln)"/>
<text class="d-l" x="855" y="322" text-anchor="middle">3</text>
<path class="d-e " d="M938 356 L772 356" marker-end="url(#e-ln)"/>
<text class="d-l" x="855" y="350" text-anchor="middle">set 4</text>
<path class="d-e " d="M940 376 L916 376 L916 388 L938 388" marker-end="url(#e-ln)"/>
<text class="d-l" x="910" y="385" text-anchor="end">lock.Release</text>
<rect class="d-note d-note--ok" x="560" y="398" width="420" height="24" rx="4"/>
<text class="d-n" x="770" y="415" text-anchor="middle">итог 4: оба клика учтены</text>
<rect class="d-box g-php" x="543" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="600" y="46" text-anchor="middle">Воркер 1</text>
<text class="d-s" x="600" y="61" text-anchor="middle">pumpkin-3</text>
<rect class="d-box g-rr" x="713" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="770" y="46" text-anchor="middle">RR kv</text>
<text class="d-m" x="770" y="61" text-anchor="middle">clicks</text>
<rect class="d-box g-php" x="883" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="940" y="46" text-anchor="middle">Воркер 2</text>
<text class="d-s" x="940" y="61" text-anchor="middle">pumpkin-3</text>
<path class="d-sep" d="M500 8 V442"/>
</svg>
                </div>
                <figcaption>Оранжевое — воркер держит лок, пунктирная рамка — ждёт его.</figcaption>
            </figure>
            <p>
                Локи — плагин <code>lock</code> RoadRunner, у каждой операции свой набор. TTL 5 секунд — лок сам
                истечёт, если воркер упал; ждать занятый — до 5 секунд, дальше <code>RitualBusyException</code>.
            </p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Операция</th><th>Локи</th><th>Что проверяется под локом</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>клик, <code>RitualStore::click()</code></td><td><code>pumpkin:{id}</code></td><td>ничего: <code>clicks + 1</code>, затем чтение <code>round</code> и <code>scene</code></td></tr>
                    <tr><td>переход тыквы, <code>advancePumpkin()</code></td><td><code>pumpkin:{id}</code></td><td><code>round</code> текущий и <code>stage == toStage − 1</code></td></tr>
                    <tr><td>готовность сцены, <code>markReady()</code></td><td><code>scene</code></td><td><code>round</code> и <code>scene</code> текущие, готовность этой сцены ещё не отмечена</td></tr>
                    <tr><td>переход сцены, <code>advanceScene()</code></td><td><code>scene</code></td><td><code>round</code> текущий и <code>scene == toScene − 1</code></td></tr>
                    <tr><td>пульт: шаг финала, пауза прогресса, <code>updateStory()</code></td><td><code>scene</code></td><td>сюжет до и после: событие — только если изменился</td></tr>
                    <tr><td>сброс, <code>reset()</code></td><td><code>scene</code> и все <code>pumpkin:{id}</code></td><td><code>round == fromRound</code></td></tr>
                    <tr><td>статистика игрока, <code>PlayerStore</code></td><td><code>player:{id}</code></td><td>ничего: счётчики игрока + 1</td></tr>
                    <tr><td>выстрел, <code>FlyerStore::shoot()</code></td><td><code>flyer:{id}</code></td><td>ключ летуна ещё есть и полёт не кончился — тогда удалить</td></tr>
                    <tr><td>ход и вход фигурок, <code>WalkerStore::update()</code></td><td><code>walkers</code></td><td>ничего: read-modify-write одного ключа со всеми фигурками</td></tr>
                    <tr><td>кружки бара: розлив, «взять», выпитые за раунд, <code>BarStore</code></td><td><code>bar</code> (при «взять» — внутри <code>walkers</code>)</td><td>сцена — бар; кружка на стойке ещё есть — её получает первый дошедший</td></tr>
                    <tr><td>цель проклятого мира: кружки, зомби, добавки, <code>CursedStore</code></td><td><code>cursed</code></td><td>ничего: счётчики раунда + 1; обе цели выполнены — отметка готовности под <code>scene</code></td></tr>
                    <tr><td>удар рапирой по FrankenPHP, <code>BossStore</code></td><td><code>boss</code></td><td>босс проснулся и ещё жив — тогда здоровье − 1</td></tr>
                    </tbody>
                </table>
            </div>

            <p>Клик берёт один лок — своей тыквы — и читает, меняет и пишет её только внутри него:</p>
            <figure class="code">
                <figcaption><code>app/src/Domain/Ritual/RitualStore.php</code> · <code>RitualStore::click()</code></figcaption>
                <pre><code>public function click(string $id, ?\Closure $accepts = null, ?\Closure $acceptsIn = null): ?ClickOutcome
{
    $id = $this-&gt;known($id);

    return $this-&gt;locked([$this-&gt;lockOf($id)], function () use ($id, $accepts, $acceptsIn): ?ClickOutcome {
        // …
        $story = $this-&gt;readStory();
        $current = $this-&gt;readPumpkin($id);
        $clicks = $story-&gt;paused ? $current-&gt;clicks : $current-&gt;clicks + 1;
        $pumpkin = new Pumpkin($id, $current-&gt;stage, $clicks, $current-&gt;accepted + 1, $current-&gt;spent);
        $this-&gt;writePumpkin($pumpkin);

        return new ClickOutcome($this-&gt;readRound(), $this-&gt;readScene(), $pumpkin, $story-&gt;k, $story-&gt;players, !$story-&gt;paused);
    });
}</code></pre>
            </figure>
            <p><code>locked()</code> берёт локи плагина по порядку и отпускает их в обратном порядке в <code>finally</code>:</p>
            <figure class="code">
                <figcaption><code>app/src/Domain/Ritual/RitualStore.php</code> · <code>RitualStore::locked()</code></figcaption>
                <pre><code>private function locked(array $resources, \Closure $operation): mixed
{
    $held = [];

    try {
        foreach ($resources as $resource) {
            $id = $this-&gt;lock-&gt;lock($resource, ttl: $this-&gt;lockTtl, waitTTL: $this-&gt;lockWait);
            $id === false and throw new RitualBusyException(\sprintf('"%s" is locked by another worker.', $resource));
            $held[$resource] = $id;
        }

        return $operation();
    } finally {
        foreach (\array_reverse($held, true) as $resource =&gt; $id) {
            $this-&gt;lock-&gt;release($resource, $id);
        }
    }
}</code></pre>
            </figure>

            <h3 id="bar">Бар: последняя кружка и розлив партиями</h3>
            <p>
                Вечер начинается в баре (сцена 0): там нет тыкв, зато есть стойка, за которой сова-бармен наливает пиво.
                Бар показывает ещё две механики — те же лок и KV, но на кружках.
            </p>
            <ul>
                <li><strong>Последнюю кружку забирает первый.</strong> Клик по стойке (RPC <code>bar.take</code>) только
                    отправляет персонажа к ней и пишет в его запись момент прибытия. Когда он наступил, тикер под локом
                    <code>bar</code> одним шагом проверяет, есть ли кружка в <code>hellowin:bar</code>, и списывает её —
                    как «жив ли летун» у выстрела. Двое дошли почти одновременно — лок выстраивает их по очереди: первый
                    уходит с пивом (<code>take</code> с <code>ok: true</code>), второй застаёт пустую стойку
                    (<code>ok: false</code>, сова: «кончилось»). Очереди нет — опоздавший кликает снова.</li>
                <li><strong>Розлив партиями.</strong> Пока на стойке есть свободные места (их столько, сколько фигурок
                    на поле, но не больше <code>HELLOWIN_BAR_MUGS</code>), сова наливает следующую партию
                    <code>HELLOWIN_BAR_POUR_MS</code> мс: по кружке на каждые <code>HELLOWIN_BAR_POUR_PER</code> свободных
                    мест, но не меньше одной — большой зал не ждёт пива по одной кружке. Партия решается в начале розлива: в
                    KV лежит <code>pouring {since, until, batch}</code> по серверным часам, тикер RoadRunner раз в 200 мс
                    ставит налитые кружки (<code>mugs</code>, не больше мест) и начинает следующую партию (<code>pour</code>). Все видят одну и ту же пену, а перезапуск воркеров или самого тикера
                    ничего не теряет: состояние — в Valkey, а не в памяти процесса.</li>
                <li>Пить можно только чокнувшейся кружкой (<code>drink</code>, иначе <code>not clinked</code>); выпитое
                    считается за раунд, и когда зал выпил цель бара (её двигает модератор) — бар готов, сова зовёт всех
                    на грядку. Переход из бара — обычный <code>scene.next</code>: кружки сгорают, фигурки переходят на
                    лужайку одним событием <code>scene</code>, без ходьбы.</li>
            </ul>

            <h3 id="rapier">Рапира: слово, расколдовать, один босс на весь зал</h3>
            <p>Конец ночи показывает ещё три механики — и снова лок, KV и сервер, который решает сам.</p>
            <ul>
                <li><strong>Слово проверяет только сервер.</strong> Клиент шлёт то, что набрал игрок (RPC
                    <code>word</code>), и узнаёт только ответ. Сервер нормализует ввод — нижний регистр, кириллица в
                    латиницу, только <code>a-z</code> — и сравнивает с ответом с поблажкой на опечатку (префикс,
                    расстояние Левенштейна, <code>metaphone</code>). Ни ответа, ни правил в браузере нет. Неверно —
                    фигурка становится зомби, и её водит тикер сервера.</li>
                <li><strong>Расколдовать первым.</strong> Клик по зомби-игроку (RPC <code>cure</code>) под локом
                    <code>walkers</code> снимает с него заклятие — тот же «проверить и изменить одним шагом», что у
                    выстрела по летуну: второй кликнувший видит, что зомби уже нет.</li>
                <li><strong>Один босс на весь зал.</strong> Все удары рапирой (RPC <code>strike</code>) идут в одну
                    запись <code>hellowin:boss</code> под одним локом <code>boss</code>, как клики по костру: зал
                    конкурирует за один ресурс, и удар, отнявший последнюю единицу здоровья, — ровно один. Он и ставит в
                    очередь переход на рассвет с ключом идемпотентности <code>{round}:finale:7</code>.</li>
            </ul>

            <h3>Раунд и сброс</h3>
            <p>
                Сброс начинает ритуал заново: сцена 0 (бар), у всех тыкв <code>stage 0</code> и <code>clicks 0</code>,
                <code>round + 1</code>, сюжет заново (пауза прогресса снимается, множитель <code>k</code> остаётся) и событие
                <code>reset</code> всем. Делает это
                <code>ResetService::reset(?int $fromRound)</code>, его вызывают кнопка «Заново» на пульте модератора
                (RPC <code>reset {round}</code> — раунд, который модератор видит) и консольная команда
                <code>hellowin:reset [--round=N]</code>. Сервис берёт лок
                <code>scene</code> и локи всех тыкв, поэтому ни клик, ни переход не увидят полусброшенный ритуал, и
                под ними сравнивает текущий раунд с тем, который хотят завершить. Второй сброс того же раунда видит
                уже новый раунд и ничего не делает — это идемпотентность по <code>reset:{round}</code> без отдельного
                ключа: состояние и так под этими локами, а ключ в Valkey стал бы вторым источником правды о раунде.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 484" style="min-width: 720px" role="img" aria-label="Сброс: два вызова ResetService::reset(1) почти одновременно. Первый берёт локи scene и всех pumpkin:{id}, видит round = 1, сбрасывает сцену и тыквы, пишет round = 2, отпускает локи и публикует reset. Второй ждёт локи, видит round = 2, не равный 1, и ничего не делает.">
<defs><marker id="f-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="f-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-crit" x="95" y="84" width="10" height="158" rx="2"/>
<rect class="d-wait" x="905" y="114" width="10" height="180" rx="2"/>
<rect class="d-crit" x="905" y="294" width="10" height="128" rx="2"/>
<path class="d-life" d="M100 60 V484"/>
<path class="d-life" d="M330 60 V484"/>
<path class="d-life" d="M560 60 V484"/>
<path class="d-life" d="M740 60 V484"/>
<path class="d-life" d="M910 60 V484"/>
<path class="d-e " d="M102 92 L328 92" marker-end="url(#f-ln)"/>
<text class="d-l" x="215" y="86" text-anchor="middle">lock scene, pumpkin:pumpkin-1…</text>
<path class="d-e " d="M908 122 L332 122" marker-end="url(#f-ln)"/>
<text class="d-l" x="620" y="116" text-anchor="middle">lock scene: ждёт</text>
<path class="d-e " d="M102 152 L558 152" marker-end="url(#f-ln)"/>
<text class="d-l" x="330" y="146" text-anchor="middle">get hellowin:round</text>
<path class="d-e d-e--ret" d="M558 182 L102 182" marker-end="url(#f-ln)"/>
<text class="d-l" x="330" y="176" text-anchor="middle">1 = fromRound</text>
<path class="d-e " d="M102 212 L558 212" marker-end="url(#f-ln)"/>
<text class="d-l" x="330" y="206" text-anchor="middle">тыквы {0, 0}, scene 0, round 2</text>
<path class="d-e " d="M102 242 L328 242" marker-end="url(#f-ln)"/>
<text class="d-l" x="215" y="236" text-anchor="middle">release всех локов</text>
<path class="d-e d-e--acc" d="M102 272 L738 272" marker-end="url(#f-acc)"/>
<text class="d-l" x="420" y="266" text-anchor="middle">publish type:'reset', round:2</text>
<path class="d-e " d="M910 294 L886 294 L886 306 L908 306" marker-end="url(#f-ln)"/>
<text class="d-l" x="880" y="303" text-anchor="end">локи получены</text>
<path class="d-e " d="M908 332 L562 332" marker-end="url(#f-ln)"/>
<text class="d-l" x="735" y="326" text-anchor="middle">get hellowin:round</text>
<path class="d-e d-e--ret" d="M562 362 L908 362" marker-end="url(#f-ln)"/>
<text class="d-l" x="735" y="356" text-anchor="middle">2 ≠ fromRound</text>
<path class="d-e " d="M910 384 L886 384 L886 396 L908 396" marker-end="url(#f-ln)"/>
<text class="d-l" x="880" y="393" text-anchor="end">null: раунд уже сброшен</text>
<path class="d-e " d="M908 422 L332 422" marker-end="url(#f-ln)"/>
<text class="d-l" x="620" y="416" text-anchor="middle">release</text>
<rect class="d-note " x="30" y="438" width="950" height="24" rx="4"/>
<text class="d-n" x="505" y="455" text-anchor="middle">reset:{round} без пакета идемпотентности: round сравнивается под теми же локами, что защищают состояние</text>
<rect class="d-box g-php" x="10" y="16" width="180" height="44" rx="6"/>
<text class="d-t" x="100" y="34" text-anchor="middle">Вызов A</text>
<text class="d-m" x="100" y="49" text-anchor="middle">hellowin:reset --round=1</text>
<rect class="d-box g-rr" x="255" y="16" width="150" height="44" rx="6"/>
<text class="d-t" x="330" y="34" text-anchor="middle">RR lock</text>
<text class="d-m" x="330" y="49" text-anchor="middle">scene, pumpkin:*</text>
<rect class="d-box g-rr" x="495" y="16" width="130" height="44" rx="6"/>
<text class="d-t" x="560" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="560" y="49" text-anchor="middle">Valkey</text>
<rect class="d-box g-cf" x="680" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="740" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="740" y="49" text-anchor="middle">канал hellowin</text>
<rect class="d-box g-php" x="840" y="16" width="140" height="44" rx="6"/>
<text class="d-t" x="910" y="34" text-anchor="middle">Вызов B</text>
<text class="d-m" x="910" y="49" text-anchor="middle">reset(fromRound: 1)</text>
</svg>
                </div>
                <figcaption>Оранжевое — вызов держит локи, пунктирная рамка — ждёт их.</figcaption>
            </figure>
        </section>

        <section class="how__section" id="idempotency" role="tabpanel" aria-labelledby="tab-idempotency">
            <h2>Идемпотентный переход</h2>
            <p>
                Тыква набрала порог — нужен ровно один переход в следующую стадию. Но клики не останавливаются: пока
                задача стоит в очереди и ждёт воркер, по тыкве успевают кликнуть ещё n раз, и каждый клик ставит
                <code>TransitionPumpkin</code> снова. Это сделано нарочно. Клику не нужно знать, поставил ли кто-то задачу
                до него: не нужен ни флаг «переход в пути», ни его сброс, если задача потерялась (очередь в памяти, при
                рестарте <code>app</code> задачи пропадают — следующий клик просто поставит новую). Цена простоты — k + n
                задач на один переход, и выполниться должна одна.
            </p>
            <p>
                Отсекает дубли пакет <code>spiral/idempotency</code>. Класс задачи помечен атрибутом, и
                <code>IdempotencyInterceptor</code> в цепочке <code>interceptors.consume</code> очереди
                (<code>app/config/queue.php</code>) берёт ключ из payload:
            </p>
            <figure class="code">
                <figcaption><code>app/src/Application/Hellowin/Job/TransitionPumpkin.php</code> · <code>TransitionPumpkin::invoke()</code></figcaption>
                <pre><code>#[Idempotent(storage: 'transitions', key: 'payload.key')]
final class TransitionPumpkin extends JobHandler
{
    public function invoke(int $round, string $pumpkin, int $toStage, TransitionHandler $transitions, ?string $player = null): void
    {
        $transitions-&gt;pumpkin($round, $pumpkin, $toStage, PlayerId::isValid($player) ? $player : null);
    }
}</code></pre>
            </figure>

            <h3>Кто придумывает ключ</h3>
            <p>
                Ключ строит сервер из смысла операции: <code>TransitionQueue::pumpkinKey()</code> —
                <code>{round}:{pumpkin}:{toStage}</code>, например <code>1:pumpkin-3:2</code> — «в раунде 1 тыква 3
                переходит в стадию 2». Все клики, которые ставят этот переход, независимо друг от друга получают один и
                тот же ключ. Это отличается от привычного Idempotency-Key в стиле Stripe:
            </p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th></th><th>Детерминированный ключ (здесь)</th><th>Случайный ключ клиента (Stripe-style)</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>Кто придумывает</td><td>сервер, из данных операции</td><td>клиент, UUID на одну попытку действия</td></tr>
                    <tr><td>Где передаётся</td><td>в payload задачи, поле <code>key</code></td><td>заголовок <code>Idempotency-Key</code> запроса</td></tr>
                    <tr><td>Кто дублирует</td><td>разные клики разных людей, не знающие друг о друге</td><td>тот же клиент при повторе: таймаут, обрыв сети</td></tr>
                    <tr><td>Что защищаем</td><td>одно изменение состояния: переход тыквы на стадию</td><td>одну попытку запроса: платёж не спишется дважды</td></tr>
                    <tr><td>Два разных намерения с одним ключом</td><td>невозможны: тот же ключ — тот же переход</td><td>ошибка клиента: сервер сверяет запрос с первым</td></tr>
                    </tbody>
                </table>
            </div>
            <p>Ключ кладётся в payload задачи рядом с данными перехода:</p>
            <figure class="code">
                <figcaption><code>app/src/Application/Hellowin/TransitionQueue.php</code> · <code>TransitionQueue::pumpkinKey(), ::pumpkin()</code></figcaption>
                <pre><code>public static function pumpkinKey(int $round, string $pumpkin, int $toStage): string
{
    return \sprintf('%d:%s:%d', $round, $pumpkin, $toStage);
}
// …
public function pumpkin(int $round, string $pumpkin, int $toStage, ?string $player = null, string $type = Element::TYPE_PUMPKIN): void
{
    $this-&gt;queue-&gt;push(TransitionPumpkin::class, [
        'key' =&gt; self::pumpkinKey($round, $pumpkin, $toStage),
        'round' =&gt; $round,
        'pumpkin' =&gt; $pumpkin,
        'toStage' =&gt; $toStage,
        'player' =&gt; $player,
    ]);
    $this-&gt;metrics-&gt;jobPushed($type);
}</code></pre>
            </figure>
            <p>
                В Valkey ключ попадает с префиксом хранилища и областью — именем обработчика, чтобы ключи разных
                задач не столкнулись:
                <code>hellowin:amo:App\Application\Hellowin\Job\TransitionPumpkin::handle:1:pumpkin-3:2</code>.
            </p>

            <h3>Как отсекается дубль</h3>
            <p>
                Хранилище <code>transitions</code> в <code>app/config/idempotency.php</code> —
                <code>RedisAtMostOnceConfig</code> с префиксом <code>hellowin:amo:</code> и <code>retentionTtl: 300</code>.
                Перед вызовом обработчика драйвер одной атомарной командой <code>SET {ключ} M:{token} NX EX 300</code>
                занимает ключ. Записал — это первая доставка, обработчик выполняется. Ключ уже был — дубль: обработчик не
                вызывается вовсе, интерсептор возвращает <code>null</code>, и задача подтверждается (ACK) — не падает и
                не возвращается в очередь. Маркер не удаляется ни при успехе, ни при ошибке и исчезает сам по TTL.
            </p>
            <figure class="code">
                <figcaption><code>app/config/idempotency.php</code> · <code>storages.transitions</code></figcaption>
                <pre><code>'storages' =&gt; [
    'transitions' =&gt; new RedisAtMostOnceConfig(
        keyPrefix: 'hellowin:amo:',
        retentionTtl: 300,
    ),
    // …
],</code></pre>
            </figure>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1100 838" style="min-width: 792px" role="img" aria-label="Идемпотентный переход: клики сверх порога ставят в очередь transitions несколько задач TransitionPumpkin с одним ключом. Воркеры jobs разбирают их параллельно; IdempotencyInterceptor каждого делает SET NX EX в Valkey. Первый получает OK и выполняет переход: под локом тыквы проверяет раунд и стадию, пишет stage + 1 и clicks = 0, публикует событие pumpkin и проверяет, не готова ли сцена. Остальные получают отказ: обработчик не вызывается, задача подтверждается.">
<defs><marker id="g-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="g-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-wait d-risk" x="441" y="380" width="10" height="278" rx="2"/>
<path class="d-life" d="M56 60 V838"/>
<path class="d-life" d="M172 60 V838"/>
<path class="d-life" d="M296 60 V838"/>
<path class="d-life" d="M446 60 V838"/>
<path class="d-life" d="M626 60 V838"/>
<path class="d-life" d="M776 60 V838"/>
<path class="d-life" d="M906 60 V838"/>
<path class="d-life" d="M1036 60 V838"/>
<text class="d-h d-phase" x="14" y="96" text-anchor="start">1. Дубли намеренные: один ключ на k + n задач</text>
<path class="d-e " d="M58 124 L170 124" marker-end="url(#g-ln)"/>
<text class="d-l" x="114" y="118" text-anchor="middle">клик</text>
<path class="d-e " d="M174 154 L294 154" marker-end="url(#g-ln)"/>
<text class="d-l" x="234" y="148" text-anchor="middle">push key=1:pumpkin-3:2</text>
<path class="d-e " d="M58 184 L170 184" marker-end="url(#g-ln)"/>
<text class="d-l" x="114" y="178" text-anchor="middle">клик</text>
<path class="d-e " d="M174 214 L294 214" marker-end="url(#g-ln)"/>
<text class="d-l" x="234" y="208" text-anchor="middle">push key=1:pumpkin-3:2</text>
<path class="d-e " d="M58 244 L170 244" marker-end="url(#g-ln)"/>
<text class="d-l" x="114" y="238" text-anchor="middle">… ×n</text>
<path class="d-e " d="M174 274 L294 274" marker-end="url(#g-ln)"/>
<text class="d-l" x="234" y="268" text-anchor="middle">тот же key ×n</text>
<text class="d-h d-phase" x="14" y="300" text-anchor="start">2. Первая доставка выполняет, остальные отсекаются</text>
<path class="d-e " d="M298 328 L444 328" marker-end="url(#g-ln)"/>
<text class="d-l" x="371" y="322" text-anchor="middle">задача 1</text>
<path class="d-e " d="M298 358 L624 358" marker-end="url(#g-ln)"/>
<text class="d-l" x="304" y="352" text-anchor="start">задача 2 (параллельно)</text>
<path class="d-e " d="M448 388 L774 388" marker-end="url(#g-ln)"/>
<text class="d-l" x="611" y="382" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M774 418 L448 418" marker-end="url(#g-ln)"/>
<text class="d-l" x="611" y="412" text-anchor="middle">OK: маркер записан</text>
<path class="d-e " d="M628 448 L774 448" marker-end="url(#g-ln)"/>
<text class="d-l" x="701" y="442" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M774 478 L628 478" marker-end="url(#g-ln)"/>
<text class="d-l" x="701" y="472" text-anchor="middle">nil: ключ занят</text>
<path class="d-e " d="M626 500 L650 500 L650 512 L628 512" marker-end="url(#g-ln)"/>
<text class="d-l" x="656" y="509" text-anchor="start">дубль: обработчик не вызывается</text>
<path class="d-e d-e--ret" d="M624 538 L298 538" marker-end="url(#g-ln)"/>
<text class="d-l" x="618" y="532" text-anchor="end">null → ACK</text>
<path class="d-e " d="M446 560 L470 560 L470 572 L448 572" marker-end="url(#g-ln)"/>
<text class="d-l" x="476" y="569" text-anchor="start">TransitionPumpkin → TransitionHandler::pumpkin()</text>
<path class="d-e " d="M448 598 L904 598" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="592" text-anchor="middle">lock.Lock pumpkin:pumpkin-3</text>
<path class="d-e " d="M448 628 L904 628" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="622" text-anchor="middle">round текущий и stage == toStage − 1?</text>
<path class="d-e " d="M448 658 L904 658" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="652" text-anchor="middle">set stage = toStage, clicks = 0</text>
<path class="d-e " d="M448 688 L904 688" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="682" text-anchor="middle">lock.Release</text>
<path class="d-e d-e--acc" d="M448 718 L1034 718" marker-end="url(#g-acc)"/>
<text class="d-l" x="741" y="712" text-anchor="middle">publish type:'pumpkin' — ровно один раз</text>
<path class="d-e " d="M446 740 L470 740 L470 752 L448 752" marker-end="url(#g-ln)"/>
<text class="d-l" x="476" y="749" text-anchor="start">transitions_total +1 · все на stageMax? → markReady() под локом scene</text>
<path class="d-e d-e--ret" d="M444 778 L298 778" marker-end="url(#g-ln)"/>
<text class="d-l" x="371" y="772" text-anchor="middle">ACK</text>
<path class="d-e " d="M298 808 L624 808" marker-end="url(#g-ln)"/>
<text class="d-l" x="304" y="802" text-anchor="start">задачи 3…n: SET NX → nil → ACK, пока жив маркер</text>
<rect class="d-box g-edge" x="6" y="16" width="100" height="44" rx="6"/>
<text class="d-t" x="56" y="34" text-anchor="middle">Клики</text>
<text class="d-s" x="56" y="49" text-anchor="middle">сверх порога</text>
<rect class="d-box g-php" x="116" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="172" y="34" text-anchor="middle">ClickHandler</text>
<text class="d-m" x="172" y="49" text-anchor="middle">TransitionQueue</text>
<rect class="d-box g-rr" x="238" y="16" width="116" height="44" rx="6"/>
<text class="d-t" x="296" y="34" text-anchor="middle">RR jobs</text>
<text class="d-m" x="296" y="49" text-anchor="middle">transitions</text>
<rect class="d-box g-php" x="366" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="446" y="34" text-anchor="middle">jobs-воркер A</text>
<text class="d-m" x="446" y="49" text-anchor="middle">IdempotencyInterceptor</text>
<rect class="d-box g-php" x="546" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="626" y="34" text-anchor="middle">jobs-воркер B</text>
<text class="d-m" x="626" y="49" text-anchor="middle">IdempotencyInterceptor</text>
<rect class="d-box g-kv" x="716" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="776" y="34" text-anchor="middle">Valkey</text>
<text class="d-m" x="776" y="49" text-anchor="middle">hellowin:amo:*</text>
<rect class="d-box g-rr" x="846" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="906" y="34" text-anchor="middle">RR lock + kv</text>
<text class="d-m" x="906" y="49" text-anchor="middle">pumpkin:{id}</text>
<rect class="d-box g-cf" x="980" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="1036" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="1036" y="49" text-anchor="middle">канал hellowin</text>
<text class="d-s d-bad" x="434" y="574" text-anchor="end">окно риска:</text>
<text class="d-s" x="434" y="588" text-anchor="end">падение здесь —</text>
<text class="d-s" x="434" y="602" text-anchor="end">переход потерян</text>
<text class="d-s" x="434" y="616" text-anchor="end">до истечения TTL</text>
</svg>
                </div>
                <figcaption>
                    Сплошные стрелки — вызовы, пунктир — ответы и ACK. Пунктирная рамка на воркере A — окно между
                    маркером и эффектом.
                </figcaption>
            </figure>

            <h3>Почему «не более одного раза»</h3>
            <p>У пакета три гарантии, и они про разное:</p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Гарантия</th><th>Как устроена</th><th>Если воркер упал посреди</th><th>Что нужно</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>AtLeastOnce</code></td><td>аренда ключа на время работы; ключ закрывается после эффекта</td><td>аренда истекает, повтор выполняет эффект ещё раз — он может случиться дважды</td><td>эффект, который безопасно повторить</td></tr>
                    <tr><td><code>AtMostOnce</code> — выбрана</td><td>маркер пишется <strong>до</strong> эффекта и не удаляется</td><td>эффект потерян, повторы отсекаются, пока жив маркер — 0 или 1 раз</td><td>атомарный <code>SET NX EX</code></td></tr>
                    <tr><td><code>ExactlyOnce</code></td><td>запись о задаче и эффект в одной транзакции (inbox)</td><td>транзакция откатывается целиком, повтор выполнит эффект заново</td><td>эффект и запись в одной БД с транзакциями</td></tr>
                    </tbody>
                </table>
            </div>
            <p>
                <code>ExactlyOnce</code> здесь недостижим: эффект — запись в KV через плагин RoadRunner плюс публикация в
                Centrifugo, общей транзакции с маркером у них нет. Из оставшихся двух опаснее двойной переход: тыква
                перепрыгнет стадию, событие <code>pumpkin</code> уйдёт дважды. Потерять переход дёшево — тыква останется
                на пороге, и её дотыкают. Поэтому <code>AtMostOnce</code>.
            </p>
            <p class="callout">
                Цена: если воркер упадёт между маркером и записью стадии (или лок тыквы окажется занят дольше 5 секунд),
                переход потерян, а новые задачи с тем же ключом отсекаются, пока маркер жив — тыква «застревает» на
                пороге. Поэтому окно короткое: <code>retentionTtl</code> 300 секунд, а не сутки по умолчанию. После него
                следующий клик снова запускает переход, а от двойного перехода защищает доменная проверка под локом —
                <code>stage == toStage − 1</code>: если переход на самом деле успел выполниться, повтор ничего не меняет.
            </p>

            <h3 id="scene-transition">Переход сцены</h3>
            <p>
                Сцена не переключается сама: игра идёт не на время, её ведёт модератор. Переход разбит на два шага, и
                каждый защищён от двойного выполнения по-своему.
            </p>
            <ol>
                <li>
                    <strong>Готовность.</strong> После каждого перехода тыквы <code>TransitionHandler</code> спрашивает у
                    политики, не дошли ли все тыквы до <code>stageMax</code> (<code>RitualPolicy::sceneReady()</code>).
                    Последние тыквы могут дойти почти одновременно, и тогда «да» услышат несколько воркеров. Отметку
                    ставит <code>RitualStore::markReady()</code> под локом <code>scene</code>: в сюжете
                    <code>hellowin:story</code> поле <code>ready</code> ещё не равно этой сцене — записать и вернуть
                    <code>true</code>, уже равно — <code>false</code>. Поэтому событие <code>ready</code> и реплики совы
                    <code>last-pumpkin</code> (с игроком, добившим последнюю тыкву) и <code>scene-ready</code> уходят
                    ровно один раз. Сама сцена остаётся прежней.
                </li>
                <li>
                    <strong>Готовность с костром.</strong> Со сцены 2 готовых тыкв мало: в центре грядки ещё костёр, один элемент на
                    весь зал. Пока тыквы сцены не готовы, клик по нему отвечает <code>locked</code> и никуда не идёт;
                    последняя тыква вместо готовности открывает костёр (отметка в сюжете под тем же локом
                    <code>scene</code>, реплика <code>bonfire-open</code>). Дальше все бьют в один лок
                    <code>bonfire</code> — отсюда ответы <code>busy</code> — и после порога костра
                    (лимит <code>bonfire</code>) ставят горсть дублей одной задачи
                    <code>TransitionPumpkin</code> с ключом <code>{round}:bonfire:{toStage}</code>; выполняется одна,
                    это видно по <code>kind="bonfire"</code> в метриках. Сцену делает готовой переход костра на его
                    последнюю стадию сцены (<code>HELLOWIN_BONFIRE_STAGE_MAX</code>) — тот же
                    <code>markReady()</code>, реплики <code>bonfire-done</code> и <code>scene-ready</code>.
                    Так же в финальной сцене зал рисует магический круг (лок <code>circle</code>, ключ
                    <code>{round}:circle:{toStage}</code>): замкнутый круг делает финал готовым, и только тогда
                    «Шаг финала» поднимает FrankenPHP (без готовности — лишь с <code>force</code>).
                </li>
                <li>
                    <strong>Переход.</strong> Модератор нажимает «Следующая сцена» — RPC
                    <code>scene.next {scene}</code> с номером сцены, которую он видит. <code>StoryService::nextScene()</code>
                    по снимку проверяет: сцена текущая, не финальная и готова (или пришёл <code>force</code>) — и ставит
                    <code>TransitionScene</code>. Двойной клик, две вкладки пульта или два модератора поставят две
                    задачи, и это то же, что дубли кликов по тыкве: ключ <code>{round}:scene:{toScene}</code>
                    (<code>TransitionQueue::sceneKey()</code>), тот же <code>#[Idempotent]</code> с гарантией
                    <code>AtMostOnce</code>, внутри — лок <code>scene</code>, проверка
                    <code>scene == toScene − 1</code> и одно событие <code>scene</code> со <code>stageMax</code> новой
                    сцены и предметом фигурок. Нажатие, пришедшее после перехода, видит, что сцена уже не та, и
                    ничего не ставит — просто отвечает текущей.
                </li>
            </ol>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1100 1000" style="min-width: 792px" role="img" aria-label="Переход сцены. Фаза 1: два jobs-воркера одновременно добивают последние тыквы, оба видят, что сцена готова; markReady под локом scene отмечает готовность у первого и отказывает второму; первый публикует событие ready и реплики совы. Фаза 2: модератор дважды вызывает RPC scene.next; Centrifugo добавляет meta подключения, StoryService проверяет роль и готовность и оба раза ставит TransitionScene с ключом 1:scene:2. Первый воркер записывает маркер в Valkey и переключает сцену под локом scene, второй получает отказ и подтверждает задачу. Модератор и все клиенты получают одно событие scene.">
<defs><marker id="i-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="i-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-crit" x="590" y="178" width="10" height="30" rx="2"/>
<rect class="d-crit" x="710" y="238" width="10" height="30" rx="2"/>
<rect class="d-crit" x="590" y="820" width="10" height="60" rx="2"/>
<path class="d-life" d="M60 60 V1000"/>
<path class="d-life" d="M215 60 V1000"/>
<path class="d-life" d="M345 60 V1000"/>
<path class="d-life" d="M470 60 V1000"/>
<path class="d-life" d="M595 60 V1000"/>
<path class="d-life" d="M715 60 V1000"/>
<path class="d-life" d="M840 60 V1000"/>
<path class="d-life" d="M975 60 V1000"/>
<text class="d-h d-phase" x="14" y="92" text-anchor="start">1. Готовность: две последние тыквы добиты одновременно</text>
<path class="d-e " d="M595 106 L619 106 L619 118 L597 118" marker-end="url(#i-ln)"/>
<text class="d-l" x="625" y="115" text-anchor="start">последняя тыква → stageMax, sceneReady(): да</text>
<path class="d-e " d="M715 132 L739 132 L739 144 L717 144" marker-end="url(#i-ln)"/>
<text class="d-l" x="745" y="141" text-anchor="start">и эта тоже: sceneReady(): да</text>
<path class="d-e " d="M597 178 L973 178" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="172" text-anchor="middle">markReady(round, 1): lock scene</text>
<path class="d-e d-e--ret" d="M973 208 L597 208" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="202" text-anchor="middle">не отмечена → story.ready = 1 → true</text>
<path class="d-e " d="M717 238 L973 238" marker-end="url(#i-ln)"/>
<text class="d-l" x="845" y="232" text-anchor="middle">markReady(round, 1)</text>
<path class="d-e d-e--ret" d="M973 268 L717 268" marker-end="url(#i-ln)"/>
<text class="d-l" x="845" y="262" text-anchor="middle">уже отмечена → false</text>
<path class="d-e d-e--acc" d="M593 298 L217 298" marker-end="url(#i-acc)"/>
<text class="d-l" x="405" y="292" text-anchor="middle">publish ready · owl last-pumpkin · owl scene-ready</text>
<path class="d-e d-e--acc" d="M213 328 L62 328" marker-end="url(#i-acc)"/>
<text class="d-l" x="137" y="322" text-anchor="middle">ready</text>
<path class="d-e " d="M60 350 L84 350 L84 362 L62 362" marker-end="url(#i-ln)"/>
<text class="d-l" x="90" y="359" text-anchor="start">кнопка «Следующая сцена» ждёт нажатия</text>
<text class="d-h d-phase" x="14" y="392" text-anchor="start">2. Переход: модератор нажал кнопку дважды</text>
<path class="d-e " d="M62 420 L213 420" marker-end="url(#i-ln)"/>
<text class="d-l" x="137" y="414" text-anchor="middle">scene.next {scene: 1}</text>
<path class="d-e " d="M62 450 L213 450" marker-end="url(#i-ln)"/>
<text class="d-l" x="137" y="444" text-anchor="middle">то же ещё раз</text>
<path class="d-e d-e--acc" d="M217 480 L343 480" marker-end="url(#i-acc)"/>
<text class="d-l" x="280" y="474" text-anchor="middle">RPC proxy ×2</text>
<text class="d-s" x="280" y="496" text-anchor="middle">+ meta подключения</text>
<path class="d-e " d="M345 512 L369 512 L369 524 L347 524" marker-end="url(#i-ln)"/>
<text class="d-l" x="375" y="521" text-anchor="start">роль из meta · сцена 1 готова → TransitionScene, оба раза</text>
<path class="d-e " d="M347 550 L468 550" marker-end="url(#i-ln)"/>
<text class="d-l" x="407" y="544" text-anchor="middle">key 1:scene:2 ×2</text>
<path class="d-e d-e--ret" d="M343 580 L217 580" marker-end="url(#i-ln)"/>
<text class="d-l" x="280" y="574" text-anchor="middle">{ok, scene: 2}</text>
<path class="d-e d-e--ret" d="M213 610 L62 610" marker-end="url(#i-ln)"/>
<text class="d-l" x="137" y="604" text-anchor="middle">{ok, scene: 2} ×2</text>
<path class="d-e " d="M472 640 L593 640" marker-end="url(#i-ln)"/>
<text class="d-l" x="532" y="634" text-anchor="middle">задача 1</text>
<path class="d-e " d="M472 670 L713 670" marker-end="url(#i-ln)"/>
<text class="d-l" x="478" y="664" text-anchor="start">задача 2 (параллельно)</text>
<path class="d-e " d="M597 700 L838 700" marker-end="url(#i-ln)"/>
<text class="d-l" x="655" y="694" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M838 730 L597 730" marker-end="url(#i-ln)"/>
<text class="d-l" x="655" y="724" text-anchor="middle">OK</text>
<path class="d-e " d="M717 760 L838 760" marker-end="url(#i-ln)"/>
<text class="d-l" x="777" y="754" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M838 790 L717 790" marker-end="url(#i-ln)"/>
<text class="d-l" x="777" y="784" text-anchor="middle">nil → дубль, ACK</text>
<path class="d-e " d="M597 820 L973 820" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="814" text-anchor="middle">lock scene · round текущий и scene == 1?</text>
<path class="d-e " d="M597 850 L973 850" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="844" text-anchor="middle">scene = 2, ready снят</text>
<path class="d-e " d="M597 880 L973 880" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="874" text-anchor="middle">lock.Release</text>
<path class="d-e d-e--acc" d="M593 910 L217 910" marker-end="url(#i-acc)"/>
<text class="d-l" x="405" y="904" text-anchor="middle">publish scene {stageMax, item} · owl scene-2</text>
<path class="d-e d-e--acc" d="M213 940 L62 940" marker-end="url(#i-acc)"/>
<text class="d-l" x="137" y="934" text-anchor="middle">scene: 2</text>
<rect class="d-note d-note--ok" x="30" y="958" width="1040" height="24" rx="4"/>
<text class="d-n" x="550" y="975" text-anchor="middle">две тыквы и два нажатия — одна отметка ready и один переход: лок scene и ключ {round}:scene:{toScene}</text>
<rect class="d-box g-edge" x="4" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="60" y="34" text-anchor="middle">Модератор</text>
<text class="d-s" x="60" y="49" text-anchor="middle">пульт в браузере</text>
<rect class="d-box g-cf" x="159" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="215" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="215" y="49" text-anchor="middle">канал hellowin</text>
<rect class="d-box g-php" x="280" y="16" width="130" height="44" rx="6"/>
<text class="d-t" x="345" y="34" text-anchor="middle">RPCService</text>
<text class="d-m" x="345" y="49" text-anchor="middle">→ StoryService</text>
<rect class="d-box g-rr" x="415" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="470" y="34" text-anchor="middle">RR jobs</text>
<text class="d-m" x="470" y="49" text-anchor="middle">transitions</text>
<rect class="d-box g-php" x="540" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="595" y="34" text-anchor="middle">jobs-воркер A</text>
<text class="d-m" x="595" y="49" text-anchor="middle">#[Idempotent]</text>
<rect class="d-box g-php" x="660" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="715" y="34" text-anchor="middle">jobs-воркер B</text>
<text class="d-m" x="715" y="49" text-anchor="middle">#[Idempotent]</text>
<rect class="d-box g-kv" x="785" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="840" y="34" text-anchor="middle">Valkey</text>
<text class="d-m" x="840" y="49" text-anchor="middle">hellowin:amo:*</text>
<rect class="d-box g-rr" x="900" y="16" width="150" height="44" rx="6"/>
<text class="d-t" x="975" y="34" text-anchor="middle">RR lock + kv</text>
<text class="d-m" x="975" y="49" text-anchor="middle">scene, hellowin:story</text>
</svg>
                </div>
                <figcaption>
                    Оранжевое — воркер держит лок <code>scene</code>. В фазе 1 воркеры выполняют
                    <code>TransitionPumpkin</code>, в фазе 2 — <code>TransitionScene</code>.
                </figcaption>
            </figure>

            <h3>Зачем в ключе раунд</h3>
            <p>
                После сброса ритуал проходит те же переходы заново: тыква 3 снова идёт в стадию 2. Без раунда ключ
                совпал бы со старым, и пока жив старый маркер, новый переход отсекался бы как дубль. С раундом это новая
                операция: <code>2:pumpkin-3:2</code>. А задачи прошлого раунда, оставшиеся в очереди, ничего не меняют, даже если
                пройдут маркер: доменная проверка видит, что раунд уже другой.
            </p>

            <h3>Что видно в Grafana</h3>
            <p>
                В разделе «Transitions: queue and idempotency» дашборда — панели «Jobs pushed vs transitions applied»
                (в минуту и всего, по <code>kind</code>), «Duplicates dropped» = <code>pushed − transitions</code> и
                «Pumpkin stages». Чем активнее кликают по тыкве на пороге, тем больше разница. Маркеры можно увидеть и
                в самом Valkey, вместе с оставшимся TTL:
            </p>
            <pre><code>docker compose exec valkey valkey-cli --scan --pattern 'hellowin:amo:*'
docker compose exec valkey valkey-cli ttl 'hellowin:amo:App\Application\Hellowin\Job\TransitionPumpkin::handle:1:pumpkin-3:2'</code></pre>
        </section>

        <section class="how__section" id="dice" role="tabpanel" aria-labelledby="tab-dice">
            <h2>Кости: идемпотентность с сохранённым ответом</h2>
            <p>
                Перед восстанием в финале зал бросает три кости, пока кто-то не выбросит 6-6-6 — версию PHP, которой
                никогда не было. Бросает сервер: клиент шлёт RPC <code>roll {attempt}</code> с номером своей попытки, а
                в ответ и событием <code>roll</code> всем приходят кости. Задача та же, что у перехода тыквы, — повтор
                запроса не должен дать второй эффект, — но дубль перехода можно просто отбросить, а повтору броска нужен
                ответ: те же кости. Поэтому ответ попытки <em>сохраняется</em>, и повтор получает его.
            </p>

            <h3>Честный бросок</h3>
            <p>
                Кости — настоящий случайный выбор: <code>DiceRoller</code> трижды берёт
                <code>Randomizer::getInt(1, 6)</code> поверх системного CSPRNG — то же, что <code>random_int</code>.
                Предсказать, на какой попытке выпадет 6-6-6, не может никто, и сервер тоже. Но и посчитать те же кости
                второй раз нельзя: бросок тянется один раз, а результат нужно запомнить.
            </p>

            <h3>Ключ и сохранённый ответ</h3>
            <p>
                Ключ попытки — <code>{round}:roll:{player}:{attempt}</code>: раунд, публичный id игрока и номер попытки.
                <code>DiceService::roll</code> выполняет бросок через хранилище <code>dice</code> пакета
                <code>spiral/idempotency</code> — лизинг <code>RedisLeaseConfig</code> с гарантией
                <strong>AtLeastOnce</strong>. Первый вызов с ключом бросает и сохраняет ответ
                <code>{attempt, dice}</code> на <code>HELLOWIN_DICE_IDEMPOTENCY_TTL</code> с. Повтор того же ключа —
                двойной клик, переподключение, ретрай после потерянного ответа — получает сохранённый ответ: нового
                броска нет, события и счёта тоже. Отказ (<code>busy</code>, <code>not now</code>,
                <code>bad attempt</code>) — не ответ: <code>FailurePolicy::Release</code> освобождает ключ, и попытку
                можно бросить, когда отказ уйдёт.
            </p>
            <p>В коде это один вызов <code>execute()</code>; <code>LockedException</code> значит, что ту же попытку прямо сейчас бросает другой вызов:</p>
            <figure class="code">
                <figcaption><code>app/src/Application/Story/DiceService.php</code> · <code>DiceService::roll()</code></figcaption>
                <pre><code>public function roll(string $id, mixed $attempt): array
{
    // …
    try {
        // A refusal is not an answer: released, so the attempt throws once the refusal is gone
        $answer = $this-&gt;replies-&gt;execute(
            self::key($round, $id, $attempt),
            $throw,
            new ExecuteOptions(lockTtl: self::LOCK_TTL, ttl: $this-&gt;replyTtl, failurePolicy: FailurePolicy::Release),
        );
    } catch (LockedException) {
        throw new StoryRefusedException('busy');
    }
    // …
    return $answer;
}</code></pre>
            </figure>
            <p>Хранилище <code>dice</code> — лизинг рядом с <code>transitions</code> в том же конфиге:</p>
            <figure class="code">
                <figcaption><code>app/config/idempotency.php</code> · <code>storages.dice</code></figcaption>
                <pre><code>'dice' =&gt; new RedisLeaseConfig(
    keyPrefix: 'hellowin:lease:',
    lockTtl: 5,
),</code></pre>
            </figure>
            <p>
                Грани последней попытки игрока ещё и лежат в <code>hellowin:dice</code> под локом <code>dice</code> —
                одной записью с номером попытки и временем нового броска. Это страховка: AtLeastOnce допускает второй
                прогон, если воркер упал до сохранения ответа или ответ истёк. Тогда прогон под локом видит свою попытку
                в записи и отдаёт её грани, не бросая заново.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 490" style="min-width: 720px" role="img" aria-label="Бросок костей: браузер вызывает RPC roll с номером попытки n. Хранилище dice пакета spiral/idempotency ищет ответ по ключу {round}:roll:{player}:{n}. Ответ сохранён — повтор получает тот же ответ без броска, события и счёта. Ответа нет — DiceService под локом dice читает из hellowin:dice последнюю попытку игрока last. Если n = last + 1 — новый бросок: random_int трижды, запись попытки с гранями, событие roll всем, счёт бросков, ответ сохраняется на HELLOWIN_DICE_IDEMPOTENCY_TTL; не чаще HELLOWIN_DICE_COOLDOWN_MS. Если n = last — ответ потерян, грани берутся из записи, без броска и события. Иначе — ошибка bad attempt с last, клиент берёт last + 1. Первое 6-6-6 записывает победителя под тем же локом.">
<defs><marker id="n-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="n-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-box g-cf" x="20" y="60" width="170" height="70" rx="6"/>
<text class="d-t" x="105" y="88" text-anchor="middle">Браузер</text>
<text class="d-m" x="105" y="107" text-anchor="middle">roll {attempt: n}</text>
<path class="d-e" d="M190 95 L270 95" marker-end="url(#n-ln)"/>
<text class="d-l" x="230" y="87" text-anchor="middle">RPC</text>
<rect class="d-box g-kv" x="270" y="40" width="260" height="110" rx="6"/>
<text class="d-t" x="400" y="66" text-anchor="middle">хранилище dice</text>
<text class="d-m" x="400" y="87" text-anchor="middle">{round}:roll:{player}:{n}</text>
<text class="d-s" x="400" y="107" text-anchor="middle">spiral/idempotency, AtLeastOnce</text>
<text class="d-s" x="400" y="125" text-anchor="middle">TTL HELLOWIN_DICE_IDEMPOTENCY_TTL</text>
<path class="d-e d-e--ret" d="M530 95 L620 95" marker-end="url(#n-ln)"/>
<text class="d-l" x="575" y="87" text-anchor="middle">есть ответ</text>
<path class="d-e" d="M400 150 L400 175" marker-end="url(#n-ln)"/>
<text class="d-l" x="410" y="167" text-anchor="start">ответа нет</text>
<rect class="d-box g-php" x="270" y="175" width="260" height="260" rx="6"/>
<text class="d-t" x="400" y="202" text-anchor="middle">DiceService::roll</text>
<text class="d-m" x="400" y="222" text-anchor="middle">лок dice</text>
<text class="d-m" x="400" y="240" text-anchor="middle">hellowin:dice → last</text>
<text class="d-s" x="400" y="260" text-anchor="middle">последняя попытка и её грани</text>
<rect class="d-crit" x="285" y="372" width="230" height="48" rx="4"/>
<text class="d-n" x="400" y="391" text-anchor="middle">первое 6-6-6 — winner под тем же</text>
<text class="d-n" x="400" y="408" text-anchor="middle">локом: второе видит его, not now</text>
<path class="d-e d-e--ok" d="M530 210 L620 210" marker-end="url(#n-ok)"/>
<text class="d-l" x="575" y="202" text-anchor="middle">n = last + 1</text>
<path class="d-e d-e--ret" d="M530 305 L620 305" marker-end="url(#n-ln)"/>
<text class="d-l" x="575" y="297" text-anchor="middle">n = last</text>
<path class="d-e" d="M530 395 L620 395" marker-end="url(#n-ln)"/>
<text class="d-l" x="575" y="387" text-anchor="middle">иначе</text>
<rect class="d-note" x="620" y="40" width="360" height="110" rx="4"/>
<text class="d-h" x="636" y="64" text-anchor="start">Повтор: ответ уже сохранён</text>
<text class="d-m" x="636" y="86" text-anchor="start">тот же {attempt, dice} — без броска</text>
<text class="d-s" x="636" y="106" text-anchor="start">двойной клик, переподключение, потерянный ответ;</text>
<text class="d-s" x="636" y="124" text-anchor="start">ни лока, ни события, ни счёта: не перебросить</text>
<rect class="d-note d-note--ok" x="620" y="165" width="360" height="90" rx="4"/>
<text class="d-h d-ok" x="636" y="189" text-anchor="start">Новый бросок</text>
<text class="d-m" x="636" y="211" text-anchor="start">random_int × 3 → кости, ответ сохранён</text>
<text class="d-s" x="636" y="231" text-anchor="start">запись {attempt, at, dice}, событие roll всем,</text>
<text class="d-s" x="636" y="247" text-anchor="start">rolls + 1; не чаще HELLOWIN_DICE_COOLDOWN_MS</text>
<rect class="d-note" x="620" y="265" width="360" height="80" rx="4"/>
<text class="d-h" x="636" y="289" text-anchor="start">Ответ потерян, попытка в записи</text>
<text class="d-s" x="636" y="311" text-anchor="start">воркер упал до сохранения или ответ истёк:</text>
<text class="d-s" x="636" y="329" text-anchor="start">грани из hellowin:dice, без броска и события</text>
<rect class="d-note d-note--bad" x="620" y="355" width="360" height="80" rx="4"/>
<text class="d-h d-bad" x="636" y="379" text-anchor="start">bad attempt {last}</text>
<text class="d-s" x="636" y="401" text-anchor="start">пропустить попытку нельзя: клиент берёт last + 1</text>
<text class="d-s" x="636" y="419" text-anchor="start">и бросает заново</text>
<text class="d-s" x="20" y="470" text-anchor="start">ответ попытки — в хранилище dice (hellowin:lease:*) на HELLOWIN_DICE_IDEMPOTENCY_TTL с; грани последней попытки — ещё и в hellowin:dice</text>
</svg>
                </div>
                <figcaption>Зелёное — новый эффект, пунктир — тот же ответ без эффекта, красное — отказ; оранжевое — работа под локом.</figcaption>
            </figure>
            <ul>
                <li><strong>Почему повтор не перебрасывает.</strong> Повтор не бросает: он получает сохранённый ответ,
                    а если тот пропал — грани своей попытки из записи. Новые кости даёт только следующая попытка, и не
                    чаще паузы <code>HELLOWIN_DICE_COOLDOWN_MS</code>: перебрать попытки быстрее остальных нельзя.
                    Сравните с переходом тыквы: там AtMostOnce (<code>hellowin:amo:</code>) — маркер
                    <code>SET NX</code> отсекает дубль, ответ ему не нужен; здесь лизинг AtLeastOnce
                    (<code>hellowin:lease:</code>) — дубль получает тот же ответ, что и первый вызов.</li>
                <li><strong>Первое 6-6-6 — под локом.</strong> Все броски зала проходят через одну запись
                    <code>hellowin:dice</code> под одним локом <code>dice</code>, как удары по FrankenPHP. Победителя
                    записывает тот, кто взял лок первым; второе 6-6-6 видит его и получает <code>not now</code> — как
                    второй выстрел по уже сбитому летуну. Победа делает шаг финала готовым
                    (<code>markReady</code>), и сова называет победителя.</li>
                <li><strong>Сова.</strong> Запасная кнопка модератора <code>dice.owl</code>: первые два броска —
                    случайные, а выпавшее 6-6-6 перебрасывается; третий — 6-6-6 наверняка. Ключа идемпотентности нет:
                    каждое нажатие модератора — новый бросок. В число попыток зала (<code>reveal.rolls</code>) не
                    идут.</li>
            </ul>
        </section>

        <section class="how__section" id="live" role="tabpanel" aria-labelledby="tab-live">
            <h2>Модератор, фигурки, летуны</h2>
            <p>
                Вокруг ритуала — живой вечер: модератор ведёт сюжет с пульта, фигурки игроков ходят по лужайке, по небу
                летают птицы и нечисть, а в финале сова выкладывает реальные числа. Каждая из этих механик — ещё один
                приём: роль в meta подключения, presence канала, общие часы сервера, «первый под локом», счёт из
                состояния без лишних записей.
            </p>

            <h3 id="moderator">Роль модератора</h3>
            <p>
                Модератор открывает страницу с <code>?moderator=&lt;секрет&gt;</code>; страница кладёт секрет в
                <code>localStorage</code>, убирает его из адреса и передаёт в <code>data</code> подключения рядом с
                секретом игрока. <code>ConnectService</code> сравнивает его с <code>HELLOWIN_MODERATOR_SECRET</code>
                (<code>hash_equals</code>) и при совпадении кладёт <code>{moderator: true}</code> в <strong>meta</strong>
                подключения. Meta хранит Centrifugo, клиенту она не видна, а в каждый RPC proxy Centrifugo добавляет её
                сам — так настроено <code>rpc.proxy.include_connection_meta</code> в <code>config.json</code>. Клиент
                шлёт в RPC только данные и не может назначить себе роль запросом. Пустой секрет в env — роли нет ни у
                кого.
            </p>
            <p>
                <code>RPCService</code> читает роль из meta и на методы пульта (<code>scene.next</code>,
                <code>finale.step</code>, <code>owl.say</code>, <code>flyer.special</code>, <code>flyers.burst</code>, <code>pause</code>,
                <code>thresholds</code>, <code>reset</code>) без неё отвечает <code>forbidden</code> ещё до разбора данных.
                <code>thresholds {k}</code> умножает все лимиты на <code>k</code> (<a href="#limits">«Лимиты»</a>) — подогнать ритуал под зал на ходу;
                <code>k</code> лежит в сюжете и переживает сброс.
                <code>pause {on}</code> — пауза прогресса: задача сцены замирает, а зал живёт дальше. RPC, которые двигают
                задачу (<code>click</code> по тыквам, костру и кругу, <code>drink</code>, <code>strike</code>,
                <code>roll</code>), не отказывают: действие проходит, событие уходит всем, но с <code>counted: false</code> —
                клики и стадии стоят, кружки не идут в цель, здоровье босса прежнее, 6-6-6 не выигрывает, готовность сцены
                не наступает. Игроки паузу не видят — она только на пульте;
                летунов, зомби и выстрелы пауза не трогает. Снятая пауза догоняет своё тем же пересчётом, что и
                снизившийся лимит, а сброс снимает её сам.
                Роль — не в <code>user</code>: модератор может быть и игроком со своим секретом, а <code>user</code> — это
                публичный id игрока в событиях и presence. Каждый метод пульта безопасно повторить:
                <code>StoryService</code> меняет сюжет <code>hellowin:story</code> под локом <code>scene</code>, сравнивает
                «до» и «после» и публикует событие (и реплику совы) только при изменении.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 650" style="min-width: 720px" role="img" aria-label="Роль модератора. Браузер модератора подключается с секретом модератора в data; ConnectService сверяет его с HELLOWIN_MODERATOR_SECRET и отвечает meta moderator true; Centrifugo хранит meta у себя. На RPC pause от модератора Centrifugo добавляет meta, и RPCService выполняет вызов. Тот же RPC от обычного игрока приходит с пустой meta и получает forbidden.">
<defs><marker id="j-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="j-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-life" d="M70 60 V650"/>
<path class="d-life" d="M200 60 V650"/>
<path class="d-life" d="M400 60 V650"/>
<path class="d-life" d="M720 60 V650"/>
<text class="d-h d-phase" x="14" y="88" text-anchor="start">1. Подключение: секрет в data, роль в meta</text>
<path class="d-e " d="M72 116 L398 116" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="110" text-anchor="middle">connect {data: {playerSecret, moderatorSecret}}</text>
<path class="d-e d-e--acc" d="M402 146 L718 146" marker-end="url(#j-acc)"/>
<text class="d-l" x="560" y="140" text-anchor="middle">connect proxy: data</text>
<path class="d-e " d="M720 168 L744 168 L744 180 L722 180" marker-end="url(#j-ln)"/>
<text class="d-l" x="714" y="177" text-anchor="end">hash_equals(HELLOWIN_MODERATOR_SECRET, …)</text>
<path class="d-e d-e--ret" d="M718 206 L402 206" marker-end="url(#j-ln)"/>
<text class="d-l" x="560" y="200" text-anchor="middle">user, channels, meta {moderator: true}</text>
<path class="d-e " d="M400 228 L424 228 L424 240 L402 240" marker-end="url(#j-ln)"/>
<text class="d-l" x="430" y="237" text-anchor="start">meta хранится здесь, клиенту не уходит</text>
<path class="d-e d-e--ret" d="M398 266 L72 266" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="260" text-anchor="middle">connected (без meta)</text>
<text class="d-h d-phase" x="14" y="296" text-anchor="start">2. RPC пульта: meta добавляет Centrifugo, не клиент</text>
<path class="d-e " d="M72 324 L398 324" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="318" text-anchor="middle">rpc('pause', {on: true})</text>
<path class="d-e d-e--acc" d="M402 354 L718 354" marker-end="url(#j-acc)"/>
<text class="d-l" x="560" y="348" text-anchor="middle">RPC proxy + meta {moderator: true}</text>
<path class="d-e " d="M720 376 L744 376 L744 388 L722 388" marker-end="url(#j-ln)"/>
<text class="d-l" x="714" y="385" text-anchor="end">метод пульта, роль есть → StoryService</text>
<path class="d-e d-e--ret" d="M718 414 L402 414" marker-end="url(#j-ln)"/>
<text class="d-l" x="560" y="408" text-anchor="middle">{ok: true, on: true}</text>
<path class="d-e d-e--ret" d="M398 444 L72 444" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="438" text-anchor="middle">{ok: true, on: true}</text>
<path class="d-e " d="M202 474 L398 474" marker-end="url(#j-ln)"/>
<text class="d-l" x="300" y="468" text-anchor="middle">rpc('pause', …)</text>
<path class="d-e d-e--acc" d="M402 504 L718 504" marker-end="url(#j-acc)"/>
<text class="d-l" x="560" y="498" text-anchor="middle">RPC proxy + meta {}</text>
<path class="d-e " d="M720 526 L744 526 L744 538 L722 538" marker-end="url(#j-ln)"/>
<text class="d-l" x="714" y="535" text-anchor="end">роли нет → forbidden, данные не разбираются</text>
<path class="d-e d-e--ret" d="M718 564 L402 564" marker-end="url(#j-ln)"/>
<text class="d-l" x="560" y="558" text-anchor="middle">{ok: false, error: 'forbidden'}</text>
<path class="d-e d-e--ret" d="M398 594 L202 594" marker-end="url(#j-ln)"/>
<text class="d-l" x="300" y="588" text-anchor="middle">forbidden</text>
<rect class="d-note d-note--ok" x="30" y="610" width="940" height="24" rx="4"/>
<text class="d-n" x="500" y="627" text-anchor="middle">клиент не может назначить себе роль: в RPC он шлёт только данные, meta приходит от Centrifugo</text>
<rect class="d-box g-edge" x="5" y="16" width="130" height="44" rx="6"/>
<text class="d-t" x="70" y="34" text-anchor="middle">Модератор</text>
<text class="d-s" x="70" y="49" text-anchor="middle">браузер с секретом</text>
<rect class="d-box g-edge" x="145" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="200" y="34" text-anchor="middle">Игрок</text>
<text class="d-s" x="200" y="49" text-anchor="middle">без секрета</text>
<rect class="d-box g-cf" x="320" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="400" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="400" y="49" text-anchor="middle">include_connection_meta</text>
<rect class="d-box g-php" x="625" y="16" width="190" height="44" rx="6"/>
<text class="d-t" x="720" y="34" text-anchor="middle">RR centrifuge</text>
<text class="d-m" x="720" y="49" text-anchor="middle">ConnectService · RPCService</text>
</svg>
                </div>
                <figcaption>Сплошные стрелки — запросы, пунктир — ответы. Фиолетовые — вызовы proxy из Centrifugo.</figcaption>
            </figure>

            <h3 id="walkers">Фигурки на поле: presence и тикер</h3>
            <p>
                Игрок, собравший персонажа на экране входа (RPC <code>register</code> с именем и <code>look</code>), —
                фигурка на лужайке. Кто сейчас на странице, сервер не считает сам: у канала <code>{{ $channel }}</code>
                включён presence Centrifugo (<code>"presence": true</code> в <code>config.json</code>, клиентам он
                закрыт). Каждое подключение подписано на канал сервером, поэтому presence — это все открытые вкладки с
                <code>user</code> = публичный id игрока, и запись пропадает сама, как только соединение закрылось.
            </p>
            <p>
                Раз в секунду тикер (<code>WalkService::sync()</code>) спрашивает presence через gRPC API Centrifugo
                (<code>CentrifugoPresence</code>), сводит записи к набору игроков и под локом <code>walkers</code>
                сравнивает его с ключом <code>hellowin:walkers</code>: у присутствующих обновляет <code>seenAt</code>,
                игроки с <code>look</code> без фигурки выходят на поле (событие <code>walker</code>), а фигурки, чьих
                игроков нет дольше <code>HELLOWIN_WALK_LEAVE_AFTER</code> мс, уходят (<code>leave</code>). Несколько
                вкладок — несколько записей одного <code>user</code>, но фигурка одна и уходит, когда закрыта последняя.
                Задержка ухода нужна, чтобы перезагрузка страницы не роняла фигурку и не переносила её к краю.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 310" style="min-width: 720px" role="img" aria-label="Шкала времени одного игрока: вкладка 1 открыта, перезагружается и закрывается; вкладка 2 открыта позже и закрывается последней. Presence канала есть, пока открыта хоть одна вкладка, с коротким провалом на перезагрузке. Тикер раз в секунду сверяет presence: фигурка выходит на поле на первом тике, переживает перезагрузку и уходит через HELLOWIN_WALK_LEAVE_AFTER после закрытия последней вкладки.">
<defs><marker id="k-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<text class="d-s" x="20" y="62" text-anchor="start">вкладка 1</text>
<text class="d-s" x="20" y="98" text-anchor="start">вкладка 2</text>
<text class="d-s" x="20" y="134" text-anchor="start">presence канала</text>
<text class="d-s" x="20" y="170" text-anchor="start">фигурка на поле</text>
<text class="d-s" x="20" y="204" text-anchor="start">тикер, раз в секунду</text>
<rect class="d-box g-edge" x="190" y="50" width="140" height="16" rx="3"/>
<rect class="d-box g-edge" x="382" y="50" width="148" height="16" rx="3"/>
<rect class="d-box g-edge" x="450" y="86" width="184" height="16" rx="3"/>
<rect class="d-box g-cf" x="190" y="122" width="140" height="16" rx="3"/>
<rect class="d-box g-cf" x="382" y="122" width="252" height="16" rx="3"/>
<text class="d-s" x="356" y="153" text-anchor="middle">перезагрузка</text>
<path class="d-cap" d="M210 166 H810"/>
<circle class="d-pk d-pk--done" cx="210" cy="166" r="5"/>
<circle class="d-pk d-pk--done" cx="810" cy="166" r="5"/>
<text class="d-m" x="210" y="186" text-anchor="middle">walker</text>
<text class="d-m" x="810" y="186" text-anchor="middle">leave</text>
<path class="d-axis" d="M170 200 H970"/>
<path class="d-axis" d="M170 195 V205 M210 195 V205 M250 195 V205 M290 195 V205 M330 195 V205 M370 195 V205 M410 195 V205 M450 195 V205 M490 195 V205 M530 195 V205 M570 195 V205 M610 195 V205 M650 195 V205 M690 195 V205 M730 195 V205 M770 195 V205 M810 195 V205 M850 195 V205 M890 195 V205 M930 195 V205 M970 195 V205"/>
<path class="d-e" d="M636 230 L808 230" marker-start="url(#k-ln)" marker-end="url(#k-ln)"/>
<text class="d-m" x="722" y="248" text-anchor="middle">HELLOWIN_WALK_LEAVE_AFTER</text>
<text class="d-s" x="722" y="262" text-anchor="middle">без единого подключения</text>
<text class="d-s" x="356" y="230" text-anchor="middle">провал короче задержки —</text>
<text class="d-s" x="356" y="244" text-anchor="middle">фигурка стоит на месте</text>
<rect class="d-note " x="30" y="276" width="940" height="24" rx="4"/>
<text class="d-n" x="500" y="293" text-anchor="middle">две вкладки — две записи presence одного user, а фигурка одна; уходит, когда закрыта последняя</text>
</svg>
                </div>
                <figcaption>
                    Прямоугольники — открытые соединения и записи presence, оранжевая линия — фигурка на поле, засечки —
                    тики. Длины условные: задержка — <code>HELLOWIN_WALK_LEAVE_AFTER</code>.
                </figcaption>
            </figure>
            <p>
                Почему не connect proxy: в момент connect клиент ещё не подписан на канал и своё событие
                <code>walker</code> не получил бы. Почему не refresh proxy с продлением подключений: уход был бы виден
                только по истечении срока, а каждое продление — лишний вызов и запись в KV. Presence — готовый учёт
                подключений, который Centrifugo сам чистит при разрыве. Сбой опроса presence пишется в лог, а фигурки
                остаются: недоступный presence не значит, что все ушли.
            </p>

            <h3 id="walk-path">Путь фигурки считает сервер</h3>
            <p>
                Клик по земле — только намерение: RPC <code>move {x, y}</code>. Координаты кадр за кадром никто не
                шлёт. <code>WalkService::move()</code> приводит цель в зону — прямоугольник лужайки
                <code>walk.area</code> минус эллипс грядки <code>walk.patch</code> из <code>app/config/hellowin.php</code>
                (клик в грядку — на её край), берёт начало там, где фигурка сейчас по её текущему пути и серверным
                часам, и строит ломаную: напрямую, если отрезок не задевает грядку, иначе по касательной к эллипсу,
                увеличенному на 3 %, по его дуге и по касательной к цели, — со стороны, где путь короче. Длительность —
                длина ломаной, делённая на <code>HELLOWIN_WALK_SPEED</code>. Под локом <code>walkers</code> путь
                записывается в <code>hellowin:walkers</code>, а в канал уходит событие <code>move</code> с
                <code>Walk {path, startedAt, arrivesAt}</code>.
            </p>
            <p>Событие собирает <code>ChannelPublisher::move()</code>, а все события канала уходят одним <code>publish()</code> — через <code>BroadcastInterface</code> и плагин <code>centrifuge</code> в gRPC API Centrifugo:</p>
            <figure class="code">
                <figcaption><code>app/src/Application/Hellowin/ChannelPublisher.php</code> · <code>ChannelPublisher::move(), ::publish()</code></figcaption>
                <pre><code>public function move(int $round, Walker $walker, ?string $stop = null): void
{
    $message = [
        'type' =&gt; 'move',
        'round' =&gt; $round,
        'player' =&gt; ['id' =&gt; $walker-&gt;id],
        'walk' =&gt; $walker-&gt;walk-&gt;toArray(),
    ];
    // …

    $this-&gt;publish($message);
}

private function publish(array $message): void
{
    $this-&gt;broadcast-&gt;publish(
        $this-&gt;channel,
        \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
    );
}</code></pre>
            </figure>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 370" style="min-width: 720px" role="img" aria-label="Путь фигурки: прямоугольник лужайки и эллипс грядки в центре. Прямой отрезок от фигурки к цели прошёл бы через грядку, поэтому сервер ведёт путь по касательной к чуть увеличенному эллипсу, по его нижней дуге ломаной и по касательной к цели — с той стороны, где короче. Клик внутри грядки переносится на её край по лучу из центра.">
<defs><marker id="l-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-life" x="60" y="30" width="880" height="290"/>
<text class="d-s" x="72" y="50" text-anchor="start">walk.area — лужайка</text>
<ellipse class="d-box g-rr" cx="500" cy="200" rx="170" ry="90"/>
<ellipse class="d-life" cx="500" cy="200" rx="175" ry="92.7"/>
<text class="d-t" x="500" y="186" text-anchor="middle">грядка walk.patch</text>
<text class="d-s" x="500" y="202" text-anchor="middle">сюда фигурки не заходят</text>
<path class="d-e d-e--ret" d="M230 240 L780 232"/>
<text class="d-s d-bad" x="500" y="226" text-anchor="middle">напрямую — через грядку</text>
<path class="d-cap" d="M230 240 L412.5 280.3 L469.6 291.3 L530.4 291.3 L587.5 280.3 L780 232"/>
<circle class="d-pk d-pk--done" cx="230" cy="240" r="6"/>
<circle class="d-pk d-pk--done" cx="780" cy="232" r="6"/>
<text class="d-s" x="220" y="236" text-anchor="end">откуда: где фигурка сейчас</text>
<text class="d-s" x="220" y="251" text-anchor="end">по Walk и серверным часам</text>
<text class="d-s" x="792" y="228" text-anchor="start">цель move {x, y}</text>
<text class="d-s" x="500" y="312" text-anchor="middle">обход по дуге: сторона короче, шаг дуги ≤ 15°</text>
<text class="d-s" x="683" y="128" text-anchor="start">пунктир — эллипс + 3 %:</text>
<text class="d-s" x="683" y="142" text-anchor="start">хорды дуги не задевают грядку</text>
<circle class="d-pk" cx="430" cy="150" r="5"/>
<path class="d-e" d="M426 147 L402 130" marker-end="url(#l-ln)"/>
<text class="d-s" x="394" y="118" text-anchor="end">клик в грядку → на край по лучу из центра</text>
<text class="d-m" x="500" y="352" text-anchor="middle">Walk {path: [откуда, …, цель], startedAt, arrivesAt} · время = длина / HELLOWIN_WALK_SPEED</text>
</svg>
                </div>
                <figcaption>Оранжевая ломаная — путь из события <code>move</code>; его одинаково анимируют все клиенты.</figcaption>
            </figure>
            <p class="callout">
                Анимацию задаёт сервер, а не клиент кликнувшего. Каждый браузер по <code>now</code> из снимка знает
                смещение своих часов от серверных и ставит фигурку в точку пути, где она должна быть в этот момент
                серверного времени. Поэтому все видят одно и то же движение, а вкладка, открытая посреди хода, сразу
                рисует фигурку на середине пути. Новый <code>move</code> во время хода начинается из текущей точки.
            </p>
            <p>
                Чоканье по клику в проклятом мире — тот же путь плюс отложенная проверка: RPC <code>clink</code> ведёт
                фигурку к цели и кладёт рядом с ней в <code>hellowin:walkers</code> запись «жду встречи к
                <code>dueAt</code>», а тикер каждые 200 мс под локом <code>walkers</code> снимает наступившие записи и,
                если цель не ушла, публикует ровно одно событие <code>clink</code> с тостом и временем встречи.
            </p>

            <h3 id="flyers">Летуны и зомби: очки — первому</h3>
            <p>
                Летунов запускает тикер: случайно раз в <code>HELLOWIN_FLYER_INTERVAL</code> мс, вид — из набора
                текущей сцены <code>HELLOWIN_FLYER_KINDS</code>, изредка редкий (<code>HELLOWIN_FLYER_RARE</code>), ещё
                модератор может выпустить особого кнопкой или целую волну («Пуск летунов», <a href="#limits">«Лимиты»</a>). Траекторию сервер присылает готовой: событие
                <code>flyer</code> с точками, глубиной, очками и временем <code>launchedAt</code> / <code>expiresAt</code>,
                а в KV — ключ <code>hellowin:flyer:{id}</code> с TTL и индекс летящих. Зомби проклятого мира — те же
                летуны (<code>kind: zombie</code>, свой интервал <code>HELLOWIN_ZOMBIE_INTERVAL</code>), только идут по
                лужайке от края грядки наружу, — поэтому выстрел, очки и метрики у них общие. Пауза прогресса тикер не
                останавливает: летуны и зомби идут, попадание засчитывается игроку, только подбитый на паузе зомби не
                идёт в цель проклятого мира.
            </p>
            <p>
                Клик по летуну — RPC <code>shoot {flyer}</code>. Под локом <code>flyer:{id}</code>: ключ ещё есть, по
                летуну можно стрелять и полёт не кончился — ключ удаляется, это попадание: очки игроку, одно событие
                <code>shot</code>, метрика. Следующий выстрел ключа уже не найдёт — промах. Каждый тик тикер тем же
                способом учитывает улетевших: ключ ещё есть — удалить и засчитать как улетевшего. Ключ удаляет ровно
                один из них, поэтому летун не бывает и сбит, и улетел. Сервер принимает выстрел до
                <code>expiresAt</code> + 500 мс, а клиент не рисует летуна, чьё время по серверным часам уже вышло.
            </p>
            <p>
                Очки собираются в топ стрелков вечера — <code>hellowin:top</code> под локом <code>top</code>
                (<code>TopService</code>). Попадание пишет туда не «+N», а абсолютные очки игрока, которые вернула его
                запись, и берёт <code>max(старое, новое)</code>: повтор и гонка дважды не посчитают. Сам выстрел ничего
                не публикует — доску шлёт тикер событием <code>top {board}</code>, когда она изменилась, и не чаще
                <code>HELLOWIN_TOP_INTERVAL</code> мс. Это склейка: при полном зале полный список каждому на каждое
                попадание — слишком много, а раз в интервал — один. Доска живёт весь вечер, сброс её не чистит, и
                она же лежит в снимке страницы.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 440" style="min-width: 720px" role="img" aria-label="Два игрока стреляют в одного летуна. Воркер первого берёт лок flyer:{id}, видит ключ летуна, удаляет его, отпускает лок и публикует shot. Воркер второго ждёт лок, потом не находит ключа и отвечает промахом.">
<defs><marker id="m-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="m-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-crit" x="95" y="96" width="10" height="120" rx="2"/>
<rect class="d-wait" x="755" y="126" width="10" height="150" rx="2"/>
<rect class="d-crit" x="755" y="276" width="10" height="90" rx="2"/>
<path class="d-life" d="M100 60 V440"/>
<path class="d-life" d="M330 60 V440"/>
<path class="d-life" d="M530 60 V440"/>
<path class="d-life" d="M760 60 V440"/>
<path class="d-life" d="M920 60 V440"/>
<path class="d-e " d="M102 96 L328 96" marker-end="url(#m-ln)"/>
<text class="d-l" x="215" y="90" text-anchor="middle">lock flyer:{id}</text>
<path class="d-e " d="M758 126 L332 126" marker-end="url(#m-ln)"/>
<text class="d-l" x="430" y="120" text-anchor="middle">lock flyer:{id}: ждёт</text>
<path class="d-e " d="M102 156 L528 156" marker-end="url(#m-ln)"/>
<text class="d-l" x="315" y="150" text-anchor="middle">get: ключ есть, летун ещё летит</text>
<path class="d-e " d="M102 186 L528 186" marker-end="url(#m-ln)"/>
<text class="d-l" x="315" y="180" text-anchor="middle">delete — попадание</text>
<path class="d-e " d="M102 216 L328 216" marker-end="url(#m-ln)"/>
<text class="d-l" x="215" y="210" text-anchor="middle">release</text>
<path class="d-e d-e--acc" d="M102 246 L918 246" marker-end="url(#m-acc)"/>
<text class="d-l" x="510" y="240" text-anchor="middle">publish shot {points, player} · очки игроку · flyers_shot +1</text>
<path class="d-e " d="M760 276 L736 276 L736 288 L758 288" marker-end="url(#m-ln)"/>
<text class="d-l" x="730" y="285" text-anchor="end">лок получен</text>
<path class="d-e " d="M758 316 L532 316" marker-end="url(#m-ln)"/>
<text class="d-l" x="645" y="310" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M532 346 L758 346" marker-end="url(#m-ln)"/>
<text class="d-l" x="645" y="340" text-anchor="middle">ключа нет</text>
<path class="d-e " d="M760 366 L736 366 L736 378 L758 378" marker-end="url(#m-ln)"/>
<text class="d-l" x="730" y="375" text-anchor="end">промах: {ok: true, hit: false}</text>
<rect class="d-note d-note--ok" x="30" y="398" width="940" height="24" rx="4"/>
<text class="d-n" x="500" y="415" text-anchor="middle">летун сбит ровно один раз: ключ удаляет первый под локом; тикер учитывает улетевших так же</text>
<rect class="d-box g-php" x="20" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="100" y="34" text-anchor="middle">RPC shoot</text>
<text class="d-s" x="100" y="49" text-anchor="middle">игрок 1, первый</text>
<rect class="d-box g-rr" x="260" y="16" width="140" height="44" rx="6"/>
<text class="d-t" x="330" y="34" text-anchor="middle">RR lock</text>
<text class="d-m" x="330" y="49" text-anchor="middle">flyer:{id}</text>
<rect class="d-box g-rr" x="455" y="16" width="150" height="44" rx="6"/>
<text class="d-t" x="530" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="530" y="49" text-anchor="middle">hellowin:flyer:{id}</text>
<rect class="d-box g-php" x="680" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="760" y="34" text-anchor="middle">RPC shoot</text>
<text class="d-s" x="760" y="49" text-anchor="middle">игрок 2, следом</text>
<rect class="d-box g-cf" x="860" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="920" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="920" y="49" text-anchor="middle">канал hellowin</text>
</svg>
                </div>
                <figcaption>Оранжевое — воркер держит лок, пунктирная рамка — ждёт его.</figcaption>
            </figure>

            <h3 id="reveal">Разоблачение: числа вечера</h3>
            <p>
                На шаге финала, где сова раскрывает правду, событие <code>finale</code> и снимок несут
                <code>reveal</code> — реальные числа раунда. Отдельных счётчиков на каждый клик для этого нет: всё
                следует из состояния в Valkey, которое и так пишется под локами. <code>RevealBuilder</code> считает их
                заново при каждой сборке.
            </p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Число</th><th>Откуда</th><th>Почему так</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>clicks</code> — обработанные клики</td><td>Σ <code>accepted</code> по <code>hellowin:pumpkin:{id}</code></td><td><code>accepted</code> растёт под тем же локом, что и <code>clicks</code>, но не обнуляется при переходе</td></tr>
                    <tr><td><code>jobs</code> — задачи, прошедшие очередь</td><td>Σ <code>stage</code> + <code>scene</code></td><td>каждая стадия и каждая сцена — ровно одна выполненная задача перехода</td></tr>
                    <tr><td><code>burned</code> — сгоревшие клики</td><td><code>clicks</code> − Σ <code>spent</code> − Σ min(<code>clicks</code> тыквы, порог)</td><td>переход тратит ровно порог, действовавший при его выполнении, и прибавляет его к <code>spent</code> тыквы; остальное отбросила идемпотентность или пришло после готовности тыквы, кроме кликов, ещё копящихся к переходу</td></tr>
                    <tr><td><code>shot</code> — сбитые летуны и зомби</td><td><code>hellowin:shot</code> — <code>{round, count}</code> под локом <code>shot</code></td><td>единственный отдельный счётчик: выстрелы редки, лок не мешает; счёт другого раунда читается как 0</td></tr>
                    <tr><td><code>drinks</code>, <code>slain</code>, <code>damage</code> — кружки вечера, зомби и удары по FrankenPHP</td><td><code>hellowin:drinks</code>, <code>hellowin:cursed</code>, <code>hellowin:boss</code> — каждый с <code>round</code></td><td>счётчики бара, проклятого мира и босса уже есть ради игры; запись другого раунда читается как пустая</td></tr>
                    <tr><td><code>lastPumpkin</code> — кто добил последнюю тыкву</td><td><code>lastPumpkin</code> в <code>hellowin:story</code></td><td>игрок из задачи, которая отметила готовность сцены; <code>null</code>, если клик был анонимным или сцену переключили <code>force</code></td></tr>
                    </tbody>
                </table>
            </div>
            <p>
                Сброс раунда обнуляет эти числа без отдельного кода: <code>accepted</code>, <code>stage</code>,
                <code>scene</code> и сюжет сбрасываются вместе с ритуалом, а счёт сбитых привязан к раунду.
            </p>
        </section>

        <section class="how__section" id="metrics" role="tabpanel" aria-labelledby="tab-metrics">
            <h2>Метрики</h2>
            <p>
                При старте воркера <code>HellowinBootloader</code> объявляет коллекторы в плагине <code>metrics</code> и
                выставляет gauge из состояния в Valkey — оно переживает перезапуск RoadRunner. Дальше
                <code>RitualMetrics</code> вызывает <code>metrics.Add</code> и <code>metrics.Set</code> по RPC из клика,
                постановки задачи, перехода и сброса, а <code>PlayMetrics</code> — из выстрела и тикера (летуны, фигурки,
                игроки). Ошибка метрик только пишется в лог: клик из-за неё не падает.
                RoadRunner отдаёт всё на <code>:2112/metrics</code> вместе со своими <code>rr_http_*</code> (middleware
                <code>http_metrics</code> в <code>.rr.yaml</code>; там же <code>gzip</code>, который сжимает и ответы PHP,
                и статику — он стоит перед <code>static</code>, потому что RoadRunner 3 выполняет middleware слева
                направо).
            </p>
            <p>Как это выглядит в коде — на RPC-метриках с вкладки <a href="#click">«Клик»</a>: коллекторы объявляются один раз, значения пишутся по ходу работы:</p>
            <figure class="code">
                <figcaption><code>app/src/Application/Hellowin/PlayMetrics.php</code> · <code>PlayMetrics::declare(), ::rpc()</code></figcaption>
                <pre><code>public function declare(): void
{
    $collectors = [
        // …
        self::RPC_CALLS =&gt; Collector::counter()-&gt;withHelp('RPC calls by method and result: ok or the error code.')-&gt;withLabels('method', 'result'),
        self::RPC_DURATION =&gt; Collector::histogram(...self::RPC_BUCKETS)-&gt;withHelp('RPC handling time in PHP, seconds.')-&gt;withLabels('method'),
        // …
    ];

    foreach ($collectors as $name =&gt; $collector) {
        $this-&gt;safely(fn() =&gt; $this-&gt;metrics-&gt;declare($name, $collector));
    }
}

public function rpc(string $method, string $result, float $seconds): void
{
    $this-&gt;safely(fn() =&gt; $this-&gt;metrics-&gt;add(self::RPC_CALLS, 1, [$method, $result]));
    $this-&gt;safely(fn() =&gt; $this-&gt;metrics-&gt;observe(self::RPC_DURATION, $seconds, [$method]));
}</code></pre>
            </figure>
            <p>
                Prometheus раз в 5 секунд скрейпит <code>app:2112</code> и <code>centrifugo:9000/metrics</code> (внутренний
                порт Centrifugo: наружу открыт только WebSocket на 8000).
                Grafana получает datasource и дашборд «Hellowin» через provisioning и пускает анонимно в роли Viewer:
                смотреть может любой, редактировать — нет.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 330" style="min-width: 720px" role="img" aria-label="Путь метрики: RitualMetrics из ClickHandler, TransitionQueue, TransitionHandler, ResetService и HellowinBootloader вызывает metrics.Add и metrics.Set по RPC в плагин metrics RoadRunner; Prometheus каждые 5 секунд забирает /metrics с :2112 и с Centrifugo :9000; Grafana строит дашборд PromQL-запросами к Prometheus, браузер открывает её на порту :3000.">
<defs><marker id="h-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-box g-php" x="20" y="50" width="230" height="150" rx="6"/>
<text class="d-t" x="135" y="83.5" text-anchor="middle">RitualMetrics</text>
<text class="d-m" x="135" y="98.5" text-anchor="middle">ClickHandler: clicks</text>
<text class="d-m" x="135" y="113.5" text-anchor="middle">TransitionQueue: pushed</text>
<text class="d-m" x="135" y="128.5" text-anchor="middle">TransitionHandler:</text>
<text class="d-m" x="135" y="143.5" text-anchor="middle">transitions + gauges</text>
<text class="d-m" x="135" y="158.5" text-anchor="middle">boot, ResetService:</text>
<text class="d-m" x="135" y="173.5" text-anchor="middle">gauges из Valkey</text>
<rect class="d-box g-rr" x="340" y="85" width="190" height="80" rx="6"/>
<text class="d-t" x="435" y="113.5" text-anchor="middle">RR metrics</text>
<text class="d-m" x="435" y="128.5" text-anchor="middle">:2112 /metrics</text>
<text class="d-m" x="435" y="143.5" text-anchor="middle">+ rr_http_*</text>
<rect class="d-box g-obs" x="610" y="85" width="150" height="80" rx="6"/>
<text class="d-t" x="685" y="113.5" text-anchor="middle">Prometheus</text>
<text class="d-m" x="685" y="128.5" text-anchor="middle">:9090</text>
<text class="d-s" x="685" y="143.5" text-anchor="middle">хранит 15d</text>
<rect class="d-box g-obs" x="830" y="85" width="150" height="80" rx="6"/>
<text class="d-t" x="905" y="113.5" text-anchor="middle">Grafana</text>
<text class="d-m" x="905" y="128.5" text-anchor="middle">:3000</text>
<text class="d-s" x="905" y="143.5" text-anchor="middle">дашборд «Hellowin»</text>
<rect class="d-box g-cf" x="340" y="240" width="190" height="60" rx="6"/>
<text class="d-t" x="435" y="266" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="435" y="281" text-anchor="middle">:9000/metrics</text>
<rect class="d-box g-edge" x="830" y="240" width="150" height="60" rx="6"/>
<text class="d-t" x="905" y="266" text-anchor="middle">Браузер</text>
<text class="d-m" x="905" y="281" text-anchor="middle">:3000</text>
<path class="d-e" d="M250 125 L340 125" marker-end="url(#h-ln)"/>
<text class="d-l" x="295" y="117" text-anchor="middle">Add, Set</text>
<text class="d-m" x="295" y="141" text-anchor="middle">:6001</text>
<path class="d-e d-e--ok" d="M610 125 L530 125" marker-end="url(#h-ok)"/>
<text class="d-l" x="570" y="117" text-anchor="middle">scrape 5s</text>
<path class="d-e d-e--ok" d="M830 125 L760 125" marker-end="url(#h-ok)"/>
<text class="d-l" x="795" y="117" text-anchor="middle">PromQL</text>
<path class="d-e d-e--ok" d="M685 165 L685 270 L530 270" marker-end="url(#h-ok)"/>
<text class="d-l" x="607" y="262" text-anchor="middle">scrape 5s</text>
<path class="d-e" d="M905 240 L905 165" marker-end="url(#h-ln)"/>
<text class="d-l" x="897" y="208" text-anchor="end">anonymous Viewer</text>
<text class="d-s" x="20" y="26" text-anchor="start">pull-модель: Prometheus сам приходит за метриками, приложение никуда их не отправляет</text>
</svg>
                </div>
                <figcaption>Стрелки показывают, кто инициирует запрос; данные по scrape-стрелкам идут обратно.</figcaption>
            </figure>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Метрика</th><th>Откуда</th><th>Что значит</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>hellowin_clicks_total{type, element}</code></td><td>ClickHandler</td><td>counter: принятые клики</td></tr>
                    <tr><td><code>hellowin_transition_jobs_pushed_total{kind}</code></td><td>TransitionQueue</td><td>counter: задачи перехода в очереди, дубли включительно; <code>kind</code> — <code>pumpkin</code> или <code>scene</code></td></tr>
                    <tr><td><code>hellowin_transitions_total{kind}</code></td><td>TransitionHandler</td><td>counter: реально выполненные переходы</td></tr>
                    <tr><td><code>hellowin_scene_stage</code></td><td>TransitionHandler, старт, сброс</td><td>gauge: текущая сцена</td></tr>
                    <tr><td><code>hellowin_round</code></td><td>старт, сброс</td><td>gauge: текущий раунд</td></tr>
                    <tr><td><code>hellowin_pumpkin_stage{element}</code></td><td>TransitionHandler, старт, сброс</td><td>gauge: стадия каждой тыквы</td></tr>
                    <tr><td><code>hellowin_flyers_launched_total{depth}</code>, <code>hellowin_flyers_shot_total{depth}</code>, <code>hellowin_flyers_escaped_total{depth}</code></td><td>тикер, RPC <code>shoot</code></td><td>counter: запущенные, сбитые и улетевшие несбитыми летуны (и зомби)</td></tr>
                    <tr><td><code>hellowin_walkers</code></td><td>тикер, раз в секунду</td><td>gauge: фигурки на поле</td></tr>
                    <tr><td><code>hellowin_players</code>, <code>hellowin_players_registered</code></td><td>тикер, счёт игроков <code>PlayerCensus</code></td><td>gauge: известные игроки и игроки с именем</td></tr>
                    <tr><td><code>hellowin_online_players</code>, <code>hellowin_online_connections</code></td><td>тикер <code>WalkService</code>, presence канала Centrifugo</td><td>gauge: кто онлайн сейчас — уникальные игроки и все подключения, с анонимами; то же число видит модератор на пульте</td></tr>
                    <tr><td><code>hellowin_rpc_calls_total{method, result}</code></td><td>RPCService</td><td>counter: каждый RPC через вебсокет; <code>method</code> — имя метода или <code>unknown</code>, <code>result</code> — <code>ok</code> или код ошибки</td></tr>
                    <tr><td><code>hellowin_rpc_duration_seconds{method}</code></td><td>RPCService</td><td>histogram: сколько PHP обрабатывал RPC</td></tr>
                    <tr><td><code>hellowin_rpc_rate_limited_total{group}</code></td><td>RPCService</td><td>counter: вызовы, отклонённые лимитом (<code>rate limited</code>), по группе ведра</td></tr>
                    <tr><td><code>hellowin_beer_mugs_total{place}</code></td><td>BarService</td><td>counter: выпитые кружки; <code>place</code> — <code>bar</code> или <code>cursed</code> (проклятый мир)</td></tr>
                    <tr><td><code>hellowin_beer_litres</code></td><td>тикер, счёт игроков <code>PlayerCensus</code></td><td>gauge: выпито за вечер, литров — кружки игроков × <code>HELLOWIN_MUG_LITRES</code></td></tr>
                    <tr><td><code>rr_http_request_total</code>, <code>rr_http_request_duration_seconds</code>, <code>rr_http_workers_*</code></td><td>RoadRunner http</td><td>HTTP-запросы, задержка, воркеры</td></tr>
                    <tr><td><code>centrifugo_node_num_clients</code>, <code>centrifugo_transport_messages_sent</code>, <code>centrifugo_proxy_duration_seconds_histogram</code></td><td>Centrifugo</td><td>подключения, отправленные сообщения, задержка proxy-вызовов в RoadRunner</td></tr>
                    </tbody>
                </table>
            </div>
            <p>
                Счётчики живут в процессе RoadRunner и обнуляются при его перезапуске, gauge восстанавливаются из
                Valkey. Разделы дашборда: <strong>Game</strong> — клики, сцена, раунд, онлайн;
                <strong>Transitions: queue and idempotency</strong> — задачи против выполненных переходов, отсечённые
                дубли, стадии тыкв; <strong>Centrifugo</strong> — соединения, сообщения, proxy-вызовы;
                <strong>RoadRunner</strong> — HTTP и воркеры.
            </p>
            <p>Запросы с дашборда, например:</p>
            <pre><code># клики в секунду, всего и по тыквам
sum(rate(hellowin_clicks_total[1m]))
sum by (element) (rate(hellowin_clicks_total[1m]))

# отсечённые дубли: задачи в очереди минус выполненные переходы
sum by (kind) (hellowin_transition_jobs_pushed_total) - sum by (kind) (hellowin_transitions_total)

# p95 задержки proxy-вызовов Centrifugo → RoadRunner
histogram_quantile(0.95, sum by (le, type) (rate(centrifugo_proxy_duration_seconds_histogram_bucket[1m])))</code></pre>
            <p><a href="{{ $grafanaUrl }}">Открыть дашборд в Grafana</a> · <a href="/">Вернуться к тыквам</a></p>
        </section>
    </main>
</define:body>
