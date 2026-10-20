import fs from 'node:fs';

/** Writes 16-bit PCM WAV: the 44-byte RIFF header by hand, channels interleaved. */
export function writeWav(path, channels, sr) {
    const n = channels[0].length;
    const ch = channels.length;
    const data = n * ch * 2;
    const buf = Buffer.alloc(44 + data);
    buf.write('RIFF', 0, 'ascii');
    buf.writeUInt32LE(36 + data, 4);
    buf.write('WAVE', 8, 'ascii');
    buf.write('fmt ', 12, 'ascii');
    buf.writeUInt32LE(16, 16);
    buf.writeUInt16LE(1, 20);
    buf.writeUInt16LE(ch, 22);
    buf.writeUInt32LE(sr, 24);
    buf.writeUInt32LE(sr * ch * 2, 28);
    buf.writeUInt16LE(ch * 2, 32);
    buf.writeUInt16LE(16, 34);
    buf.write('data', 36, 'ascii');
    buf.writeUInt32LE(data, 40);
    let o = 44;
    for (let i = 0; i < n; i++) {
        for (let c = 0; c < ch; c++) {
            const v = Math.max(-1, Math.min(1, channels[c][i]));
            buf.writeInt16LE(Math.round(v * 32767), o);
            o += 2;
        }
    }
    fs.writeFileSync(path, buf);
}
