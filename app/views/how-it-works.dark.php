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
                Hellowin — демо реального времени: клик по тыкве в одной вкладке показывает emoji во всех открытых
                вкладках, клики считаются в метриках, а после порога меняется вид страницы у всех сразу. Ниже — что
                происходит на самом деле, с именами классов, каналов и портами из кода.
            </p>
            <dl class="how__terms">
                <dt>Spiral Framework</dt>
                <dd>PHP-фреймворк: контроллеры, DI-контейнер, конфиги, шаблоны Stempler. Здесь он — бизнес-логика.</dd>
                <dt>RoadRunner</dt>
                <dd>Сервер приложений на Go. Держит пул долгоживущих PHP-воркеров и даёт им плагины: HTTP, KV,
                    локи, метрики, мост к Centrifugo. PHP обращается к плагинам по RPC.</dd>
                <dt>Centrifugo</dt>
                <dd>Сервер WebSocket-соединений и каналов (pub/sub). Держит соединения с браузерами, а решения
                    «пускать ли», «что делать с RPC» спрашивает у приложения через proxy.</dd>
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
                PHP-воркеры сами сокетов не держат. Запросы (HTTP и proxy-вызовы Centrifugo) RoadRunner передаёт
                воркеру через pipes — stdin/stdout процесса <code>php app.php</code>. Обратно к плагинам
                <code>kv</code>, <code>lock</code>, <code>metrics</code> и <code>centrifuge</code> воркер ходит по
                goridge RPC на <code>127.0.0.1:6001</code>.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 600" style="min-width: 720px" role="img" aria-label="Модули Hellowin: браузер ходит в nginx, nginx раздаёт запросы в RoadRunner, Centrifugo и Grafana; Centrifugo и RoadRunner связаны по gRPC в обе стороны; Prometheus собирает метрики с RoadRunner и Centrifugo.">
