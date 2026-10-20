// Renders the music and the effects to WAV for listening: node tools/audio/render.mjs <dir> [rate] [loops]
// Tracks: the full mix played `loops` times in a row (to hear the seam) and each stem once; effects: every variant.
import fs from 'node:fs';
import path from 'node:path';
import { TRACKS, renderTrack } from '../../public/js/audio/synth/music.js';
import { SFX, renderSfx } from '../../public/js/audio/synth/sfx.js';
import { writeWav } from './wav.mjs';

const dir = process.argv[2] ?? 'audio-out';
const sr = Number(process.argv[3] ?? 48000);
const loops = Number(process.argv[4] ?? 3);
fs.mkdirSync(dir, { recursive: true });

for (const name of Object.keys(TRACKS)) {
    const track = renderTrack(name, sr);
    const n = track.length;
    const mix = [new Float32Array(n * loops), new Float32Array(n * loops)];
    for (const stem of Object.values(track.stems)) {
        for (let k = 0; k < loops; k++) {
            for (let c = 0; c < 2; c++) for (let i = 0; i < n; i++) mix[c][k * n + i] += stem[c][i];
        }
    }
    writeWav(path.join(dir, `music-${name}.wav`), mix, sr);
    for (const [stem, ch] of Object.entries(track.stems)) writeWav(path.join(dir, `music-${name}-${stem}.wav`), ch, sr);
    console.log(`music ${name}: ${(n / sr).toFixed(2)} s × ${loops}`);
}

for (const [name, def] of Object.entries(SFX)) {
    for (let v = 0; v < def.variants; v++) writeWav(path.join(dir, `sfx-${name}-${v}.wav`), renderSfx(name, v, sr), sr);
}
console.log(`effects: ${Object.keys(SFX).length} sounds → ${dir}`);
