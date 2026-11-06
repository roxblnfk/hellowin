// node video.mjs <scene> [--fps 60] [--stills 1,2.5,...] [--audio]
//   default   — frames → ../_silent/<scene>.mp4, sound (window.SOUND) → ../_audio/<scene>.wav, both → ../<NN>-<scene>.mp4 (NN: place in ORDER)
//   --audio   — only the sound, muxed onto the existing silent video
//   --stills  — single frames → ../_stills/<scene>-<t>.png
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { launch, sleep } from './cdp.mjs';
import { mix, writeWav } from './sound.mjs';

// Story order: the finished video is named by its place in the evening, so a folder listing reads as the edit.
const ORDER = ['bar-intro', 'franken-rise', 'roadrunner-alive'];

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.resolve(HERE, '..');
const args = process.argv.slice(2);
const scene = args[0];
const final = `${String(ORDER.indexOf(scene) + 1 || 99).padStart(2, '0')}-${scene}.mp4`;
const opt = (k, d) => { const i = args.indexOf(k); return i < 0 ? d : args[i + 1]; };
const fps = Number(opt('--fps', 60));
const stills = opt('--stills', null);
const audioOnly = args.includes('--audio');
const run = (cmd, a) => new Promise((ok, no) => spawn(cmd, a, { stdio: 'inherit' }).on('close', (c) => (c ? no(new Error(`${cmd} exited ${c}`)) : ok())));
for (const d of ['_silent', '_audio', '_stills']) mkdirSync(path.join(OUT, d), { recursive: true });

const server = spawn('node', [path.join(HERE, 'serve.js'), '8899'], { stdio: 'ignore' });
await sleep(700);
const B = await launch('cutvid', 9451, { w: 1920, h: 1080, fresh: true });
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
    await B.go(`http://localhost:8899/scratch/${scene}.html`);
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