<defs><marker id="a-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-group g-rr" x="440" y="160" width="500" height="310" rx="10"/>
<text class="d-h" x="456" y="182" text-anchor="start">RoadRunner 3 · app · .rr.yaml</text>
<rect class="d-box g-edge" x="20" y="200" width="150" height="90" rx="6"/>
<text class="d-t" x="95" y="233.5" text-anchor="middle">Браузер</text>
<text class="d-m" x="95" y="248.5" text-anchor="middle">app.js</text>
<text class="d-m" x="95" y="263.5" text-anchor="middle">centrifuge-js</text>
<rect class="d-box g-edge" x="250" y="200" width="140" height="90" rx="6"/>
<text class="d-t" x="320" y="233.5" text-anchor="middle">nginx (proxy)</text>
<text class="d-s" x="320" y="248.5" text-anchor="middle">:80 в контейнере</text>
<text class="d-m" x="320" y="263.5" text-anchor="middle">localhost:8080</text>
<rect class="d-box g-cf" x="600" y="20" width="220" height="90" rx="6"/>
<text class="d-t" x="710" y="53.5" text-anchor="middle">Centrifugo v6</text>
<text class="d-m" x="710" y="68.5" text-anchor="middle">:8000 WebSocket, /metrics</text>
<text class="d-m" x="710" y="83.5" text-anchor="middle">:10000 gRPC API</text>
<rect class="d-box g-rr" x="460" y="196" width="140" height="50" rx="6"/>
<text class="d-t" x="530" y="212" text-anchor="middle">http</text>
<text class="d-m" x="530" y="226" text-anchor="middle">:8080</text>
<text class="d-s" x="530" y="240" text-anchor="middle" style="font-size:9.5px">metrics → gzip → static</text>
<rect class="d-box g-rr" x="640" y="196" width="160" height="50" rx="6"/>
<text class="d-t" x="720" y="217" text-anchor="middle">centrifuge</text>
<text class="d-m" x="720" y="232" text-anchor="middle">proxy :10001</text>
<rect class="d-box g-php" x="460" y="290" width="440" height="60" rx="6"/>
<text class="d-t" x="680" y="316" text-anchor="middle">PHP-воркеры Spiral (php app.php)</text>
<text class="d-m" x="680" y="331" text-anchor="middle">HomeController · ConnectService · RPCService</text>
<rect class="d-box g-rr" x="460" y="380" width="440" height="24" rx="6"/>
<text class="d-m" x="680" y="395.5" text-anchor="middle">rpc :6001 — kv.* · lock.* · metrics.* · centrifuge.Broadcast</text>
<rect class="d-box g-rr" x="460" y="420" width="130" height="40" rx="6"/>
<text class="d-t" x="525" y="436" text-anchor="middle">kv</text>
<text class="d-m" x="525" y="451" text-anchor="middle">hellowin: memory</text>
<rect class="d-box g-rr" x="610" y="420" width="130" height="40" rx="6"/>
<text class="d-t" x="675" y="436" text-anchor="middle">lock</text>
<text class="d-m" x="675" y="451" text-anchor="middle">hellowin:state</text>
<rect class="d-box g-rr" x="760" y="420" width="140" height="40" rx="6"/>
<text class="d-t" x="830" y="436" text-anchor="middle">metrics</text>
<text class="d-m" x="830" y="451" text-anchor="middle">:2112 /metrics</text>
<rect class="d-box g-obs" x="250" y="520" width="140" height="60" rx="6"/>
<text class="d-t" x="320" y="546" text-anchor="middle">Grafana</text>
<text class="d-m" x="320" y="561" text-anchor="middle">:3000</text>
<rect class="d-box g-obs" x="760" y="520" width="160" height="60" rx="6"/>
<text class="d-t" x="840" y="546" text-anchor="middle">Prometheus</text>
<text class="d-m" x="840" y="561" text-anchor="middle">:9090</text>
<path class="d-e" d="M170 245 L250 245" marker-end="url(#a-ln)" marker-start="url(#a-lns)"/>
<text class="d-l" x="210" y="237" text-anchor="middle">HTTP</text>
<text class="d-l" x="210" y="262" text-anchor="middle">WS</text>
<path class="d-e" d="M320 200 L320 65 L600 65" marker-end="url(#a-ln)"/>
<text class="d-l" x="460" y="57" text-anchor="middle">/connection/ → :8000</text>
<text class="d-s" x="460" y="82" text-anchor="middle">WebSocket</text>
<path class="d-e" d="M390 221 L460 221" marker-end="url(#a-ln)"/>
<text class="d-l" x="415" y="213" text-anchor="middle">/→:8080</text>
<path class="d-e" d="M320 290 L320 520" marker-end="url(#a-ln)"/>
<text class="d-l" x="328" y="400" text-anchor="start">/grafana/ → :3000</text>
<path class="d-e d-e--acc" d="M680 110 L680 196" marker-end="url(#a-acc)"/>
<text class="d-l" x="674" y="140" text-anchor="end">gRPC proxy :10001</text>
<text class="d-s" x="674" y="154" text-anchor="end">connect, rpc</text>
<path class="d-e d-e--acc" d="M760 196 L760 110" marker-end="url(#a-acc)"/>
<text class="d-l" x="766" y="140" text-anchor="start">gRPC API :10000</text>
<text class="d-s" x="766" y="154" text-anchor="start">broadcast</text>
<path class="d-e" d="M530 246 L530 290" marker-end="url(#a-ln)"/>
<text class="d-s" x="536" y="272" text-anchor="start">pipes</text>
<path class="d-e" d="M720 246 L720 290" marker-end="url(#a-ln)"/>
<text class="d-s" x="726" y="272" text-anchor="start">pipes</text>
<path class="d-e" d="M600 350 L600 380" marker-end="url(#a-ln)"/>
<text class="d-s" x="606" y="369" text-anchor="start">goridge RPC</text>
<path class="d-e" d="M525 404 L525 420" marker-end="url(#a-ln)"/>
<path class="d-e" d="M675 404 L675 420" marker-end="url(#a-ln)"/>
<path class="d-e" d="M830 404 L830 420" marker-end="url(#a-ln)"/>
<path class="d-e d-e--acc" d="M900 392 L920 392 L920 221 L800 221" marker-end="url(#a-acc)"/>
<path class="d-e d-e--ok" d="M830 520 L830 460" marker-end="url(#a-ok)"/>
<text class="d-l" x="838" y="496" text-anchor="start">scrape :2112 · 5s</text>
<path class="d-e d-e--ok" d="M920 550 L975 550 L975 65 L820 65" marker-end="url(#a-ok)"/>
<text class="d-l" x="897" y="57" text-anchor="middle">scrape :8000/metrics</text>
<path class="d-e d-e--ok" d="M390 550 L760 550" marker-end="url(#a-ok)"/>
<text class="d-l" x="575" y="542" text-anchor="middle">PromQL → :9090</text>
</svg>
                </div>
                <figcaption>
                    <ul class="legend">
                        <li><span class="legend__swatch" style="--swatch: var(--d-cf)"></span>gRPC между Centrifugo и RoadRunner</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-obs)"></span>метрики: Prometheus скрейпит, Grafana спрашивает</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-line)"></span>HTTP, WebSocket, pipes, RPC</li>
                    </ul>
                </figcaption>
            </figure>
        </section>

        <section class="how__section" id="page-load" role="tabpanel" aria-labelledby="tab-page-load">
            <h2>Загрузка страницы и подключение</h2>
            <p>
                <code>GET /</code> — обычный HTTP. <code>HomeController::index()</code> берёт снимок состояния
                <code>GameState::snapshot()</code> из KV-хранилища <code>hellowin</code> (без лока: страница может
                отстать на клик, это не страшно) и рендерит шаблон. Стадия попадает в <code>&lt;body data-stage&gt;</code>,
                клики и порог — в data-атрибуты для прогресс-бара, так что новый клиент сразу видит текущую стадию.
            </p>
            <p>
                Затем <code>app.js</code> открывает WebSocket. Centrifugo ничего не знает о пользователях и на connect
                спрашивает приложение (connect proxy). <code>ConnectService</code> отвечает анонимным пользователем и
                списком <code>channels: ['{{ $channel }}']</code> — это server-side подписка. Клиент сам
                <code>subscribe</code> не вызывает: в <code>config.json</code> клиентские подписки и публикации
                запрещены, сообщения канала приходят в <code>centrifuge.on('publication')</code>.
                <code>SubscribeService</code> зарегистрирован, но subscribe proxy в Centrifugo не включён, поэтому
                сейчас он не вызывается.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 650" style="min-width: 720px" role="img" aria-label="Загрузка страницы: GET / проходит через nginx в RoadRunner и HomeController, тот читает состояние из KV и рендерит HTML; затем браузер открывает WebSocket к Centrifugo, Centrifugo спрашивает ConnectService через connect proxy и подписывает клиента на канал hellowin на стороне сервера.">
