// The finale's timeline on its own, without PixiJS: the scene draws by it and the sound (sound.js) plays by it.

/** Timeline of the steps, ms since the step's event. */
export const T = {
    // Step 1: the circle flashes, the column lights up, smoke rolls, the silhouette rises; the ground rumbles while the
    // pumpkins are pulled into a round dance.
    flash: 90,
    column: 180,
    cloud: 300,
    rise: 600,
    riseFrame: 400,
    rumble: 2200,
    pull: 1500,
    // Step 2: three strikes; the third cuts to the night under its flash, then the smoke clears off FrankenPHP and the
    // pumpkins' lights go out as they drop.
    strikes: [0, 380, 760],
    strikeFrame: 60,
    night: 780,
    reveal: 900,
    revealFrame: 150,
    fling: 700,
    // Step 3: on a diagonal from the screen edge at the horizon down to the front, a beat of standing, «мип-мип».
    run: 4600,
    meep: 5000,
    meepMs: 1800,
    // Step 5: the pumpkins rise and light up; RoadRunner runs back up the diagonal and off the edge; FrankenPHP keeps
    // quiet while the owl says its last line and flies away.
    rise5: 1000,
    leave: 1200,
    back: 4400,
    quiet: 14000,
};
