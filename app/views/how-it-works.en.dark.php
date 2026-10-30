<extends:layout.base title="How it works · Hellowin"/>

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
                <a href="/">← to the pumpkins</a>
                <a href="{{ $grafanaUrl }}">Grafana dashboard →</a>
                <a href="?lang=ru" data-lang-switch hreflang="ru" lang="ru">RU</a>
            </div>
            <h1>How it works</h1>
            <p class="how__lead">
                RoadRunner “died”, and the visitors bring it back to life with a ritual on the pumpkin patch. The ritual runs in scenes. In
                every scene the pumpkins go through several stages: a pumpkin moves on to the next one once it has collected its click threshold
                (it grows with the number of players on the field — <a href="#limits">“Limits”</a>), and stops at the scene's last stage, which is set by
                the list <code>HELLOWIN_SCENE_STAGE_MAX</code>. When every pumpkin has reached it, the scene is ready, and the next one is
                started by the moderator, who is also the owl narrator. In the finale the owl reveals the real numbers of the evening. One pass of
                the ritual is a round; a reset starts the next one. Players walk their characters around the lawn and shoot down flyers. Everyone
                sees everyone else's actions in real time, and the metrics go to a public Grafana. Below is what really happens under
                the hood, with the names of classes, keys, channels and ports taken from the code.
            </p>
            <dl class="how__terms">
                <dt>Spiral Framework</dt>
                <dd>A PHP framework: controllers, a DI container, configs, queues, Stempler templates. Here it holds the business logic.</dd>
                <dt>RoadRunner</dt>
                <dd>An application server written in Go. It keeps pools of long-lived PHP workers and gives them plugins: HTTP, KV,
                    locks, the jobs queue, metrics, a bridge to Centrifugo. PHP talks to the plugins over RPC.</dd>
                <dt>Centrifugo</dt>
                <dd>A server for WebSocket connections and channels (pub/sub). It holds the browser connections, while decisions like
                    “let this one in?” or “what to do with this RPC?” it asks the application through a proxy.</dd>
                <dt>Valkey</dt>
                <dd>A Redis-compatible store. It keeps the state of the ritual and the story, the players, flyers, characters and
                    the transition idempotency keys; AOF on a volume, so the state survives a restart.</dd>
                <dt>Prometheus, Grafana</dt>
                <dd>Prometheus pulls the metrics from the services and stores them; Grafana draws a dashboard from them.</dd>
            </dl>
        </header>

        <nav class="tabs" aria-label="Sections">
            <ul class="tabs__list" role="tablist">
                <li role="presentation"><a role="tab" id="tab-roadrunner" href="#roadrunner" aria-controls="roadrunner" aria-selected="true">RoadRunner</a></li>
                <li role="presentation"><a role="tab" id="tab-modules" href="#modules" aria-controls="modules" aria-selected="false">Modules</a></li>
                <li role="presentation"><a role="tab" id="tab-page-load" href="#page-load" aria-controls="page-load" aria-selected="false">Page load</a></li>
                <li role="presentation"><a role="tab" id="tab-click" href="#click" aria-controls="click" aria-selected="false">Click</a></li>
                <li role="presentation"><a role="tab" id="tab-stages" href="#stages" aria-controls="stages" aria-selected="false">Stages and lock</a></li>
                <li role="presentation"><a role="tab" id="tab-idempotency" href="#idempotency" aria-controls="idempotency" aria-selected="false">Idempotent transition</a></li>
                <li role="presentation"><a role="tab" id="tab-dice" href="#dice" aria-controls="dice" aria-selected="false">Dice</a></li>
                <li role="presentation"><a role="tab" id="tab-live" href="#live" aria-controls="live" aria-selected="false">Moderator, characters, flyers</a></li>
                <li role="presentation"><a role="tab" id="tab-metrics" href="#metrics" aria-controls="metrics" aria-selected="false">Metrics</a></li>
            </ul>
        </nav>

        <section class="how__section" id="roadrunner" role="tabpanel" aria-labelledby="tab-roadrunner">
            <h2>RoadRunner: PHP that does not die</h2>
            <p>
                RoadRunner appeared at the end of 2017, and version 1.0 came out in June 2018. It was built to take PHP
                to the enterprise level and win some of that ground from Java. It all started with a question: what if a
                PHP application did not die after every request? That is how enterprise frameworks work in Java: the
                application boots once and serves requests until it is stopped. PHP in the classic model, by contrast,
                loads the configs, builds the container and opens the connections for every request — and throws it all
                away after the response. If the process lives on, that bootstrap happens once. That is the model
                RoadRunner keeps: a pool of long-lived PHP workers it hands the requests to.
            </p>
            <p>
                The only sensible language for such a server back then was Go. But Go does not get along well with C,
                which PHP is written in, so a protocol of its own was needed between PHP and RoadRunner —
                <a href="https://github.com/roadrunner-server/goridge">Goridge</a>. It costs a tax on serialisation and
                deserialisation. In return, practice and time showed it to be the most stable and safe option, unlike
                embedding PHP into Go through CGO or talking to it over FastCGI
                (<a href="https://rapira.rs/blog/php2shell">a write-up</a>).
            </p>
            <p>
                The new model called for a new framework. The frameworks of the day could not stay alive: they leaked
                memory, and their habits counted on the process dying anyway. That is how
                <a href="https://spiral.dev/">Spiral Framework</a> came about — this demo is written on it too. It needed
                an ORM that does not leak: Doctrine and Eloquent did not fit, and
                <a href="https://cycle-orm.dev/">Cycle ORM</a> appeared.
            </p>
            <p>
                The first versions proved the concept works, and new features started to hang on RoadRunner: the jobs
                queue, websockets, KV, locks, metrics, and later <a href="https://temporal.io/">Temporal</a>. RoadRunner
                gives PHP one API to different storages and message brokers and takes the IO-bound work on itself. This
                demo uses the <code>http</code>, <code>centrifuge</code>, <code>kv</code>, <code>lock</code>,
                <code>jobs</code> and <code>metrics</code> plugins — how exactly, the other tabs tell, starting with
                <a href="#modules">“Modules”</a>.
            </p>
            <p>
                In time Laravel and Symfony also learned to live in the long-running model, and RoadRunner
                integrations appeared for them. The official ones either work suboptimally or take little from
                RoadRunner beyond HTTP. We recommend the community bridges:
            </p>
            <ul>
                <li><a href="https://github.com/roadrunner-php/laravel-bridge">roadrunner-php/laravel-bridge</a> for
                    Laravel — HTTP, gRPC, queues, KV and Temporal;</li>
                <li><a href="https://github.com/Baldinof/roadrunner-bundle">baldinof/roadrunner-bundle</a> for
                    Symfony — HTTP, gRPC and KV as a cache.</li>
            </ul>

            <h3>RoadRunner 3 and the honest downsides</h3>
            <p>
                RoadRunner 3 came out in September 2026. It extends flexibly with plugins and runs at many enterprises
                around the world. It has its problems, though:
            </p>
            <ul>
                <li><strong>It is hard to handle.</strong> Not every application can run long-running, and an everyday
                    PHP developer needs to learn a bit more to write for it.</li>
                <li><strong>Memory.</strong> Every worker is a separate process with no shared opcache, so the memory
                    use is high.</li>
                <li><strong>Marketing.</strong> However technically great a thing we build, telling the whole world
                    about it is a problem of its own for us.</li>
            </ul>
            <p>
                And not everyone needs it: many applications on general-purpose frameworks never see a high load.
            </p>

            <h3>What next: Rapira</h3>
            <p>
                PHP still needs a proper web server able to run any application in any mode: classic, worker or even
                asynchronous. So the idea of RoadRunner went further, and that is how
                <a href="https://rapira.rs/">Rapira</a> came about, its continuation.
            </p>
            <p class="callout">
                RoadRunner is not abandoned: many companies run on it, and its support goes on. But in the long run,
                once Rapira covers everything RoadRunner does, the hope is that everyone moves over to it.
            </p>
        </section>

        <section class="how__section" id="modules" role="tabpanel" aria-labelledby="tab-modules">
            <h2>Who talks to whom</h2>
            <p>
                There is no nginx in front of RoadRunner: its HTTP plugin serves the pages and static files itself on <code>localhost:8080</code>.
                Its middleware run left to right: <code>http_metrics</code>, <code>headers</code> (security
                headers), <code>gzip</code>, <code>static</code> (files from <code>public/</code> with an ETag, so a repeated
                request gets a 304) and <code>rate_limiter</code> (a token bucket per IP, only for requests that make it
                to PHP). RoadRunner is not a reverse proxy, so Centrifugo and Grafana are exposed on their own ports: WebSocket on
                <code>:8000</code>, the dashboard on <code>:3000</code>. The page gets these addresses from the server, built from the
                host it was opened by. Everything else lives in the internal docker compose network.
            </p>
            <p>This is how it reads in <code>.rr.yaml</code>:</p>
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
                The main load is not HTTP but RPC over WebSocket, and the HTTP limiter never sees it. That is why
                <code>RPCService</code> takes a token from the player's bucket before any method: the bucket lives in the KV store
                <code>process</code> (RoadRunner's memory) and is changed under the lock <code>rate:{group}:{player}</code>, because
                KV has no atomic update. Fast clicking gets through, a stream from a script gets, after <code>burst</code>,
                a <code>rate limited</code>; the moderator is exempt from the limit.
            </p>
            <p>
                RoadRunner and Centrifugo are linked by two gRPC channels going in opposite directions. Centrifugo proxies client events
                (connect, rpc) to the <code>centrifuge</code> plugin at <code>app:10001</code>, and the application
                publishes messages through the Centrifugo gRPC API at <code>centrifugo:10000</code>.
            </p>
            <p>
                RoadRunner has three pools of PHP workers, <code>php app.php</code> processes: <code>centrifuge</code> has
                <code>RR_CENTRIFUGE_WORKERS</code> of them, the others two each: <code>http</code> serves
                the pages, <code>centrifuge</code> answers connect and RPC, <code>jobs</code> runs the transition jobs
                from the <code>transitions</code> queue (driver <code>memory</code>). RoadRunner hands requests and jobs
                to a worker through pipes, the process's stdin/stdout. Back to the <code>kv</code>,
                <code>lock</code>, <code>jobs</code>, <code>metrics</code> and <code>centrifuge</code> plugins the worker goes over
                goridge RPC on <code>127.0.0.1:6001</code>.
            </p>
            <p>The pools and the queue are there too:</p>
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
                Flyers, characters and the top shooters are moved by time, not by requests. That is what the
                <code>service</code> plugin is for: RoadRunner keeps one long-lived process <code>php app.php hellowin:ticker</code>
                outside the worker pools and restarts it if it crashes. The ticker reaches the plugins over the same RPC as the
                workers do, and through the Centrifugo gRPC API it also asks for the channel's presence. A second copy (overlap during
                a restart) is kept aside by the lock <code>ticker</code>, a lease that its owner renews once a second.
            </p>
            <p>
                The game state lives in Valkey: the ritual and the story, the players and the top shooters, flyers, characters. It is read and
                written by RoadRunner's KV plugin: the store <code>hellowin</code> with the <code>redis</code> driver on
                <code>VALKEY_ADDR</code>, which PHP sees as the PSR-16 cache <code>hellowin</code>.
                The transition idempotency keys <code>hellowin:amo:*</code> and the stored dice responses
                <code>hellowin:lease:*</code> are written by the workers to the same Valkey directly,
                via predis (<code>VALKEY_DSN</code>): the <code>spiral/idempotency</code> package needs an atomic
                <code>SET NX EX</code>, which the KV plugin lacks. The locks (<code>pumpkin:{id}</code>, <code>scene</code>,
                <code>player:{id}</code>, <code>flyer:{id}</code>, <code>walkers</code> and others) and the queue live in the memory
                of the RoadRunner process, which is enough as long as there is a single <code>app</code>.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1210 750" style="min-width: 871px" role="img" aria-label="Hellowin modules: the browser goes straight to three published ports: :8080 is the RoadRunner HTTP plugin, :8000 is the Centrifugo WebSocket, :3000 is Grafana. Inside RoadRunner there are three plugins with PHP worker pools: http, centrifuge and jobs, and the ticker process under the service plugin. The workers and the ticker call the kv, lock, jobs, metrics and centrifuge plugins over RPC. The game state (kv, redis driver) and the idempotency keys (predis) are kept in Valkey. Prometheus scrapes metrics from RoadRunner and Centrifugo.">
<defs><marker id="a-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="a-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-group g-rr" x="440" y="146" width="530" height="450" rx="10"/>
<text class="d-h" x="456" y="168" text-anchor="start">RoadRunner 3 · container app · .rr.yaml</text>
<rect class="d-box g-edge" x="20" y="200" width="150" height="90" rx="6"/>
<text class="d-t" x="95" y="233.5" text-anchor="middle">Browser</text>
<text class="d-m" x="95" y="248.5" text-anchor="middle">centrifuge-js</text>
<text class="d-s" x="95" y="263.5" text-anchor="middle">WebSocket</text>
<rect class="d-box g-edge" x="250" y="200" width="140" height="90" rx="6"/>
<text class="d-t" x="320" y="233.5" text-anchor="middle">host ports</text>
<text class="d-s" x="320" y="248.5" text-anchor="middle">no proxy</text>
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
<text class="d-t" x="535" y="311" text-anchor="middle">http workers ×2</text>
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
<text class="d-s" x="715" y="574" text-anchor="middle">one process, lock ticker</text>
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
                        <li><span class="legend__swatch" style="--swatch: var(--d-cf)"></span>gRPC between Centrifugo and RoadRunner, centrifuge.Broadcast</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-kv)"></span>to Valkey: the KV plugin and predis</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-obs)"></span>metrics: Prometheus scrapes, Grafana queries</li>
                        <li><span class="legend__swatch" style="--swatch: var(--d-line)"></span>HTTP, WebSocket, pipes, RPC, jobs.Push</li>
                    </ul>
                </figcaption>
            </figure>
        </section>

        <section class="how__section" id="page-load" role="tabpanel" aria-labelledby="tab-page-load">
            <h2>Page load and connection</h2>
            <p>
                <code>GET /</code> is plain HTTP. <code>HomeController::index()</code> takes a state snapshot from
                <code>SnapshotFactory</code>: <code>RitualStore::snapshot()</code> reads in a single <code>kv.MGet</code>
                <code>hellowin:round</code>, <code>hellowin:scene</code>, the story <code>hellowin:story</code> and
                <code>hellowin:pumpkin:{id}</code> of every pumpkin, and one more request fetches the characters <code>hellowin:walkers</code>. All of it
                without locks: the page may lag a click behind, and that is fine. The layout from
                <code>app/config/hellowin.php</code> and the policy numbers are added, and the template gets the snapshot (Snapshot):
            </p>
            <ul>
                <li><code>round</code>, <code>scene</code> — the current round and scene;</li>
                <li><code>sceneStages</code> — the number of the final scene (the length of <code>HELLOWIN_SCENE_STAGE_MAX</code>), <code>stageMax</code> — a pumpkin's last stage in the current scene;</li>
                <li><code>threshold</code> — a pumpkin's threshold, the <code>pumpkin</code> limit (<a href="#limits">“Limits”</a>); <code>limits</code> — the current scene's limits with the number of players <code>players</code> and the multiplier <code>k</code>;</li>
                <li><code>elements</code> — the pumpkins: <code>id</code>, <code>type</code>, <code>x</code>, <code>y</code>, <code>size</code>, <code>stage</code> and <code>clicks</code> since the move to the current stage; <code>bushes</code> — the bushes the flyers take off from;</li>
                <li><code>walkers</code> — the players' characters on the field with their paths;</li>
                <li>the story: <code>ready</code> — the scene is ready and waits for the moderator, <code>finale</code> — the finale step (from the reveal step on, also <code>reveal</code>), <code>paused</code> — progress is paused;</li>
                <li><code>now</code> — server time in ms: the client uses it to compute its clock offset for the flyers and characters.</li>
            </ul>
            <p>
                Then the browser opens a WebSocket and passes the player's secret from <code>localStorage</code> in the connection's
                <code>data</code>: <code>{playerSecret}</code>. Centrifugo itself knows nothing about users and
                asks the application on connect (connect proxy). <code>ConnectService</code> answers with
                <code>user</code>, the player's public id derived from the secret (no secret means anonymous), a list
                <code>channels: ['{{ $channel }}']</code>, which is a server-side subscription, and the connection's <code>meta</code>
                (the moderator role, see <a href="#live">“Moderator, characters, flyers”</a>). From then on Centrifugo puts
                <code>user</code> into every RPC itself: the client does not send its id and cannot pose as someone else. The client never
                calls <code>subscribe</code>: client-side subscriptions and publications are disabled in <code>config.json</code>,
                and channel messages arrive in <code>centrifuge.on('publication')</code>.
            </p>
            <p>
                The RPC <code>state</code> returns the same snapshot. Every channel event carries <code>round</code>: if it does not
                match the client's round, the client has missed a reset and requests the snapshot again; the same happens after
                a reconnect, when the events sent during the outage are lost.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 974" style="min-width: 720px" role="img" aria-label="Page load: GET / passes the RoadRunner HTTP plugin middleware into the worker pool and HomeController; SnapshotFactory reads the round, scene, story, pumpkins and characters from KV without a lock and hands the snapshot to the template. Then the browser opens a WebSocket straight to Centrifugo on :8000 with the player secret in data, Centrifugo asks ConnectService through the connect proxy, gets user, meta and the channel and subscribes the client to the hellowin channel on the server side. On a reconnect or an event with a different round the client calls the RPC state and gets the same snapshot.">