<defs><marker id="b-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-life" d="M62 60 V650"/>
<path class="d-life" d="M180 60 V650"/>
<path class="d-life" d="M298 60 V650"/>
<path class="d-life" d="M416 60 V650"/>
<path class="d-life" d="M534 60 V650"/>
<path class="d-life" d="M660 60 V650"/>
<path class="d-life" d="M786 60 V650"/>
<path class="d-life" d="M920 60 V650"/>
<text class="d-h d-phase" x="14" y="88" text-anchor="start">1. HTML</text>
<path class="d-e " d="M64 116 L178 116" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="121" y="110" text-anchor="middle">GET /</text>
<path class="d-e " d="M182 146 L296 146" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="239" y="140" text-anchor="middle">app:8080</text>
<path class="d-e " d="M300 176 L414 176" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="357" y="170" text-anchor="middle">pipes</text>
<path class="d-e " d="M418 206 L532 206" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="475" y="200" text-anchor="middle">kv.MGet clicks, stage</text>
<path class="d-e d-e--ret" d="M532 236 L418 236" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="475" y="230" text-anchor="middle">значения</text>
<path class="d-e " d="M416 258 L440 258 L440 270 L418 270" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="446" y="267" text-anchor="start">render('home') · data-stage</text>
<path class="d-e d-e--ret" d="M414 296 L64 296" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="239" y="290" text-anchor="middle">200 text/html (обратно через http и nginx)</text>
<path class="d-e " d="M62 318 L86 318 L86 330 L64 330" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="92" y="327" text-anchor="start">new Centrifuge(…).connect()</text>
<text class="d-h d-phase" x="14" y="352" text-anchor="start">2. WebSocket</text>
<path class="d-e " d="M64 380 L178 380" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="121" y="374" text-anchor="middle">/connection/websocket</text>
<path class="d-e " d="M182 410 L658 410" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="420" y="404" text-anchor="middle">Upgrade → centrifugo:8000</text>
<path class="d-e d-e--acc" d="M662 440 L784 440" marker-end="url(#b-acc)"/>
<text class="d-l d-halo" x="723" y="434" text-anchor="middle">connect proxy (gRPC)</text>
<path class="d-e " d="M788 470 L918 470" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="853" y="464" text-anchor="middle">pipes</text>
<path class="d-e d-e--ret" d="M918 500 L788 500" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="853" y="494" text-anchor="middle">ConnectResponse channels:[hellowin]</text>
<path class="d-e d-e--ret" d="M784 530 L662 530" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="723" y="524" text-anchor="middle">ответ</text>
<path class="d-e " d="M660 552 L684 552 L684 564 L662 564" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="690" y="561" text-anchor="start">подписать на hellowin (server-side)</text>
<path class="d-e d-e--ret d-e--acc" d="M658 590 L64 590" marker-end="url(#b-acc)"/>
<text class="d-l d-halo" x="361" y="584" text-anchor="middle">connected + subs: hellowin</text>
<path class="d-e " d="M62 612 L86 612 L86 624 L64 624" marker-end="url(#b-ln)"/>
<text class="d-l d-halo" x="92" y="621" text-anchor="start">status: connected</text>
<rect class="d-box g-edge" x="5" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="62" y="34" text-anchor="middle">Браузер</text>
<text class="d-m" x="62" y="49" text-anchor="middle">app.js</text>
<rect class="d-box g-edge" x="123" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="180" y="34" text-anchor="middle">nginx</text>
<text class="d-m" x="180" y="49" text-anchor="middle">:80</text>
<rect class="d-box g-rr" x="241" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="298" y="34" text-anchor="middle">RR http</text>
<text class="d-m" x="298" y="49" text-anchor="middle">:8080</text>
<rect class="d-box g-php" x="359" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="416" y="34" text-anchor="middle">HomeController</text>
<text class="d-s" x="416" y="49" text-anchor="middle">PHP</text>
<rect class="d-box g-rr" x="477" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="534" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="534" y="49" text-anchor="middle">hellowin</text>
<rect class="d-box g-cf" x="603" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="660" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="660" y="49" text-anchor="middle">:8000</text>
<rect class="d-box g-rr" x="729" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="786" y="34" text-anchor="middle">RR centrifuge</text>
<text class="d-m" x="786" y="49" text-anchor="middle">:10001</text>
<rect class="d-box g-php" x="863" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="920" y="34" text-anchor="middle">ConnectService</text>
<text class="d-s" x="920" y="49" text-anchor="middle">PHP</text>
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
                <li><code>ClickMetrics</code> добавляет 1 к <code>hellowin_clicks_total</code>.</li>
                <li><code>GameState::registerClick()</code> под локом читает и увеличивает счётчик в KV, считает стадию, отпускает лок.</li>
                <li><code>ChannelPublisher</code> выбирает случайный emoji элемента и публикует событие <code>emoji</code> в канал <code>{{ $channel }}</code> — всем подписчикам сразу.</li>
                <li>Ответ RPC — всегда данные: <code>{ok: true}</code> или <code>{ok: false, error: 'unknown element' | 'busy' | 'internal error'}</code>.</li>
            </ol>
            <p class="callout">
                Свой клик браузер локально не рисует. Emoji появляется только когда из канала приходит publication —
                у кликнувшего так же, как у всех. Поэтому на экране видна честная задержка круга
                браузер → сервер → браузер.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 784" style="min-width: 720px" role="img" aria-label="Клик: браузер вызывает RPC click через Centrifugo, RoadRunner передаёт его в RPCService и ClickHandler; тот пишет метрику, под локом обновляет счётчик в KV, отпускает лок и рассылает emoji через gRPC API Centrifugo всем клиентам канала, включая кликнувшего.">
