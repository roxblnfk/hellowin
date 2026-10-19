<?php

declare(strict_types=1);

/**
 * @see \App\Application\Config\HellowinConfig
 */
return [
    // Centrifugo channel every client is subscribed to on connect
    'channel' => 'hellowin',

    // Cache storage (app/config/cache.php) that keeps the ritual: round, scene, pumpkin stages and clicks
    'storage' => 'hellowin',

    // WebSocket endpoint for centrifuge-js; a path is resolved against the page host
    'centrifugo_url' => env('CENTRIFUGO_WS_URL', '/connection/websocket'),

    // Clicks on a pumpkin that move it to its next stage
    'clicks_per_stage' => env('HELLOWIN_CLICKS_PER_STAGE', 5),

    // Last pumpkin stage of each scene, cumulative; the scene after the last one is the final scene
    'scene_stage_max' => env('HELLOWIN_SCENE_STAGE_MAX', '4,6,8'),

    // Valkey of the idempotency storage (app/config/idempotency.php)
    'valkey_dsn' => env('VALKEY_DSN', 'tcp://valkey:6379'),

    // Around the root in the centre of the screen: x/y are percents of the viewport, size is s, m or l
    'elements' => [
        ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 48.7, 'y' => 51.2, 'size' => 'm', 'emojis' => ['🎃', '👻', '🦇']],
        ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 55.9, 'y' => 56.6, 'size' => 's', 'emojis' => ['🎃', '🍬', '🍭']],
        ['id' => 'pumpkin-3', 'type' => 'pumpkin', 'x' => 65.9, 'y' => 48.3, 'size' => 's', 'emojis' => ['🎃', '🕷️', '🕸️']],
        ['id' => 'pumpkin-4', 'type' => 'pumpkin', 'x' => 64.3, 'y' => 55.9, 'size' => 's', 'emojis' => ['🎃', '💀', '☠️']],
        ['id' => 'pumpkin-5', 'type' => 'pumpkin', 'x' => 73.2, 'y' => 59.5, 'size' => 'm', 'emojis' => ['🎃', '🧙', '🔮']],
        ['id' => 'pumpkin-6', 'type' => 'pumpkin', 'x' => 64.6, 'y' => 63.4, 'size' => 's', 'emojis' => ['🎃', '🧛', '🩸']],
        ['id' => 'pumpkin-7', 'type' => 'pumpkin', 'x' => 71, 'y' => 72.4, 'size' => 'l', 'emojis' => ['🎃', '🧟', '🪦']],
        ['id' => 'pumpkin-8', 'type' => 'pumpkin', 'x' => 59.6, 'y' => 70.8, 'size' => 'm', 'emojis' => ['🎃', '🐈‍⬛', '🌕']],
        ['id' => 'pumpkin-9', 'type' => 'pumpkin', 'x' => 60.9, 'y' => 80.4, 'size' => 'm', 'emojis' => ['🎃', '👻', '🍫']],
        ['id' => 'pumpkin-10', 'type' => 'pumpkin', 'x' => 50.6, 'y' => 79.4, 'size' => 'l', 'emojis' => ['🎃', '🦉', '🕯️']],
        ['id' => 'pumpkin-11', 'type' => 'pumpkin', 'x' => 40.2, 'y' => 84.8, 'size' => 'm', 'emojis' => ['🎃', '🍂', '🌾']],
        ['id' => 'pumpkin-12', 'type' => 'pumpkin', 'x' => 39, 'y' => 75.1, 'size' => 'm', 'emojis' => ['🎃', '🕷️', '🧹']],
        ['id' => 'pumpkin-13', 'type' => 'pumpkin', 'x' => 27.1, 'y' => 76.5, 'size' => 'l', 'emojis' => ['🎃', '🦇', '🌑']],
        ['id' => 'pumpkin-14', 'type' => 'pumpkin', 'x' => 33.3, 'y' => 67.7, 'size' => 's', 'emojis' => ['🎃', '🍁', '🐿️']],
        ['id' => 'pumpkin-15', 'type' => 'pumpkin', 'x' => 25.3, 'y' => 60.3, 'size' => 'm', 'emojis' => ['🎃', '🧪', '⚗️']],
        ['id' => 'pumpkin-16', 'type' => 'pumpkin', 'x' => 38.6, 'y' => 62.1, 'size' => 's', 'emojis' => ['🎃', '🕸️', '🪄']],
        ['id' => 'pumpkin-17', 'type' => 'pumpkin', 'x' => 34, 'y' => 52.4, 'size' => 's', 'emojis' => ['🎃', '🐺', '🌕']],
        ['id' => 'pumpkin-18', 'type' => 'pumpkin', 'x' => 43.3, 'y' => 56.5, 'size' => 's', 'emojis' => ['🎃', '🍄', '🦔']],
    ],

    // Bushes ducks fly out of, on the edges of the patch: x/y are percents of the viewport
    'bushes' => [
        ['id' => 'bush-1', 'x' => 4, 'y' => 86],
        ['id' => 'bush-2', 'x' => 96, 'y' => 88],
        ['id' => 'bush-3', 'x' => 2, 'y' => 62],
        ['id' => 'bush-4', 'x' => 98, 'y' => 66],
        ['id' => 'bush-5', 'x' => 16, 'y' => 44],
        ['id' => 'bush-6', 'x' => 88, 'y' => 43],
    ],
];
