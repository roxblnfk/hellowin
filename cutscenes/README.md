# Cutscenes

Video inserts for the event recording, drawn from the game's own pixel art (`public/js/pixel/`) and sound synth
(`public/js/audio/synth/`). Not part of the site: nothing here is served or deployed.

Each scene is a page in `_src/` that paints the frame at any moment (`window.draw(t)`, `window.DURATION`) and lists
its sound cues (`window.SOUND`, see `_src/sound.mjs`). Headless Edge captures the frames, ffmpeg encodes them.

```
cd cutscenes/_src
node video.mjs bar-intro                 # → ../bar-intro.mp4 (1080p60 + sound), ~7 min
node video.mjs bar-intro --audio         # sound only, onto the last silent render
node video.mjs bar-intro --stills 2,7.5  # single frames → ../_stills/
```

Needs Node 22+, Microsoft Edge and ffmpeg in PATH.

| Scene | What happens |
|---|---|
| `bar-intro` | the owl pours beer and calls everyone to the ritual; a fly on the foam gets shot |
| `franken-rise` | the owl awaits RoadRunner, lightning reveals FrankenPHP, «ЧТО ВЫ НАДЕЛАЛИ?» |