<defs><marker id="c-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="c-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-crit" x="560" y="272" width="12" height="180" rx="2"/>
<path class="d-life" d="M60 60 V784"/>
<path class="d-life" d="M180 60 V784"/>
<path class="d-life" d="M298 60 V784"/>
<path class="d-life" d="M424 60 V784"/>
<path class="d-life" d="M566 60 V784"/>
<path class="d-life" d="M704 60 V784"/>
<path class="d-life" d="M822 60 V784"/>
<path class="d-life" d="M940 60 V784"/>
<path class="d-e " d="M180 84 L204 84 L204 96 L182 96" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="210" y="93" text-anchor="start">клик: локально ничего не рисуем</text>
<path class="d-e " d="M182 122 L296 122" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="239" y="116" text-anchor="middle">rpc('click', {element})</text>
<path class="d-e d-e--acc" d="M300 152 L422 152" marker-end="url(#c-acc)"/>
<text class="d-l d-halo" x="361" y="146" text-anchor="middle">RPC proxy (gRPC)</text>
<path class="d-e " d="M426 182 L564 182" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="495" y="176" text-anchor="middle">pipes</text>
<path class="d-e " d="M566 204 L590 204 L590 216 L568 216" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="596" y="213" text-anchor="start">ElementRegistry::find()</text>
<path class="d-e " d="M568 242 L702 242" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="635" y="236" text-anchor="middle">metrics.Add clicks_total</text>
<path class="d-e " d="M568 272 L820 272" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="694" y="266" text-anchor="middle">lock.Lock (ttl 5s, wait 5s)</text>
<path class="d-e d-e--ret" d="M820 302 L568 302" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="694" y="296" text-anchor="middle">lockId</text>
<path class="d-e " d="M568 332 L938 332" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="753" y="326" text-anchor="middle">kv.MGet clicks, stage</text>
<path class="d-e d-e--ret" d="M938 362 L568 362" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="753" y="356" text-anchor="middle">N, stage</text>
<path class="d-e " d="M566 384 L590 384 L590 396 L568 396" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="596" y="393" text-anchor="start">N+1 · сравнить с порогом</text>
<path class="d-e " d="M568 422 L938 422" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="753" y="416" text-anchor="middle">kv.Set clicks (+ stage)</text>
<path class="d-e " d="M568 452 L820 452" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="694" y="446" text-anchor="middle">lock.Release</text>
<path class="d-e " d="M568 482 L702 482" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="635" y="476" text-anchor="middle">metrics.Set hellowin_stage</text>
<path class="d-e d-e--acc" d="M564 512 L426 512" marker-end="url(#c-acc)"/>
<text class="d-l d-halo" x="495" y="506" text-anchor="middle">centrifuge.Broadcast (RPC :6001)</text>
<path class="d-e d-e--acc" d="M422 542 L300 542" marker-end="url(#c-acc)"/>
<text class="d-l d-halo" x="361" y="536" text-anchor="middle">gRPC API :10000</text>
<path class="d-e d-e--acc" d="M296 572 L182 572" marker-end="url(#c-acc)"/>
<text class="d-l d-halo" x="239" y="566" text-anchor="middle">publication type:'emoji'</text>
<path class="d-e d-e--acc" d="M296 602 L62 602" marker-end="url(#c-acc)"/>
<text class="d-l d-halo" x="179" y="596" text-anchor="middle">та же publication</text>
<path class="d-e " d="M60 624 L84 624 L84 636 L62 636" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="90" y="633" text-anchor="start">popEmoji()</text>
<path class="d-e " d="M180 624 L204 624 L204 636 L182 636" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="210" y="633" text-anchor="start">popEmoji() + прогресс</text>
<path class="d-e d-e--ret" d="M564 662 L426 662" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="495" y="656" text-anchor="middle">RPCResponse {ok: true}</text>
<path class="d-e d-e--ret" d="M422 692 L300 692" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="361" y="686" text-anchor="middle">ответ RPC</text>
<path class="d-e d-e--ret" d="M296 722 L182 722" marker-end="url(#c-ln)"/>
<text class="d-l d-halo" x="239" y="716" text-anchor="middle">{ok: true}</text>
<rect class="d-note " x="20" y="738" width="586" height="24" rx="4"/>
<text class="d-n" x="313" y="755" text-anchor="middle">порог пройден → ещё одна publication type:'state' (тем же путём)</text>
<rect class="d-box g-edge" x="2" y="16" width="116" height="44" rx="6"/>
<text class="d-t" x="60" y="34" text-anchor="middle">Другие вкладки</text>
<text class="d-s" x="60" y="49" text-anchor="middle">канал hellowin</text>
<rect class="d-box g-edge" x="123" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="180" y="34" text-anchor="middle">Браузер (клик)</text>
<text class="d-m" x="180" y="49" text-anchor="middle">app.js</text>
<rect class="d-box g-cf" x="241" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="298" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="298" y="49" text-anchor="middle">:8000</text>
<rect class="d-box g-rr" x="367" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="424" y="34" text-anchor="middle">RR centrifuge</text>
<text class="d-m" x="424" y="49" text-anchor="middle">:10001</text>
<rect class="d-box g-php" x="498" y="16" width="136" height="44" rx="6"/>
<text class="d-t" x="566" y="34" text-anchor="middle">RPCService</text>
<text class="d-m" x="566" y="49" text-anchor="middle">→ ClickHandler</text>
<rect class="d-box g-rr" x="647" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="704" y="34" text-anchor="middle">RR metrics</text>
<text class="d-m" x="704" y="49" text-anchor="middle">:2112</text>
<rect class="d-box g-rr" x="765" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="822" y="34" text-anchor="middle">RR lock</text>
<text class="d-m" x="822" y="49" text-anchor="middle">hellowin:state</text>
<rect class="d-box g-rr" x="888" y="16" width="104" height="44" rx="6"/>
<text class="d-t" x="940" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="940" y="49" text-anchor="middle">hellowin</text>
<text class="d-s d-halo" x="554" y="356" text-anchor="end">под локом:</text>
<text class="d-m d-halo" x="554" y="370" text-anchor="end">read-modify-write</text>
</svg>
                </div>
                <figcaption>
                    Оранжевая полоса на линии воркера — время, пока он держит лок <code>hellowin:state</code>.
                    Метрики и публикация — вне лока.
                </figcaption>
            </figure>
            <p>Сообщения в канале — JSON-строки. Пример — клик, которым набрали порог (здесь <code>HELLOWIN_CLICKS_PER_STAGE=20</code>) и сменили стадию:</p>
            <pre><code>{"type":"emoji","element":"pumpkin-3","emoji":"🎃","clicks":20,"threshold":20}
{"type":"state","stage":1,"clicks":20,"threshold":20}</code></pre>
        </section>

        <section class="how__section" id="stages" role="tabpanel" aria-labelledby="tab-stages">
            <h2>Стадии и зачем нужен лок</h2>
            <p>
                Счётчик кликов один на всех, а клики обрабатывают несколько воркеров параллельно (в пуле
                <code>centrifuge</code> их два). Увеличение счётчика — это read-modify-write: <code>kv.MGet</code>,
                +1, <code>kv.Set</code>; атомарного инкремента у KV нет. Без лока два воркера прочитают одно и то же
                значение, и один клик пропадёт.
            </p>
            <p>
                Поэтому <code>GameState</code> берёт эксклюзивный лок RoadRunner на ресурс <code>hellowin:state</code>.
                TTL 5 секунд — лок сам истечёт, если воркер упал посреди записи. Ждать занятый лок — до 5 секунд,
                дальше <code>GameBusyException</code> и ответ <code>{ok: false, error: 'busy'}</code>. И KV (driver
                <code>memory</code>), и локи живут в процессе RoadRunner: они общие для всех воркеров, но сбрасываются
                при перезапуске контейнера <code>app</code>.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 442" style="min-width: 720px" role="img" aria-label="Слева без лока: два воркера читают 41 и оба пишут 42, один клик теряется. Справа с локом hellowin:state: второй воркер ждёт, читает уже 42 и пишет 43.">