<defs><marker id="b-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="b-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-life" d="M62 60 V974"/>
<path class="d-life" d="M180 60 V974"/>
<path class="d-life" d="M298 60 V974"/>
<path class="d-life" d="M422 60 V974"/>
<path class="d-life" d="M546 60 V974"/>
<path class="d-life" d="M670 60 V974"/>
<path class="d-life" d="M790 60 V974"/>
<path class="d-life" d="M925 60 V974"/>
<text class="d-h d-phase" x="14" y="88" text-anchor="start">1. HTML with a snapshot</text>
<path class="d-e " d="M64 116 L178 116" marker-end="url(#b-ln)"/>
<text class="d-l" x="121" y="110" text-anchor="middle">GET /</text>
<path class="d-e " d="M182 146 L296 146" marker-end="url(#b-ln)"/>
<text class="d-l" x="239" y="140" text-anchor="middle">past static</text>
<path class="d-e " d="M300 176 L420 176" marker-end="url(#b-ln)"/>
<text class="d-l" x="360" y="170" text-anchor="middle">pipes</text>
<path class="d-e " d="M424 206 L544 206" marker-end="url(#b-ln)"/>
<text class="d-l" x="484" y="200" text-anchor="middle">kv.MGet, no lock</text>
<path class="d-e d-e--ret" d="M544 236 L424 236" marker-end="url(#b-ln)"/>
<text class="d-l" x="484" y="230" text-anchor="middle">game state</text>
<path class="d-e " d="M422 258 L446 258 L446 270 L424 270" marker-end="url(#b-ln)"/>
<text class="d-l" x="452" y="267" text-anchor="start">Snapshot: ritual, story, characters, layout, now</text>
<path class="d-e " d="M422 288 L446 288 L446 300 L424 300" marker-end="url(#b-ln)"/>
<text class="d-l" x="452" y="297" text-anchor="start">render('home', snapshot + centrifugoUrl from the host)</text>
<path class="d-e d-e--ret" d="M420 326 L64 326" marker-end="url(#b-ln)"/>
<text class="d-l" x="242" y="320" text-anchor="middle">200 text/html (back through gzip and headers)</text>
<path class="d-e " d="M62 348 L86 348 L86 360 L64 360" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="357" text-anchor="start">scene from the snapshot · new Centrifuge(url, {data: {playerSecret}})</text>
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
<text class="d-l" x="730" y="554" text-anchor="middle">response</text>
<path class="d-e " d="M670 582 L694 582 L694 594 L672 594" marker-end="url(#b-ln)"/>
<text class="d-l" x="700" y="591" text-anchor="start">subscribe to hellowin (server-side)</text>
<path class="d-e d-e--ret d-e--acc" d="M668 620 L64 620" marker-end="url(#b-acc)"/>
<text class="d-l" x="366" y="614" text-anchor="middle">connected + subs: hellowin</text>
<text class="d-h d-phase" x="14" y="646" text-anchor="start">3. Resync: RPC state</text>
<path class="d-e " d="M62 666 L86 666 L86 678 L64 678" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="675" text-anchor="start">reconnected, or an event came with another round</text>
<path class="d-e " d="M64 704 L668 704" marker-end="url(#b-ln)"/>
<text class="d-l" x="366" y="698" text-anchor="middle">rpc('state', {}) over the same WebSocket</text>
<path class="d-e d-e--acc" d="M672 734 L788 734" marker-end="url(#b-acc)"/>
<text class="d-l" x="730" y="728" text-anchor="middle">RPC proxy (gRPC)</text>
<path class="d-e " d="M792 764 L923 764" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="758" text-anchor="middle">pipes</text>
<path class="d-e " d="M923 794 L548 794" marker-end="url(#b-ln)"/>
<text class="d-l" x="735.5" y="788" text-anchor="middle">SnapshotFactory::create() → kv.MGet</text>
<path class="d-e d-e--ret" d="M548 824 L923 824" marker-end="url(#b-ln)"/>
<text class="d-l" x="735.5" y="818" text-anchor="middle">ritual, story, characters</text>
<path class="d-e d-e--ret" d="M923 854 L792 854" marker-end="url(#b-ln)"/>
<text class="d-l" x="857.5" y="848" text-anchor="middle">{ok: true, state}</text>
<path class="d-e d-e--ret" d="M788 884 L672 884" marker-end="url(#b-ln)"/>
<text class="d-l" x="730" y="878" text-anchor="middle">RPC response</text>
<path class="d-e d-e--ret" d="M668 914 L64 914" marker-end="url(#b-ln)"/>
<text class="d-l" x="366" y="908" text-anchor="middle">{ok: true, state: Snapshot}</text>
<path class="d-e " d="M62 936 L86 936 L86 948 L64 948" marker-end="url(#b-ln)"/>
<text class="d-l" x="92" y="945" text-anchor="start">rebuild the scene from the snapshot</text>
<rect class="d-box g-edge" x="5" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="62" y="34" text-anchor="middle">Browser</text>
<text class="d-m" x="62" y="49" text-anchor="middle">centrifuge-js</text>
<rect class="d-box g-edge" x="123" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="180" y="34" text-anchor="middle">RR middleware</text>
<text class="d-m" x="180" y="49" text-anchor="middle">:8080</text>
<rect class="d-box g-rr" x="241" y="16" width="114" height="44" rx="6"/>
<text class="d-t" x="298" y="34" text-anchor="middle">RR http</text>
<text class="d-m" x="298" y="49" text-anchor="middle">worker pool</text>
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
                <figcaption>Solid arrows are requests, dashed ones are responses. Purple ones take the path through Centrifugo.</figcaption>
            </figure>
        </section>

        <section class="how__section" id="click" role="tabpanel" aria-labelledby="tab-click">
            <h2>What happens on a click</h2>
            <p>
                A click is not an HTTP request but an RPC over the same WebSocket:
                <code>centrifuge.rpc('click', {element: 'pumpkin-3'})</code>. Centrifugo proxies it to
                RoadRunner, which hands the call to a free worker from the <code>centrifuge</code> pool, where
                <code>RPCService</code> passes it on to <code>ClickHandler::click()</code>.
            </p>
            <p>The answer goes back to Centrifugo through <code>$request-&gt;respond()</code>, and the call metric is written in <code>finally</code>, even if the handler threw:</p>
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
                <li>The element is checked against <code>ElementRegistry</code> (ids from <code>app/config/hellowin.php</code>).</li>
                <li><code>RitualMetrics</code> adds 1 to <code>hellowin_clicks_total</code>.</li>
                <li><code>RitualStore::click()</code>, under the lock <code>pumpkin:{id}</code>, increments the pumpkin's <code>clicks</code> in KV (and <code>accepted</code>, the round's accepted clicks, for the reveal in the finale) and right away, under the same lock, reads the round and the scene. The lock covers only this pumpkin: clicks on different pumpkins do not wait for each other.</li>
                <li><code>PlayerService</code>, under the lock <code>player:{id}</code>, adds the click to the stats of the player, the connection's <code>user</code>; an anonymous click skips the stats. A stats failure is logged and does not break the click.</li>
                <li><code>ChannelPublisher</code> publishes a <code>click</code> event to the channel <code>{{ $channel }}</code> (with <code>player</code> unless the click is anonymous), to all subscribers at once.</li>
                <li><code>LockstepPolicy::pumpkinTransition()</code> decides whether the pumpkin should move on: <code>clicks</code> is at least the pumpkin's threshold (the <code>pumpkin</code> limit) and the stage is below the current scene's <code>stageMax</code>. If so, <code>TransitionQueue</code> queues a <code>TransitionPumpkin</code> job, and <code>hellowin_transition_jobs_pushed_total{kind="pumpkin"}</code> grows by 1.</li>
                <li>The RPC response is always data: <code>{ok: true}</code> or <code>{ok: false, error: 'unknown element' | 'busy' | 'internal error'}</code>; <code>busy</code> means the pumpkin's lock could not be taken within 5 seconds.</li>
            </ol>
            <p class="callout">
                The click itself does not change the stage, it only queues a job. Until the job is done, <code>clicks</code>
                stays above the threshold, and <strong>every</strong> next click queues one more identical job. The duplicates
                are intended: idempotency cuts them off, and the clicks above the threshold are burnt. Details are on the tab
                <a href="#idempotency">“Idempotent transition”</a>.
            </p>
            <p class="callout">
                The browser does not draw its own click locally. The effect appears only when a publication comes from the channel,
                for the one who clicked just like for everyone else. That is why the screen shows the honest round trip delay
                browser → server → browser.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1100 874" style="min-width: 792px" role="img" aria-label="Click: the browser calls the RPC click through Centrifugo, RoadRunner hands it to RPCService and ClickHandler. ClickHandler writes a metric, under the lock pumpkin:{id} increments the pumpkin's clicks in KV and reads the round and the scene, releases the lock and broadcasts a click event to every client of the channel, including the one who clicked. Then LockstepPolicy decides whether the pumpkin is due for its next stage; if so, a TransitionPumpkin job goes to the jobs queue.">
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
<text class="d-l" x="210" y="93" text-anchor="start">click: draw nothing locally</text>
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
<text class="d-l" x="179" y="596" text-anchor="middle">the same publication</text>
<path class="d-e " d="M60 624 L84 624 L84 636 L62 636" marker-end="url(#c-ln)"/>
<text class="d-l" x="90" y="633" text-anchor="start">click effect</text>
<path class="d-e " d="M180 624 L204 624 L204 636 L182 636" marker-end="url(#c-ln)"/>
<text class="d-l" x="210" y="633" text-anchor="start">click effect + progress</text>
<path class="d-e " d="M550 654 L574 654 L574 666 L552 666" marker-end="url(#c-ln)"/>
<text class="d-l" x="580" y="663" text-anchor="start">LockstepPolicy::pumpkinTransition(): clicks ≥ threshold and stage &lt; stageMax?</text>
<path class="d-e " d="M552 692 L1032 692" marker-end="url(#c-ln)"/>
<text class="d-l" x="792" y="686" text-anchor="middle">yes: jobs.Push TransitionPumpkin {key, round, pumpkin, toStage, player}</text>
<path class="d-e " d="M552 722 L678 722" marker-end="url(#c-ln)"/>
<text class="d-l" x="615" y="716" text-anchor="middle">jobs_pushed +1</text>
<path class="d-e d-e--ret" d="M548 752 L418 752" marker-end="url(#c-ln)"/>
<text class="d-l" x="483" y="746" text-anchor="middle">RPCResponse {ok: true}</text>
<path class="d-e d-e--ret" d="M414 782 L300 782" marker-end="url(#c-ln)"/>
<text class="d-l" x="357" y="776" text-anchor="middle">RPC response</text>
<path class="d-e d-e--ret" d="M296 812 L182 812" marker-end="url(#c-ln)"/>
<text class="d-l" x="239" y="806" text-anchor="middle">{ok: true}</text>
<rect class="d-note " x="248" y="828" width="836" height="24" rx="4"/>
<text class="d-n" x="666" y="845" text-anchor="middle">every further click above the threshold is one more TransitionPumpkin with the same key until the transition is done</text>
<rect class="d-box g-edge" x="4" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="60" y="34" text-anchor="middle">Other tabs</text>
<text class="d-s" x="60" y="49" text-anchor="middle">channel hellowin</text>
<rect class="d-box g-edge" x="124" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="180" y="34" text-anchor="middle">Browser (click)</text>
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
<text class="d-s" x="538" y="371" text-anchor="end">under the pumpkin lock:</text>
<text class="d-m" x="538" y="385" text-anchor="end">read-modify-write</text>
</svg>
                </div>
                <figcaption>
                    The orange bar on the worker's line is the time it holds the lock <code>pumpkin:{id}</code>.
                    Metrics, publishing and queuing the job happen outside the lock.
                </figcaption>
            </figure>
            <p>The click event is built by <code>ChannelPublisher::click()</code>:</p>
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
            <p>It goes to the channel as a JSON string:</p>
            <pre><code>{"type":"click","round":…,"element":"pumpkin-3","emoji":"🕸️","clicks":…,"threshold":…,"stage":…}</code></pre>
        </section>

        <section class="how__section" id="stages" role="tabpanel" aria-labelledby="tab-stages">
            <h2>Scenes, pumpkin stages and locks</h2>
            <p>
                The engine knows only numbers: <code>round</code>, <code>scene</code> and, for each pumpkin,
                <code>{stage, clicks}</code>. What it looks like is up to the frontend. When things move is up to the policy —
                the <code>RitualPolicy</code> interface, implemented by <code>LockstepPolicy</code>: one spot in the code that can be
                replaced without touching the engine.
            </p>
            <ul>
                <li>Scenes are numbered from 0 to the final N — the length of the <code>HELLOWIN_SCENE_STAGE_MAX</code> list.</li>
                <li>Scene s has a stage cap <code>stageMax(s)</code> — element s of the same list, cumulative; the finale has the same cap as the scene before it.</li>
                <li>If the list starts with 0, scene 0 is the <strong>bar</strong>: there are no pumpkins (clicking them returns <code>locked</code>, <code>RitualPolicy::bar()</code>); the scene is ready when the crowd has drunk the bar target or the moderator gives the go-ahead, and the moderator leads everyone out to the pumpkin patch. Details in <a href="#bar">“The bar”</a>.</li>
                <li>A pumpkin moves to <code>stage + 1</code> once it collects the click threshold — the <code>pumpkin</code> limit (<a href="#limits">“Limits”</a>) — while <code>stage &lt; stageMax(scene)</code>; the transition resets <code>clicks</code> to zero.</li>
                <li>When <strong>all</strong> pumpkins reach <code>stageMax</code>, the scene is <strong>ready</strong> but does not switch by itself: a <code>ready</code> event, the owl says “all set”, and the scene waits for the moderator. The “Next scene” button on the control panel (RPC <code>scene.next</code>) starts the next one; if the crowd is stuck, the moderator can switch an unready scene too (<code>force</code>). Details in <a href="#scene-transition">“Scene transition”</a>.</li>
                <li>In the final scene pumpkins do not transition; clicks are only broadcast. The moderator walks through the finale steps, and after the reveal the endless “cursed world” begins.</li>
            </ul>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 330" style="min-width: 720px" role="img" aria-label="Scenes and stages: scenes from 0 to the final N run horizontally, the pumpkin stage vertically. In each scene the pumpkins climb the stages up to that scene's stageMax and wait there; once all have arrived, the scene is ready, and at the moderator's button it changes and the cap rises to the next scene's stageMax. The final scene has no transitions.">
