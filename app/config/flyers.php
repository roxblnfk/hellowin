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
    'duration' => env('HELLOWIN_FLYER_DURATION', '9000,6500,4000'),
    'points' => env('HELLOWIN_FLYER_POINTS', '3,2,1'),

    // Probability to take off from a bush instead of coming from behind the screen edge
    'bush_chance' => env('HELLOWIN_FLYER_BUSH_CHANCE', 0.4),

    // Probability of a curved flight
    'arc_chance' => env('HELLOWIN_FLYER_ARC_CHANCE', 0.5),

    // Kinds picked at random for every launch; how each kind looks is up to the frontend
    'kinds' => env('HELLOWIN_FLYER_KINDS', 'duck,chicken'),
];
