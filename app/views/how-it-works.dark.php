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
                <a href="/grafana/">Дашборд в Grafana →</a>
            </div>
            <h1>Как это работает</h1>
            <p class="how__lead">
                RoadRunner «умер», и посетители воскрешают его ритуалом на тыквенной грядке. Ритуал идёт сценами. В
                каждой сцене тыквы проходят несколько стадий: тыква переходит в следующую, когда по ней набрали
                <code>HELLOWIN_CLICKS_PER_STAGE</code> кликов, и останавливается на последней стадии сцены — её задаёт
                список <code>HELLOWIN_SCENE_STAGE_MAX</code>. Когда все тыквы дошли до неё, сцена сменяется. Один проход
                ритуала — раунд; сброс начинает следующий. Все видят клики всех в реальном времени, а метрики уходят в
                публичную Grafana. Ниже — что происходит на самом деле, с именами классов, ключей, каналов и портов из
                кода. Скоро к тыквам добавятся игроки и летуны — пока это в работе.
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
                <dd>Redis-совместимое хранилище. В нём лежат состояние ритуала и ключи идемпотентности переходов;
                    AOF на volume — состояние переживает перезапуск.</dd>
                <dt>Prometheus, Grafana</dt>
                <dd>Prometheus сам забирает метрики с сервисов и хранит их; Grafana рисует по ним дашборд.</dd>
            </dl>
        </header>

        <nav class="tabs" aria-label="Разделы">
            <ul class="tabs__list" role="tablist">
                <li role="presentation"><a role="tab" id="tab-modules" href="#modules" aria-controls="modules" aria-selected="true">Модули</a></li>
                <li role="presentation"><a role="tab" id="tab-page-load" href="#page-load" aria-controls="page-load" aria-selected="false">Загрузка страницы</a></li>
                <li role="presentation"><a role="tab" id="tab-click" href="#click" aria-controls="click" aria-selected="false">Клик</a></li>
                <li role="presentation"><a role="tab" id="tab-stages" href="#stages" aria-controls="stages" aria-selected="false">Стадии и лок</a></li>
                <li role="presentation"><a role="tab" id="tab-idempotency" href="#idempotency" aria-controls="idempotency" aria-selected="false">Идемпотентный переход</a></li>
                <li role="presentation"><a role="tab" id="tab-metrics" href="#metrics" aria-controls="metrics" aria-selected="false">Метрики</a></li>
            </ul>
        </nav>

        <section class="how__section" id="modules" role="tabpanel" aria-labelledby="tab-modules">
            <h2>Кто с кем разговаривает</h2>
            <p>
                Наружу открыт только nginx (<code>localhost:8080</code>). Он разводит запросы по путям:
                <code>/</code> — в HTTP-плагин RoadRunner, <code>/connection/</code> — в Centrifugo с апгрейдом до
                WebSocket, <code>/grafana/</code> — в Grafana. Всё остальное живёт во внутренней сети docker compose.
            </p>
            <p>
                RoadRunner и Centrifugo связаны двумя gRPC-каналами в разные стороны. Centrifugo проксирует события
                клиентов (connect, rpc) в плагин <code>centrifuge</code> на <code>app:10001</code>, а приложение
                публикует сообщения через gRPC API Centrifugo на <code>centrifugo:10000</code>.
            </p>
            <p>
                У RoadRunner три пула PHP-воркеров по два процесса <code>php app.php</code>: <code>http</code> отдаёт
                страницы, <code>centrifuge</code> отвечает на connect и RPC, <code>jobs</code> выполняет задачи
                переходов из очереди <code>transitions</code> (драйвер <code>memory</code>). Запросы и задачи RoadRunner
                передаёт воркеру через pipes — stdin/stdout процесса. Обратно к плагинам <code>kv</code>,
                <code>lock</code>, <code>jobs</code>, <code>metrics</code> и <code>centrifuge</code> воркер ходит по
                goridge RPC на <code>127.0.0.1:6001</code>.
            </p>
            <p>
                Состояние ритуала — в Valkey. Его читает и пишет KV-плагин RoadRunner: хранилище <code>hellowin</code>
                с драйвером <code>redis</code> на <code>VALKEY_ADDR</code>, в PHP это PSR-16 кэш <code>hellowin</code>.
                Ключи идемпотентности переходов <code>hellowin:amo:*</code> воркеры jobs пишут в тот же Valkey напрямую,
                через predis (<code>VALKEY_DSN</code>): пакету <code>spiral/idempotency</code> нужен атомарный
                <code>SET NX EX</code>, которого у KV-плагина нет. Локи <code>pumpkin:{id}</code> и <code>scene</code>
                и очередь живут в памяти процесса RoadRunner — этого хватает, пока <code>app</code> один.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1210 700" style="min-width: 871px" role="img" aria-label="Модули Hellowin: браузер ходит в nginx; nginx раздаёт запросы в RoadRunner, Centrifugo и Grafana. В RoadRunner три плагина с пулами PHP-воркеров: http, centrifuge и jobs. Воркеры по RPC обращаются к плагинам kv, lock, jobs, metrics и centrifuge. Состояние игры (kv, драйвер redis) и ключи идемпотентности (predis) лежат в Valkey. Prometheus собирает метрики с RoadRunner и Centrifugo.">