<defs><marker id="d-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="d-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-axis" d="M170 50 V260 H980"/>
<path class="d-cap" d="M170 190 H380 V140 H590 V80 H980"/>
<text class="d-m" x="160" y="264" text-anchor="end">0</text>
<text class="d-m" x="160" y="194" text-anchor="end">stageMax(0)</text>
<text class="d-m" x="160" y="144" text-anchor="end">stageMax(1)</text>
<text class="d-m" x="160" y="84" text-anchor="end">stageMax(N−1)</text>
<text class="d-s" x="20" y="30" text-anchor="start">pumpkin stage</text>
<text class="d-h" x="275" y="30" text-anchor="middle">scene 0</text>
<text class="d-h" x="485" y="30" text-anchor="middle">scene 1</text>
<text class="d-h" x="690" y="30" text-anchor="middle">…</text>
<text class="d-h" x="885" y="30" text-anchor="middle">scene N — finale</text>
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
<text class="d-s" x="885" y="120" text-anchor="middle">no transitions,</text>
<text class="d-s" x="885" y="135" text-anchor="middle">clicks only broadcast</text>
<text class="d-s" x="690" y="175" text-anchor="middle">pumpkins go to stageMax(s)</text>
<text class="d-s" x="690" y="190" text-anchor="middle">and wait for others</text>
<text class="d-s" x="275" y="282" text-anchor="middle">stage +1 = click threshold (pumpkin limit)</text>
<text class="d-m" x="275" y="297" text-anchor="middle">→ TransitionPumpkin</text>
<text class="d-s" x="485" y="282" text-anchor="middle">all at stageMax(0) → ready, wait</text>
<text class="d-m" x="485" y="297" text-anchor="middle">scene.next → TransitionScene</text>
<text class="d-s" x="790" y="282" text-anchor="middle">ready in scene N−1</text>
<text class="d-m" x="790" y="297" text-anchor="middle">scene.next → finale</text>
<text class="d-s" x="575" y="318" text-anchor="middle">N = length of HELLOWIN_SCENE_STAGE_MAX, stageMax(s) = its element s; finale cap = stageMax(N−1)</text>
</svg>
                </div>
                <figcaption>The orange step is the stage cap <code>stageMax(s)</code>; circles are pumpkins.</figcaption>
            </figure>
            <p>One player on the field, multiplier 1: the threshold is {{ $clicksPerStage }} clicks per stage, and this is how a pumpkin goes through a scene:</p>
            <ol class="stages" start="0">
                <li class="stages__item"><b>0</b>round start</li>
                <li class="stages__item"><b>1</b>after {{ $clicksPerStage }} clicks</li>
                <li class="stages__item"><b>2</b>{{ $clicksPerStage }} more</li>
                <li class="stages__item"><b>…</b>{{ $clicksPerStage }} each</li>
                <li class="stages__item"><b>stageMax</b>waits for the rest</li>
            </ol>

            <h3 id="limits">Limits: base, players, multiplier</h3>
            <p>
                A pumpkin threshold is not one number for every case: one player clicks through it in a minute, forty in a second.
                So every number in the ritual — clicks per stage of a pumpkin, the bonfire and the circle, the bar target, both cursed-world targets,
                FrankenPHP's health — is computed by one formula from the number of players on the field:
            </p>
            <p><code>limit = max(1, ⌈k × (BASE + PER_PLAYER × (n − 1))⌉) + bonus</code></p>
            <ul>
                <li><strong>BASE</strong> and <strong>PER_PLAYER</strong> are the element's env vars <code>HELLOWIN_&lt;X&gt;_BASE</code> and
                    <code>HELLOWIN_&lt;X&gt;_PER_PLAYER</code>, X being <code>PUMPKIN</code>, <code>BONFIRE</code>,
                    <code>CIRCLE</code>, <code>BAR</code>, <code>CURSED_DRINKS</code>, <code>CURSED_ZOMBIES</code>,
                    <code>BOSS_HP</code>: how much one player needs and how much each additional player adds. The formula leaves
                    the number of stages alone — only clicks per stage.</li>
                <li><strong>n</strong> — characters on the field, at least one. <code>LimitsService</code> counts them with a ticker once a
                    second and accepts a new n once it has held for <code>LimitsService::DEBOUNCE</code>: someone joins and leaves —
                    the limits don't twitch.</li>
                <li><strong>k</strong> — the moderator's multiplier (RPC <code>thresholds</code>), one for all limits: fits the
                    ritual to the crowd on the fly.</li>
                <li><strong>Bonus</strong> — manual, from the control panel: the bar target, the cursed-world targets, the boss's health. It lasts until
                    the end of the round; click thresholds have none.</li>
            </ul>
            <p>
                When any input changes, <code>LimitsService::settle</code> compares the current scene's limits with the last
                announced ones and, if they differ, publishes a <code>limits {players, k, burst, limits}</code> event:
                browsers update their thresholds, the control panel shows the resulting numbers. A limit that drops below the accumulated count
                takes no progress away: the server itself queues the stage transition (the same idempotent <code>TransitionPumpkin</code>
                as after a click) or marks the bar and the cursed world ready, without waiting for a click. FrankenPHP keeps its damage:
                <code>hp = max(1, max − damage)</code>.
            </p>
            <p>
                The same player count grows the flyer wave the moderator launches with the “Launch flyers” button (RPC
                <code>flyers.burst</code>): <code>⌈HELLOWIN_FLYER_BURST_BASE + HELLOWIN_FLYER_BURST_PER_PLAYER ×
                (n − 1)⌉</code> flyers of the current set at once. No cooldown, no cap: as many presses, as many waves.
            </p>

            <h3>Why lock a pumpkin</h3>
            <p>
                Clicks are handled by several workers in parallel (the <code>centrifuge</code> pool has <code>RR_CENTRIFUGE_WORKERS</code>), and many people
                click the same pumpkin. Incrementing the counter is read-modify-write: read <code>{stage, clicks}</code>,
                +1, write; the KV plugin has no atomic increment. Without a lock two workers read the same
                value, and one click is lost.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 442" style="min-width: 720px" role="img" aria-label="Left, without a lock: two workers read clicks = 2 and both write 3, one click is lost. Right, with the pumpkin:{id} lock: the second worker waits, reads 3 already and writes 4.">
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
<text class="d-h d-bad" x="20" y="18" text-anchor="start">No lock: a race</text>
<text class="d-h d-ok" x="540" y="18" text-anchor="start">With lock pumpkin:{id} (RitualStore::click)</text>
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
<text class="d-n" x="250" y="275" text-anchor="middle">result 3, not 4: a click is lost</text>
<rect class="d-box g-php" x="23" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="80" y="46" text-anchor="middle">Worker 1</text>
<text class="d-s" x="80" y="61" text-anchor="middle">pumpkin-3</text>
<rect class="d-box g-rr" x="193" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="250" y="46" text-anchor="middle">RR kv</text>
<text class="d-m" x="250" y="61" text-anchor="middle">clicks</text>
<rect class="d-box g-php" x="363" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="420" y="46" text-anchor="middle">Worker 2</text>
<text class="d-s" x="420" y="61" text-anchor="middle">pumpkin-3</text>
<path class="d-e " d="M600 96 L624 96 L624 108 L602 108" marker-end="url(#e-ln)"/>
<text class="d-l" x="630" y="105" text-anchor="start">lock.Lock → lockId</text>
<path class="d-e " d="M940 124 L916 124 L916 136 L938 136" marker-end="url(#e-ln)"/>
<text class="d-l" x="910" y="133" text-anchor="end">lock.Lock: waits</text>
<path class="d-e " d="M602 160 L768 160" marker-end="url(#e-ln)"/>
<text class="d-l" x="685" y="154" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M768 188 L602 188" marker-end="url(#e-ln)"/>
<text class="d-l" x="685" y="182" text-anchor="middle">2</text>
<path class="d-e " d="M602 216 L768 216" marker-end="url(#e-ln)"/>
<text class="d-l" x="685" y="210" text-anchor="middle">set 3</text>
<path class="d-e " d="M600 236 L624 236 L624 248 L602 248" marker-end="url(#e-ln)"/>
<text class="d-l" x="630" y="245" text-anchor="start">lock.Release</text>
<path class="d-e " d="M940 264 L916 264 L916 276 L938 276" marker-end="url(#e-ln)"/>
<text class="d-l" x="910" y="273" text-anchor="end">lock acquired</text>
<path class="d-e " d="M938 300 L772 300" marker-end="url(#e-ln)"/>
<text class="d-l" x="855" y="294" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M772 328 L938 328" marker-end="url(#e-ln)"/>
<text class="d-l" x="855" y="322" text-anchor="middle">3</text>
<path class="d-e " d="M938 356 L772 356" marker-end="url(#e-ln)"/>
<text class="d-l" x="855" y="350" text-anchor="middle">set 4</text>
<path class="d-e " d="M940 376 L916 376 L916 388 L938 388" marker-end="url(#e-ln)"/>
<text class="d-l" x="910" y="385" text-anchor="end">lock.Release</text>
<rect class="d-note d-note--ok" x="560" y="398" width="420" height="24" rx="4"/>
<text class="d-n" x="770" y="415" text-anchor="middle">result 4: both clicks counted</text>
<rect class="d-box g-php" x="543" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="600" y="46" text-anchor="middle">Worker 1</text>
<text class="d-s" x="600" y="61" text-anchor="middle">pumpkin-3</text>
<rect class="d-box g-rr" x="713" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="770" y="46" text-anchor="middle">RR kv</text>
<text class="d-m" x="770" y="61" text-anchor="middle">clicks</text>
<rect class="d-box g-php" x="883" y="28" width="114" height="44" rx="6"/>
<text class="d-t" x="940" y="46" text-anchor="middle">Worker 2</text>
<text class="d-s" x="940" y="61" text-anchor="middle">pumpkin-3</text>
<path class="d-sep" d="M500 8 V442"/>
</svg>
                </div>
                <figcaption>Orange: the worker holds the lock; dashed frame: it waits for it.</figcaption>
            </figure>
            <p>
                Locks are RoadRunner's <code>lock</code> plugin; each operation has its own set. TTL is 5 seconds — the lock expires
                by itself if a worker dies; waiting for a held one — up to 5 seconds, then <code>RitualBusyException</code>.
            </p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Operation</th><th>Locks</th><th>What is checked under the lock</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>click, <code>RitualStore::click()</code></td><td><code>pumpkin:{id}</code></td><td>nothing: <code>clicks + 1</code>, then reading <code>round</code> and <code>scene</code></td></tr>
                    <tr><td>pumpkin transition, <code>advancePumpkin()</code></td><td><code>pumpkin:{id}</code></td><td><code>round</code> is current and <code>stage == toStage − 1</code></td></tr>
                    <tr><td>scene readiness, <code>markReady()</code></td><td><code>scene</code></td><td><code>round</code> and <code>scene</code> are current, this scene's readiness not marked yet</td></tr>
                    <tr><td>scene transition, <code>advanceScene()</code></td><td><code>scene</code></td><td><code>round</code> is current and <code>scene == toScene − 1</code></td></tr>
                    <tr><td>control panel: finale step, progress pause, <code>updateStory()</code></td><td><code>scene</code></td><td>the story before and after: an event only if it changed</td></tr>
                    <tr><td>reset, <code>reset()</code></td><td><code>scene</code> and all <code>pumpkin:{id}</code></td><td><code>round == fromRound</code></td></tr>
                    <tr><td>player stats, <code>PlayerStore</code></td><td><code>player:{id}</code></td><td>nothing: player counters + 1</td></tr>
                    <tr><td>shot, <code>FlyerStore::shoot()</code></td><td><code>flyer:{id}</code></td><td>the flyer key still exists and the flight isn't over — then delete it</td></tr>
                    <tr><td>character moves and joins, <code>WalkerStore::update()</code></td><td><code>walkers</code></td><td>nothing: read-modify-write of one key holding all characters</td></tr>
                    <tr><td>bar mugs: pouring, “take”, drunk this round, <code>BarStore</code></td><td><code>bar</code> (on “take” — inside <code>walkers</code>)</td><td>the scene is the bar; a mug is still on the counter — the first to arrive gets it</td></tr>
                    <tr><td>cursed-world target: mugs, zombies, bonuses, <code>CursedStore</code></td><td><code>cursed</code></td><td>nothing: round counters + 1; both targets met — readiness mark under <code>scene</code></td></tr>
                    <tr><td>rapier strike at FrankenPHP, <code>BossStore</code></td><td><code>boss</code></td><td>the boss is awake and still alive — then health − 1</td></tr>
                    </tbody>
                </table>
            </div>

            <p>A click takes one lock, its pumpkin's, and reads, changes and writes the pumpkin only inside it:</p>
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
            <p><code>locked()</code> takes the plugin's locks in order and releases them in reverse order in <code>finally</code>:</p>
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

            <h3 id="bar">The bar: the last mug and pouring in batches</h3>
            <p>
                The evening starts in the bar (scene 0): no pumpkins there, but a counter where the owl bartender pours beer.
                The bar shows two more mechanics — the same lock and KV, but on mugs.
            </p>
            <ul>
                <li><strong>The last mug goes to whoever comes first.</strong> A click on the counter (RPC <code>bar.take</code>) only
                    sends the character there and writes the arrival time into its record. When that moment comes, the ticker, under the
                    <code>bar</code> lock, checks in one step whether a mug is in <code>hellowin:bar</code> and takes it off —
                    like “is the flyer alive” for a shot. Two arrive almost at once — the lock lines them up: the first
                    leaves with a beer (<code>take</code> with <code>ok: true</code>), the second finds an empty counter
                    (<code>ok: false</code>, the owl: “all gone”). There is no queue — the latecomer clicks again.</li>
                <li><strong>Pouring in batches.</strong> While the counter has free places (one per character on the field,
                    up to <code>HELLOWIN_BAR_MUGS</code>), the owl pours the next batch for <code>HELLOWIN_BAR_POUR_MS</code> ms:
                    a mug for every <code>HELLOWIN_BAR_POUR_PER</code> free places, at least one — a big crowd doesn't wait
                    for beer one mug at a time. The batch is decided when the pour starts: KV holds
                    <code>pouring {since, until, batch}</code> by server time, a RoadRunner ticker every 200 ms sets down the
                    poured mugs (<code>mugs</code>, no more than the places) and starts the next batch (<code>pour</code>). Everyone sees the same foam, and restarting the workers or the ticker itself
                    loses nothing: the state lives in Valkey, not in process memory.</li>
                <li>You can drink only from a mug that has clinked (<code>drink</code>, otherwise <code>not clinked</code>); drinks
                    are counted per round, and once the crowd has drunk the bar target (the moderator adjusts it), the bar is ready and the owl calls everyone
                    to the pumpkin patch. Leaving the bar is a plain <code>scene.next</code>: the mugs vanish, the characters move to the
                    lawn in one <code>scene</code> event, without walking.</li>
            </ul>

            <h3 id="rapier">The rapier: the magic word, curing, one boss for the whole crowd</h3>
            <p>The end of the night shows three more mechanics — again a lock, KV and a server that decides on its own.</p>
            <ul>
                <li><strong>Only the server checks the word.</strong> The client sends what the player typed (RPC
                    <code>word</code>) and learns only the verdict. The server normalizes the input — lowercase, Cyrillic to
                    Latin, only <code>a-z</code> — and compares it with the answer, forgiving typos (prefix,
                    Levenshtein distance, <code>metaphone</code>). Neither the answer nor the rules are in the browser. Wrong —
                    the character turns into a zombie, and the server's ticker walks it around.</li>
                <li><strong>Cure first.</strong> A click on a zombie player (RPC <code>cure</code>) under the
                    <code>walkers</code> lock lifts the spell — the same “check and change in one step” as a
                    shot at a flyer: the second clicker sees the zombie is already gone.</li>
                <li><strong>One boss for the whole crowd.</strong> All rapier strikes (RPC <code>strike</code>) go to one
                    record <code>hellowin:boss</code> under one <code>boss</code> lock, like clicks on the bonfire: the crowd
                    competes for one resource, and exactly one strike takes the last point of health. That strike queues
                    the transition to dawn with the idempotency key <code>{round}:finale:7</code>.</li>
            </ul>

            <h3>Round and reset</h3>
            <p>
                A reset starts the ritual over: scene 0 (the bar), every pumpkin at <code>stage 0</code> and <code>clicks 0</code>,
                <code>round + 1</code>, the story from the start (the progress pause is lifted, the multiplier <code>k</code> stays) and a
                <code>reset</code> event to everyone. This is done by
                <code>ResetService::reset(?int $fromRound)</code>, called by the “Restart” button on the moderator's control panel
                (RPC <code>reset {round}</code> — the round the moderator sees) and by the CLI command
                <code>hellowin:reset [--round=N]</code>. The service takes the
                <code>scene</code> lock and the locks of all pumpkins, so neither a click nor a transition sees a half-reset ritual, and
                under them compares the current round with the one being finished. A second reset of the same round sees
                the new round already and does nothing — idempotency by <code>reset:{round}</code> without a separate
                key: the state is already under these locks, and a key in Valkey would be a second source of truth about the round.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 484" style="min-width: 720px" role="img" aria-label="Reset: two calls to ResetService::reset(1) almost at once. The first takes the scene lock and all pumpkin:{id} locks, sees round = 1, resets the scene and the pumpkins, writes round = 2, releases the locks and publishes reset. The second waits for the locks, sees round = 2, which is not 1, and does nothing.">
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
<text class="d-l" x="620" y="116" text-anchor="middle">lock scene: waits</text>
<path class="d-e " d="M102 152 L558 152" marker-end="url(#f-ln)"/>
<text class="d-l" x="330" y="146" text-anchor="middle">get hellowin:round</text>
<path class="d-e d-e--ret" d="M558 182 L102 182" marker-end="url(#f-ln)"/>
<text class="d-l" x="330" y="176" text-anchor="middle">1 = fromRound</text>
<path class="d-e " d="M102 212 L558 212" marker-end="url(#f-ln)"/>
<text class="d-l" x="330" y="206" text-anchor="middle">pumpkins {0, 0}, scene 0, round 2</text>
<path class="d-e " d="M102 242 L328 242" marker-end="url(#f-ln)"/>
<text class="d-l" x="215" y="236" text-anchor="middle">release all locks</text>
<path class="d-e d-e--acc" d="M102 272 L738 272" marker-end="url(#f-acc)"/>
<text class="d-l" x="420" y="266" text-anchor="middle">publish type:'reset', round:2</text>
<path class="d-e " d="M910 294 L886 294 L886 306 L908 306" marker-end="url(#f-ln)"/>
<text class="d-l" x="880" y="303" text-anchor="end">locks acquired</text>
<path class="d-e " d="M908 332 L562 332" marker-end="url(#f-ln)"/>
<text class="d-l" x="735" y="326" text-anchor="middle">get hellowin:round</text>
<path class="d-e d-e--ret" d="M562 362 L908 362" marker-end="url(#f-ln)"/>
<text class="d-l" x="735" y="356" text-anchor="middle">2 ≠ fromRound</text>
<path class="d-e " d="M910 384 L886 384 L886 396 L908 396" marker-end="url(#f-ln)"/>
<text class="d-l" x="880" y="393" text-anchor="end">null: round already reset</text>
<path class="d-e " d="M908 422 L332 422" marker-end="url(#f-ln)"/>
<text class="d-l" x="620" y="416" text-anchor="middle">release</text>
<rect class="d-note " x="30" y="438" width="950" height="24" rx="4"/>
<text class="d-n" x="505" y="455" text-anchor="middle">reset:{round} without the idempotency package: round is compared under the same locks that guard the state</text>
<rect class="d-box g-php" x="10" y="16" width="180" height="44" rx="6"/>
<text class="d-t" x="100" y="34" text-anchor="middle">Call A</text>
<text class="d-m" x="100" y="49" text-anchor="middle">hellowin:reset --round=1</text>
<rect class="d-box g-rr" x="255" y="16" width="150" height="44" rx="6"/>
<text class="d-t" x="330" y="34" text-anchor="middle">RR lock</text>
<text class="d-m" x="330" y="49" text-anchor="middle">scene, pumpkin:*</text>
<rect class="d-box g-rr" x="495" y="16" width="130" height="44" rx="6"/>
<text class="d-t" x="560" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="560" y="49" text-anchor="middle">Valkey</text>
<rect class="d-box g-cf" x="680" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="740" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="740" y="49" text-anchor="middle">channel hellowin</text>
<rect class="d-box g-php" x="840" y="16" width="140" height="44" rx="6"/>
<text class="d-t" x="910" y="34" text-anchor="middle">Call B</text>
<text class="d-m" x="910" y="49" text-anchor="middle">reset(fromRound: 1)</text>
</svg>
                </div>
                <figcaption>Orange: the call holds the locks; dashed frame: it waits for them.</figcaption>
            </figure>
        </section>

        <section class="how__section" id="idempotency" role="tabpanel" aria-labelledby="tab-idempotency">
            <h2>Idempotent transition</h2>
            <p>
                A pumpkin hit its threshold — exactly one transition to the next stage is needed. But clicks don't stop: while
                the job sits in the queue waiting for a worker, the pumpkin gets clicked n more times, and each click queues
                <code>TransitionPumpkin</code> again. This is on purpose. A click doesn't need to know whether someone queued the job
                before it: no “transition in flight” flag, and no resetting it if the job got lost (the queue is in memory; on
                an <code>app</code> restart jobs vanish — the next click simply queues a new one). The price of simplicity is k + n
                jobs per transition, and only one must run.
            </p>
            <p>
                Duplicates are cut off by the <code>spiral/idempotency</code> package. The job class carries an attribute, and
                <code>IdempotencyInterceptor</code> in the queue's <code>interceptors.consume</code> chain
                (<code>app/config/queue.php</code>) takes the key from the payload:
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

            <h3>Who makes up the key</h3>
            <p>
                The server builds the key from the meaning of the operation: <code>TransitionQueue::pumpkinKey()</code> —
                <code>{round}:{pumpkin}:{toStage}</code>, e.g. <code>1:pumpkin-3:2</code> — “in round 1 pumpkin 3
                moves to stage 2”. All clicks that queue this transition get the same key independently
                of each other. This differs from the familiar Stripe-style Idempotency-Key:
            </p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th></th><th>Deterministic key (here)</th><th>Random client key (Stripe-style)</th></tr>
                    </thead>
                    <tbody>
                    <tr><td>Who makes it up</td><td>the server, from the operation's data</td><td>the client, a UUID per attempt of an action</td></tr>
                    <tr><td>Where it travels</td><td>in the job payload, field <code>key</code></td><td>the request's <code>Idempotency-Key</code> header</td></tr>
                    <tr><td>Who duplicates</td><td>different clicks by different people, unaware of each other</td><td>the same client on retry: timeout, dropped connection</td></tr>
                    <tr><td>What it protects</td><td>one state change: a pumpkin's move to a stage</td><td>one request attempt: a payment isn't charged twice</td></tr>
                    <tr><td>Two different intents with one key</td><td>impossible: same key, same transition</td><td>a client bug: the server checks the request against the first one</td></tr>
                    </tbody>
                </table>
            </div>
            <p>The key goes into the job payload next to the transition data:</p>
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
                In Valkey the key gets the store prefix and a scope — the handler name, so that keys of different
                jobs don't collide:
                <code>hellowin:amo:App\Application\Hellowin\Job\TransitionPumpkin::handle:1:pumpkin-3:2</code>.
            </p>

            <h3>How a duplicate is cut off</h3>
            <p>
                The <code>transitions</code> store in <code>app/config/idempotency.php</code> is
                <code>RedisAtMostOnceConfig</code> with the prefix <code>hellowin:amo:</code> and <code>retentionTtl: 300</code>.
                Before calling the handler, the driver claims the key with one atomic command <code>SET {key} M:{token} NX EX 300</code>.
                Written — it's the first delivery, the handler runs. The key already existed — a duplicate: the handler isn't
                called at all, the interceptor returns <code>null</code>, and the job is acknowledged (ACK) — it doesn't fail and
                doesn't go back to the queue. The marker is deleted neither on success nor on error; it expires by TTL on its own.
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
<svg class="diagram__svg" viewBox="0 0 1100 838" style="min-width: 792px" role="img" aria-label="Idempotent transition: clicks over the threshold push several TransitionPumpkin jobs with one key into the transitions queue. The jobs workers take them in parallel; each one's IdempotencyInterceptor does SET NX EX in Valkey. The first gets OK and performs the transition: under the pumpkin lock it checks the round and the stage, writes stage + 1 and clicks = 0, publishes the pumpkin event and checks whether the scene is ready. The rest are refused: the handler isn't called, the job is acknowledged.">
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
<text class="d-h d-phase" x="14" y="96" text-anchor="start">1. Duplicates on purpose: one key for k + n jobs</text>
<path class="d-e " d="M58 124 L170 124" marker-end="url(#g-ln)"/>
<text class="d-l" x="114" y="118" text-anchor="middle">click</text>
<path class="d-e " d="M174 154 L294 154" marker-end="url(#g-ln)"/>
<text class="d-l" x="234" y="148" text-anchor="middle">push key=1:pumpkin-3:2</text>
<path class="d-e " d="M58 184 L170 184" marker-end="url(#g-ln)"/>
<text class="d-l" x="114" y="178" text-anchor="middle">click</text>
<path class="d-e " d="M174 214 L294 214" marker-end="url(#g-ln)"/>
<text class="d-l" x="234" y="208" text-anchor="middle">push key=1:pumpkin-3:2</text>
<path class="d-e " d="M58 244 L170 244" marker-end="url(#g-ln)"/>
<text class="d-l" x="114" y="238" text-anchor="middle">… ×n</text>
<path class="d-e " d="M174 274 L294 274" marker-end="url(#g-ln)"/>
<text class="d-l" x="234" y="268" text-anchor="middle">same key ×n</text>
<text class="d-h d-phase" x="14" y="300" text-anchor="start">2. The first delivery runs, the rest are cut off</text>
<path class="d-e " d="M298 328 L444 328" marker-end="url(#g-ln)"/>
<text class="d-l" x="371" y="322" text-anchor="middle">job 1</text>
<path class="d-e " d="M298 358 L624 358" marker-end="url(#g-ln)"/>
<text class="d-l" x="304" y="352" text-anchor="start">job 2 (in parallel)</text>
<path class="d-e " d="M448 388 L774 388" marker-end="url(#g-ln)"/>
<text class="d-l" x="611" y="382" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M774 418 L448 418" marker-end="url(#g-ln)"/>
<text class="d-l" x="611" y="412" text-anchor="middle">OK: marker written</text>
<path class="d-e " d="M628 448 L774 448" marker-end="url(#g-ln)"/>
<text class="d-l" x="701" y="442" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M774 478 L628 478" marker-end="url(#g-ln)"/>
<text class="d-l" x="701" y="472" text-anchor="middle">nil: key taken</text>
<path class="d-e " d="M626 500 L650 500 L650 512 L628 512" marker-end="url(#g-ln)"/>
<text class="d-l" x="656" y="509" text-anchor="start">duplicate: handler not called</text>
<path class="d-e d-e--ret" d="M624 538 L298 538" marker-end="url(#g-ln)"/>
<text class="d-l" x="618" y="532" text-anchor="end">null → ACK</text>
<path class="d-e " d="M446 560 L470 560 L470 572 L448 572" marker-end="url(#g-ln)"/>
<text class="d-l" x="476" y="569" text-anchor="start">TransitionPumpkin → TransitionHandler::pumpkin()</text>
<path class="d-e " d="M448 598 L904 598" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="592" text-anchor="middle">lock.Lock pumpkin:pumpkin-3</text>
<path class="d-e " d="M448 628 L904 628" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="622" text-anchor="middle">round current and stage == toStage − 1?</text>
<path class="d-e " d="M448 658 L904 658" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="652" text-anchor="middle">set stage = toStage, clicks = 0</text>
<path class="d-e " d="M448 688 L904 688" marker-end="url(#g-ln)"/>
<text class="d-l" x="676" y="682" text-anchor="middle">lock.Release</text>
<path class="d-e d-e--acc" d="M448 718 L1034 718" marker-end="url(#g-acc)"/>
<text class="d-l" x="741" y="712" text-anchor="middle">publish type:'pumpkin' — exactly once</text>
<path class="d-e " d="M446 740 L470 740 L470 752 L448 752" marker-end="url(#g-ln)"/>
<text class="d-l" x="476" y="749" text-anchor="start">transitions_total +1 · all at stageMax? → markReady() under lock scene</text>
<path class="d-e d-e--ret" d="M444 778 L298 778" marker-end="url(#g-ln)"/>
<text class="d-l" x="371" y="772" text-anchor="middle">ACK</text>
<path class="d-e " d="M298 808 L624 808" marker-end="url(#g-ln)"/>
<text class="d-l" x="304" y="802" text-anchor="start">jobs 3…n: SET NX → nil → ACK while the marker lives</text>
<rect class="d-box g-edge" x="6" y="16" width="100" height="44" rx="6"/>
<text class="d-t" x="56" y="34" text-anchor="middle">Clicks</text>
<text class="d-s" x="56" y="49" text-anchor="middle">over threshold</text>
<rect class="d-box g-php" x="116" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="172" y="34" text-anchor="middle">ClickHandler</text>
<text class="d-m" x="172" y="49" text-anchor="middle">TransitionQueue</text>
<rect class="d-box g-rr" x="238" y="16" width="116" height="44" rx="6"/>
<text class="d-t" x="296" y="34" text-anchor="middle">RR jobs</text>
<text class="d-m" x="296" y="49" text-anchor="middle">transitions</text>
<rect class="d-box g-php" x="366" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="446" y="34" text-anchor="middle">jobs worker A</text>
<text class="d-m" x="446" y="49" text-anchor="middle">IdempotencyInterceptor</text>
<rect class="d-box g-php" x="546" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="626" y="34" text-anchor="middle">jobs worker B</text>
<text class="d-m" x="626" y="49" text-anchor="middle">IdempotencyInterceptor</text>
<rect class="d-box g-kv" x="716" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="776" y="34" text-anchor="middle">Valkey</text>
<text class="d-m" x="776" y="49" text-anchor="middle">hellowin:amo:*</text>
<rect class="d-box g-rr" x="846" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="906" y="34" text-anchor="middle">RR lock + kv</text>
<text class="d-m" x="906" y="49" text-anchor="middle">pumpkin:{id}</text>
<rect class="d-box g-cf" x="980" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="1036" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="1036" y="49" text-anchor="middle">channel hellowin</text>
<text class="d-s d-bad" x="434" y="574" text-anchor="end">risk window:</text>
<text class="d-s" x="434" y="588" text-anchor="end">a crash here —</text>
<text class="d-s" x="434" y="602" text-anchor="end">transition lost</text>
<text class="d-s" x="434" y="616" text-anchor="end">until TTL expires</text>
</svg>
                </div>
                <figcaption>
                    Solid arrows are calls, dashed ones are responses and ACKs. The dashed frame on worker A is the window between
                    the marker and the effect.
                </figcaption>
            </figure>

            <h3>Why “at most once”</h3>
            <p>The package has three guarantees, and they are about different things:</p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Guarantee</th><th>How it works</th><th>If the worker dies midway</th><th>What it needs</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>AtLeastOnce</code></td><td>a lease on the key while the work runs; the key is closed after the effect</td><td>the lease expires, a retry performs the effect again — it may happen twice</td><td>an effect that is safe to repeat</td></tr>
                    <tr><td><code>AtMostOnce</code> — chosen</td><td>the marker is written <strong>before</strong> the effect and never deleted</td><td>the effect is lost, retries are cut off while the marker lives — 0 or 1 times</td><td>an atomic <code>SET NX EX</code></td></tr>
                    <tr><td><code>ExactlyOnce</code></td><td>the job record and the effect in one transaction (inbox)</td><td>the transaction rolls back entirely, a retry performs the effect anew</td><td>the effect and the record in one transactional DB</td></tr>
                    </tbody>
                </table>
            </div>
            <p>
                <code>ExactlyOnce</code> is out of reach here: the effect is a KV write through a RoadRunner plugin plus a publication to
                Centrifugo, and they share no transaction with the marker. Of the remaining two, a double transition is worse: the pumpkin
                would skip a stage, and the <code>pumpkin</code> event would go out twice. Losing a transition is cheap — the pumpkin stays
                at the threshold, and people click it on through. Hence <code>AtMostOnce</code>.
            </p>
            <p class="callout">
                The price: if a worker dies between the marker and the stage write (or the pumpkin lock stays held longer than 5 seconds),
                the transition is lost, and new jobs with the same key are cut off while the marker lives — the pumpkin “gets stuck” at the
                threshold. So the window is short: <code>retentionTtl</code> is 300 seconds, not the default of a day. After it
                the next click starts the transition again, and a double transition is prevented by the domain check under the lock —
                <code>stage == toStage − 1</code>: if the transition did in fact go through, the retry changes nothing.
            </p>

            <h3 id="scene-transition">Scene transition</h3>
            <p>
                A scene doesn't switch by itself: the game isn't timed, the moderator runs it. The transition is split into two steps, and
                each is protected from double execution in its own way.
            </p>
            <ol>
                <li>
                    <strong>Readiness.</strong> After each pumpkin transition <code>TransitionHandler</code> asks the
                    policy whether all pumpkins have reached <code>stageMax</code> (<code>RitualPolicy::sceneReady()</code>).
                    The last pumpkins can arrive almost at once, and then several workers hear “yes”. The mark
                    is set by <code>RitualStore::markReady()</code> under the <code>scene</code> lock: if in the story
                    <code>hellowin:story</code> the <code>ready</code> field isn't this scene yet, it writes it and returns
                    <code>true</code>; if it already is, <code>false</code>. So the <code>ready</code> event and the owl's lines
                    <code>last-pumpkin</code> (with the player who finished off the last pumpkin) and <code>scene-ready</code> go out
                    exactly once. The scene itself stays the same.
                </li>
                <li>
                    <strong>Readiness with the bonfire.</strong> From scene 2 on, ready pumpkins are not enough: the middle of the patch also has a bonfire, one element for
                    the whole crowd. While the scene's pumpkins aren't ready, clicking it answers <code>locked</code> and goes nowhere;
                    instead of readiness, the last pumpkin opens the bonfire (a mark in the story under the same
                    <code>scene</code> lock, owl line <code>bonfire-open</code>). Then everyone hits one
                    <code>bonfire</code> lock — hence the <code>busy</code> answers — and past the bonfire threshold
                    (the <code>bonfire</code> limit) they queue a handful of duplicates of one
                    <code>TransitionPumpkin</code> job with the key <code>{round}:bonfire:{toStage}</code>; only one runs,
                    as <code>kind="bonfire"</code> shows in the metrics. The scene becomes ready when the bonfire moves to its
                    last stage of the scene (<code>HELLOWIN_BONFIRE_STAGE_MAX</code>) — the same
                    <code>markReady()</code>, lines <code>bonfire-done</code> and <code>scene-ready</code>.
                    Likewise, in the final scene the crowd draws the magic circle (lock <code>circle</code>, key
                    <code>{round}:circle:{toStage}</code>): a closed circle makes the finale ready, and only then does
                    “Finale step” raise FrankenPHP (without readiness — only with <code>force</code>).
                </li>
                <li>
                    <strong>Transition.</strong> The moderator presses “Next scene” — RPC
                    <code>scene.next {scene}</code> with the number of the scene they see. <code>StoryService::nextScene()</code>
                    checks against a snapshot that the scene is current, not final and ready (or <code>force</code> came), and queues
                    <code>TransitionScene</code>. A double click, two control panel tabs or two moderators queue two
                    jobs, and that is the same as duplicate clicks on a pumpkin: the key <code>{round}:scene:{toScene}</code>
                    (<code>TransitionQueue::sceneKey()</code>), the same <code>#[Idempotent]</code> with the
                    <code>AtMostOnce</code> guarantee, and inside — the <code>scene</code> lock, the check
                    <code>scene == toScene − 1</code> and one <code>scene</code> event with the new scene's <code>stageMax</code>
                    and the characters' item. A press that arrives after the transition sees the scene has changed and
                    queues nothing — it just answers with the current one.
                </li>
            </ol>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1100 1000" style="min-width: 792px" role="img" aria-label="Scene transition. Phase 1: two jobs workers finish off the last pumpkins at once, and both see the scene is ready; markReady under the scene lock marks readiness for the first and refuses the second; the first publishes the ready event and the owl's lines. Phase 2: the moderator calls RPC scene.next twice; Centrifugo adds the connection meta, StoryService checks the role and readiness and queues TransitionScene with key 1:scene:2 both times. The first worker writes the marker to Valkey and switches the scene under the scene lock, the second is refused and acknowledges the job. The moderator and all clients get one scene event.">
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
<text class="d-h d-phase" x="14" y="92" text-anchor="start">1. Readiness: the last two pumpkins finished at once</text>
<path class="d-e " d="M595 106 L619 106 L619 118 L597 118" marker-end="url(#i-ln)"/>
<text class="d-l" x="625" y="115" text-anchor="start">last pumpkin → stageMax, sceneReady(): yes</text>
<path class="d-e " d="M715 132 L739 132 L739 144 L717 144" marker-end="url(#i-ln)"/>
<text class="d-l" x="745" y="141" text-anchor="start">this one too: sceneReady(): yes</text>
<path class="d-e " d="M597 178 L973 178" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="172" text-anchor="middle">markReady(round, 1): lock scene</text>
<path class="d-e d-e--ret" d="M973 208 L597 208" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="202" text-anchor="middle">not marked → story.ready = 1 → true</text>
<path class="d-e " d="M717 238 L973 238" marker-end="url(#i-ln)"/>
<text class="d-l" x="845" y="232" text-anchor="middle">markReady(round, 1)</text>
<path class="d-e d-e--ret" d="M973 268 L717 268" marker-end="url(#i-ln)"/>
<text class="d-l" x="845" y="262" text-anchor="middle">already marked → false</text>
<path class="d-e d-e--acc" d="M593 298 L217 298" marker-end="url(#i-acc)"/>
<text class="d-l" x="405" y="292" text-anchor="middle">publish ready · owl last-pumpkin · owl scene-ready</text>
<path class="d-e d-e--acc" d="M213 328 L62 328" marker-end="url(#i-acc)"/>
<text class="d-l" x="137" y="322" text-anchor="middle">ready</text>
<path class="d-e " d="M60 350 L84 350 L84 362 L62 362" marker-end="url(#i-ln)"/>
<text class="d-l" x="90" y="359" text-anchor="start">the “Next scene” button waits for a press</text>
<text class="d-h d-phase" x="14" y="392" text-anchor="start">2. Transition: the moderator pressed twice</text>
<path class="d-e " d="M62 420 L213 420" marker-end="url(#i-ln)"/>
<text class="d-l" x="137" y="414" text-anchor="middle">scene.next {scene: 1}</text>
<path class="d-e " d="M62 450 L213 450" marker-end="url(#i-ln)"/>
<text class="d-l" x="137" y="444" text-anchor="middle">same again</text>
<path class="d-e d-e--acc" d="M217 480 L343 480" marker-end="url(#i-acc)"/>
<text class="d-l" x="280" y="474" text-anchor="middle">RPC proxy ×2</text>
<text class="d-s" x="280" y="496" text-anchor="middle">+ connection meta</text>
<path class="d-e " d="M345 512 L369 512 L369 524 L347 524" marker-end="url(#i-ln)"/>
<text class="d-l" x="375" y="521" text-anchor="start">role from meta · scene 1 ready → TransitionScene, both times</text>
<path class="d-e " d="M347 550 L468 550" marker-end="url(#i-ln)"/>
<text class="d-l" x="407" y="544" text-anchor="middle">key 1:scene:2 ×2</text>
<path class="d-e d-e--ret" d="M343 580 L217 580" marker-end="url(#i-ln)"/>
<text class="d-l" x="280" y="574" text-anchor="middle">{ok, scene: 2}</text>
<path class="d-e d-e--ret" d="M213 610 L62 610" marker-end="url(#i-ln)"/>
<text class="d-l" x="137" y="604" text-anchor="middle">{ok, scene: 2} ×2</text>
<path class="d-e " d="M472 640 L593 640" marker-end="url(#i-ln)"/>
<text class="d-l" x="532" y="634" text-anchor="middle">job 1</text>
<path class="d-e " d="M472 670 L713 670" marker-end="url(#i-ln)"/>
<text class="d-l" x="478" y="664" text-anchor="start">job 2 (in parallel)</text>
<path class="d-e " d="M597 700 L838 700" marker-end="url(#i-ln)"/>
<text class="d-l" x="655" y="694" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M838 730 L597 730" marker-end="url(#i-ln)"/>
<text class="d-l" x="655" y="724" text-anchor="middle">OK</text>
<path class="d-e " d="M717 760 L838 760" marker-end="url(#i-ln)"/>
<text class="d-l" x="777" y="754" text-anchor="middle">SET … NX EX 300</text>
<path class="d-e d-e--ret" d="M838 790 L717 790" marker-end="url(#i-ln)"/>
<text class="d-l" x="777" y="784" text-anchor="middle">nil → duplicate, ACK</text>
<path class="d-e " d="M597 820 L973 820" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="814" text-anchor="middle">lock scene · round current and scene == 1?</text>
<path class="d-e " d="M597 850 L973 850" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="844" text-anchor="middle">scene = 2, ready cleared</text>
<path class="d-e " d="M597 880 L973 880" marker-end="url(#i-ln)"/>
<text class="d-l" x="785" y="874" text-anchor="middle">lock.Release</text>
<path class="d-e d-e--acc" d="M593 910 L217 910" marker-end="url(#i-acc)"/>
<text class="d-l" x="405" y="904" text-anchor="middle">publish scene {stageMax, item} · owl scene-2</text>
<path class="d-e d-e--acc" d="M213 940 L62 940" marker-end="url(#i-acc)"/>
<text class="d-l" x="137" y="934" text-anchor="middle">scene: 2</text>
<rect class="d-note d-note--ok" x="30" y="958" width="1040" height="24" rx="4"/>
<text class="d-n" x="550" y="975" text-anchor="middle">two pumpkins and two presses — one ready mark and one transition: lock scene and key {round}:scene:{toScene}</text>
<rect class="d-box g-edge" x="4" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="60" y="34" text-anchor="middle">Moderator</text>
<text class="d-s" x="60" y="49" text-anchor="middle">control panel</text>
<rect class="d-box g-cf" x="159" y="16" width="112" height="44" rx="6"/>
<text class="d-t" x="215" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="215" y="49" text-anchor="middle">channel hellowin</text>
<rect class="d-box g-php" x="280" y="16" width="130" height="44" rx="6"/>
<text class="d-t" x="345" y="34" text-anchor="middle">RPCService</text>
<text class="d-m" x="345" y="49" text-anchor="middle">→ StoryService</text>
<rect class="d-box g-rr" x="415" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="470" y="34" text-anchor="middle">RR jobs</text>
<text class="d-m" x="470" y="49" text-anchor="middle">transitions</text>
<rect class="d-box g-php" x="540" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="595" y="34" text-anchor="middle">jobs worker A</text>
<text class="d-m" x="595" y="49" text-anchor="middle">#[Idempotent]</text>
<rect class="d-box g-php" x="660" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="715" y="34" text-anchor="middle">jobs worker B</text>
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
                    Orange: a worker holds the <code>scene</code> lock. In phase 1 the workers run
                    <code>TransitionPumpkin</code>, in phase 2 — <code>TransitionScene</code>.
                </figcaption>
            </figure>

            <h3>Why the key includes the round</h3>
            <p>
                After a reset the ritual goes through the same transitions again: pumpkin 3 heads to stage 2 once more. Without the round the key
                would match the old one, and while the old marker lives, the new transition would be cut off as a duplicate. With the round it is a new
                operation: <code>2:pumpkin-3:2</code>. And jobs from the previous round left in the queue change nothing, even if they
                get past the marker: the domain check sees the round has changed.
            </p>

            <h3>What you see in Grafana</h3>
            <p>
                The dashboard's “Transitions: queue and idempotency” section has the panels “Jobs pushed vs transitions applied”
                (per minute and total, by <code>kind</code>), “Duplicates dropped” = <code>pushed − transitions</code> and
                “Pumpkin stages”. The harder people click a pumpkin at the threshold, the bigger the gap. You can also see the markers
                in Valkey itself, along with the remaining TTL:
            </p>
            <pre><code>docker compose exec valkey valkey-cli --scan --pattern 'hellowin:amo:*'
docker compose exec valkey valkey-cli ttl 'hellowin:amo:App\Application\Hellowin\Job\TransitionPumpkin::handle:1:pumpkin-3:2'</code></pre>
        </section>

        <section class="how__section" id="dice" role="tabpanel" aria-labelledby="tab-dice">
            <h2>Dice: idempotency with a stored response</h2>
            <p>
                Before the uprising in the finale, the crowd rolls three dice until someone gets 6-6-6, a PHP version that
                never existed. The server does the rolling: the client sends the RPC <code>roll {attempt}</code> with its
                attempt number, and the dice come back in the reply and to everyone in the <code>roll</code> event. The
                problem is the same as with a pumpkin transition: a repeated request must not cause a second effect. But a
                duplicate transition can simply be dropped, while a repeated roll needs an answer: the same dice. So the
                attempt's response is <em>stored</em>, and the retry gets it.
            </p>

            <h3>A fair roll</h3>
            <p>
                The dice are truly random: <code>DiceRoller</code> calls
                <code>Randomizer::getInt(1, 6)</code> three times on top of the system CSPRNG, same as <code>random_int</code>.
                Nobody can predict which attempt will hit 6-6-6, the server included. But the same dice can't be computed
                a second time either: a roll is drawn once, and the result has to be remembered.
            </p>

            <h3>The key and the stored response</h3>
            <p>
                The attempt key is <code>{round}:roll:{player}:{attempt}</code>: the round, the player's public id and the attempt number.
                <code>DiceService::roll</code> runs the roll through the <code>dice</code> store of the
                <code>spiral/idempotency</code> package, a <code>RedisLeaseConfig</code> lease with the
                <strong>AtLeastOnce</strong> guarantee. The first call with a key rolls and stores the response
                <code>{attempt, dice}</code> for <code>HELLOWIN_DICE_IDEMPOTENCY_TTL</code> s. A repeat of the same key
                (a double click, a reconnect, a retry after a lost reply) gets the stored response: no new
                roll, no event, no count. A refusal (<code>busy</code>, <code>not now</code>,
                <code>bad attempt</code>) is not a response: <code>FailurePolicy::Release</code> frees the key, and the
                attempt can be rolled once the refusal no longer applies.
            </p>
            <p>In code it is one <code>execute()</code> call; <code>LockedException</code> means another call is rolling the same attempt right now:</p>
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
            <p>The <code>dice</code> store is a lease next to <code>transitions</code> in the same config:</p>
            <figure class="code">
                <figcaption><code>app/config/idempotency.php</code> · <code>storages.dice</code></figcaption>
                <pre><code>'dice' =&gt; new RedisLeaseConfig(
    keyPrefix: 'hellowin:lease:',
    lockTtl: 5,
),</code></pre>
            </figure>
            <p>
                The faces of a player's last attempt are also kept in <code>hellowin:dice</code> under the <code>dice</code> lock,
                in one record together with the attempt number and the time of the next roll. This is a safety net: AtLeastOnce allows
                a second run if the worker died before storing the response or the response expired. A run under the lock then
                finds its attempt in the record and returns those faces without rolling again.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 490" style="min-width: 720px" role="img" aria-label="A dice roll: the browser calls the RPC roll with attempt number n. The dice store of the spiral/idempotency package looks up a response by the key {round}:roll:{player}:{n}. If a response is stored, the retry gets the same response with no roll, event or count. If not, DiceService under the dice lock reads the player's last attempt, last, from hellowin:dice. If n = last + 1, it is a new roll: random_int three times, an attempt record with the faces, a roll event to everyone, the roll count, and the response is stored for HELLOWIN_DICE_IDEMPOTENCY_TTL; no more often than HELLOWIN_DICE_COOLDOWN_MS. If n = last, the response was lost and the faces come from the record, with no roll or event. Otherwise it is a bad attempt error carrying last, and the client takes last + 1. The first 6-6-6 records the winner under the same lock.">
<defs><marker id="n-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="n-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-box g-cf" x="20" y="60" width="170" height="70" rx="6"/>
<text class="d-t" x="105" y="88" text-anchor="middle">Browser</text>
<text class="d-m" x="105" y="107" text-anchor="middle">roll {attempt: n}</text>
<path class="d-e" d="M190 95 L270 95" marker-end="url(#n-ln)"/>
<text class="d-l" x="230" y="87" text-anchor="middle">RPC</text>
<rect class="d-box g-kv" x="270" y="40" width="260" height="110" rx="6"/>
<text class="d-t" x="400" y="66" text-anchor="middle">dice store</text>
<text class="d-m" x="400" y="87" text-anchor="middle">{round}:roll:{player}:{n}</text>
<text class="d-s" x="400" y="107" text-anchor="middle">spiral/idempotency, AtLeastOnce</text>
<text class="d-s" x="400" y="125" text-anchor="middle">TTL HELLOWIN_DICE_IDEMPOTENCY_TTL</text>
<path class="d-e d-e--ret" d="M530 95 L620 95" marker-end="url(#n-ln)"/>
<text class="d-l" x="575" y="87" text-anchor="middle">stored</text>
<path class="d-e" d="M400 150 L400 175" marker-end="url(#n-ln)"/>
<text class="d-l" x="410" y="167" text-anchor="start">none</text>
<rect class="d-box g-php" x="270" y="175" width="260" height="260" rx="6"/>
<text class="d-t" x="400" y="202" text-anchor="middle">DiceService::roll</text>
<text class="d-m" x="400" y="222" text-anchor="middle">lock dice</text>
<text class="d-m" x="400" y="240" text-anchor="middle">hellowin:dice → last</text>
<text class="d-s" x="400" y="260" text-anchor="middle">last attempt and its faces</text>
<rect class="d-crit" x="285" y="372" width="230" height="48" rx="4"/>
<text class="d-n" x="400" y="391" text-anchor="middle">first 6-6-6 → winner under the</text>
<text class="d-n" x="400" y="408" text-anchor="middle">same lock; the next gets not now</text>
<path class="d-e d-e--ok" d="M530 210 L620 210" marker-end="url(#n-ok)"/>
<text class="d-l" x="575" y="202" text-anchor="middle">n = last + 1</text>
<path class="d-e d-e--ret" d="M530 305 L620 305" marker-end="url(#n-ln)"/>
<text class="d-l" x="575" y="297" text-anchor="middle">n = last</text>
<path class="d-e" d="M530 395 L620 395" marker-end="url(#n-ln)"/>
<text class="d-l" x="575" y="387" text-anchor="middle">else</text>
<rect class="d-note" x="620" y="40" width="360" height="110" rx="4"/>
<text class="d-h" x="636" y="64" text-anchor="start">Retry: response already stored</text>
<text class="d-m" x="636" y="86" text-anchor="start">same {attempt, dice}, no roll</text>
<text class="d-s" x="636" y="106" text-anchor="start">double click, reconnect, lost reply;</text>
<text class="d-s" x="636" y="124" text-anchor="start">no lock, no event, no count: no reroll</text>
<rect class="d-note d-note--ok" x="620" y="165" width="360" height="90" rx="4"/>
<text class="d-h d-ok" x="636" y="189" text-anchor="start">New roll</text>
<text class="d-m" x="636" y="211" text-anchor="start">random_int × 3 → dice, response stored</text>
<text class="d-s" x="636" y="231" text-anchor="start">record {attempt, at, dice}, roll event to all,</text>
<text class="d-s" x="636" y="247" text-anchor="start">rolls + 1; gap ≥ HELLOWIN_DICE_COOLDOWN_MS</text>
<rect class="d-note" x="620" y="265" width="360" height="80" rx="4"/>
<text class="d-h" x="636" y="289" text-anchor="start">Reply lost, attempt in record</text>
<text class="d-s" x="636" y="311" text-anchor="start">worker died before storing, or it expired:</text>
<text class="d-s" x="636" y="329" text-anchor="start">faces from hellowin:dice, no roll or event</text>
<rect class="d-note d-note--bad" x="620" y="355" width="360" height="80" rx="4"/>
<text class="d-h d-bad" x="636" y="379" text-anchor="start">bad attempt {last}</text>
<text class="d-s" x="636" y="401" text-anchor="start">attempts can't be skipped: client takes last + 1</text>
<text class="d-s" x="636" y="419" text-anchor="start">and rolls again</text>
<text class="d-s" x="20" y="470" text-anchor="start">attempt response: dice store (hellowin:lease:*) for HELLOWIN_DICE_IDEMPOTENCY_TTL s; last attempt's faces: also hellowin:dice</text>
</svg>
                </div>
                <figcaption>Green is a new effect, dashed is the same response with no effect, red is a refusal; orange is work under the lock.</figcaption>
            </figure>
            <ul>
                <li><strong>Why a retry doesn't reroll.</strong> A retry doesn't roll: it gets the stored response,
                    and if that is gone, its attempt's faces from the record. Only the next attempt brings new dice, and no
                    more often than the <code>HELLOWIN_DICE_COOLDOWN_MS</code> pause: nobody can cycle through attempts faster than the rest.
                    Compare with the pumpkin transition: there it is AtMostOnce (<code>hellowin:amo:</code>), a
                    <code>SET NX</code> marker cuts off the duplicate, which needs no response; here it is an AtLeastOnce lease
                    (<code>hellowin:lease:</code>), and the duplicate gets the same response as the first call.</li>
                <li><strong>The first 6-6-6 is under the lock.</strong> Every roll in the room goes through one record,
                    <code>hellowin:dice</code>, under one <code>dice</code> lock, like the strikes on FrankenPHP. The winner
                    is recorded by whoever takes the lock first; a second 6-6-6 sees it and gets <code>not now</code>, like
                    a second shot at a flyer that is already down. The win makes the finale step ready
                    (<code>markReady</code>), and the owl names the winner.</li>
                <li><strong>The owl.</strong> The moderator's fallback button <code>dice.owl</code>: the first two rolls are
                    random, and a 6-6-6 that comes up is rerolled; the third is a guaranteed 6-6-6. There is no idempotency key:
                    each press by the moderator is a new roll. These don't count toward the room's attempts (<code>reveal.rolls</code>).</li>
            </ul>
        </section>

        <section class="how__section" id="live" role="tabpanel" aria-labelledby="tab-live">
            <h2>Moderator, characters, flyers</h2>
            <p>
                Around the ritual runs a live evening: the moderator drives the story from the control panel, players' characters
                walk the lawn, birds and spooks fly across the sky, and in the finale the owl lays out the real numbers. Each of
                these mechanics is one more technique: a role in the connection meta, channel presence, a shared server clock,
                “first under the lock”, a count derived from state without extra writes.
            </p>

            <h3 id="moderator">The moderator role</h3>
            <p>
                The moderator opens the page with <code>?moderator=&lt;secret&gt;</code>; the page puts the secret into
                <code>localStorage</code>, removes it from the address and passes it in the connection <code>data</code> next to
                the player secret. <code>ConnectService</code> compares it with <code>HELLOWIN_MODERATOR_SECRET</code>
                (<code>hash_equals</code>) and on a match puts <code>{moderator: true}</code> into the connection
                <strong>meta</strong>. Centrifugo keeps the meta, the client can't see it, and Centrifugo adds it to every RPC proxy
                call itself, as configured by <code>rpc.proxy.include_connection_meta</code> in <code>config.json</code>. The client
                sends only data in an RPC and can't grant itself a role by request. With an empty secret in env, nobody has the
                role.
            </p>
            <p>
                <code>RPCService</code> reads the role from meta, and for control panel methods (<code>scene.next</code>,
                <code>finale.step</code>, <code>owl.say</code>, <code>flyer.special</code>, <code>flyers.burst</code>, <code>pause</code>,
                <code>thresholds</code>, <code>reset</code>) answers <code>forbidden</code> without it, before parsing the data.
                <code>thresholds {k}</code> multiplies all limits by <code>k</code> (<a href="#limits">“Limits”</a>) to fit the ritual to the room on the fly;
                <code>k</code> lives in the story and survives a reset.
                <code>pause {on}</code> pauses progress: the scene's goal freezes while the room carries on. RPCs that advance
                the goal (<code>click</code> on pumpkins, the bonfire and the circle, <code>drink</code>, <code>strike</code>,
                <code>roll</code>) are not refused: the action goes through and the event reaches everyone, but with <code>counted: false</code>:
                clicks and stages hold, mugs don't count toward the goal, the boss's health stays the same, 6-6-6 doesn't win, and the
                scene never becomes ready. Players don't see the pause; it shows only on the control panel.
                Flyers, zombies and shots are not affected by the pause. Lifting the pause catches up through the same recount as a
                lowered limit, and a reset lifts it by itself.
                The role is not in <code>user</code>: the moderator can also be a player with their own secret, and <code>user</code> is
                the player's public id in events and presence. Every control panel method is safe to repeat:
                <code>StoryService</code> changes the story <code>hellowin:story</code> under the <code>scene</code> lock, compares
                “before” and “after”, and publishes the event (and an owl line) only if something changed.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 650" style="min-width: 720px" role="img" aria-label="The moderator role. The moderator's browser connects with the moderator secret in data; ConnectService checks it against HELLOWIN_MODERATOR_SECRET and replies with meta moderator true; Centrifugo keeps the meta. On a pause RPC from the moderator, Centrifugo adds the meta and RPCService executes the call. The same RPC from a regular player arrives with empty meta and gets forbidden.">
<defs><marker id="j-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="j-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<path class="d-life" d="M70 60 V650"/>
<path class="d-life" d="M200 60 V650"/>
<path class="d-life" d="M400 60 V650"/>
<path class="d-life" d="M720 60 V650"/>
<text class="d-h d-phase" x="14" y="88" text-anchor="start">1. Connect: secret in data, role in meta</text>
<path class="d-e " d="M72 116 L398 116" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="110" text-anchor="middle">connect {data: {playerSecret, moderatorSecret}}</text>
<path class="d-e d-e--acc" d="M402 146 L718 146" marker-end="url(#j-acc)"/>
<text class="d-l" x="560" y="140" text-anchor="middle">connect proxy: data</text>
<path class="d-e " d="M720 168 L744 168 L744 180 L722 180" marker-end="url(#j-ln)"/>
<text class="d-l" x="714" y="177" text-anchor="end">hash_equals(HELLOWIN_MODERATOR_SECRET, …)</text>
<path class="d-e d-e--ret" d="M718 206 L402 206" marker-end="url(#j-ln)"/>
<text class="d-l" x="560" y="200" text-anchor="middle">user, channels, meta {moderator: true}</text>
<path class="d-e " d="M400 228 L424 228 L424 240 L402 240" marker-end="url(#j-ln)"/>
<text class="d-l" x="430" y="237" text-anchor="start">meta stays here, never sent to the client</text>
<path class="d-e d-e--ret" d="M398 266 L72 266" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="260" text-anchor="middle">connected (no meta)</text>
<text class="d-h d-phase" x="14" y="296" text-anchor="start">2. Control panel RPC: Centrifugo adds meta, not the client</text>
<path class="d-e " d="M72 324 L398 324" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="318" text-anchor="middle">rpc('pause', {on: true})</text>
<path class="d-e d-e--acc" d="M402 354 L718 354" marker-end="url(#j-acc)"/>
<text class="d-l" x="560" y="348" text-anchor="middle">RPC proxy + meta {moderator: true}</text>
<path class="d-e " d="M720 376 L744 376 L744 388 L722 388" marker-end="url(#j-ln)"/>
<text class="d-l" x="714" y="385" text-anchor="end">panel method, role set → StoryService</text>
<path class="d-e d-e--ret" d="M718 414 L402 414" marker-end="url(#j-ln)"/>
<text class="d-l" x="560" y="408" text-anchor="middle">{ok: true, on: true}</text>
<path class="d-e d-e--ret" d="M398 444 L72 444" marker-end="url(#j-ln)"/>
<text class="d-l" x="235" y="438" text-anchor="middle">{ok: true, on: true}</text>
<path class="d-e " d="M202 474 L398 474" marker-end="url(#j-ln)"/>
<text class="d-l" x="300" y="468" text-anchor="middle">rpc('pause', …)</text>
<path class="d-e d-e--acc" d="M402 504 L718 504" marker-end="url(#j-acc)"/>
<text class="d-l" x="560" y="498" text-anchor="middle">RPC proxy + meta {}</text>
<path class="d-e " d="M720 526 L744 526 L744 538 L722 538" marker-end="url(#j-ln)"/>
<text class="d-l" x="714" y="535" text-anchor="end">no role → forbidden, data not parsed</text>
<path class="d-e d-e--ret" d="M718 564 L402 564" marker-end="url(#j-ln)"/>
<text class="d-l" x="560" y="558" text-anchor="middle">{ok: false, error: 'forbidden'}</text>
<path class="d-e d-e--ret" d="M398 594 L202 594" marker-end="url(#j-ln)"/>
<text class="d-l" x="300" y="588" text-anchor="middle">forbidden</text>
<rect class="d-note d-note--ok" x="30" y="610" width="940" height="24" rx="4"/>
<text class="d-n" x="500" y="627" text-anchor="middle">the client can't grant itself a role: it sends only data in an RPC, meta comes from Centrifugo</text>
<rect class="d-box g-edge" x="5" y="16" width="130" height="44" rx="6"/>
<text class="d-t" x="70" y="34" text-anchor="middle">Moderator</text>
<text class="d-s" x="70" y="49" text-anchor="middle">browser + secret</text>
<rect class="d-box g-edge" x="145" y="16" width="110" height="44" rx="6"/>
<text class="d-t" x="200" y="34" text-anchor="middle">Player</text>
<text class="d-s" x="200" y="49" text-anchor="middle">no secret</text>
<rect class="d-box g-cf" x="320" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="400" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="400" y="49" text-anchor="middle">include_connection_meta</text>
<rect class="d-box g-php" x="625" y="16" width="190" height="44" rx="6"/>
<text class="d-t" x="720" y="34" text-anchor="middle">RR centrifuge</text>
<text class="d-m" x="720" y="49" text-anchor="middle">ConnectService · RPCService</text>
</svg>
                </div>
                <figcaption>Solid arrows are requests, dashed are replies. Purple ones are proxy calls from Centrifugo.</figcaption>
            </figure>

            <h3 id="walkers">Characters on the lawn: presence and the ticker</h3>
            <p>
                A player who has built a character on the entry screen (RPC <code>register</code> with a name and <code>look</code>) is
                a character on the lawn. The server doesn't track who is on the page by itself: the <code>{{ $channel }}</code> channel
                has Centrifugo presence enabled (<code>"presence": true</code> in <code>config.json</code>, closed to
                clients). Every connection is subscribed to the channel by the server, so presence is every open tab with
                <code>user</code> = the player's public id, and an entry disappears on its own as soon as the connection closes.
            </p>
            <p>
                Once a second, the ticker (<code>WalkService::sync()</code>) asks for presence over the Centrifugo gRPC API
                (<code>CentrifugoPresence</code>), reduces the entries to a set of players and, under the <code>walkers</code> lock,
                compares it with the <code>hellowin:walkers</code> key: present players get their <code>seenAt</code> updated,
                players with a <code>look</code> but no character step onto the lawn (the <code>walker</code> event), and characters whose
                players have been gone longer than <code>HELLOWIN_WALK_LEAVE_AFTER</code> ms leave (<code>leave</code>). Several
                tabs mean several entries for one <code>user</code>, but there is one character, and it leaves when the last tab closes.
                The leave delay keeps a page reload from dropping the character or moving it to the edge.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 310" style="min-width: 720px" role="img" aria-label="Timeline of one player: tab 1 opens, reloads and closes; tab 2 opens later and closes last. The channel presence exists while at least one tab is open, with a short gap during the reload. The ticker checks presence once a second: the character steps onto the lawn on the first tick, survives the reload and leaves HELLOWIN_WALK_LEAVE_AFTER after the last tab closes.">
<defs><marker id="k-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<text class="d-s" x="20" y="62" text-anchor="start">tab 1</text>
<text class="d-s" x="20" y="98" text-anchor="start">tab 2</text>
<text class="d-s" x="20" y="134" text-anchor="start">channel presence</text>
<text class="d-s" x="20" y="170" text-anchor="start">character on lawn</text>
<text class="d-s" x="20" y="204" text-anchor="start">ticker, every second</text>
<rect class="d-box g-edge" x="190" y="50" width="140" height="16" rx="3"/>
<rect class="d-box g-edge" x="382" y="50" width="148" height="16" rx="3"/>
<rect class="d-box g-edge" x="450" y="86" width="184" height="16" rx="3"/>
<rect class="d-box g-cf" x="190" y="122" width="140" height="16" rx="3"/>
<rect class="d-box g-cf" x="382" y="122" width="252" height="16" rx="3"/>
<text class="d-s" x="356" y="153" text-anchor="middle">reload</text>
<path class="d-cap" d="M210 166 H810"/>
<circle class="d-pk d-pk--done" cx="210" cy="166" r="5"/>
<circle class="d-pk d-pk--done" cx="810" cy="166" r="5"/>
<text class="d-m" x="210" y="186" text-anchor="middle">walker</text>
<text class="d-m" x="810" y="186" text-anchor="middle">leave</text>
<path class="d-axis" d="M170 200 H970"/>
<path class="d-axis" d="M170 195 V205 M210 195 V205 M250 195 V205 M290 195 V205 M330 195 V205 M370 195 V205 M410 195 V205 M450 195 V205 M490 195 V205 M530 195 V205 M570 195 V205 M610 195 V205 M650 195 V205 M690 195 V205 M730 195 V205 M770 195 V205 M810 195 V205 M850 195 V205 M890 195 V205 M930 195 V205 M970 195 V205"/>
<path class="d-e" d="M636 230 L808 230" marker-start="url(#k-ln)" marker-end="url(#k-ln)"/>
<text class="d-m" x="722" y="248" text-anchor="middle">HELLOWIN_WALK_LEAVE_AFTER</text>
<text class="d-s" x="722" y="262" text-anchor="middle">with no connection at all</text>
<text class="d-s" x="356" y="230" text-anchor="middle">gap shorter than the delay:</text>
<text class="d-s" x="356" y="244" text-anchor="middle">the character stays put</text>
<rect class="d-note " x="30" y="276" width="940" height="24" rx="4"/>
<text class="d-n" x="500" y="293" text-anchor="middle">two tabs: two presence entries for one user, but one character; it leaves when the last tab closes</text>
</svg>
                </div>
                <figcaption>
                    Rectangles are open connections and presence entries, the orange line is the character on the lawn, the
                    marks are ticks. Lengths are illustrative: the delay is <code>HELLOWIN_WALK_LEAVE_AFTER</code>.
                </figcaption>
            </figure>
            <p>
                Why not the connect proxy: at connect time the client isn't subscribed to the channel yet and would miss its own
                <code>walker</code> event. Why not the refresh proxy with connection renewal: a departure would show up
                only when the term expired, and every renewal is an extra call and a KV write. Presence is a ready-made record of
                connections that Centrifugo cleans up itself on disconnect. A failed presence poll is logged, and the characters
                stay: an unavailable presence doesn't mean everyone left.
            </p>

            <h3 id="walk-path">The server plans the character's path</h3>
            <p>
                A click on the ground is only an intent: the RPC <code>move {x, y}</code>. Nobody sends coordinates frame by
                frame. <code>WalkService::move()</code> clamps the target into the zone, the lawn rectangle
                <code>walk.area</code> minus the patch ellipse <code>walk.patch</code> from <code>app/config/hellowin.php</code>
                (a click inside the patch goes to its edge), starts from where the character is right now according to its current
                path and the server clock, and builds a polyline: straight if the segment misses the patch, otherwise along a tangent
                to the ellipse enlarged by 3 %, along its arc and along a tangent to the target, on whichever side is shorter. The duration is
                the polyline length divided by <code>HELLOWIN_WALK_SPEED</code>. Under the <code>walkers</code> lock the path
                is written to <code>hellowin:walkers</code>, and the channel gets a <code>move</code> event with
                <code>Walk {path, startedAt, arrivesAt}</code>.
            </p>
            <p>The event is built by <code>ChannelPublisher::move()</code>, and every channel event leaves through one <code>publish()</code>: <code>BroadcastInterface</code>, the <code>centrifuge</code> plugin, Centrifugo's gRPC API:</p>
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
<svg class="diagram__svg" viewBox="0 0 1000 370" style="min-width: 720px" role="img" aria-label="A character's path: the lawn rectangle with the pumpkin patch ellipse in the middle. A straight segment from the character to the target would cross the patch, so the server routes the path along a tangent to a slightly enlarged ellipse, along its lower arc as a polyline and along a tangent to the target, on the shorter side. A click inside the patch is moved to its edge along a ray from the center.">
<defs><marker id="l-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-life" x="60" y="30" width="880" height="290"/>
<text class="d-s" x="72" y="50" text-anchor="start">walk.area: the lawn</text>
<ellipse class="d-box g-rr" cx="500" cy="200" rx="170" ry="90"/>
<ellipse class="d-life" cx="500" cy="200" rx="175" ry="92.7"/>
<text class="d-t" x="500" y="186" text-anchor="middle">patch walk.patch</text>
<text class="d-s" x="500" y="202" text-anchor="middle">characters stay out</text>
<path class="d-e d-e--ret" d="M230 240 L780 232"/>
<text class="d-s d-bad" x="500" y="226" text-anchor="middle">direct: crosses the patch</text>
<path class="d-cap" d="M230 240 L412.5 280.3 L469.6 291.3 L530.4 291.3 L587.5 280.3 L780 232"/>
<circle class="d-pk d-pk--done" cx="230" cy="240" r="6"/>
<circle class="d-pk d-pk--done" cx="780" cy="232" r="6"/>
<text class="d-s" x="220" y="236" text-anchor="end">from: where it is now</text>
<text class="d-s" x="220" y="251" text-anchor="end">by Walk and server clock</text>
<text class="d-s" x="792" y="228" text-anchor="start">target move {x, y}</text>
<text class="d-s" x="500" y="312" text-anchor="middle">detour along the arc: shorter side, arc step ≤ 15°</text>
<text class="d-s" x="683" y="128" text-anchor="start">dashed: ellipse + 3 %:</text>
<text class="d-s" x="683" y="142" text-anchor="start">arc chords miss the patch</text>
<circle class="d-pk" cx="430" cy="150" r="5"/>
<path class="d-e" d="M426 147 L402 130" marker-end="url(#l-ln)"/>
<text class="d-s" x="394" y="118" text-anchor="end">click in patch → edge, on a ray from center</text>
<text class="d-m" x="500" y="352" text-anchor="middle">Walk {path: [from, …, target], startedAt, arrivesAt} · time = length / HELLOWIN_WALK_SPEED</text>
</svg>
                </div>
                <figcaption>The orange polyline is the path from the <code>move</code> event; every client animates it the same way.</figcaption>
            </figure>
            <p class="callout">
                The server sets the animation, not the clicking player's client. From <code>now</code> in the snapshot, each browser knows
                its clock offset from the server's and places the character at the point on the path where it should be at that moment of
                server time. So everyone sees the same movement, and a tab opened mid-walk immediately
                draws the character halfway along. A new <code>move</code> during a walk starts from the current point.
            </p>
            <p>
                Clinking mugs on click in the cursed world is the same path plus a deferred check: the RPC <code>clink</code> walks
                the character to the target and puts next to it in <code>hellowin:walkers</code> a record “waiting to meet by
                <code>dueAt</code>”, and every 200 ms the ticker, under the <code>walkers</code> lock, takes the records that are due and,
                if the target hasn't left, publishes exactly one <code>clink</code> event with a toast and the meeting time.
            </p>

            <h3 id="flyers">Flyers and zombies: points go to the first</h3>
            <p>
                The ticker launches flyers: at random once per <code>HELLOWIN_FLYER_INTERVAL</code> ms, the kind taken from the
                current scene's set <code>HELLOWIN_FLYER_KINDS</code>, occasionally a rare one (<code>HELLOWIN_FLYER_RARE</code>); the
                moderator can also release a special one by button or a whole wave (“Launch flyers”, <a href="#limits">“Limits”</a>). The server sends the trajectory ready-made: a
                <code>flyer</code> event with points, depth, score and the <code>launchedAt</code> / <code>expiresAt</code> times,
                and in KV a <code>hellowin:flyer:{id}</code> key with a TTL plus an index of flyers in the air. Zombies in the cursed world are
                flyers too (<code>kind: zombie</code>, with their own interval <code>HELLOWIN_ZOMBIE_INTERVAL</code>), they just walk across
                the lawn from the edge of the patch outward, so the shooting, points and metrics are shared. A progress pause doesn't
                stop the ticker: flyers and zombies keep moving and a hit is credited to the player, only a zombie downed during the pause
                doesn't count toward the cursed world's goal.
            </p>
            <p>
                A click on a flyer is the RPC <code>shoot {flyer}</code>. Under the <code>flyer:{id}</code> lock: if the key still exists, the
                flyer can be shot and its flight isn't over, the key is deleted and it's a hit: points to the player, one
                <code>shot</code> event, a metric. The next shot won't find the key: a miss. On every tick the ticker accounts for escaped
                flyers the same way: if the key still exists, delete it and count it as escaped. Exactly one of them deletes the key,
                so a flyer is never both shot and escaped. The server accepts a shot until
                <code>expiresAt</code> + 500 ms, and the client doesn't draw a flyer whose time has run out by the server clock.
            </p>
            <p>
                Points add up to the evening's top shooters: <code>hellowin:top</code> under the <code>top</code> lock
                (<code>TopService</code>). A hit doesn't write “+N” there but the player's absolute score returned by their
                record, taking <code>max(old, new)</code>: a retry or a race can't count twice. The shot itself publishes
                nothing: the ticker sends the board as a <code>top {board}</code> event when it has changed, and no more often than
                <code>HELLOWIN_TOP_INTERVAL</code> ms. This is coalescing: with a full room, the full list to everyone on every
                hit is too much, while once per interval it is one. The board lives all evening, a reset doesn't clear it, and
                it is also in the page snapshot.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 440" style="min-width: 720px" role="img" aria-label="Two players shoot at the same flyer. The first player's worker takes the flyer:{id} lock, sees the flyer's key, deletes it, releases the lock and publishes shot. The second player's worker waits for the lock, then finds no key and replies with a miss.">
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
<text class="d-l" x="430" y="120" text-anchor="middle">lock flyer:{id}: waits</text>
<path class="d-e " d="M102 156 L528 156" marker-end="url(#m-ln)"/>
<text class="d-l" x="315" y="150" text-anchor="middle">get: key exists, still flying</text>
<path class="d-e " d="M102 186 L528 186" marker-end="url(#m-ln)"/>
<text class="d-l" x="315" y="180" text-anchor="middle">delete: a hit</text>
<path class="d-e " d="M102 216 L328 216" marker-end="url(#m-ln)"/>
<text class="d-l" x="215" y="210" text-anchor="middle">release</text>
<path class="d-e d-e--acc" d="M102 246 L918 246" marker-end="url(#m-acc)"/>
<text class="d-l" x="510" y="240" text-anchor="middle">publish shot {points, player} · points to player · flyers_shot +1</text>
<path class="d-e " d="M760 276 L736 276 L736 288 L758 288" marker-end="url(#m-ln)"/>
<text class="d-l" x="730" y="285" text-anchor="end">lock acquired</text>
<path class="d-e " d="M758 316 L532 316" marker-end="url(#m-ln)"/>
<text class="d-l" x="645" y="310" text-anchor="middle">get</text>
<path class="d-e d-e--ret" d="M532 346 L758 346" marker-end="url(#m-ln)"/>
<text class="d-l" x="645" y="340" text-anchor="middle">no key</text>
<path class="d-e " d="M760 366 L736 366 L736 378 L758 378" marker-end="url(#m-ln)"/>
<text class="d-l" x="730" y="375" text-anchor="end">miss: {ok: true, hit: false}</text>
<rect class="d-note d-note--ok" x="30" y="398" width="940" height="24" rx="4"/>
<text class="d-n" x="500" y="415" text-anchor="middle">a flyer is shot exactly once: the first under the lock deletes the key; the ticker counts escapes the same way</text>
<rect class="d-box g-php" x="20" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="100" y="34" text-anchor="middle">RPC shoot</text>
<text class="d-s" x="100" y="49" text-anchor="middle">player 1, first</text>
<rect class="d-box g-rr" x="260" y="16" width="140" height="44" rx="6"/>
<text class="d-t" x="330" y="34" text-anchor="middle">RR lock</text>
<text class="d-m" x="330" y="49" text-anchor="middle">flyer:{id}</text>
<rect class="d-box g-rr" x="455" y="16" width="150" height="44" rx="6"/>
<text class="d-t" x="530" y="34" text-anchor="middle">RR kv</text>
<text class="d-m" x="530" y="49" text-anchor="middle">hellowin:flyer:{id}</text>
<rect class="d-box g-php" x="680" y="16" width="160" height="44" rx="6"/>
<text class="d-t" x="760" y="34" text-anchor="middle">RPC shoot</text>
<text class="d-s" x="760" y="49" text-anchor="middle">player 2, next</text>
<rect class="d-box g-cf" x="860" y="16" width="120" height="44" rx="6"/>
<text class="d-t" x="920" y="34" text-anchor="middle">Centrifugo</text>
<text class="d-s" x="920" y="49" text-anchor="middle">channel hellowin</text>
</svg>
                </div>
                <figcaption>Orange: the worker holds the lock; dashed frame: it waits for it.</figcaption>
            </figure>

            <h3 id="reveal">The reveal: the evening's numbers</h3>
            <p>
                At the finale step where the owl reveals the truth, the <code>finale</code> event and the snapshot carry
                <code>reveal</code>, the round's real numbers. There are no separate per-click counters for this: everything
                follows from the state in Valkey, which is written under locks anyway. <code>RevealBuilder</code> recomputes them
                on every build.
            </p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Number</th><th>Source</th><th>Why this way</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>clicks</code>: processed clicks</td><td>Σ <code>accepted</code> over <code>hellowin:pumpkin:{id}</code></td><td><code>accepted</code> grows under the same lock as <code>clicks</code> but isn't zeroed on a transition</td></tr>
                    <tr><td><code>jobs</code>: jobs that went through the queue</td><td>Σ <code>stage</code> + <code>scene</code></td><td>every stage and every scene is exactly one executed transition job</td></tr>
                    <tr><td><code>burned</code>: burned clicks</td><td><code>clicks</code> − Σ <code>spent</code> − Σ min(pumpkin's <code>clicks</code>, threshold)</td><td>a transition spends exactly the threshold in force when it ran and adds it to the pumpkin's <code>spent</code>; the rest was dropped by idempotency or came after the pumpkin was ready, except clicks still piling up toward a transition</td></tr>
                    <tr><td><code>shot</code>: downed flyers and zombies</td><td><code>hellowin:shot</code>: <code>{round, count}</code> under the <code>shot</code> lock</td><td>the only separate counter: shots are rare, the lock doesn't get in the way; another round's count reads as 0</td></tr>
                    <tr><td><code>drinks</code>, <code>slain</code>, <code>damage</code>: the evening's mugs, zombies and strikes on FrankenPHP</td><td><code>hellowin:drinks</code>, <code>hellowin:cursed</code>, <code>hellowin:boss</code>, each with <code>round</code></td><td>the bar, cursed world and boss counters already exist for the game; another round's record reads as empty</td></tr>
                    <tr><td><code>lastPumpkin</code>: who finished the last pumpkin</td><td><code>lastPumpkin</code> in <code>hellowin:story</code></td><td>the player from the job that marked the scene ready; <code>null</code> if the click was anonymous or the scene was switched with <code>force</code></td></tr>
                    </tbody>
                </table>
            </div>
            <p>
                A round reset zeroes these numbers without dedicated code: <code>accepted</code>, <code>stage</code>,
                <code>scene</code> and the story are reset along with the ritual, and the shot count is tied to the round.
            </p>
        </section>

        <section class="how__section" id="metrics" role="tabpanel" aria-labelledby="tab-metrics">
            <h2>Metrics</h2>
            <p>
                When a worker starts, <code>HellowinBootloader</code> declares the collectors in the <code>metrics</code> plugin and
                sets the gauges from the state in Valkey, which survives a RoadRunner restart. After that,
                <code>RitualMetrics</code> calls <code>metrics.Add</code> and <code>metrics.Set</code> over RPC from the click,
                job push, transition and reset, and <code>PlayMetrics</code> from the shot and the ticker (flyers, characters,
                players). A metrics error is only logged: a click never fails because of it.
                RoadRunner serves everything on <code>:2112/metrics</code> along with its own <code>rr_http_*</code> (the
                <code>http_metrics</code> middleware in <code>.rr.yaml</code>; also there is <code>gzip</code>, which compresses both PHP
                responses and static files; it sits before <code>static</code> because RoadRunner 3 runs middleware left
                to right).
            </p>
            <p>Here is how it looks in code, on the RPC metrics from the <a href="#click">“Click”</a> tab: collectors are declared once, values are written as things happen:</p>
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
                Every 5 seconds Prometheus scrapes <code>app:2112</code> and <code>centrifugo:9000/metrics</code> (Centrifugo's internal
                port: only the WebSocket on 8000 is exposed).
                Grafana gets the datasource and the “Hellowin” dashboard through provisioning and lets visitors in anonymously as Viewer:
                anyone can look, nobody can edit.
            </p>
            <figure class="diagram">
                <div class="diagram__scroll">
<svg class="diagram__svg" viewBox="0 0 1000 330" style="min-width: 720px" role="img" aria-label="The path of a metric: RitualMetrics, from ClickHandler, TransitionQueue, TransitionHandler, ResetService and HellowinBootloader, calls metrics.Add and metrics.Set over RPC into the RoadRunner metrics plugin; every 5 seconds Prometheus pulls /metrics from :2112 and from Centrifugo :9000; Grafana builds the dashboard with PromQL queries to Prometheus, and the browser opens it on port :3000.">
<defs><marker id="h-ln" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-lns" class="d-mk d-mk--ln" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-acc" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-accs" class="d-mk d-mk--acc" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-ok" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-oks" class="d-mk d-mk--ok" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-kv" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker><marker id="h-kvs" class="d-mk d-mk--kv" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z"/></marker></defs>
<rect class="d-box g-php" x="20" y="50" width="230" height="150" rx="6"/>
<text class="d-t" x="135" y="83.5" text-anchor="middle">RitualMetrics</text>
<text class="d-m" x="135" y="98.5" text-anchor="middle">ClickHandler: clicks</text>
<text class="d-m" x="135" y="113.5" text-anchor="middle">TransitionQueue: pushed</text>
<text class="d-m" x="135" y="128.5" text-anchor="middle">TransitionHandler:</text>
<text class="d-m" x="135" y="143.5" text-anchor="middle">transitions + gauges</text>
<text class="d-m" x="135" y="158.5" text-anchor="middle">boot, ResetService:</text>
<text class="d-m" x="135" y="173.5" text-anchor="middle">gauges from Valkey</text>
<rect class="d-box g-rr" x="340" y="85" width="190" height="80" rx="6"/>
<text class="d-t" x="435" y="113.5" text-anchor="middle">RR metrics</text>
<text class="d-m" x="435" y="128.5" text-anchor="middle">:2112 /metrics</text>
<text class="d-m" x="435" y="143.5" text-anchor="middle">+ rr_http_*</text>
<rect class="d-box g-obs" x="610" y="85" width="150" height="80" rx="6"/>
<text class="d-t" x="685" y="113.5" text-anchor="middle">Prometheus</text>
<text class="d-m" x="685" y="128.5" text-anchor="middle">:9090</text>
<text class="d-s" x="685" y="143.5" text-anchor="middle">keeps 15d</text>
<rect class="d-box g-obs" x="830" y="85" width="150" height="80" rx="6"/>
<text class="d-t" x="905" y="113.5" text-anchor="middle">Grafana</text>
<text class="d-m" x="905" y="128.5" text-anchor="middle">:3000</text>
<text class="d-s" x="905" y="143.5" text-anchor="middle">“Hellowin” board</text>
<rect class="d-box g-cf" x="340" y="240" width="190" height="60" rx="6"/>
<text class="d-t" x="435" y="266" text-anchor="middle">Centrifugo</text>
<text class="d-m" x="435" y="281" text-anchor="middle">:9000/metrics</text>
<rect class="d-box g-edge" x="830" y="240" width="150" height="60" rx="6"/>
<text class="d-t" x="905" y="266" text-anchor="middle">Browser</text>
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
<text class="d-s" x="20" y="26" text-anchor="start">pull model: Prometheus comes for the metrics itself, the app pushes them nowhere</text>
</svg>
                </div>
                <figcaption>Arrows show who initiates the request; on the scrape arrows the data flows back.</figcaption>
            </figure>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr><th>Metric</th><th>Source</th><th>Meaning</th></tr>
                    </thead>
                    <tbody>
                    <tr><td><code>hellowin_clicks_total{type, element}</code></td><td>ClickHandler</td><td>counter: accepted clicks</td></tr>
                    <tr><td><code>hellowin_transition_jobs_pushed_total{kind}</code></td><td>TransitionQueue</td><td>counter: transition jobs pushed to the queue, duplicates included; <code>kind</code> is <code>pumpkin</code> or <code>scene</code></td></tr>
                    <tr><td><code>hellowin_transitions_total{kind}</code></td><td>TransitionHandler</td><td>counter: transitions actually executed</td></tr>
                    <tr><td><code>hellowin_scene_stage</code></td><td>TransitionHandler, startup, reset</td><td>gauge: current scene</td></tr>
                    <tr><td><code>hellowin_round</code></td><td>startup, reset</td><td>gauge: current round</td></tr>
                    <tr><td><code>hellowin_pumpkin_stage{element}</code></td><td>TransitionHandler, startup, reset</td><td>gauge: stage of each pumpkin</td></tr>
                    <tr><td><code>hellowin_flyers_launched_total{depth}</code>, <code>hellowin_flyers_shot_total{depth}</code>, <code>hellowin_flyers_escaped_total{depth}</code></td><td>ticker, RPC <code>shoot</code></td><td>counter: flyers (and zombies) launched, shot down, and escaped unshot</td></tr>
                    <tr><td><code>hellowin_walkers</code></td><td>ticker, every second</td><td>gauge: characters on the lawn</td></tr>
                    <tr><td><code>hellowin_players</code>, <code>hellowin_players_registered</code></td><td>ticker, player count <code>PlayerCensus</code></td><td>gauge: known players and players with a name</td></tr>
                    <tr><td><code>hellowin_online_players</code>, <code>hellowin_online_connections</code></td><td><code>WalkService</code> ticker, Centrifugo channel presence</td><td>gauge: who is online now, unique players and all connections including anonymous ones; the moderator sees the same number on the control panel</td></tr>
                    <tr><td><code>hellowin_rpc_calls_total{method, result}</code></td><td>RPCService</td><td>counter: every RPC over the WebSocket; <code>method</code> is the method name or <code>unknown</code>, <code>result</code> is <code>ok</code> or an error code</td></tr>
                    <tr><td><code>hellowin_rpc_duration_seconds{method}</code></td><td>RPCService</td><td>histogram: how long PHP took to handle the RPC</td></tr>
                    <tr><td><code>hellowin_rpc_rate_limited_total{group}</code></td><td>RPCService</td><td>counter: calls rejected by the limit (<code>rate limited</code>), by bucket group</td></tr>
                    <tr><td><code>hellowin_beer_mugs_total{place}</code></td><td>BarService</td><td>counter: mugs drunk; <code>place</code> is <code>bar</code> or <code>cursed</code> (the cursed world)</td></tr>
                    <tr><td><code>hellowin_beer_litres</code></td><td>ticker, player count <code>PlayerCensus</code></td><td>gauge: litres drunk over the evening, players' mugs × <code>HELLOWIN_MUG_LITRES</code></td></tr>
                    <tr><td><code>rr_http_request_total</code>, <code>rr_http_request_duration_seconds</code>, <code>rr_http_workers_*</code></td><td>RoadRunner http</td><td>HTTP requests, latency, workers</td></tr>
                    <tr><td><code>centrifugo_node_num_clients</code>, <code>centrifugo_transport_messages_sent</code>, <code>centrifugo_proxy_duration_seconds_histogram</code></td><td>Centrifugo</td><td>connections, messages sent, latency of proxy calls to RoadRunner</td></tr>
                    </tbody>
                </table>
            </div>
            <p>
                Counters live in the RoadRunner process and reset when it restarts; gauges are restored from
                Valkey. Dashboard sections: <strong>Game</strong>: clicks, scene, round, online;
                <strong>Transitions: queue and idempotency</strong>: jobs versus executed transitions, dropped
                duplicates, pumpkin stages; <strong>Centrifugo</strong>: connections, messages, proxy calls;
                <strong>RoadRunner</strong>: HTTP and workers.
            </p>
            <p>Queries from the dashboard, for example:</p>
            <pre><code># clicks per second, total and per pumpkin
sum(rate(hellowin_clicks_total[1m]))
sum by (element) (rate(hellowin_clicks_total[1m]))

# dropped duplicates: jobs in the queue minus executed transitions
sum by (kind) (hellowin_transition_jobs_pushed_total) - sum by (kind) (hellowin_transitions_total)

# p95 latency of Centrifugo → RoadRunner proxy calls
histogram_quantile(0.95, sum by (le, type) (rate(centrifugo_proxy_duration_seconds_histogram_bucket[1m])))</code></pre>
            <p><a href="{{ $grafanaUrl }}">Open the dashboard in Grafana</a> · <a href="/">Back to the pumpkins</a></p>
        </section>
    </main>
</define:body>
