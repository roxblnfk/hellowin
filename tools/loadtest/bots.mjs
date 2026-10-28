// Bot players for a load run: each connects with its own secret, steps onto the field and every 0.7–2.5 s does what
// the scene allows — takes, clinks and drinks beer in the bar, clicks pumpkins, shoots flyers, walks, chats. Prints
// RPC rate and latency every 15 s, then per method latencies and errors.
//   cd tools/loadtest && npm ci && node bots.mjs [bots=50] [minutes=5] [origin=http://localhost:8080]
// The origin must be in CENTRIFUGO_ALLOWED_ORIGINS; the WebSocket is taken on port 8000 of its host. Bots stay in the
// game's statistics (players "Bot NN"): on a public server, wipe Valkey before the real event.
import { Centrifuge } from 'centrifuge';
import WebSocket from 'ws';
import { randomUUID } from 'node:crypto';

const BOTS = +(process.argv[2] ?? 50);
const MINUTES = +(process.argv[3] ?? 5);
const ORIGIN = process.argv[4] ?? 'http://localhost:8080';
const WS_URL = ORIGIN.replace(/^https:/, 'wss:').replace(/^http:/, 'ws:') + ':8000/connection/websocket';

const pick = (a) => a[Math.floor(Math.random() * a.length)];
const rnd = (a, b) => a + Math.random() * (b - a);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const LOOK = {
    body: ['skeleton', 'witch', 'robe', 'wrestler', 'vampire', 'mummy', 'zombie', 'scarecrow', 'cat', 'robot', 'dino', 'clown'],
    cloak: ['none', 'cape', 'rags', 'poncho', 'wings'],
    face: ['smile', 'fangs', 'stitches', 'skull', 'grin', 'scared', 'cyclops', 'eyepatch'],
    eyes: ['amber', 'green', 'red', 'blue', 'violet'],
    hat: ['pumpkin', 'witch', 'hood', 'top-hat', 'horns', 'cat-ears', 'tricorn', 'mohawk', 'valera'],
};
const PHRASES = ['Мип-мип!', 'Где RoadRunner?', 'Ещё пива!', 'Бу!', 'За PHP!', 'Тыквы, просыпайтесь', 'gc_collect_cycles()'];

// Stats: per method calls, errors by code, latencies
const stats = { calls: {}, errors: {}, lat: {}, events: 0, connected: 0, disconnects: 0 };
const note = (m, ms, err) => {
    stats.calls[m] = (stats.calls[m] ?? 0) + 1;
    (stats.lat[m] ??= []).push(ms);
    if (err) stats.errors[`${m}:${err}`] = (stats.errors[`${m}:${err}`] ?? 0) + 1;
};
const pct = (a, p) => (a.length ? [...a].sort((x, y) => x - y)[Math.min(a.length - 1, Math.floor(a.length * p))] : 0);

class Bot {
    constructor(n) {
        this.n = n;
        this.secret = randomUUID() + '-loadtest';
        this.state = null;
        this.flyers = new Map();
        this.beer = false;
        this.me = null;
    }

    async rpc(method, data = {}) {
        const t = performance.now();
        try {
            const res = (await this.c.rpc(method, data)).data;
            note(method, performance.now() - t, res?.ok === false ? res.error : null);
            return res;
        } catch (e) {
            note(method, performance.now() - t, `exception ${e?.code ?? e?.message ?? e}`);
            return null;
        }
    }

    async start() {
        this.c = new Centrifuge(WS_URL, {
            websocket: class extends WebSocket { constructor(url, p) { super(url, p, { headers: { Origin: ORIGIN } })} },
            data: { playerSecret: this.secret },
        });
        this.c.on('connected', () => stats.connected++);
        this.c.on('disconnected', () => { stats.connected--; stats.disconnects++; });
        this.c.on('publication', (ctx) => this.onEvent(ctx.data));
        this.c.connect();
        await this.c.ready(15000);
        const reg = await this.rpc('register', {
            name: `Bot ${String(this.n).padStart(2, '0')}`,
            look: Object.fromEntries(Object.entries(LOOK).map(([k, v]) => [k, pick(v)])),
        });
        this.me = reg?.player?.id;
        await this.refresh();
    }

