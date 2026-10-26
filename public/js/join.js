// Join screen of docs/spec/frontend.md («Экран входа и персонажи»): name, character parts, a walking preview and
// RPC `register`. Shared by app.js and public/dev/lab.html; the preview is a plain 2D canvas, no PixiJS.

import { CATALOG, POSES, characterFrame, normalizeLook, randomLook } from './pixel/characters.js';
import { pick } from './i18n.js';

const LOOK_KEY = 'hellowin.look';
const NAME_KEY = 'hellowin.name';
const PARTS = ['body', 'cloak', 'face', 'eyes', 'hat'];
// Preview canvas in art pixels; CSS blows it up with image-rendering: pixelated.
const PREVIEW = { w: 48, h: 48, footY: 43 };
// The preview turns around and walks in place: front, side, back, the other side.
const TURNS = [
    { dir: 'front', flip: false, pose: 'idle', ms: 1600 },
    { dir: 'side', flip: false, pose: 'walk', ms: 1800 },
    { dir: 'back', flip: false, pose: 'idle', ms: 1200 },
    { dir: 'side', flip: true, pose: 'walk', ms: 1800 },
];
const ERRORS = pick({
    ru: {
        'invalid name': 'Имя — от 1 до 24 символов: буквы, цифры, пробел, _ . -',
        'invalid look': 'Сервер не узнал персонажа — нажмите «Случайно»',
        anonymous: 'Нет секрета игрока — обновите страницу',
        failed: 'Не вышло — попробуйте ещё раз',
    },
    en: {
        'invalid name': 'Name: 1 to 24 characters — letters, digits, space, _ . -',
        'invalid look': 'The server did not recognise the character — press “Random”',
        anonymous: 'No player secret — reload the page',
        failed: "Didn't work — try again",
    },
});

function read(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the choice is simply not remembered.
    }
}

/** The look saved by the last successful registration, or null before the first one. */
export function storedLook() {
    try {
        const look = JSON.parse(read(LOOK_KEY) ?? 'null');
        return look && typeof look === 'object' ? normalizeLook(look) : null;
    } catch {
        return null;
    }
}

const css = (color) => (typeof color === 'number' ? '#' + color.toString(16).padStart(6, '0') : color);

/**
 * @param {HTMLDialogElement} dialog  `<dialog data-join>`
 * @param {{register: (name: string, look: object) => Promise<unknown>}} actions
 *        register rejects with Error(code) on `{ok: false, error: code}`
 * @returns {{open: () => void}|null}
 */
export function initJoin(dialog, { register }) {
    if (!dialog) return null;
    const form = dialog.querySelector('form');
    const nameInput = dialog.querySelector('[data-join-name]');
    const error = dialog.querySelector('[data-join-error]');
    const submit = dialog.querySelector('[data-join-submit]');
    const preview = dialog.querySelector('[data-join-preview]');
    const ctx = preview.getContext('2d');
    preview.width = PREVIEW.w;
    preview.height = PREVIEW.h;
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');

    let look = storedLook() ?? randomLook();

    function paintParts() {
        for (const part of PARTS) {
            const row = dialog.querySelector(`[data-part="${part}"]`);
            const item = CATALOG[part]?.find((p) => p.id === look[part]) ?? CATALOG[part]?.[0];
            const value = row?.querySelector('[data-part-value]');
            if (!value || !item) continue;
            value.textContent = item.label;
            if (item.color !== undefined) value.style.setProperty('--swatch', css(item.color));
        }
    }

    function step(part, by) {
        const list = CATALOG[part] ?? [];
        if (!list.length) return;
        const at = Math.max(0, list.findIndex((p) => p.id === look[part]));
        look = { ...look, [part]: list[(at + by + list.length) % list.length].id };
        paintParts();
    }

    function showError(code) {
        error.textContent = code ? ERRORS[code] ?? ERRORS.failed : '';
        nameInput.toggleAttribute('aria-invalid', code === 'invalid name');
    }

    // The preview loop only runs while the dialog is open.
    let frameRequest = 0;
    let started = 0;
    function draw(time) {
        const cycle = TURNS.reduce((sum, t) => sum + t.ms, 0);
        let into = motion.matches ? 0 : (time - started) % cycle;
        const turn = TURNS.find((t) => (into -= t.ms) < 0) ?? TURNS[0];
        const pose = POSES[turn.pose] ?? { frames: 1, ms: 500 };
        const frame = motion.matches ? 0 : Math.floor(time / pose.ms) % pose.frames;
        const f = characterFrame(look, { dir: turn.dir, pose: turn.pose, frame });
        ctx.clearRect(0, 0, PREVIEW.w, PREVIEW.h);
        ctx.imageSmoothingEnabled = false;
        ctx.save();
        ctx.translate(PREVIEW.w >> 1, PREVIEW.footY);
        if (turn.flip) ctx.scale(-1, 1);
        ctx.drawImage(f.canvas, -f.anchor.x, -f.anchor.y);
        ctx.restore();
        frameRequest = requestAnimationFrame(draw);
    }

    function open() {
        look = storedLook() ?? look;
        nameInput.value = read(NAME_KEY) ?? nameInput.value;
        showError(null);
        paintParts();
        if (!dialog.open) dialog.showModal();
        started = performance.now();
        cancelAnimationFrame(frameRequest);
        frameRequest = requestAnimationFrame(draw);
    }

    dialog.addEventListener('close', () => cancelAnimationFrame(frameRequest));

    dialog.addEventListener('click', (event) => {
        const stepButton = event.target.closest('[data-step]');
        if (stepButton) step(stepButton.closest('[data-part]').dataset.part, Number(stepButton.dataset.step));
        if (event.target.closest('[data-join-random]')) {
            look = randomLook();
            paintParts();
        }
        if (event.target.closest('[data-join-watch]')) dialog.close('watch');
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const name = nameInput.value.trim();
        showError(null);
        submit.disabled = true;
        register(name, { ...look })
            .then(() => {
                write(NAME_KEY, name);
                write(LOOK_KEY, JSON.stringify(look));
                dialog.close('join');
            })
            .catch((e) => {
                console.warn('register failed', e);
                showError(e?.message ?? 'error');
                if (e?.message === 'invalid name') nameInput.focus();
            })
            .finally(() => (submit.disabled = false));
    });

    paintParts();
    return { open };
}
