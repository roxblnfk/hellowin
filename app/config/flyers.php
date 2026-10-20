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

    // Kinds picked at random for every launch, a set per scene separated by ";"; how each kind looks is up to the
    // frontend
    'kinds' => env('HELLOWIN_FLYER_KINDS', 'duck,chicken;crow,bat;ghost,bat;ghost,bat'),

    // The rare flyer of each scene (empty — none), how often a launch is it, and its points. The roadrunner runs along
    // the horizon and cannot be hit
    'rare' => env('HELLOWIN_FLYER_RARE', 'roadrunner;;witch;witch'),
    'rare_chance' => env('HELLOWIN_FLYER_RARE_CHANCE', 0.08),
    'rare_points' => env('HELLOWIN_FLYER_RARE_POINTS', 10),

    // Zombies of the cursed world, when the moderator lets them out: milliseconds between them (range or fixed),
    // milliseconds from the patch to the edge of the lawn, points for one
    'zombie_interval' => env('HELLOWIN_ZOMBIE_INTERVAL', '4000-9000'),
    'zombie_duration' => env('HELLOWIN_ZOMBIE_DURATION', 14000),
    'zombie_points' => env('HELLOWIN_ZOMBIE_POINTS', 2),
];
