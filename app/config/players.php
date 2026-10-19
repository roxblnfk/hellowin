<?php

declare(strict_types=1);

/**
 * @see \App\Application\Config\PlayerConfig
 */
return [
    // Milliseconds between leaderboard builds of the ticker; every build is published as a `leaderboard` event
    'leaderboard_interval' => env('HELLOWIN_LEADERBOARD_INTERVAL', 5000),

    // Players in the leaderboard
    'leaderboard_size' => env('HELLOWIN_LEADERBOARD_SIZE', 10),
];