<defs><marker id="d-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
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
<text class="d-h d-ok" x="540" y="18" text-anchor="start">С локом (GameState::registerClick)</text>
<path class="d-e " d="M82 104 L248 104" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="165" y="98" text-anchor="middle">get clicks</text>
<path class="d-e d-e--ret" d="M248 132 L82 132" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="165" y="126" text-anchor="middle">41</text>
<path class="d-e " d="M418 160 L252 160" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="335" y="154" text-anchor="middle">get clicks</text>
<path class="d-e d-e--ret" d="M252 188 L418 188" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="335" y="182" text-anchor="middle">41</text>
<path class="d-e " d="M82 216 L248 216" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="165" y="210" text-anchor="middle">set 42</text>
<path class="d-e " d="M418 244 L252 244" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="335" y="238" text-anchor="middle">set 42</text>
<rect class="d-note d-note--bad" x="40" y="258" width="420" height="24" rx="4"/>
<text class="d-n" x="250" y="275" text-anchor="middle">итог 42 вместо 43: клик потерян</text>
<rect class="d-box g-php" x="23" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="80" y="46" text-anchor="middle">Воркер 1</text>
<text class="d-s" x="80" y="61" text-anchor="middle">клик</text>
<rect class="d-box g-rr" x="193" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="250" y="46" text-anchor="middle">RR kv</text>
<text class="d-m" x="250" y="61" text-anchor="middle">clicks</text>
<rect class="d-box g-php" x="363" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="420" y="46" text-anchor="middle">Воркер 2</text>
<text class="d-s" x="420" y="61" text-anchor="middle">клик</text>
<path class="d-e " d="M600 96 L624 96 L624 108 L602 108" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="630" y="105" text-anchor="start">lock.Lock → lockId</text>
<path class="d-e " d="M940 124 L916 124 L916 136 L938 136" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="910" y="133" text-anchor="end">lock.Lock: ждёт</text>
<path class="d-e " d="M602 160 L768 160" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="685" y="154" text-anchor="middle">get clicks</text>
<path class="d-e d-e--ret" d="M768 188 L602 188" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="685" y="182" text-anchor="middle">41</text>
<path class="d-e " d="M602 216 L768 216" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="685" y="210" text-anchor="middle">set 42</text>
<path class="d-e " d="M600 236 L624 236 L624 248 L602 248" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="630" y="245" text-anchor="start">lock.Release</text>
<path class="d-e " d="M940 264 L916 264 L916 276 L938 276" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="910" y="273" text-anchor="end">лок получен</text>
<path class="d-e " d="M938 300 L772 300" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="855" y="294" text-anchor="middle">get clicks</text>
<path class="d-e d-e--ret" d="M772 328 L938 328" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="855" y="322" text-anchor="middle">42</text>
<path class="d-e " d="M938 356 L772 356" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="855" y="350" text-anchor="middle">set 43</text>
<path class="d-e " d="M940 376 L916 376 L916 388 L938 388" marker-end="url(#d-ln)"/>
<text class="d-l d-halo" x="910" y="385" text-anchor="end">lock.Release</text>
<rect class="d-note d-note--ok" x="560" y="398" width="420" height="24" rx="4"/>
<text class="d-n" x="770" y="415" text-anchor="middle">итог 43: оба клика учтены</text>
<rect class="d-box g-php" x="543" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="600" y="46" text-anchor="middle">Воркер 1</text>
<text class="d-s" x="600" y="61" text-anchor="middle">клик</text>
<rect class="d-box g-rr" x="713" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="770" y="46" text-anchor="middle">RR kv</text>
<text class="d-m" x="770" y="61" text-anchor="middle">clicks</text>
<rect class="d-box g-php" x="883" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="940" y="46" text-anchor="middle">Воркер 2</text>
<text class="d-s" x="940" y="61" text-anchor="middle">клик</text>
<path class="d-sep" d="M500 8 V442"/>
</svg>
                </div>
                <figcaption>
                    Оранжевое — воркер держит лок, пунктирная рамка — ждёт его.
                </figcaption>
            </figure>
            <p>
                Стадий две, порог один: когда счётчик доходит до <code>HELLOWIN_CLICKS_PER_STAGE</code> кликов (env
                контейнера <code>app</code>), <code>GameState</code> переключает стадию с 0 на 1. Дальше стадия не
                меняется, а клики продолжают считаться. Сейчас порог — {{ $clicksPerStage }}:
            </p>
            <ol class="stages" start="0">
                <li class="stages__item"><b>0</b>с 0 кликов</li>
                <li class="stages__item"><b>1</b>с {{ $clicksPerStage }} кликов</li>
            </ol>
            <p>
                Когда клик переводит игру на стадию 1, <code>ClickResult::$stageChanged</code> равно true и
                <code>ChannelPublisher</code> вслед за <code>emoji</code> шлёт событие <code>state</code>.
                <code>app.js</code> записывает стадию в <code>document.body.dataset.stage</code>, а CSS по селектору
                <code>body[data-stage="1"]</code> перекрашивает небо и луну.
            </p>
        </section>

        <section class="how__section" id="metrics" role="tabpanel" aria-labelledby="tab-metrics">
            <h2>Метрики</h2>
            <p>
                При старте воркера <code>ClickMetrics</code> объявляет коллекторы в плагине <code>metrics</code>, а на
                каждый клик вызывает <code>metrics.Add</code> и <code>metrics.Set</code> по RPC. Ошибка метрик только
                пишется в лог: клик из-за неё не падает. RoadRunner отдаёт всё на <code>:2112/metrics</code> вместе со
                своими <code>rr_http_*</code> (middleware <code>http_metrics</code> в <code>.rr.yaml</code>; там же <code>gzip</code>, который сжимает и ответы PHP, и статику — он стоит перед <code>static</code>, потому что RoadRunner 3 выполняет middleware слева направо).
            </p>
            <p>
                Prometheus раз в 5 секунд скрейпит <code>app:2112</code> и <code>centrifugo:8000/metrics</code>.
                Grafana получает datasource и дашборд «Hellowin» через provisioning и пускает анонимно в роли Viewer:
                смотреть может любой, редактировать — нет.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 280" style="min-width: 720px" role="img" aria-label="Путь метрики: ClickMetrics отправляет metrics.Add и metrics.Set по RPC в плагин metrics RoadRunner; Prometheus каждые 5 секунд забирает /metrics с :2112 и с Centrifugo :8000; Grafana строит дашборд PromQL-запросами к Prometheus, браузер открывает её через nginx /grafana/.">
