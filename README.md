# Hellowin

**English** · [Русский](README.ru.md)

![preview.png](public/preview.png)

**Hellowin** is a multiplayer pixel-art Halloween quest about resurrecting RoadRunner. Every player makes a little
character, and all the characters walk around one shared scene, so everyone sees what the others do in real time.
The backend is PHP on Spiral Framework, RoadRunner 3 and Centrifugo. The project shows the machinery at work:
channels, RPC over WebSocket, KV, locks, a queue, idempotency, and metrics on a public Grafana dashboard.

## The event

When RoadRunner 3 came out, rumors started that it was the end of the project. Hellowin answers them with a Halloween
ritual.

- **The bar.** The evening starts in a viking bar. The owl behind the counter pours beer, passes on rumors and swears
  RoadRunner can be brought back. Players grab mugs, clink them (no clink, no drink), drink and swat flies.
- **The ritual.** On October 31, 2026 at 16:00 UTC the pumpkin patch opened, and the whole crowd went through the story
  live. A game master played the owl and switched the scenes: day, sunset, night, blood moon. Players clicked
  pumpkins, lit and blew up the bonfire, drew the magic circle and rolled dice until someone got 6-6-6.
- **The twist.** RoadRunner was alive all along: this whole world runs on it, and it served every click. In the finale
  the owl shows the real numbers of the evening. The ritual, meanwhile, raised FrankenPHP from the ground, and the
  crowd beat it with a magic word and a rapier.
- **Autopilot.** After the live event the game ran by itself for a week, on a loop with no game master: a scene ends as
  soon as its goal is met.

All of it ran on one small box with 2 vCPUs and 2 GB of RAM.

