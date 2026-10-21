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

    // WebSocket endpoint for centrifuge-js and the Grafana dashboard: ":<port>/path" is completed with the host the
    // page was opened by (\App\Application\Web\ServiceUrl), a full URL or a path is used as is
    'centrifugo_url' => env('CENTRIFUGO_WS_URL', ':8000/connection/websocket'),
    'grafana_url' => env('GRAFANA_URL', ':3000/'),

    // Clicks on a pumpkin that move it to its next stage
    'clicks_per_stage' => env('HELLOWIN_CLICKS_PER_STAGE', 5),

    // A connection passing this secret is the moderator and drives the story from the console; empty turns it off
    'moderator_secret' => env('HELLOWIN_MODERATOR_SECRET', ''),

    // Last pumpkin stage of each scene, cumulative; the scene after the last one is the final scene. A leading 0 makes
    // scene 0 the bar: no pumpkins to click there, always ready
    'scene_stage_max' => env('HELLOWIN_SCENE_STAGE_MAX', '0,4,8,11'),

    // The bonfire in the middle: clicks per stage (the whole room clicks it, so more than a pumpkin) and its last
    // stage by scene, cumulative like the pumpkins'; 0 in a scene leaves it out there
    'bonfire_clicks_per_stage' => env('HELLOWIN_BONFIRE_CLICKS_PER_STAGE', 20),
    'bonfire_stage_max' => env('HELLOWIN_BONFIRE_STAGE_MAX', '0,0,4,7'),

    // The magic circle on the ashes of the final scene: clicks per stage and its last stage, where it is closed
    'circle_clicks_per_stage' => env('HELLOWIN_CIRCLE_CLICKS_PER_STAGE', 20),
    'circle_stages' => env('HELLOWIN_CIRCLE_STAGES', 4),

    // RPC limits per player (or per connection without one) and group of methods: "<per second>,<burst>" of a token
    // bucket, 0 turns the group's limit off; the moderator is never limited. `other` is every method not listed
    'rpc_rate' => [
        'click' => env('HELLOWIN_RPC_RATE_CLICK', '20,40'),
        'shoot' => env('HELLOWIN_RPC_RATE_SHOOT', '20,40'),
        'move' => env('HELLOWIN_RPC_RATE_MOVE', '10,20'),
        'other' => env('HELLOWIN_RPC_RATE_OTHER', '10,30'),
    ],

    // Valkey of the idempotency storage (app/config/idempotency.php)
    'valkey_dsn' => env('VALKEY_DSN', 'tcp://valkey:6379'),

    // Pumpkins around the root in the centre of the screen and, in the very middle, the bonfire on the tops and later
    // the magic circle on its ashes: x/y are percents of the viewport, a pumpkin's size is s, m or l
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
        ['id' => 'bonfire', 'type' => 'bonfire', 'x' => 50, 'y' => 63, 'emojis' => ['🔥', '✨', '💨']],
        ['id' => 'circle', 'type' => 'circle', 'x' => 50, 'y' => 63, 'emojis' => ['🩸', '🔮', '🕯️']],
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

    // The horizon line in percents of the viewport, from the top
    'horizon' => 38,

    // What every character holds, by scene number; null is empty hands. The frontend draws an item by its id. In the
    // bar every character holds its own: a beer or nothing
    'items' => [null, 'pistol', 'candle', 'book', 'red-flame'],

    // In the finale: the item every character holds from a step on, until a later step gives another; null is empty
    // hands, a step left out keeps what the previous one gave. The circle is drawn with a red flame, the cursed world
    // hands out beer
    'finale_items' => [0 => 'red-flame', 1 => null, 2 => null, 3 => null, 4 => null, 5 => 'beer'],

    // Where players' characters walk: the lawn rectangle minus the patch ellipse, in percents of the viewport like
    // the elements (players.md, «На поле»)
    'walk' => [
        'area' => ['x1' => 3, 'y1' => 44, 'x2' => 97, 'y2' => 97],
        'patch' => ['cx' => 50, 'cy' => 63, 'rx' => 31, 'ry' => 25],

        // New characters appear at a random point of this band: lower on a phone lies the HUD
        'spawn' => ['y1' => 86, 'y2' => 90],

        // Percents of the screen per second along the path, the same for every character
        'speed' => env('HELLOWIN_WALK_SPEED', 12),

        // Milliseconds a player may stay without connections before the character leaves: a reloaded page keeps it
        'leave_after' => env('HELLOWIN_WALK_LEAVE_AFTER', 5000),

        // Clinking in the cursed world (scenario.md, «Чоканье»): a pumpkin at most `reach` percents from the lawn is
        // reachable, a character clinking with another stands `clink_gap` percents to its side, and a clink plays
        // `clink_duration` ms — the frontend's timeline of the clink
        'reach' => 10,
        'clink_gap' => 4,
        'clink_duration' => 3200,

        // The bar of scene 0 (docs/spec/protocol.md, «Бар»): the floor minus the obstacles — tables are ellipses
        // {cx, cy, rx, ry}, the counter a rectangle {x1, y1, x2, y2}; a mug is taken standing on the line `counter`,
        // new characters appear in the band `spawn`, and two characters at most `clink_reach` apart clink by
        // themselves. The frontend draws the bar over these numbers
        'bar' => [
            'area' => ['x1' => 3, 'y1' => 50, 'x2' => 97, 'y2' => 97],
            'obstacles' => [
                ['x1' => 64, 'y1' => 46, 'x2' => 100, 'y2' => 60],
                ['cx' => 22, 'cy' => 64, 'rx' => 10, 'ry' => 4.5],
                ['cx' => 44, 'cy' => 78, 'rx' => 10, 'ry' => 4.5],
                ['cx' => 76, 'cy' => 80, 'rx' => 10, 'ry' => 4.5],
            ],
            'counter' => ['x1' => 66, 'x2' => 95, 'y' => 62],
            'spawn' => ['y1' => 90, 'y2' => 94],
            'clink_reach' => 10,
        ],
    ],
];
