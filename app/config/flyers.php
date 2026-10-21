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

    // The rare flyer of each scene (empty — none), how often a launch is it, and its points. The roadrunner runs along
    // the horizon and cannot be hit
    'rare' => env('HELLOWIN_FLYER_RARE', ';roadrunner;;;'),
    'rare_chance' => env('HELLOWIN_FLYER_RARE_CHANCE', 0.08),
    'rare_points' => env('HELLOWIN_FLYER_RARE_POINTS', 10),

    // Zombies of the cursed world, let out by themselves as it begins (the moderator may hold them): milliseconds
    // between them (range or fixed), milliseconds from the patch to the edge of the lawn, points for one
    'zombie_interval' => env('HELLOWIN_ZOMBIE_INTERVAL', '2000-5000'),
    'zombie_duration' => env('HELLOWIN_ZOMBIE_DURATION', 14000),
    'zombie_points' => env('HELLOWIN_ZOMBIE_POINTS', 2),
];
