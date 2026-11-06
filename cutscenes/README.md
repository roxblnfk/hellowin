# Cutscenes

Video inserts for the event recording, drawn from the game's own pixel art (`public/js/pixel/`) and sound synth
(`public/js/audio/synth/`). Not part of the site: nothing here is served or deployed.

Each scene is a page in `_src/` that paints the frame at any moment (`window.draw(t)`, `window.DURATION`) and lists
its sound cues (`window.SOUND`, see `_src/sound.mjs`). Headless Edge captures the frames, ffmpeg encodes them.

```
cd cutscenes/_src
node video.mjs bar-intro                 # → ../01-bar-intro.mp4 (1080p60 + sound), ~7 min
node video.mjs bar-intro --audio         # sound only, onto the last silent render
node video.mjs bar-intro --stills 2,7.5  # single frames → ../_stills/
```

Needs Node 22+, Microsoft Edge and ffmpeg in PATH.

| # | Scene | What happens |
|---|---|---|
| 01 | `bar-intro` | the owl pours beer and calls everyone to the ritual; a fly on the foam gets shot |
| 02 | `magic-place` | the owl leads everyone to the pumpkin patch, the elephant tows its banner by, chickens among the untouched pumpkins, a duck from the bush gets shot |
| 03 | `candles` | the sun sets over the carved pumpkins, the owl lights a candle and sets it down among them, flies up to its branch; candles light up round the spot, everyone takes one, «Берите свечи и зажигайте тыквы — пусть случится магия!» |
| 04 | `nightfall` | night falls over the burning pumpkins, the owl pulls a stack of spell books from under its wing and remembers the hooded necromancer who slid them over the bar's counter (a sepia flashback); it throws everyone a book, the first spells turn the pumpkins green, «Что может пойти не так?» |
| 05 | `green-blast` | the green bonfire roars but will not blow, «Достаточно… сейчас рванёт!» — it sinks nearly out; the owl reads the last ingredient out of the book: everyone farts on the count of three, a herd of elephants farts over the hills, the Fart Time clouds spiral into the fire; a farting duck is shot over it and drops in — the blast; the vines burn to ash, the moon turns red, the summoning circle lights and a dark silhouette rises in it |
| 06 | `franken-rise` | the owl awaits RoadRunner, lightning reveals FrankenPHP, «ЧТО ВЫ НАДЕЛАЛИ?» |
| 07 | `roadrunner-alive` | «Походу… ошибочка вышла.» — RoadRunner runs in off the horizon alive, «Мип-мип!», the owl explains |