<defs><marker id="a-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-group g-rr" x="440" y="146" width="530" height="384" rx="10"/>
<text class="d-h" x="456" y="168" text-anchor="start">RoadRunner 3 · контейнер app · .rr.yaml</text>
<rect class="d-box g-edge" x="20" y="200" width="150" height="90" rx="6"/>
<text class="d-t" x="95" y="233.5" text-anchor="middle">Браузер</text>
<text class="d-m" x="95" y="248.5" text-anchor="middle">centrifuge-js</text>
<text class="d-s" x="95" y="263.5" text-anchor="middle">WebSocket</text>
<rect class="d-box g-edge" x="250" y="200" width="140" height="90" rx="6"/>
<text class="d-t" x="320" y="233.5" text-anchor="middle">nginx (proxy)</text>
<text class="d-s" x="320" y="248.5" text-anchor="middle">:80 в контейнере</text>
<text class="d-m" x="320" y="263.5" text-anchor="middle">localhost:8080</text>
<rect class="d-box g-cf" x="600" y="20" width="220" height="90" rx="6"/>
<text class="d-t" x="710" y="53.5" text-anchor="middle">Centrifugo v6</text>
<text class="d-m" x="710" y="68.5" text-anchor="middle">:8000 WebSocket, /metrics</text>
<text class="d-m" x="710" y="83.5" text-anchor="middle">:10000 gRPC API</text>
<rect class="d-box g-rr" x="460" y="186" width="150" height="50" rx="6"/>
<text class="d-t" x="535" y="207" text-anchor="middle">http</text>
<text class="d-m" x="535" y="222" text-anchor="middle">:8080</text>
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
<text class="d-m" x="715" y="465" text-anchor="middle">pumpkin:{id}, scene</text>
<rect class="d-box g-rr" x="830" y="432" width="125" height="44" rx="6"/>
<text class="d-t" x="892.5" y="450" text-anchor="middle">metrics</text>
<text class="d-m" x="892.5" y="465" text-anchor="middle">:2112 /metrics</text>
<rect class="d-box g-kv" x="1050" y="250" width="145" height="270" rx="6"/>
<text class="d-t" x="1122.5" y="328.5" text-anchor="middle">Valkey</text>
<text class="d-m" x="1122.5" y="343.5" text-anchor="middle">valkey:6379</text>
<text class="d-s" x="1122.5" y="358.5" text-anchor="middle">AOF + volume</text>
<text class="d-s" x="1122.5" y="373.5" text-anchor="middle"></text>
<text class="d-m" x="1122.5" y="388.5" text-anchor="middle">hellowin:round</text>
<text class="d-m" x="1122.5" y="403.5" text-anchor="middle">hellowin:scene</text>
<text class="d-m" x="1122.5" y="418.5" text-anchor="middle">hellowin:pumpkin:*</text>
<text class="d-s" x="1122.5" y="433.5" text-anchor="middle"></text>
<text class="d-m" x="1122.5" y="448.5" text-anchor="middle">hellowin:amo:*</text>
<rect class="d-box g-obs" x="250" y="600" width="140" height="60" rx="6"/>
<text class="d-t" x="320" y="626" text-anchor="middle">Grafana</text>
<text class="d-m" x="320" y="641" text-anchor="middle">:3000</text>
<rect class="d-box g-obs" x="800" y="600" width="170" height="60" rx="6"/>
<text class="d-t" x="885" y="626" text-anchor="middle">Prometheus</text>
<text class="d-m" x="885" y="641" text-anchor="middle">:9090</text>
<path class="d-e" d="M170 245 L250 245" marker-end="url(#a-ln)" marker-start="url(#a-lns)"/>
<text class="d-l" x="210" y="237" text-anchor="middle">HTTP</text>
<text class="d-l" x="210" y="262" text-anchor="middle">WS</text>
<path class="d-e" d="M320 200 L320 65 L600 65" marker-end="url(#a-ln)"/>
<text class="d-l" x="460" y="57" text-anchor="middle">/connection/ → :8000</text>
<text class="d-s" x="460" y="82" text-anchor="middle">WebSocket</text>
<path class="d-e" d="M390 211 L460 211" marker-end="url(#a-ln)"/>
<text class="d-l" x="422" y="203" text-anchor="middle">/ → :8080</text>
<path class="d-e" d="M320 290 L320 600" marker-end="url(#a-ln)"/>
<text class="d-l" x="328" y="450" text-anchor="start">/grafana/ → :3000</text>
<path class="d-e d-e--acc" d="M680 110 L680 186" marker-end="url(#a-acc)"/>
<text class="d-l" x="674" y="122" text-anchor="end">gRPC proxy :10001</text>
<text class="d-s" x="674" y="136" text-anchor="end">connect, rpc</text>
<path class="d-e d-e--acc" d="M760 186 L760 110" marker-end="url(#a-acc)"/>
<text class="d-l" x="766" y="122" text-anchor="start">gRPC API :10000</text>
<text class="d-s" x="766" y="136" text-anchor="start">broadcast</text>
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
<path class="d-e d-e--acc" d="M800 384 L800 222 L792 222" marker-end="url(#a-acc)"/>
<path class="d-e" d="M818 384 L818 200 L828 200" marker-end="url(#a-ln)"/>
<path class="d-e d-e--kv" d="M535 476 L535 505 L1050 505" marker-end="url(#a-kv)"/>
<text class="d-l" x="700" y="498" text-anchor="start">driver redis · VALKEY_ADDR</text>
<path class="d-e d-e--kv" d="M955 315 L1050 315" marker-end="url(#a-kv)"/>
<text class="d-l" x="1002" y="308" text-anchor="middle">predis</text>
<text class="d-s" x="1002" y="332" text-anchor="middle">VALKEY_DSN</text>
<path class="d-e d-e--ok" d="M892 600 L892 476" marker-end="url(#a-ok)"/>
<text class="d-l" x="900" y="565" text-anchor="start">scrape :2112 · 5s</text>
<path class="d-e d-e--ok" d="M970 630 L1203 630 L1203 65 L820 65" marker-end="url(#a-ok)"/>
<text class="d-l" x="1010" y="57" text-anchor="middle">scrape :8000/metrics</text>
<path class="d-e d-e--ok" d="M390 630 L800 630" marker-end="url(#a-ok)"/>
<text class="d-l" x="595" y="622" text-anchor="middle">PromQL → :9090</text>
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
                <code>hellowin:round</code>, <code>hellowin:scene</code> и <code>hellowin:pumpkin:{id}</code> всех тыкв —
                без локов, страница может отстать на клик, это не страшно. К ним добавляются раскладка из
                <code>app/config/hellowin.php</code> и числа политики, и шаблон получает снимок (Snapshot):
            </p>
            <ul>
                <li><code>round</code>, <code>scene</code> — текущий раунд и сцена;</li>
                <li><code>sceneStages</code> — номер финальной сцены (длина <code>HELLOWIN_SCENE_STAGE_MAX</code>), <code>stageMax</code> — последняя стадия тыквы в текущей сцене;</li>
                <li><code>threshold</code> — <code>HELLOWIN_CLICKS_PER_STAGE</code>;</li>
                <li><code>elements</code> — тыквы: <code>id</code>, <code>type</code>, <code>x</code>, <code>y</code>, <code>size</code>, <code>stage</code> и <code>clicks</code> с момента перехода в текущую стадию.</li>
            </ul>
            <p>
                Затем браузер открывает WebSocket. Centrifugo ничего не знает о пользователях и на connect спрашивает
                приложение (connect proxy). <code>ConnectService</code> отвечает анонимным пользователем и списком
                <code>channels: ['{{ $channel }}']</code> — это server-side подписка. Клиент сам <code>subscribe</code>
                не вызывает: в <code>config.json</code> клиентские подписки и публикации запрещены, сообщения канала
                приходят в <code>centrifuge.on('publication')</code>.
            </p>
            <p>
                Тот же снимок отдаёт RPC <code>state</code>. Каждое событие канала несёт <code>round</code>: если он не
                совпал с раундом клиента, клиент пропустил сброс и перезапрашивает снимок; так же — после
                переподключения, когда события за время обрыва потеряны.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 974" style="min-width: 720px" role="img" aria-label="Загрузка страницы: GET / проходит через nginx в RoadRunner и HomeController; SnapshotFactory без лока читает раунд, сцену и тыквы из KV и отдаёт снимок в шаблон. Затем браузер открывает WebSocket к Centrifugo, Centrifugo спрашивает ConnectService через connect proxy и подписывает клиента на канал hellowin на стороне сервера. При переподключении или событии с чужим раундом клиент вызывает RPC state и получает тот же снимок.">
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
<text class="d-l" x="239" y="140" text-anchor="middle">app:8080</text>
<path class="d-e " d="M300 176 L420 176" marker-end="url(#b-ln)"/>
<text class="d-l" x="360" y="170" text-anchor="middle">pipes</text>
<path class="d-e " d="M424 206 L544 206" marker-end="url(#b-ln)"/>
<text class="d-l" x="484" y="200" text-anchor="middle">kv.MGet, без лока</text>
<path class="d-e d-e--ret" d="M544 236 L424 236" marker-end="url(#b-ln)"/>
<text class="d-l" x="484" y="230" text-anchor="middle">round, scene, тыквы</text>
<path class="d-e " d="M422 258 L446 258 L446 270 L424 270" marker-end="url(#b-ln)"/>
<text class="d-l" x="452" y="267" text-anchor="start">Snapshot: round, scene, stageMax, threshold, elements</text>
<path class="d-e " d="M422 288 L446 288 L446 300 L424 300" marker-end="url(#b-ln)"/>
<text class="d-l" x="452" y="297" text-anchor="start">render('home', snapshot + centrifugoUrl)</text>
<path class="d-e d-e--ret" d="M420 326 L64 326" marker-end="url(#b-ln)"/>
<text class="d-l" x="242" y="320" text-anchor="middle">200 text/html (обратно через http и nginx)</text>
<path class="d-e " d="M62 348 L86 348 L86 360 L64 360" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="357" text-anchor="start">сцена по снимку · new Centrifuge(…).connect()</text>
<text class="d-h d-phase" x="14" y="382" text-anchor="start">2. WebSocket</text>
<path class="d-e " d="M64 410 L178 410" marker-end="url(#b-ln)"/>
<text class="d-l" x="121" y="404" text-anchor="middle">/connection/websocket</text>
<path class="d-e " d="M182 440 L668 440" marker-end="url(#b-ln)"/>
<text class="d-l" x="425" y="434" text-anchor="middle">Upgrade → centrifugo:8000</text>
<path class="d-e d-e--acc" d="M672 470 L788 470" marker-end="url(#b-acc)"/>
<text class="d-l" x="730" y="464" text-anchor="middle">connect proxy (gRPC)</text>
<path class="d-e " d="M792 500 L923 500" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="494" text-anchor="middle">pipes</text>
<path class="d-e d-e--ret" d="M923 530 L792 530" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="524" text-anchor="middle">channels:[hellowin]</text>
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
<text class="d-l" x="735.5" y="818" text-anchor="middle">round, scene, тыквы</text>
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
<text class="d-t" x="180" y="34" text-anchor="middle">nginx</text>
<text class="d-m" x="180" y="49" text-anchor="middle">:80</text>
<rect class="d-box g-rr" x="241" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="298" y="34" text-anchor="middle">RR http</text>
<text class="d-m" x="298" y="49" text-anchor="middle">:8080</text>
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
            <ol>
                <li>Элемент проверяется по <code>ElementRegistry</code> (id из <code>app/config/hellowin.php</code>).</li>
                <li><code>RitualMetrics</code> добавляет 1 к <code>hellowin_clicks_total</code>.</li>
                <li><code>RitualStore::click()</code> под локом <code>pumpkin:{id}</code> увеличивает <code>clicks</code> тыквы в KV и тут же, под тем же локом, читает раунд и сцену. Лок только на эту тыкву: клики по разным тыквам друг друга не ждут.</li>
                <li><code>ChannelPublisher</code> публикует в канал <code>{{ $channel }}</code> событие <code>click</code> — всем подписчикам сразу.</li>
                <li><code>LockstepPolicy::pumpkinTransition()</code> решает, пора ли тыкве дальше: <code>clicks</code> не меньше <code>HELLOWIN_CLICKS_PER_STAGE</code> и стадия ниже <code>stageMax</code> текущей сцены. Если да, <code>TransitionQueue</code> ставит в очередь задачу <code>TransitionPumpkin</code>, а <code>hellowin_transition_jobs_pushed_total{kind="pumpkin"}</code> растёт на 1.</li>
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
<text class="d-l" x="732" y="356" text-anchor="middle">{stage, clicks}</text>
<path class="d-e " d="M552 392 L912 392" marker-end="url(#c-ln)"/>
<text class="d-l" x="732" y="386" text-anchor="middle">set {stage, clicks + 1}</text>
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
<text class="d-l" x="792" y="686" text-anchor="middle">да: jobs.Push TransitionPumpkin {key, round, pumpkin, toStage}</text>
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
            <p>Сообщения в канале — JSON-строки. Событие клика:</p>
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
                <li>Тыква переходит в <code>stage + 1</code> после <code>HELLOWIN_CLICKS_PER_STAGE</code> кликов, пока <code>stage &lt; stageMax(scene)</code>; переход обнуляет <code>clicks</code>.</li>
                <li>Сцена переходит в следующую, когда <strong>все</strong> тыквы дошли до <code>stageMax</code>. В финальной сцене ничего не двигается, клики только рассылаются.</li>
            </ul>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 330" style="min-width: 720px" role="img" aria-label="Сцены и стадии: по горизонтали сцены от 0 до финальной N, по вертикали стадия тыквы. В каждой сцене тыквы поднимаются по стадиям до stageMax этой сцены и ждут там; когда все дошли, сцена меняется и потолок поднимается до stageMax следующей сцены. В финальной сцене переходов нет.">
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
<text class="d-s" x="275" y="282" text-anchor="middle">стадия +1 = HELLOWIN_CLICKS_PER_STAGE кликов</text>
<text class="d-m" x="275" y="297" text-anchor="middle">→ TransitionPumpkin</text>
<text class="d-s" x="485" y="282" text-anchor="middle">все на stageMax(0)</text>
<text class="d-m" x="485" y="297" text-anchor="middle">→ TransitionScene → сцена 1</text>
<text class="d-s" x="790" y="282" text-anchor="middle">все на stageMax(N−1)</text>
<text class="d-m" x="790" y="297" text-anchor="middle">→ финал</text>
<text class="d-s" x="575" y="318" text-anchor="middle">N = длина списка HELLOWIN_SCENE_STAGE_MAX, stageMax(s) — его элемент номер s; потолок финала = stageMax(N−1)</text>
</svg>
                </div>
                <figcaption>Оранжевая ступенька — потолок стадий <code>stageMax(s)</code>; кружки — тыквы.</figcaption>
            </figure>
            <p>Сейчас порог — {{ $clicksPerStage }} кликов на каждую стадию, так проходит тыква внутри сцены:</p>
            <ol class="stages" start="0">
                <li class="stages__item"><b>0</b>старт раунда</li>
                <li class="stages__item"><b>1</b>с {{ $clicksPerStage }} кликов</li>
                <li class="stages__item"><b>2</b>ещё {{ $clicksPerStage }}</li>
                <li class="stages__item"><b>…</b>по {{ $clicksPerStage }}</li>
                <li class="stages__item"><b>stageMax</b>ждёт остальных</li>
            </ol>

            <h3>Зачем лок на тыкву</h3>
            <p>
                Клики обрабатывают несколько воркеров параллельно (в пуле <code>centrifuge</code> их два), и по одной
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
                    <tr><td>переход сцены, <code>advanceScene()</code></td><td><code>scene</code></td><td><code>round</code> текущий и <code>scene == toScene − 1</code></td></tr>
                    <tr><td>сброс, <code>reset()</code></td><td><code>scene</code> и все <code>pumpkin:{id}</code></td><td><code>round == fromRound</code></td></tr>
                    </tbody>
                </table>
            </div>

            <h3>Раунд и сброс</h3>
            <p>
                Сброс начинает ритуал заново: сцена 0, у всех тыкв <code>stage 0</code> и <code>clicks 0</code>,
                <code>round + 1</code> и событие <code>reset</code> всем. Делает это <code>ResetService::reset(?int $fromRound)</code>,
                сейчас его вызывает консольная команда <code>hellowin:reset [--round=N]</code>. Сервис берёт лок
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
            <pre><code>#[Idempotent(storage: 'transitions', key: 'payload.key')]
