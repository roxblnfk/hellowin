(() => {
    'use strict';

    const app = document.getElementById('app');
    if (!app || typeof Centrifuge === 'undefined') {
        return;
    }

    const statusEl = app.querySelector('[data-status]');
    const clicksEl = app.querySelector('[data-progress-clicks]');
    const thresholdEl = app.querySelector('[data-progress-threshold]');
    const progressEl = app.querySelector('[data-progress]');

    function websocketUrl(url) {
        if (/^wss?:\/\//.test(url)) {
            return url;
        }
        const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
        return `${scheme}//${location.host}${url.startsWith('/') ? '' : '/'}${url}`;
    }

    function setStatus(status) {
        statusEl.dataset.status = status;
        statusEl.textContent = status;
    }

    function setProgress(clicks, threshold) {
        clicksEl.textContent = clicks;
        thresholdEl.textContent = threshold;
        progressEl.max = threshold;
        progressEl.value = clicks;
    }

    function popEmoji(elementId, emoji) {
        const target = app.querySelector(`[data-element="${CSS.escape(elementId)}"]`);
        if (!target) {
            return;
        }

        const node = document.createElement('span');
        node.className = 'emoji-pop';
        node.textContent = emoji;
        node.style.left = target.style.left;
        node.style.top = target.style.top;
        node.style.setProperty('--dx', `${Math.round((Math.random() - 0.5) * 80)}px`);
        node.style.setProperty('--rot', `${Math.round((Math.random() - 0.5) * 40)}deg`);
        node.addEventListener('animationend', () => node.remove(), {once: true});
        app.appendChild(node);
    }

    const handlers = {
        emoji(msg) {
            popEmoji(msg.element, msg.emoji);
            setProgress(msg.clicks, msg.threshold);
        },
        state(msg) {
            document.body.dataset.stage = msg.stage;
            setProgress(msg.clicks, msg.threshold);
        },
    };

    const centrifuge = new Centrifuge(websocketUrl(app.dataset.centrifugoUrl));

    centrifuge.on('connecting', () => setStatus('connecting'));
    centrifuge.on('connected', () => setStatus('connected'));
    centrifuge.on('disconnected', () => setStatus('disconnected'));

    // The server subscribes the client to the channel on connect (server-side subscription),
    // so its publications arrive on the client itself, not on a Subscription object.
    centrifuge.on('publication', (ctx) => {
        const handler = handlers[ctx.data?.type];
        if (handler) {
            handler(ctx.data);
        }
    });

    // Nothing is drawn locally on click: the emoji appears when the server broadcasts it to everyone.
    app.addEventListener('click', (event) => {
        const element = event.target.closest('[data-element]');
        if (!element) {
            return;
        }
        centrifuge.rpc('click', {element: element.dataset.element})
            .then((result) => {
                if (result.data && result.data.ok === false) {
                    console.warn('click rejected', result.data.error);
                }
            })
            .catch((err) => console.warn('click rpc failed', err));
    });

    centrifuge.connect();
})();