    async refresh() {
        const s = await this.rpc('state');
        if (s?.ok) {
            this.state = s.state;
            const mine = this.state.walkers?.find((w) => w.id === this.me);
            this.beer = mine?.item === 'beer';
            this.rapier = mine?.item === 'rapier';
            this.zombie = !!mine?.zombie;
            this.zombies = new Set((this.state.walkers ?? []).filter((w) => w.zombie).map((w) => w.id));
        }
    }

    onEvent(e) {
        stats.events++;
        if (!e || !this.state) return;
        if (e.type === 'flyer') this.flyers.set(e.flyer, e.expiresAt ?? Date.now() + 5000);
        if (e.type === 'shot') this.flyers.delete(e.flyer);
        if (e.type === 'scene' || e.type === 'reset' || e.type === 'finale') this.refresh();
        if (e.type === 'take' && e.player?.id === this.me) this.beer = !!e.ok;
        if (e.type === 'drink' && e.player?.id === this.me) { this.beer = false; this.clinked = false; }
        if (e.type === 'word') {
            if (!e.ok) this.zombies.add(e.player?.id);
            if (e.player?.id === this.me) { this.rapier = !!e.ok; this.zombie = !e.ok; }
        }
        if (e.type === 'cure') {
            this.zombies.delete(e.player?.id);
            if (e.player?.id === this.me) this.zombie = false;
        }
        if (e.type === 'roll' && !e.owl) this.attempt = Math.max(this.attempt ?? 0, e.player?.id === this.me ? e.attempt : 0);
    }

    // The bar and the cursed world: get a mug, clink, drink after the clink has played
    async beerRound(s, now, get) {
        if (!this.beer) return get();
        const others = (s.walkers ?? []).filter((w) => w.id !== this.me && w.item === 'beer' && !w.zombie);
        if (!this.clinked && others.length) {
            const r = await this.rpc('clink', { walker: pick(others).id });
            if (r?.ok) { this.clinked = true; this.drinkAt = now + 3500; }
        } else if (now >= (this.drinkAt ?? 0)) {
            const r = await this.rpc('drink');
            if (r?.ok) { this.beer = false; this.clinked = false; }
            if (r?.error === 'not clinked') this.clinked = false;
        }
    }

    // Steps of the finale; false when the step has nothing of its own and the bot does the usual things
    async finaleAct(s, now) {
        const step = s.finale?.step;
        if (step === 1) {
            const r = await this.rpc('roll', { attempt: (this.attempt ?? 0) + 1 });
            if (r?.ok) this.attempt = r.attempt;
            else if (r?.error === 'bad attempt') this.attempt = r.last ?? 0;
            return true;
        }
        if (step === 5 && Math.random() < 0.6) {
            const pumpkins = (s.elements ?? []).filter((e) => e.type === 'pumpkin');
            await this.beerRound(s, now, () => pumpkins.length && this.rpc('beer', { pumpkin: pick(pumpkins).id }));
            return true;
        }
        if (step === 6) {
            if (this.zombie) return true; // the server walks a zombie
            const zombies = [...(this.zombies ?? [])].filter((id) => id !== this.me);
            if (zombies.length && Math.random() < 0.4) { await this.rpc('cure', { walker: pick(zombies) }); return true; }
            if (this.rapier) {
                for (let i = 0; i < 1 + Math.floor(Math.random() * 3); i++) await this.rpc('strike');
                return true;
            }
            if (Math.random() < 0.5) {
                const r = await this.rpc('word', { text: Math.random() < 0.7 ? pick(['рапира', 'Рапиру!', 'rapira', 'рапиры']) : pick(['пиво', 'шпага', 'roadrunner']) });
                if (r?.ok) { this.rapier = r.correct; this.zombie = !r.correct; }
                if (r?.error === 'has rapier') this.rapier = true;
                if (r?.error === 'zombified') this.zombie = true;
                return true;
            }
        }
        return false;
    }

