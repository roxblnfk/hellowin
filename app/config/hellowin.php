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

    // RPC `reset` behind the "Заново" button: open to everyone in the demo, turn off for a public event
    'reset_rpc' => env('HELLOWIN_RESET_RPC', true),

    // Last pumpkin stage of each scene, cumulative; the scene after the last one is the final scene
    'scene_stage_max' => env('HELLOWIN_SCENE_STAGE_MAX', '4,6,8'),

    // Valkey of the idempotency storage (app/config/idempotency.php)
    'valkey_dsn' => env('VALKEY_DSN', 'tcp://valkey:6379'),

    // Around the root in the centre of the screen: x/y are percents of the viewport, size is s, m or l
    'elements' => [
        ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 47.8, 'y' => 51.2, 'size' => 'm', 'emojis' => ['🎃', '👻', '🦇']],
        ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 55.7, 'y' => 55, 'size' => 's', 'emojis' => ['🎃', '🍬', '🍭']],
        ['id' => 'pumpkin-3', 'type' => 'pumpkin', 'x' => 66.2, 'y' => 48.3, 'size' => 's', 'emojis' => ['🎃', '🕷️', '🕸️']],
        ['id' => 'pumpkin-4', 'type' => 'pumpkin', 'x' => 62.8, 'y' => 56, 'size' => 's', 'emojis' => ['🎃', '💀', '☠️']],
        ['id' => 'pumpkin-5', 'type' => 'pumpkin', 'x' => 71.2, 'y' => 58.4, 'size' => 'm', 'emojis' => ['🎃', '🧙', '🔮']],
        ['id' => 'pumpkin-6', 'type' => 'pumpkin', 'x' => 64.7, 'y' => 63.7, 'size' => 's', 'emojis' => ['🎃', '🧛', '🩸']],
        ['id' => 'pumpkin-7', 'type' => 'pumpkin', 'x' => 71, 'y' => 72.8, 'size' => 'l', 'emojis' => ['🎃', '🧟', '🪦']],
        ['id' => 'pumpkin-8', 'type' => 'pumpkin', 'x' => 59.6, 'y' => 71.1, 'size' => 'm', 'emojis' => ['🎃', '🐈‍⬛', '🌕']],
        ['id' => 'pumpkin-9', 'type' => 'pumpkin', 'x' => 60.7, 'y' => 80.8, 'size' => 'm', 'emojis' => ['🎃', '👻', '🍫']],
        ['id' => 'pumpkin-10', 'type' => 'pumpkin', 'x' => 50.4, 'y' => 82.7, 'size' => 'l', 'emojis' => ['🎃', '🦉', '🕯️']],
        ['id' => 'pumpkin-11', 'type' => 'pumpkin', 'x' => 40, 'y' => 83.7, 'size' => 'm', 'emojis' => ['🎃', '🍂', '🌾']],
        ['id' => 'pumpkin-12', 'type' => 'pumpkin', 'x' => 43.6, 'y' => 74.1, 'size' => 'm', 'emojis' => ['🎃', '🕷️', '🧹']],
        ['id' => 'pumpkin-13', 'type' => 'pumpkin', 'x' => 29.3, 'y' => 78.5, 'size' => 'l', 'emojis' => ['🎃', '🦇', '🌑']],
        ['id' => 'pumpkin-14', 'type' => 'pumpkin', 'x' => 35.4, 'y' => 69.6, 'size' => 's', 'emojis' => ['🎃', '🍁', '🐿️']],
        ['id' => 'pumpkin-15', 'type' => 'pumpkin', 'x' => 27.7, 'y' => 60.2, 'size' => 'm', 'emojis' => ['🎃', '🧪', '⚗️']],
        ['id' => 'pumpkin-16', 'type' => 'pumpkin', 'x' => 40.6, 'y' => 64, 'size' => 's', 'emojis' => ['🎃', '🕸️', '🪄']],
        ['id' => 'pumpkin-17', 'type' => 'pumpkin', 'x' => 35.8, 'y' => 51.5, 'size' => 's', 'emojis' => ['🎃', '🐺', '🌕']],
        ['id' => 'pumpkin-18', 'type' => 'pumpkin', 'x' => 45.2, 'y' => 58.4, 'size' => 's', 'emojis' => ['🎃', '🍄', '🦔']],
    ],

    // Bushes birds fly out of, on the edges of the patch: x/y are percents of the viewport; depth is the flyer depth
    // they launch (front bushes the nearest birds, the ones by the horizon the middle ones)
    'bushes' => [
        ['id' => 'bush-1', 'x' => 4, 'y' => 86, 'depth' => 2],
        ['id' => 'bush-2', 'x' => 96, 'y' => 88, 'depth' => 2],
        ['id' => 'bush-3', 'x' => 2, 'y' => 62, 'depth' => 2],
        ['id' => 'bush-4', 'x' => 98, 'y' => 66, 'depth' => 2],
        ['id' => 'bush-5', 'x' => 16, 'y' => 44, 'depth' => 1],
        ['id' => 'bush-6', 'x' => 88, 'y' => 43, 'depth' => 1],
    ],

    // Where players' characters walk: the lawn rectangle minus the patch ellipse, in percents of the viewport like
    // the elements (players.md, «На поле»)
    'walk' => [
        'area' => ['x1' => 3, 'y1' => 44, 'x2' => 97, 'y2' => 97],
        'patch' => ['cx' => 50, 'cy' => 63, 'rx' => 31, 'ry' => 25],
    ],
];
