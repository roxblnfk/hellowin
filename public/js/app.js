import { bindShooting, createClient, readSnapshot } from './client.js';
import { initDebugPanel } from './debug.js';

const STATUS = { connecting: 'подключение…', connected: 'на связи', disconnected: 'нет связи' };

const root = document.getElementById('app');

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

    if (typeof Centrifuge === 'undefined') {
        createClient(root, scene, { requestState: () => Promise.reject(new Error('no centrifuge')) }).start();
        return;
    }

    const centrifuge = new Centrifuge(websocketUrl(root.dataset.centrifugoUrl));
    const client = createClient(root, scene, {
        requestState: () => centrifuge.rpc('state', {}).then(({ data }) => {
            if (!data?.ok) throw new Error(data?.error ?? 'bad state response');
            return data.state;
        }),
    });
    client.start();

    const statusEl = root.querySelector('[data-status]');
    const setStatus = (status) => {
        if (!statusEl) return;
        statusEl.dataset.status = status;
        statusEl.textContent = STATUS[status];
    };

    let connectedOnce = false;
    centrifuge.on('connecting', () => setStatus('connecting'));
    centrifuge.on('disconnected', () => setStatus('disconnected'));
    centrifuge.on('connected', () => {
        setStatus('connected');
        if (connectedOnce) client.resync();
        connectedOnce = true;
    });

    // The server subscribes the client on connect (server-side subscription), so publications arrive on the
    // client itself, not on a Subscription object.
    centrifuge.on('publication', (ctx) => client.apply(ctx.data));

    // A background tab gets held-back publications in a burst when it returns; the scene drops expired flyers on
    // its first frame, and a fresh offset keeps the next flights on the server's clock.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && connectedOnce) client.syncClock();
    });

    // Nothing is drawn locally on click: the effect appears when the server broadcasts the `click` event.
    const click = (id) => centrifuge.rpc('click', { element: id })
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

    // Restart and "click every pumpkin" draw nothing either: the channel's `reset` / `click` events do.
    initDebugPanel(root.querySelector('[data-debug-panel]'), {
        reset: () => centrifuge.rpc('reset', { round: client.model.round }).then(({ data }) => {
            if (!data?.ok) throw new Error(data?.error ?? 'bad reset response');
        }),
        // Spaced out so the clicks do not contend for the server's lock and come back as `busy`.
        clickAll: () => client.model.elements.forEach((e, i) => setTimeout(() => click(e.id), i * 30)),
    });

    centrifuge.connect();
}

if (root) {
    main();
}
