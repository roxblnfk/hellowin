// Debug panel of the home page and public/dev/lab.html: restart the ritual and click every pumpkin.
// Hidden by default; ?debug=1 shows it and is remembered, ?debug=0 forgets it, the backquote key toggles it.

const STORAGE_KEY = 'hellowin:debug';
const CONFIRM_MS = 3000;

function remembered() {
    const param = new URLSearchParams(location.search).get('debug');
    try {
        if (param === '1') localStorage.setItem(STORAGE_KEY, '1');
        if (param === '0') localStorage.removeItem(STORAGE_KEY);
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return param === '1';
    }
}

/**
 * @param {HTMLElement} panel  `[data-debug-panel]`
 * @param {{reset: () => Promise<unknown>, clickAll: () => void, visible?: boolean}} actions
 *        reset resolves once the server accepted the restart; the scene itself resets on the channel event.
 */
export function initDebugPanel(panel, { reset, clickAll, visible }) {
    if (!panel) return;
    panel.hidden = !(visible ?? remembered());

    document.addEventListener('keydown', (event) => {
        if (event.code !== 'Backquote' || event.target.closest?.('input, textarea, select')) return;
        panel.hidden = !panel.hidden;
    });

    const resetButton = panel.querySelector('[data-action="reset"]');
    const label = resetButton?.textContent;
    let armed = 0;
    const disarm = () => {
        clearTimeout(armed);
        armed = 0;
        resetButton.textContent = label;
        delete resetButton.dataset.armed;
    };

    // Two clicks instead of confirm(): the first arms the button for a few seconds, the second sends the request.
    resetButton?.addEventListener('click', () => {
        if (!armed) {
            resetButton.textContent = 'Точно?';
            resetButton.dataset.armed = '';
            armed = setTimeout(disarm, CONFIRM_MS);
            return;
        }
        disarm();
        resetButton.disabled = true;
        reset()
            .catch((error) => console.warn('reset failed', error))
            .finally(() => (resetButton.disabled = false));
    });

    panel.querySelector('[data-action="click-all"]')?.addEventListener('click', () => clickAll());
}
