// Minimal CDP driver over Node 22's global WebSocket. Launches headless Edge per session.
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Browser profiles and loose screenshots go to the system temp dir; pages are served from the repository's public/.
export const SCRATCH = path.join(tmpdir(), 'hellowin-cutscenes');
const PUBLIC = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public');
const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// gpu: draw on the discrete GPU through Direct3D instead of SwiftShader on the CPU.
const GPU = ['--use-angle=d3d11', '--enable-gpu-rasterization', '--ignore-gpu-blocklist', '--force_high_performance_gpu'];
const SOFT = ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'];

export async function launch(name, port, { w = 1280, h = 800, local = false, fresh = false, gpu = false } = {}) {
    const dir = `${SCRATCH}/edge-${name}`;
    if (fresh) try { rmSync(dir, { recursive: true, force: true }); } catch {}
    mkdirSync(dir, { recursive: true });
    const proc = spawn(EDGE, [
        '--headless=new', ...(gpu ? GPU : SOFT), '--no-first-run',
        '--no-default-browser-check', '--disable-extensions', '--autoplay-policy=no-user-gesture-required',
        `--remote-debugging-port=${port}`, `--user-data-dir=${dir}`, `--window-size=${w},${h}`, 'about:blank',
    ], { stdio: 'ignore' });
    let targets;
    for (let i = 0; i < 50; i++) {
        try {
            targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
            if (targets.some((t) => t.type === 'page')) break;
        } catch {}
        await sleep(200);
    }
    const page = targets.find((t) => t.type === 'page');
    const ws = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
    let id = 0;
    const pending = new Map();
    const logs = [];
    ws.onmessage = (m) => {
        const msg = JSON.parse(m.data);
        if (msg.id && pending.has(msg.id)) {
            pending.get(msg.id)(msg);
            pending.delete(msg.id);
        } else if (msg.method === 'Fetch.requestPaused') {
            const u = new URL(msg.params.request.url);
            let body = null;
            try { body = readFileSync(PUBLIC + u.pathname); } catch {}
            const type = u.pathname.endsWith('.css') ? 'text/css' : 'text/javascript';
            ws.send(JSON.stringify(body
                ? { id: 900000 + Math.floor(Math.random() * 99999), method: 'Fetch.fulfillRequest', params: { requestId: msg.params.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: type }, { name: 'Cache-Control', value: 'no-store' }], body: body.toString('base64') } }
                : { id: 900000 + Math.floor(Math.random() * 99999), method: 'Fetch.continueRequest', params: { requestId: msg.params.requestId } }));
        } else if (msg.method === 'Runtime.consoleAPICalled') {
            logs.push(`[${msg.params.type}] ` + msg.params.args.map((a) => a.value ?? a.description).join(' '));
        } else if (msg.method === 'Runtime.exceptionThrown') {
            logs.push('[exception] ' + (msg.params.exceptionDetails.exception?.description ?? msg.params.exceptionDetails.text));
        }
    };
    const send = (method, params = {}) => new Promise((r) => {
        const i = ++id;
        pending.set(i, r);
        ws.send(JSON.stringify({ id: i, method, params }));
    });
    await send('Runtime.enable');
    await send('Page.enable');
    if (local) await send('Fetch.enable', { patterns: [{ urlPattern: 'http://localhost:8080/js/*' }, { urlPattern: 'http://localhost:8080/css/*' }] });
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false });
    const s = {
        name, logs, send,
        async go(url) { await send('Page.navigate', { url }); await sleep(3000); },
        async eval(expr) {
            const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
            if (r.result?.exceptionDetails) return { error: r.result.exceptionDetails.exception?.description ?? r.result.exceptionDetails.text };
            return r.result?.result?.value;
        },
        async shot(file) {
            const r = await send('Page.captureScreenshot', { format: 'png' });
            writeFileSync(`${SCRATCH}/${file}`, Buffer.from(r.result.data, 'base64'));
        },
        async click(x, y) {
            for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
                await send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 });
            }
        },
        async key(key, code, keyCode) {
            await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: keyCode, text: key === ' ' ? ' ' : undefined });
            await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: keyCode });
        },
        async type(text) { await send('Input.insertText', { text }); },
        async close() { ws.send(JSON.stringify({ id: 999999, method: 'Browser.close' })); await sleep(800); ws.close(); proc.kill(); },
        proc,
    };
    return s;
}
