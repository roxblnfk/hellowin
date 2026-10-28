<?php

declare(strict_types=1);

/**
 * Flyers the ticker launches over the patch; bushes they take off from are in hellowin.php.
 *
 * @see \App\Application\Config\FlyerConfig
 */
return [
    // Milliseconds between launches: a random value in the range, or a fixed number
    'interval' => env('HELLOWIN_FLYER_INTERVAL', '3000-8000'),

    // Per depth, from the farthest (0) to the nearest: milliseconds across the screen and points for a hit
    'duration' => env('HELLOWIN_FLYER_DURATION', '16000,6500,4000'),
    'points' => env('HELLOWIN_FLYER_POINTS', '3,2,1'),

    // Probability to take off from a bush instead of coming from behind the screen edge
    'bush_chance' => env('HELLOWIN_FLYER_BUSH_CHANCE', 0.4),

    // Probability of a curved flight
    'arc_chance' => env('HELLOWIN_FLYER_ARC_CHANCE', 0.5),

    // Kinds picked at random for every launch, a set per scene separated by ";" from the bar on (an empty set — no
    // flyers in that scene); bats wobble across their path, worms crawl over the lawn, flies buzz about the bar; how
    // each kind looks is up to the frontend
    'kinds' => env('HELLOWIN_FLYER_KINDS', 'fly;duck,chicken;;bat;worm'),

    // The cursed world (finale steps 5 and 6) takes this one set instead of its scene's; fireflies fly at the middle
    // and near depths, wobble sluggishly and bring these points at any depth
    'kinds_cursed' => env('HELLOWIN_FLYER_KINDS_CURSED', 'worm,bat,firefly'),
    'firefly_points' => env('HELLOWIN_FLYER_FIREFLY_POINTS', 1),

    // The dawn (the last finale step) takes this one set instead of its scene's, launched at its own, shorter pace:
    // the night is over, and birds are many
    'kinds_dawn' => env('HELLOWIN_FLYER_KINDS_DAWN', 'duck,chicken'),
    'interval_dawn' => env('HELLOWIN_FLYER_INTERVAL_DAWN', '1000-2500'),

    // Flies of the bar (walk.bar in hellowin.php): how many times one lands on the tables and the counter, milliseconds
    // it sits there (both ranges or fixed), milliseconds from one point to the next, points for one
    'fly_stops' => env('HELLOWIN_FLYER_FLY_STOPS', '2-3'),
    'fly_sit' => env('HELLOWIN_FLYER_FLY_SIT', '900-1600'),
    'fly_leg' => env('HELLOWIN_FLYER_FLY_LEG', 900),
    'fly_points' => env('HELLOWIN_FLYER_FLY_POINTS', 1),

    // The elephant with the release banner comes on a timer of its own, not by chance: milliseconds from the last one
    // (min,max, a random value between, or a fixed number) and milliseconds it takes across the sky
    'elephant_interval' => env('HELLOWIN_ELEPHANT_INTERVAL', '180000,360000'),
    'elephant_duration' => env('HELLOWIN_ELEPHANT_DURATION', 26000),
    // Milliseconds after an elephant's fart before a shot makes it fart again; a shot within them just misses
    'elephant_fart_cooldown' => env('HELLOWIN_ELEPHANT_FART_COOLDOWN_MS', 1000),
    // Probability (0..1) of an elephant flying low, over the hills at the horizon, instead of high in the sky
    'elephant_low_chance' => env('HELLOWIN_ELEPHANT_LOW_CHANCE', 0.33),

    // The rare flyer of each scene (empty — none), how often a launch is it, and its points
    'rare' => env('HELLOWIN_FLYER_RARE', ';;;;'),
    'rare_chance' => env('HELLOWIN_FLYER_RARE_CHANCE', 0.08),
    'rare_points' => env('HELLOWIN_FLYER_RARE_POINTS', 10),

    // Zombies of the cursed world wander until shot: never fewer than `zombie_min` alive, one more every
    // `zombie_interval` milliseconds (range or fixed) while fewer than `zombie_per_player` per character on the floor
    // are alive; the pause holds them. Points for one
    'zombie_interval' => env('HELLOWIN_ZOMBIE_INTERVAL', '2000-5000'),
    'zombie_min' => env('HELLOWIN_ZOMBIE_MIN', 5),
    'zombie_per_player' => env('HELLOWIN_ZOMBIE_PER_PLAYER', 5),
    'zombie_points' => env('HELLOWIN_ZOMBIE_POINTS', 2),

    // The moderator's burst (`flyers.burst`): `burst_base` flyers of the current set for one figure on the floor,
    // `burst_per_player` more for every other one
    'burst_base' => env('HELLOWIN_FLYER_BURST_BASE', 5),
    'burst_per_player' => env('HELLOWIN_FLYER_BURST_PER_PLAYER', 0.2),
];
