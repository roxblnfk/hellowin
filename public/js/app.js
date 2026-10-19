import { createClient, readSnapshot } from './client.js';

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

    // Nothing is drawn locally on click: the effect appears when the server broadcasts the `click` event.
    root.addEventListener('click', (event) => {
        const element = event.target.closest('[data-element]');
        if (!element) return;
        centrifuge.rpc('click', { element: element.dataset.element })
            .then(({ data }) => {
                if (data?.ok === false) console.warn('click rejected', data.error);
            })
            .catch((error) => console.warn('click rpc failed', error));
    });

    centrifuge.connect();
}

if (root) {
    main();
}
