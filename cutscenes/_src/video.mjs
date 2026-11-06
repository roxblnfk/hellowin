// node video.mjs <scene> [--fps 60] [--stills 1,2.5,...] [--audio]
//   default   — frames → ../_silent/<scene>.mp4, sound (window.SOUND) → ../_audio/<scene>.wav, both → ../<NN>-<scene>.mp4 (NN: the scene's NUMBER)
//   --audio   — only the sound, muxed onto the existing silent video
//   --stills  — single frames → ../_stills/<scene>-<t>.png
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { launch, sleep, SCRATCH } from './cdp.mjs';
import { createServer } from 'node:net';
import { mix, writeWav } from './sound.mjs';

// The finished video is named by its place in the evening. Numbers already used in the edit never change: a scene
// added between two others takes a sub-number (05.1, 05.2), one added at the end takes the next number.
const NUMBER = {
    'bar-intro': '01',
    'magic-place': '02',
    'candles': '03',
    'nightfall': '04',
    'green-blast': '05',
    'circle': '05.1',
    'franken-rise': '06',
    'roadrunner-alive': '07',
    'valera': '08',
    'franken-down': '09',
    'epilogue': '10',
};

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(HERE, '..');
const args = process.argv.slice(2);
const scene = args[0];
const final = `${NUMBER[scene] ?? '99'}-${scene}.mp4`;
const opt = (k, d) => { const i = args.indexOf(k); return i < 0 ? d : args[i + 1]; };
const fps = Number(opt('--fps', 60));
const stills = opt('--stills', null);
const audioOnly = args.includes('--audio');
const run = (cmd, a) => new Promise((ok, no) => spawn(cmd, a, { stdio: 'inherit' }).on('close', (c) => (c ? no(new Error(`${cmd} exited ${c}`)) : ok())));
for (const d of ['_silent', '_audio', '_stills']) mkdirSync(path.join(OUT, d), { recursive: true });

// Renders of different scenes run side by side: each gets its own free ports and its own browser profile. Two renders
// of one scene would write the same files, so a lock file per scene queues them; one left by a dead process is taken over.
const LOCK = path.join(OUT, '_silent', `.render-${scene}.lock`);
const alive = (pid) => { try { process.kill(pid, 0); return true; } catch { return false; } };
for (let said = false; ; await sleep(2000)) {
    try { writeFileSync(LOCK, String(process.pid), { flag: 'wx' }); break; } catch {}
    const owner = Number(readFileSync(LOCK, 'utf8'));
    if (!alive(owner)) { rmSync(LOCK, { force: true }); continue; }
    if (!said) { console.log(`waiting for the render of pid ${owner}`); said = true; }
}
const unlock = () => { try { if (readFileSync(LOCK, 'utf8') === String(process.pid)) rmSync(LOCK); } catch {} };
process.on('exit', unlock);
for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => process.exit(130));
const freePort = () => new Promise((ok) => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => ok(port)); }); });
const HTTP = await freePort(), CDP = await freePort();
const server = spawn('node', [path.join(HERE, 'serve.js'), String(HTTP)], { stdio: 'ignore' });
await sleep(700);
const B = await launch(`cutvid-${process.pid}`, CDP, { w: 1920, h: 1080, fresh: true });
process.on('exit', () => { try { rmSync(path.join(SCRATCH, `edge-cutvid-${process.pid}`), { recursive: true, force: true }); } catch {} });
const shot = async () => Buffer.from((await B.send('Page.captureScreenshot', { format: 'png', optimizeForSpeed: true, clip: { x: 0, y: 0, width: 1920, height: 1080, scale: 1 } })).result.data, 'base64');
const errs = () => B.logs.filter((l) => /error|exception/i.test(l));

async function renderVideo(duration) {
    const n = Math.round(duration * fps);
    const ff = spawn('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(fps), '-c:v', 'png', '-i', '-',
        '-c:v', 'libx264', '-preset', 'slow', '-crf', '14', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', path.join(OUT, '_silent', `${scene}.mp4`)], { stdio: ['pipe', 'inherit', 'inherit'] });
    const t0 = Date.now();
    for (let i = 0; i < n; i++) {
        await B.eval(`draw(${i / fps})`);
        const png = await shot();
        if (!ff.stdin.write(png)) await new Promise((r) => ff.stdin.once('drain', r));
        if (i % 120 === 0) console.log(`${i}/${n} ${((Date.now() - t0) / 1000).toFixed(0)}s`);
    }
    ff.stdin.end();
    await new Promise((r) => ff.on('close', r));
    console.log('video ok', n, 'frames');
}

try {
    await B.go(`http://localhost:${HTTP}/scratch/${scene}.html`);
    for (let i = 0; i < 50 && (await B.eval('document.title')) !== 'ready'; i++) await sleep(200);
    if (errs().length) throw new Error(errs().join('\n'));
    if (stills) {
        for (const t of stills.split(',')) {
            await B.eval(`draw(${Number(t)})`);
            writeFileSync(path.join(OUT, '_stills', `${scene}-${t}.png`), await shot());
        }
        console.log('stills ok');
    } else {
        const duration = await B.eval('window.DURATION');
        if (!audioOnly) await renderVideo(duration);
        const cues = JSON.parse(await B.eval('JSON.stringify(window.SOUND ?? [])'));
        const wav = path.join(OUT, '_audio', `${scene}.wav`);
        writeWav(wav, mix(cues, duration));
        await run('ffmpeg', ['-y', '-loglevel', 'error', '-i', path.join(OUT, '_silent', `${scene}.mp4`), '-i', wav,
            '-c:v', 'copy', '-c:a', 'aac', '-b:a', '256k', '-shortest', '-movflags', '+faststart', path.join(OUT, final)]);
        console.log('muxed', final);
    }
    if (errs().length) console.log(errs().join('\n'));
} finally { await B.close(); server.kill(); }