    async act() {
        const s = this.state;
        if (!s) return;
        const now = Date.now();
        for (const [id, exp] of this.flyers) if (exp < now) this.flyers.delete(id);

        const roll = Math.random();
        if (this.zombie && s.finale?.step === 6) return;
        if (this.flyers.size && roll < 0.35) {
            // Flyers, and the wandering zombies of the cursed world
            await this.rpc('shoot', { flyer: pick([...this.flyers.keys()]) });
        } else if (s.finale && await this.finaleAct(s, now)) {
            // done
        } else if (s.scene === 0 && roll < 0.75) {
            await this.beerRound(s, now, () => this.rpc('bar.take'));
        } else if (s.scene > 0 && roll < 0.75) {
            const els = (s.elements ?? []).filter((e) => e.type === 'pumpkin' || e.type === 'bonfire' || e.type === 'circle');
            if (els.length) for (let i = 0; i < 1 + Math.floor(Math.random() * 3); i++) await this.rpc('click', { element: pick(els).id });
        } else if (roll < 0.88) {
            await this.rpc('move', { x: rnd(5, 95), y: rnd(55, 95) });
        } else if (roll < 0.97 && s.emotes?.length) {
            await this.rpc('emote', { emoji: pick(s.emotes) });
        } else {
            await this.rpc('say', { text: pick(PHRASES) });
        }
    }

    async loop(until) {
        let lastRefresh = Date.now();
        while (Date.now() < until) {
            await this.act();
            if (Date.now() - lastRefresh > rnd(15000, 25000)) { await this.refresh(); lastRefresh = Date.now(); }
            await sleep(rnd(700, 2500));
        }
        this.c.disconnect();
    }
}

const until = Date.now() + MINUTES * 60_000;
const bots = [];
console.log(`${BOTS} bots → ${WS_URL} for ${MINUTES} min`);
for (let i = 1; i <= BOTS; i++) {
    const b = new Bot(i);
    bots.push(b.start().then(() => b.loop(until)).catch((e) => console.log(`bot ${i} failed: ${e?.message ?? e}`)));
    await sleep(150); // a crowd arriving, not a single burst
}

let window = { calls: 0, t: Date.now() };
const report = () => {
    const total = Object.values(stats.calls).reduce((a, b) => a + b, 0);
    const rps = ((total - window.calls) / ((Date.now() - window.t) / 1000)).toFixed(1);
    window = { calls: total, t: Date.now() };
    const all = Object.values(stats.lat).flat();
    console.log(`[${new Date().toISOString().slice(11, 19)}] online=${stats.connected} rpc/s=${rps} total=${total} p50=${pct(all, 0.5).toFixed(0)}ms p95=${pct(all, 0.95).toFixed(0)}ms p99=${pct(all, 0.99).toFixed(0)}ms events=${stats.events} disconnects=${stats.disconnects}`);
};
const timer = setInterval(report, 15000);
await Promise.all(bots);
clearInterval(timer);
report();
console.log('\nper method: calls, p50 / p95 / max ms');
for (const [m, a] of Object.entries(stats.lat).sort()) console.log(`  ${m.padEnd(10)} ${String(a.length).padStart(6)}  ${pct(a, 0.5).toFixed(0)} / ${pct(a, 0.95).toFixed(0)} / ${Math.max(...a).toFixed(0)}`);
console.log('\nerrors:');
for (const [k, v] of Object.entries(stats.errors).sort((a, b) => b[1] - a[1])) console.log(`  ${k}: ${v}`);
process.exit(0);
