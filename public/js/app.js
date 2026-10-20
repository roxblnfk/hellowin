import { bindShooting, bindWalking, createClient, readSnapshot } from './client.js';
import { initDebugPanel } from './debug.js';
import { createOwl } from './owl.js';
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

async function main() {
    const scene = await startScene(readSnapshot(root));
    root.classList.add(scene ? 'has-canvas' : 'no-canvas');
    const owl = createOwl(root, scene);
    const reveal = createReveal(root);

    if (typeof Centrifuge === 'undefined') {
        createClient(root, scene, { requestState: () => Promise.reject(new Error('no centrifuge')), owl, reveal }).start();
        return;
    }

    const secret = moderatorSecret();
    const data = secret ? { playerSecret: playerSecret(), moderatorSecret: secret } : { playerSecret: playerSecret() };
    const centrifuge = new Centrifuge(websocketUrl(root.dataset.centrifugoUrl), { data });
    // The console's buttons draw nothing either: their effects come back as channel events.
    const moderatorConsole = initDebugPanel(root.querySelector('[data-debug-panel]'), {
        call: (method, params) => centrifuge.rpc(method, params).then(answer),
        // Spaced out so the clicks do not contend for the server's lock and come back as `busy`.
        clickAll: () => client.model.elements.forEach((e, i) => setTimeout(() => click(e.id), i * 30)),
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

    const client = createClient(root, scene, {
        requestState: () => centrifuge.rpc('state', {}).then(answer).then((reply) => reply.state),
        requestTime,
        owl,
        reveal,
        onChange: (model) => moderatorConsole?.update(model),
    });
    client.start();

    // The HUD's connection line is the client's: from finale step 3 it tells RoadRunner's uptime instead.
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
                client.setMe(reply.player?.id ?? null);
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
    // bonfire is not asked at all: the server would only answer `locked`.
    const click = (id) => client.clickable(id) && centrifuge.rpc('click', { element: id })
        .then(({ data }) => {
            if (data?.ok === false) console.warn('click rejected', data.error);
        })
        .catch((error) => console.warn('click rpc failed', error));
    root.addEventListener('click', (event) => {
        const element = event.target.closest('[data-element]');
        if (element) click(element.dataset.element);
    });

    // A miss sends nothing; a hit is only drawn when the channel's `shot` event comes back.
    bindShooting(root, scene, (flyer) => centrifuge.rpc('shoot', { flyer })
        .then(({ data }) => {
            if (data?.ok === false) console.warn('shot rejected', data.error);
        })
        .catch((error) => console.warn('shoot rpc failed', error)));

    // The character appears when the channel's `walker` event comes back, like everything else.
    // Lazy like the scene: without the character sprites the page still clicks pumpkins and shoots.
    const joinModule = await import('./join.js').catch((error) => console.warn('join screen failed to load', error));
    const join = joinModule?.initJoin(document.querySelector('[data-join]'), {
        register: (name, look) => centrifuge.rpc('register', { name, look }).then(answer)
            .then((data) => client.setMe(data.player?.id ?? null)),
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