<defs><marker id="e-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="e-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-box g-php" x="20" y="50" width="160" height="80" rx="6"/>
<text class="d-t" x="100" y="78.5" text-anchor="middle">ClickHandler</text>
<text class="d-m" x="100" y="93.5" text-anchor="middle">→ ClickMetrics</text>
<text class="d-s" x="100" y="108.5" text-anchor="middle">PHP-воркер</text>
<rect class="d-box g-rr" x="280" y="50" width="190" height="80" rx="6"/>
<text class="d-t" x="375" y="78.5" text-anchor="middle">RR metrics</text>
<text class="d-m" x="375" y="93.5" text-anchor="middle">:2112 /metrics</text>
<text class="d-m" x="375" y="108.5" text-anchor="middle">+ rr_http_* (http_metrics)</text>
<rect class="d-box g-obs" x="570" y="50" width="170" height="80" rx="6"/>
<text class="d-t" x="655" y="78.5" text-anchor="middle">Prometheus</text>
<text class="d-m" x="655" y="93.5" text-anchor="middle">:9090</text>
<text class="d-s" x="655" y="108.5" text-anchor="middle">хранит 15d</text>
<rect class="d-box g-obs" x="820" y="50" width="160" height="80" rx="6"/>
<text class="d-t" x="900" y="78.5" text-anchor="middle">Grafana</text>
<text class="d-m" x="900" y="93.5" text-anchor="middle">:3000</text>
<text class="d-s" x="900" y="108.5" text-anchor="middle">дашборд «Hellowin»</text>
<rect class="d-box g-cf" x="280" y="190" width="190" height="60" rx="6"/>
<text class="d-t" x="375" y="216" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="375" y="231" text-anchor="middle">:8000/metrics</text>
<rect class="d-box g-edge" x="820" y="190" width="160" height="60" rx="6"/>
<text class="d-t" x="900" y="216" text-anchor="middle">Браузер</text>
<text class="d-m" x="900" y="231" text-anchor="middle">/grafana/ через nginx</text>
<path class="d-e" d="M180 90 L280 90" marker-end="url(#e-ln)"/>
<text class="d-l" x="230" y="82" text-anchor="middle">metrics.Add</text>
<text class="d-m" x="230" y="106" text-anchor="middle">:6001</text>
<path class="d-e d-e--ok" d="M570 90 L470 90" marker-end="url(#e-ok)"/>
<text class="d-l" x="520" y="82" text-anchor="middle">scrape 5s</text>
<text class="d-m" x="520" y="106" text-anchor="middle">GET /metrics</text>
<path class="d-e d-e--ok" d="M820 90 L740 90" marker-end="url(#e-ok)"/>
<text class="d-l" x="780" y="82" text-anchor="middle">PromQL</text>
<path class="d-e d-e--ok" d="M650 130 L650 220 L470 220" marker-end="url(#e-ok)"/>
<text class="d-l" x="560" y="212" text-anchor="middle">scrape 5s</text>
<path class="d-e" d="M900 190 L900 130" marker-end="url(#e-ln)"/>
<text class="d-l" x="892" y="166" text-anchor="end">anonymous Viewer</text>
<text class="d-s" x="20" y="26" text-anchor="start">pull-модель: Prometheus сам приходит за метриками, приложение никуда их не отправляет</text>
</svg>
                </div>
                <figcaption>Стрелки показывают, кто инициирует запрос; данные по scrape-стрелкам идут обратно.</figcaption>
            </figure>
            <div class="table-scroll">
                <table class="metrics-table">
                    <thead>
                    <tr><th>Метрика</th><th>Откуда</th><th>Что значит</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>hellowin_clicks_total{type, element}</code></td><td>ClickMetrics</td><td>counter: клики по элементам</td></tr>
                    <tr><td><code>hellowin_stage</code></td><td>ClickMetrics</td><td>gauge: текущая стадия</td></tr>
                    <tr><td><code>rr_http_request_total</code>, <code>rr_http_request_duration_seconds</code>, <code>rr_http_workers_*</code></td><td>RoadRunner http</td><td>HTTP-запросы, задержка, воркеры</td></tr>
                    <tr><td><code>centrifugo_node_num_clients</code>, <code>centrifugo_transport_messages_sent</code>, <code>centrifugo_proxy_duration_seconds_histogram</code></td><td>Centrifugo</td><td>подключения, отправленные сообщения, задержка proxy-вызовов в RoadRunner</td></tr>
                    </tbody>
                </table>
            </div>
            <p>Запросы с дашборда, например:</p>
            <pre><code># клики в секунду, всего и по тыквам
sum(rate(hellowin_clicks_total[1m]))
sum by (element) (rate(hellowin_clicks_total[1m]))

# p95 задержки proxy-вызовов Centrifugo → RoadRunner
histogram_quantile(0.95, sum by (le, type) (rate(centrifugo_proxy_duration_seconds_histogram_bucket[1m])))</code></pre>
            <p><a href="/grafana/">Открыть дашборд в Grafana</a> · <a href="/">Вернуться к тыквам</a></p>
        </section>
    </main>
</define:body>