final class TransitionPumpkin extends JobHandler</code></pre>

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
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1100 838" style="min-width: 792px" role="img" aria-label="Идемпотентный переход: клики сверх порога ставят в очередь transitions несколько задач TransitionPumpkin с одним ключом. Воркеры jobs разбирают их параллельно; IdempotencyInterceptor каждого делает SET NX EX в Valkey. Первый получает OK и выполняет переход: под локом тыквы проверяет раунд и стадию, пишет stage + 1 и clicks = 0, публикует событие pumpkin. Остальные получают отказ: обработчик не вызывается, задача подтверждается.">
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
<text class="d-l" x="476" y="749" text-anchor="start">transitions_total +1 · все на stageMax? → push TransitionScene</text>
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

            <h3>Переход сцены</h3>
            <p>
                После каждого перехода тыквы <code>TransitionHandler</code> спрашивает у политики, не пора ли сцене дальше.
                Последние тыквы могут дойти до <code>stageMax</code> почти одновременно, и тогда несколько воркеров
                поставят <code>TransitionScene</code>. Механизм тот же: ключ <code>{round}:scene:{toScene}</code>
                (<code>TransitionQueue::sceneKey()</code>), тот же <code>#[Idempotent]</code>, внутри — лок
                <code>scene</code>, проверка <code>scene == toScene − 1</code> и одно событие <code>scene</code> со
                <code>stageMax</code> новой сцены.
            </p>

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

        <section class="how__section" id="metrics" role="tabpanel" aria-labelledby="tab-metrics">
            <h2>Метрики</h2>
            <p>
                При старте воркера <code>HellowinBootloader</code> объявляет коллекторы в плагине <code>metrics</code> и
                выставляет gauge из состояния в Valkey — оно переживает перезапуск RoadRunner. Дальше
                <code>RitualMetrics</code> вызывает <code>metrics.Add</code> и <code>metrics.Set</code> по RPC из клика,
                постановки задачи, перехода и сброса. Ошибка метрик только пишется в лог: клик из-за неё не падает.
                RoadRunner отдаёт всё на <code>:2112/metrics</code> вместе со своими <code>rr_http_*</code> (middleware
                <code>http_metrics</code> в <code>.rr.yaml</code>; там же <code>gzip</code>, который сжимает и ответы PHP,
                и статику — он стоит перед <code>static</code>, потому что RoadRunner 3 выполняет middleware слева
                направо).
            </p>
            <p>
                Prometheus раз в 5 секунд скрейпит <code>app:2112</code> и <code>centrifugo:8000/metrics</code>.
                Grafana получает datasource и дашборд «Hellowin» через provisioning и пускает анонимно в роли Viewer:
                смотреть может любой, редактировать — нет.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 330" style="min-width: 720px" role="img" aria-label="Путь метрики: RitualMetrics из ClickHandler, TransitionQueue, TransitionHandler, ResetService и HellowinBootloader вызывает metrics.Add и metrics.Set по RPC в плагин metrics RoadRunner; Prometheus каждые 5 секунд забирает /metrics с :2112 и с Centrifugo :8000; Grafana строит дашборд PromQL-запросами к Prometheus, браузер открывает её через nginx /grafana/.">
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
<text class="d-m" x="435" y="281" text-anchor="middle">:8000/metrics</text>
<rect class="d-box g-edge" x="830" y="240" width="150" height="60" rx="6"/>
<text class="d-t" x="905" y="266" text-anchor="middle">Браузер</text>
<text class="d-m" x="905" y="281" text-anchor="middle">/grafana/</text>
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
            <p><a href="/grafana/">Открыть дашборд в Grafana</a> · <a href="/">Вернуться к тыквам</a></p>
        </section>
    </main>
</define:body>