▶️ [The recording of the live event](https://www.youtube.com/watch?v=nXmEKQdV-70) (YouTube).

## How it works

A walkthrough with diagrams and code. It used to be a page of the site:

1. [RoadRunner: PHP that does not die](docs/how-it-works/en/01-roadrunner.md)
2. [Who talks to whom](docs/how-it-works/en/02-modules.md)
3. [Page load and connection](docs/how-it-works/en/03-page-load.md)
4. [What happens on a click](docs/how-it-works/en/04-click.md)
5. [Scenes, pumpkin stages and locks](docs/how-it-works/en/05-stages.md)
6. [Idempotent transition](docs/how-it-works/en/06-idempotency.md)
7. [Dice: idempotency with a stored response](docs/how-it-works/en/07-dice.md)
8. [Moderator, characters, flyers](docs/how-it-works/en/08-live.md)
9. [Metrics](docs/how-it-works/en/09-metrics.md)

Context for agents is in [`AGENTS.md`](AGENTS.md), and the specs (in Russian) are in [`docs/spec/`](docs/spec/).

## Running

Everything starts with Docker Compose. RoadRunner itself serves the pages and static files, with no nginx. Centrifugo
and Grafana are published on their own ports:

| URL | What |
|---|---|
| `http://localhost:8080/` | the Hellowin page (RoadRunner) |
| `ws://localhost:8000/connection/websocket` | Centrifugo WebSocket |
| `http://localhost:3000/` | the «Hellowin» dashboard (public, read-only) |

1. A local run needs no setup. Compose variables (see below) come from `.env`; the example is `.env.docker.example`.
   The app mode in the container does not depend on `.env`: `docker-compose.yml` always sets `APP_ENV=prod`,
   `DEBUG=false`.

2. Start it:

   ```bash
   docker compose up -d --build
   docker compose ps          # every service should become healthy
   ```

3. Open `http://localhost:8080/` and `http://localhost:3000/`.

To stop: `docker compose down`. The Valkey, Prometheus and Grafana data stay in volumes; `docker compose down -v`
removes them too.

The ritual (round, scene, pumpkin stages and clicks) is stored in Valkey (the `valkey` service, AOF on a volume) and
survives restarts of `app` and `valkey`. The rules are in [`docs/spec/scenario.md`](docs/spec/scenario.md). To start
the ritual over: `docker compose exec app php app.php hellowin:reset`. To go through the ritual quickly, restart `app`
with small numbers: `HELLOWIN_PUMPKIN_BASE=1 HELLOWIN_PUMPKIN_PER_PLAYER=0 HELLOWIN_SCENE_STAGE_MAX=0,1,2 docker compose up -d app`,
and after the check, `docker compose up -d app`. Keys in Valkey: `docker compose exec valkey valkey-cli --scan`.

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `GF_SECURITY_ADMIN_PASSWORD` | `admin` | Grafana admin password, applied on the first start. Always set it for a public deployment |
| `GF_SECURITY_ADMIN_USER` | `admin` | Grafana admin login |
| `HTTP_PORT` | `8080` | external port of the site (RoadRunner http) |
| `CENTRIFUGO_PORT` | `8000` | external port of the Centrifugo WebSocket |
| `GRAFANA_PORT` | `3000` | external port of Grafana |
| `GRAFANA_ROOT_URL` | `http://localhost:3000/` | external address of Grafana; it builds its links from it |
| `CENTRIFUGO_ALLOWED_ORIGINS` | `http://localhost:8080 http://127.0.0.1:8080` | space-separated origins the browser may connect to the WebSocket from |
| `HELLOWIN_PUMPKIN_BASE`, `HELLOWIN_PUMPKIN_PER_PLAYER` | `2`, `5` | game limits: each is `max(1, ceil(k × (BASE + PER_PLAYER × (n − 1))))` plus the moderator's bonus, where n is the characters on the field (at least 1) and `k` is the moderator's multiplier; fractions are allowed. Here: clicks per pumpkin stage |
| `HELLOWIN_BONFIRE_BASE`, `HELLOWIN_BONFIRE_PER_PLAYER` | `8`, `20` | clicks per bonfire stage (same formula) |
| `HELLOWIN_CIRCLE_BASE`, `HELLOWIN_CIRCLE_PER_PLAYER` | `15`, `40` | clicks per stage of the final scene's magic circle |
| `HELLOWIN_BAR_BASE`, `HELLOWIN_BAR_PER_PLAYER` | `5`, `1.5` | the bar goal: mugs the room drinks before heading out to the patch; the moderator's bonus lasts until the end of the round |
| `HELLOWIN_CURSED_DRINKS_BASE`, `HELLOWIN_CURSED_DRINKS_PER_PLAYER` | `10`, `8` | the cursed world goal: mugs to drink |
| `HELLOWIN_CURSED_ZOMBIES_BASE`, `HELLOWIN_CURSED_ZOMBIES_PER_PLAYER` | `15`, `12` | the cursed world goal: zombies to shoot down |
| `HELLOWIN_BOSS_HP_BASE`, `HELLOWIN_BOSS_HP_PER_PLAYER` | `45`, `60` | FrankenPHP's health; damage is kept when the limit changes |
| `HELLOWIN_MODERATOR_SECRET` | empty | the moderator secret: a page opened with `?moderator=<secret>` gets the control panel (scenes, owl, finale, reset); empty turns the panel off |
| `HELLOWIN_SCENE_STAGE_MAX` | `0,4,8,11` | last pumpkin stage per scene, comma-separated, cumulative and ascending; the list length is the number of the final scene; a leading 0 makes scene 0 the bar (no pumpkins; ready when the room has drunk the bar goal (limit `bar`) or on the moderator's cue) |
| `HELLOWIN_BONFIRE_STAGE_MAX` | `0,0,4,7` | last bonfire stage per scene, comma-separated, cumulative, non-decreasing; a value that does not raise the bonfire means the scene is ready on pumpkins alone |
| `HELLOWIN_CIRCLE_STAGES` | `4` | last stage of the circle, an integer > 0: at it the circle is closed and the final scene is ready for finale step 1 |
| `HELLOWIN_BAR_MUGS` | `60` | room on the bar counter (scene 0): the owl pours a mug per player on the field, but no more than this; integer > 0 |
| `HELLOWIN_BAR_POUR_MS` | `2500` | milliseconds the owl takes to pour one batch of mugs |
| `HELLOWIN_BAR_POUR_PER` | `10` | free counter places per mug of a batch: up to this many free places the owl pours one at a time, up to twice as many two at a time, and so on; integer > 0 |
| `HELLOWIN_BAR_RUMOR_CHANCE` | `0.33` | chance that the owl passes on a rumor as she starts pouring a mug |
| `HELLOWIN_DRINK_MS` | `1200` | milliseconds a sip lasts (bar and cursed world): until it ends the server allows neither drinking nor taking a new mug |
| `HELLOWIN_MUG_LITRES` | `0.5` | litres per mug for the «Beer drunk» stat in Grafana; a number > 0 |
| `HELLOWIN_BEER_HANDOVER_MS` | `900` | milliseconds a pumpkin takes to hand beer to whoever walked up: until the handover ends the mug cannot be drunk |
| `HELLOWIN_FLYER_INTERVAL` | `3000-8000` | pause between flyer launches, ms: a range (random within) or a number |
| `HELLOWIN_FLYER_DURATION` | `16000,6500,4000` | flight time by depth from the far one (0) to the near one, ms; the list length is the number of depths |
| `HELLOWIN_FLYER_POINTS` | `3,2,1` | points for a hit at the same depths; same length as `HELLOWIN_FLYER_DURATION` |
| `HELLOWIN_FLYER_BUSH_CHANCE` | `0.4` | chance that a flyer takes off from a bush instead of flying in from the edge |
| `HELLOWIN_FLYER_ARC_CHANCE` | `0.5` | chance of an arc-shaped flight |
| `HELLOWIN_FLYER_KINDS` | `fly;duck,chicken;;bat;worm` | flyer kinds per scene starting from the bar (sets separated by `;`); the server picks at random from the current scene's set; an empty set means the scene has no ordinary flyers |
| `HELLOWIN_FLYER_KINDS_CURSED` | `worm,bat,firefly` | flyer kinds of the cursed world (finale, steps 5–6) instead of the scene's set, comma-separated; empty means only the scene's rare flyer |
| `HELLOWIN_FLYER_KINDS_DAWN` | `duck,chicken` | flyer kinds at dawn (finale, step 7) instead of the scene's set, comma-separated |
| `HELLOWIN_FLYER_INTERVAL_DAWN` | `1000-2500` | pause between flyers at dawn, ms: a range or a number (shorter than usual, there are many flyers) |
| `HELLOWIN_FLYER_FIREFLY_POINTS` | `1` | points for a firefly at any depth |
| `HELLOWIN_FLYER_FLY_STOPS` | `2-3` | how many times a fly (the bar's flyer) lands on the tables and the counter: a range or a number |
| `HELLOWIN_FLYER_FLY_SIT` | `900-1600` | ms a fly sits at each landing: a range or a number |
| `HELLOWIN_FLYER_FLY_LEG` | `900` | ms of a fly's hop from point to point |
| `HELLOWIN_FLYER_FLY_POINTS` | `1` | points for a fly |
| `HELLOWIN_FLYER_RARE` | `;;;;` | the rare flyer of each scene starting from the bar (empty means none) |
| `HELLOWIN_FLYER_RARE_CHANCE` | `0.08` | chance that a launch is the scene's rare flyer |
| `HELLOWIN_FLYER_RARE_POINTS` | `10` | points for a rare flyer |
| `HELLOWIN_FLYER_BURST_BASE`, `HELLOWIN_FLYER_BURST_PER_PLAYER` | `5`, `0.2` | a flyer wave on the moderator's button: `ceil(BASE + PER_PLAYER × (n − 1))` flyers of the current set per press |
| `HELLOWIN_ZOMBIE_INTERVAL` | `2000-5000` | pause after which new wandering zombies rise in the cursed world above the minimum, ms: a range or a number |
| `HELLOWIN_ZOMBIE_MIN` | `5` | how many wandering zombies are always alive in the cursed world: kill one and a new one rises at once |
| `HELLOWIN_ZOMBIE_PER_PLAYER` | `5` | up to how many zombies per player on the field the crowd grows, once per `HELLOWIN_ZOMBIE_INTERVAL` (no fewer than `HELLOWIN_ZOMBIE_MIN`) |
| `HELLOWIN_ZOMBIE_POINTS` | `2` | points for a zombie |
| `HELLOWIN_TOP_BOARD` | `200` | how many rows of the shooters' top (points for downed flyers over the evening) the `top` event and the snapshot carry |
| `HELLOWIN_TOP_INTERVAL` | `500` | the ticker sends a changed shooters' top at most once per this many milliseconds |
| `HELLOWIN_CHAT_MAX` | `120` | maximum characters in a player chat message (after trimming whitespace) |
| `HELLOWIN_CHAT_HISTORY` | `50` | how many recent chat messages the moderator's history keeps; survives a reset |
| `HELLOWIN_EMOTES` | `🎃,👻,💀,😂,😱,😍,👍,👏,🔥,🍺,🤘,🙏,🎉` | emoji of the emote panel, comma-separated, in button order; the server accepts no others |
| `HELLOWIN_EMOTE_COOLDOWN_MS` | `1000` | pause between one player's emotes, ms |
| `HELLOWIN_MEEP_COOLDOWN_MS` | `1500` | RoadRunner's «Meep-meep» cooldown shared by everyone, ms: inside the window a click gets `busy` |
| `HELLOWIN_DICE_IDEMPOTENCY_TTL` | `900` | seconds the response to a 6-6-6 dice attempt (finale step 1) is kept for a retry of it: a retry gets the same faces without a new roll |
| `HELLOWIN_DICE_COOLDOWN_MS` | `1800` | pause between one player's new dice rolls, ms (a retry of the same attempt is not subject to it) |
| `HELLOWIN_WALK_SPEED` | `12` | speed of the players' characters, percent of the screen per second along the path |
| `HELLOWIN_WALK_LEAVE_AFTER` | `5000` | milliseconds a player may have no connections before their character leaves the field |
| `HELLOWIN_RPC_RATE_CLICK`, `_SHOOT`, `_MOVE`, `_SAY`, `_OTHER` | `20,40`, `20,40`, `10,20`, `1,3`, `10,30` | per-player RPC limit: `<per second>,<burst>`; `_OTHER` is shared by the remaining methods; 0 means no limit; the moderator is not limited |
| `CENTRIFUGO_WS_URL` | `:${CENTRIFUGO_PORT}/connection/websocket` | WebSocket for the frontend: `:<port>/path` is completed with the page's host, a full URL (`wss://ws.example.com/connection/websocket`) is used as is |
| `GRAFANA_URL` | `:${GRAFANA_PORT}/` | link to the dashboard from the pages, in the same forms |
| `CENTRIFUGO_ADMIN_ENABLED` | `false` | Centrifugo admin UI (also needs `CENTRIFUGO_ADMIN_PASSWORD` and `CENTRIFUGO_ADMIN_SECRET`) |
| `PROMETHEUS_RETENTION` | `15d` | how long metrics are kept |

The app takes the Valkey address from `VALKEY_ADDR` (RoadRunner KV, `host:port`) and `VALKEY_DSN` (the idempotency
storage, `tcp://valkey:6379` by default); in `docker-compose.yml` both point to the `valkey` service.

To open the demo to the internet, open the three ports (or put a TLS terminator in front of them) and set
`CENTRIFUGO_ALLOWED_ORIGINS=https://your.domain`, `GRAFANA_ROOT_URL` and your own `GF_SECURITY_ADMIN_PASSWORD`. Behind a
terminator with other addresses, set `CENTRIFUGO_WS_URL` and `GRAFANA_URL` to full URLs. HTTP headers, compression,
static ETags and the per-IP request limit are in `.rr.yaml`; details are in [`docs/spec/infra.md`](docs/spec/infra.md).

### Grafana

Anonymous access with the Viewer role: any visitor sees the «Hellowin» dashboard but cannot edit anything. The
dashboard and the datasource are set up through provisioning. The admin signs in at `http://localhost:3000/login`; to
change the password later: `docker compose exec grafana grafana cli admin reset-admin-password <new>`.

### Development mode

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -V
```

The image has the dev dependencies, the code is mounted at `/app`, `APP_ENV=local`, `DEBUG=true`. Prometheus is open
on `127.0.0.1:9090`. After changing PHP code: `docker compose exec app rr reset`.

### Tests and checks

```bash
composer install
composer bin all install
composer test     # Testo
composer psalm
composer cs:fix
```
