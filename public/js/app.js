import { bindAbility, bindBar, bindClinking, bindDice, bindMeep, bindObjectKeys, bindShooting, bindStriking, bindWalking, bindWord, createClient, readSnapshot } from './client.js';
import { initDebugPanel } from './debug.js';
import { bindChat } from './chat.js';
import { bindEmotes } from './emote.js';
import { createOwl } from './owl.js';
import { refusal } from './toasts.js';
import { createReveal } from './reveal.js';

const root = document.getElementById('app');
const SECRET_KEY = 'hellowin.secret';

/**
 * The player's secret (players.md, «Идентификация»): generated once, kept in localStorage, sent only in the
 * connect data. Without storage it lives for this page only, which still gives a working, if forgetful, player.
 */
function playerSecret() {
    const make = () => crypto.randomUUID?.() ?? [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('');
    try {
        let secret = localStorage.getItem(SECRET_KEY);
        if (!secret || !/^[A-Za-z0-9-]{16,128}$/.test(secret)) {
            secret = make();
            localStorage.setItem(SECRET_KEY, secret);
        }
        return secret;
    } catch {
        return make();
    }
}

const MODERATOR_KEY = 'hellowin.moderator';
// One browser's meeps are no closer than this; a click inside it sends nothing.
const MEEP_GAP_MS = 1000;

/**
 * The moderator's secret (protocol.md, «Подключение», item 5): `?moderator=<secret>` is moved into localStorage and
 * taken off the address, so it does not end up in a shared link or a screenshot. Without storage it lives for this
 * page only. A wrong secret is no error: the connection is simply not the moderator's.
 */
function moderatorSecret() {
    const url = new URL(location.href);
    const param = url.searchParams.get('moderator');
    if (param !== null) {
        url.searchParams.delete('moderator');
        history.replaceState(history.state, '', url);
    }
    try {
        if (param) localStorage.setItem(MODERATOR_KEY, param);
        if (param === '') localStorage.removeItem(MODERATOR_KEY);
        return localStorage.getItem(MODERATOR_KEY) || null;
    } catch {
        return param || null;
    }
}

// Unwraps an RPC reply: {ok: true, …} resolves with the data, {ok: false, error} rejects with Error(error).
const answer = ({ data }) => {
    if (!data?.ok) throw new Error(data?.error ?? 'bad response');
    return data;
};

function websocketUrl(url) {
    if (/^wss?:\/\//.test(url)) {
        return url;
    }
    const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${scheme}//${location.host}${url.startsWith('/') ? '' : '/'}${url}`;
}

// Imported lazily: without the Pixi CDN or WebGL the page keeps working with the emoji buttons.
async function startScene(snapshot) {
    try {
        const { createScene } = await import('./pixel/scene.js');
        return await createScene(root, snapshot);
    } catch (error) {
        console.warn('pixel scene failed to start', error);
        return null;
    }
}

// Lazy like the scene: without Web Audio or the engine's modules the page plays silently.
async function startSound(modules, scene, serverNow) {
    try {
        const [{ createAudio }, { bindSoundControls, bindUiClicks, createSound }] = await modules;
        const audio = createAudio();
        audio.unlockOn(document);
        bindSoundControls(root, audio);
        bindUiClicks(root, audio);
        return createSound(audio, {
            serverNow,
            owlGone: () => Boolean(scene?.owlGone?.()),
            // The owl's rolls sound where it sits, a meep where RoadRunner stands (silent when he is off screen).
            owlX: () => {
                const perch = scene?.perch?.();
                return perch && root.clientWidth ? (perch.x / root.clientWidth) * 100 : null;
            },
            runnerAt: () => scene?.runnerAt?.() ?? null,
        });
    } catch (error) {
        console.warn('sound failed to start', error);
        return null;
    }
}

async function main() {
    // Fetched alongside the scene's modules, not after them.
    const soundModules = Promise.all([import('./audio/engine.js'), import('./sound.js')]);
    soundModules.catch(() => {});
    const scene = await startScene(readSnapshot(root));
    root.classList.add(scene ? 'has-canvas' : 'no-canvas');
    let client = null;
    const sound = await startSound(soundModules, scene, () => client?.serverNow() ?? Date.now());
    const owl = createOwl(root, scene, { onShow: (line) => sound?.owl(line), onHide: () => sound?.owlHush() });
    // The panel's fresh numbers come over the connection made below; until then (and without one) it keeps its own.
    let requestReveal = () => Promise.reject(new Error('no connection'));
    const reveal = createReveal(root, { request: () => requestReveal(), onToggle: () => owl.place() });

    if (typeof Centrifuge === 'undefined') {
        scene?.onCue?.((cue) => sound?.cue(cue));
        createClient(root, scene, { requestState: () => Promise.reject(new Error('no centrifuge')), owl, reveal, sound }).start();
        return;
    }

    const secret = moderatorSecret();
    const data = secret ? { playerSecret: playerSecret(), moderatorSecret: secret } : { playerSecret: playerSecret() };
    const centrifuge = new Centrifuge(websocketUrl(root.dataset.centrifugoUrl), { data });
    requestReveal = () => centrifuge.rpc('reveal', {}).then(answer).then((reply) => reply.reveal);
    // The console's buttons draw nothing either: their effects come back as channel events.
    const moderatorConsole = initDebugPanel(root.querySelector('[data-debug-panel]'), {
        call: (method, params) => centrifuge.rpc(method, params).then(answer),
        // Spaced out so the clicks do not contend for the server's lock and come back as `busy`.
        clickAll: () => client.model.elements.forEach((e, i) => setTimeout(() => click(e.id), i * 30)),
        serverNow: () => client?.serverNow() ?? Date.now(),
    });
    // The server's clock for the offset measurement: RPC `time` is cheap; a server without it answers
    // `unknown method`, and then the full `state` snapshot carries the time instead.
    let timeRpc = true;
    const stateTime = () => centrifuge.rpc('state', {}).then(answer).then((reply) => reply.state.now);
    const requestTime = () => (timeRpc
        ? centrifuge.rpc('time', {}).then(answer).then((reply) => reply.now).catch((error) => {
            if (error?.message !== 'unknown method') throw error;
            timeRpc = false;
            return stateTime();
        })
        : stateTime());

    client = createClient(root, scene, {
        requestState: () => centrifuge.rpc('state', {}).then(answer).then((reply) => reply.state),
        requestTime,
        owl,
        reveal,
        sound,
        onChange: (model) => moderatorConsole?.update(model),
        onSay: (event) => moderatorConsole?.say(event),
        // The dice of finale step 1: the reply only carries the attempt; the dice are drawn from the `roll` event.
        roll: (attempt) => centrifuge.rpc('roll', { attempt }).then(({ data }) => data),
        // My ability (docs/spec/frontend.md, «Способности»): the reply carries `readyAt`; the effect comes as `power`.
        ability: (params = {}) => centrifuge.rpc('ability', params).then(({ data }) => data),
    });
    client.start();

    // The HUD's connection lamp is the client's: from finale step 3 RoadRunner's uptime stands beside it.
    const setStatus = (status) => client.setStatus(status);

    let connectedOnce = false;
    centrifuge.on('connecting', () => setStatus('connecting'));
    centrifuge.on('disconnected', () => setStatus('disconnected'));
    centrifuge.on('connected', () => {
        setStatus('connected');
        if (connectedOnce) client.resync();
        connectedOnce = true;
        client.syncClock();
        // Who this browser is: the walker with this id is ours and may be sent around the patch; the moderator
        // connection gets the console.
        centrifuge.rpc('me', {}).then(answer)
            .then((reply) => {
                client.setMe(reply.player?.id ?? null, reply.player ?? null);
                client.setModerator(reply.moderator === true);
            })
            .catch((error) => console.warn('me rpc failed', error));
    });

    // The server subscribes the client on connect (server-side subscription), so publications arrive on the
    // client itself, not on a Subscription object.
    centrifuge.on('publication', (ctx) => client.apply(ctx.data));

    // A background tab gets held-back publications in a burst when it returns; the scene drops expired flyers on
    // its first frame, and a fresh offset keeps the next flights on the server's clock.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && connectedOnce) client.syncClock();
    });
    // A page restored from the back-forward cache keeps its old snapshot time; measure again.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && connectedOnce) client.syncClock();
    });

    // Nothing is drawn locally on click: the effect appears when the server broadcasts the `click` event. A locked
    // bonfire is not asked at all: the server would only answer `locked`. A refusal is only warned.
    const click = (id) => client.clickable(id) && centrifuge.rpc('click', { element: id })
        .then(({ data }) => {
            if (data?.ok === false) console.warn('click rejected', data.error);
        })
        .catch((error) => console.warn('click rpc failed', error));
    // An RPC whose result comes back as channel events; a refusal (`zombified`, `not now`, `no rapier`…) is only warned.
    const rpc = (method, params = {}) => centrifuge.rpc(method, params).then(({ data }) => {
        if (data?.ok === false) console.warn(`${method} rejected`, data.error);
        return data;
    }).catch((error) => console.warn(`${method} rpc failed`, error));
    // A mug RPC (`drink`, `beer`, `bar.take`, `clink`): not sent while my mug is busy, one at a time; the server's
    // `busy` is the same rule seen from its side and stays quiet.
    const mugRpc = (method, params = {}) => client.mugRpc(method, () => centrifuge.rpc(method, params).then(({ data }) => {
        if (data?.ok === false && data.error !== 'busy') console.warn(`${method} rejected`, data.error);
        return data;
    })).catch((error) => console.warn(`${method} rpc failed`, error));
    // In the cursed world a reachable pumpkin takes a clink instead: the walk comes as `move`, the glasses as `clink`.
    const clink = (target) => mugRpc('clink', target);
    // At finale step 5 with empty hands it hands out a mug instead: `move`, then `take` with `pumpkin`.
    root.addEventListener('click', (event) => {
        const element = event.target.closest('[data-element]');
        if (!element) return;
        const id = element.dataset.element;
        if (client.beersPumpkin(id)) mugRpc('beer', { pumpkin: id });
        else if (client.clinksPumpkin(id)) clink({ pumpkin: id });
        else click(id);
    });

    // A miss sends nothing; a hit is only drawn when the channel's `shot` event comes back.
    bindShooting(root, scene, (flyer) => {
        centrifuge.rpc('shoot', { flyer })
            .then(({ data }) => {
                if (data?.ok === false) console.warn('shot rejected', data.error);
            })
            .catch((error) => console.warn('shoot rpc failed', error));
    });
    // RoadRunner meeps on anyone's click, a browser's clicks no closer than MEEP_GAP_MS (the server keeps its own
    // cooldown); the dice roll by «Бросить» or Space.
    let meepAt = -Infinity;
    const meep = () => {
        if (performance.now() - meepAt < MEEP_GAP_MS) return;
        meepAt = performance.now();
        // The server's `busy` is someone else's meep a moment ago: heard already, stays quiet.
        centrifuge.rpc('meep').then(({ data }) => {
            if (data?.ok === false && data.error !== 'busy') console.warn('meep rejected', data.error);
        }).catch((error) => console.warn('meep rpc failed', error));
    };
    bindMeep(root, meep);
    bindObjectKeys();
    bindDice(root, client);
    bindAbility(root, client);
    // After the shots: a press on a flyer stays a shot even over a walker; a zombie player at step 6 is cured.
    bindClinking(root, scene, client, clink, (target) => rpc('cure', target));
    // The rapier (docs/spec/frontend.md, «Рапира»): the word form and the blow at FrankenPHP.
    bindWord(root, client, (text) => centrifuge.rpc('word', { text }).then(({ data }) => data));
    bindStriking(root, client, () => rpc('strike'));
    // The chat (docs/spec/frontend.md, «Чат»): the field answers the refusals itself, the bubble comes as `say`.
    const chat = bindChat(root, client, (text) => centrifuge.rpc('say', { text }).then(({ data }) => data));
    // The emotes (docs/spec/frontend.md, «Эмоции»): the panel rests a moment after a press, the emoji comes as `emote`.
    bindEmotes(root, (emoji) => centrifuge.rpc('emote', { emoji }).then(({ data }) => data), { onOpen: () => chat?.close() });

    // The bar (protocol.md, «Бар») and the cursed world: a mug from the counter, a clink with RoadRunner behind it (its
    // refusals — `not bar`, `no target`, `no beer`… — only warned, like any clink's), a drink by Space or «Выпить».
    // `not clinked` is a hint over my own walker only; the server broadcasts nothing for it.
    bindBar(root, client, {
        take: () => mugRpc('bar.take'),
        clink: () => clink({ roadrunner: true }),
        meep,
        drink: () => mugRpc('drink').then((data) => {
            if (data?.error === 'not clinked') scene?.hint?.(client.model.me, refusal());
        }),
    });
    // The scene's moments: the sound's cues, and an automatic clink (the bar, the cursed world) that my walker strikes — reported once,
    // by this browser only, while the tab is visible (a hidden one does not get the clink counted, by design).
    scene?.onCue?.((cue) => {
        sound?.cue(cue);
        if (cue?.type !== 'clink' || document.visibilityState !== 'visible') return;
        const partner = client.autoClinkPartner(cue);
        if (partner) rpc('clink.auto', { walker: partner }).then((data) => data?.ok && client.clinkedAuto());
    });

    // The character appears when the channel's `walker` event comes back, like everything else.
    // Lazy like the scene: without the character sprites the page still clicks pumpkins and shoots.
    const joinModule = await import('./join.js').catch((error) => console.warn('join screen failed to load', error));
    const join = joinModule?.initJoin(document.querySelector('[data-join]'), {
        register: (name, look) => centrifuge.rpc('register', { name, look }).then(answer)
            .then((data) => client.setMe(data.player?.id ?? null, data.player ?? null)),
    });
    root.querySelector('[data-join-open]')?.addEventListener('click', () => join?.open());
    if (join && !joinModule.storedLook()) join.open();

    // A step is drawn only when the channel's `move` event arrives.
    bindWalking(root, scene, () => client.canWalk(), (point) => centrifuge.rpc('move', point)
        .then(({ data }) => {
            if (data?.ok === false) console.warn('move rejected', data.error);
        })
        .catch((error) => console.warn('move rpc failed', error)));

    centrifuge.connect();
}

if (root) {
    main();
}
