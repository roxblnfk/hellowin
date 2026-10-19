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
        ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 46.4, 'y' => 48.8, 'size' => 'm', 'emojis' => ['🎃', '👻', '🦇']],
        ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 56.6, 'y' => 55.1, 'size' => 's', 'emojis' => ['🎃', '🍬', '🍭']],
        ['id' => 'pumpkin-3', 'type' => 'pumpkin', 'x' => 78.3, 'y' => 50, 'size' => 's', 'emojis' => ['🎃', '🕷️', '🕸️']],
        ['id' => 'pumpkin-4', 'type' => 'pumpkin', 'x' => 71.8, 'y' => 57.4, 'size' => 's', 'emojis' => ['🎃', '💀', '☠️']],
        ['id' => 'pumpkin-5', 'type' => 'pumpkin', 'x' => 91.7, 'y' => 58, 'size' => 'm', 'emojis' => ['🎃', '🧙', '🔮']],
        ['id' => 'pumpkin-6', 'type' => 'pumpkin', 'x' => 79.4, 'y' => 63.8, 'size' => 's', 'emojis' => ['🎃', '🧛', '🩸']],
        ['id' => 'pumpkin-7', 'type' => 'pumpkin', 'x' => 89.5, 'y' => 73.3, 'size' => 'l', 'emojis' => ['🎃', '🧟', '🪦']],
        ['id' => 'pumpkin-8', 'type' => 'pumpkin', 'x' => 72.8, 'y' => 71.7, 'size' => 'm', 'emojis' => ['🎃', '🐈‍⬛', '🌕']],
        ['id' => 'pumpkin-9', 'type' => 'pumpkin', 'x' => 65.3, 'y' => 79.6, 'size' => 'm', 'emojis' => ['🎃', '👻', '🍫']],
        ['id' => 'pumpkin-10', 'type' => 'pumpkin', 'x' => 48.5, 'y' => 77.4, 'size' => 'l', 'emojis' => ['🎃', '🦉', '🕯️']],
        ['id' => 'pumpkin-11', 'type' => 'pumpkin', 'x' => 31.7, 'y' => 83.2, 'size' => 'm', 'emojis' => ['🎃', '🍂', '🌾']],
        ['id' => 'pumpkin-12', 'type' => 'pumpkin', 'x' => 31.8, 'y' => 72.5, 'size' => 'm', 'emojis' => ['🎃', '🕷️', '🧹']],
        ['id' => 'pumpkin-13', 'type' => 'pumpkin', 'x' => 10.6, 'y' => 73.2, 'size' => 'l', 'emojis' => ['🎃', '🦇', '🌑']],
        ['id' => 'pumpkin-14', 'type' => 'pumpkin', 'x' => 25.1, 'y' => 64.6, 'size' => 's', 'emojis' => ['🎃', '🍁', '🐿️']],
        ['id' => 'pumpkin-15', 'type' => 'pumpkin', 'x' => 7.8, 'y' => 59.5, 'size' => 'm', 'emojis' => ['🎃', '🧪', '⚗️']],
        ['id' => 'pumpkin-16', 'type' => 'pumpkin', 'x' => 27.5, 'y' => 56, 'size' => 's', 'emojis' => ['🎃', '🕸️', '🪄']],
        ['id' => 'pumpkin-17', 'type' => 'pumpkin', 'x' => 25.1, 'y' => 47.4, 'size' => 's', 'emojis' => ['🎃', '🐺', '🌕']],
        ['id' => 'pumpkin-18', 'type' => 'pumpkin', 'x' => 38.9, 'y' => 55.1, 'size' => 's', 'emojis' => ['🎃', '🍄', '🦔']],
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
