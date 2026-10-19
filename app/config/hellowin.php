<?php

declare(strict_types=1);

/**
 * @see \App\Application\Config\HellowinConfig
 */
return [
    // Centrifugo channel every client is subscribed to on connect
    'channel' => 'hellowin',

    // Cache storage (app/config/cache.php) that keeps the click counter and stage
    'storage' => 'hellowin',

    // WebSocket endpoint for centrifuge-js; a path is resolved against the page host
    'centrifugo_url' => env('CENTRIFUGO_WS_URL', '/connection/websocket'),

    // Clicks after which the page switches from the initial stage to the next one, once
    'clicks_per_stage' => env('HELLOWIN_CLICKS_PER_STAGE', 5),

    // x/y are percents of the viewport
    'elements' => [
        ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 12, 'y' => 22, 'emojis' => ['🎃', '👻', '🦇']],
        ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 31, 'y' => 12, 'emojis' => ['🎃', '🍬', '🍭']],
        ['id' => 'pumpkin-3', 'type' => 'pumpkin', 'x' => 52, 'y' => 26, 'emojis' => ['🎃', '🕷️', '🕸️']],
        ['id' => 'pumpkin-4', 'type' => 'pumpkin', 'x' => 74, 'y' => 15, 'emojis' => ['🎃', '💀', '☠️']],
        ['id' => 'pumpkin-5', 'type' => 'pumpkin', 'x' => 88, 'y' => 38, 'emojis' => ['🎃', '🧙', '🔮']],
        ['id' => 'pumpkin-6', 'type' => 'pumpkin', 'x' => 20, 'y' => 55, 'emojis' => ['🎃', '🧛', '🩸']],
        ['id' => 'pumpkin-7', 'type' => 'pumpkin', 'x' => 42, 'y' => 68, 'emojis' => ['🎃', '🧟', '🪦']],
        ['id' => 'pumpkin-8', 'type' => 'pumpkin', 'x' => 63, 'y' => 52, 'emojis' => ['🎃', '🐈‍⬛', '🌕']],
        ['id' => 'pumpkin-9', 'type' => 'pumpkin', 'x' => 80, 'y' => 74, 'emojis' => ['🎃', '👻', '🍫']],
        ['id' => 'pumpkin-10', 'type' => 'pumpkin', 'x' => 8, 'y' => 82, 'emojis' => ['🎃', '🦉', '🕯️']],
    ],
];
