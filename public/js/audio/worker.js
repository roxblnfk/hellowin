import { renderTrack } from './synth/music.js';
import { renderSfx, sfxJobs } from './synth/sfx.js';

/**
 * Render worker. Requests: {id, kind: 'track', name, sr} → one reply with the stems;
 * {id, kind: 'sfx', group, sr} → one reply per sound variant ({id, name, variant, channels}) and a final {id, done}.
 * Channel arrays are transferred, not copied.
 */
self.onmessage = ({ data }) => {
    const { id, kind, sr } = data;
    try {
        if (kind === 'track') {
            const t0 = performance.now();
            const track = renderTrack(data.name, sr);
            const transfer = Object.values(track.stems).flat().map((a) => a.buffer);
            self.postMessage({ id, track, ms: performance.now() - t0 }, transfer);
            return;
        }
        if (kind === 'sfx') {
            const t0 = performance.now();
            for (const [name, variant] of sfxJobs(data.group)) {
                const channels = renderSfx(name, variant, sr);
                self.postMessage({ id, name, variant, channels }, channels.map((a) => a.buffer));
            }
            self.postMessage({ id, done: true, ms: performance.now() - t0 });
            return;
        }
        throw new Error(`unknown request ${kind}`);
    } catch (error) {
        self.postMessage({ id, error: String(error?.message ?? error) });
    }
};
