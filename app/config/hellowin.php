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

    // Every limit of the game (docs/spec/protocol.md, «Лимиты и баланс») is
    // `max(1, ceil(k × (base + per_player × (n − 1)))) + the moderator's bonus`, n the figures on the floor (at least
    // one), k the moderator's multiplier; fractions are fine. The crowd plays longer, a lone player still finishes fast.
    // `pumpkin`, `bonfire`, `circle` — clicks per stage of the element; `bar` — mugs the bar drinks before it may leave;
    // `drinks`, `zombies` — the goals of the cursed world; `boss` — FrankenPHP's health
    'limits' => [
        'pumpkin' => ['base' => env('HELLOWIN_PUMPKIN_BASE', 2), 'per_player' => env('HELLOWIN_PUMPKIN_PER_PLAYER', 5)],
        'bonfire' => ['base' => env('HELLOWIN_BONFIRE_BASE', 8), 'per_player' => env('HELLOWIN_BONFIRE_PER_PLAYER', 20)],
        'circle' => ['base' => env('HELLOWIN_CIRCLE_BASE', 15), 'per_player' => env('HELLOWIN_CIRCLE_PER_PLAYER', 40)],
        'bar' => ['base' => env('HELLOWIN_BAR_BASE', 5), 'per_player' => env('HELLOWIN_BAR_PER_PLAYER', 1.5)],
        'drinks' => ['base' => env('HELLOWIN_CURSED_DRINKS_BASE', 5), 'per_player' => env('HELLOWIN_CURSED_DRINKS_PER_PLAYER', 4)],
        'zombies' => ['base' => env('HELLOWIN_CURSED_ZOMBIES_BASE', 5), 'per_player' => env('HELLOWIN_CURSED_ZOMBIES_PER_PLAYER', 4)],
        'boss' => ['base' => env('HELLOWIN_BOSS_HP_BASE', 45), 'per_player' => env('HELLOWIN_BOSS_HP_PER_PLAYER', 60)],
    ],

    // A connection passing this secret is the moderator and drives the story from the console; empty turns it off
    'moderator_secret' => env('HELLOWIN_MODERATOR_SECRET', ''),

    // Last pumpkin stage of each scene, cumulative; the scene after the last one is the final scene. A leading 0 makes
    // scene 0 the bar: no pumpkins to click there, ready once the room has drunk the limit `bar`
    'scene_stage_max' => env('HELLOWIN_SCENE_STAGE_MAX', '0,4,8,11'),

    // The bonfire in the middle: its last stage by scene, cumulative like the pumpkins'; 0 in a scene leaves it out there
    'bonfire_stage_max' => env('HELLOWIN_BONFIRE_STAGE_MAX', '0,0,4,7'),

    // The magic circle on the ashes of the final scene: its last stage, where it is closed
    'circle_stages' => env('HELLOWIN_CIRCLE_STAGES', 4),

    // The bar of scene 0: one full mug per player on the floor, up to the `mugs` the counter has room for; the owl
    // pours one in `pour_ms` milliseconds and mutters a rumour over every pour with the chance `rumor_chance`.
    // A sip plays `drink_ms` milliseconds, in the bar and in the cursed world: the server takes no mug and lets none be
    // drunk meanwhile, so a click never skips the animation
    'bar' => [
        'mugs' => env('HELLOWIN_BAR_MUGS', 12),
        'pour_ms' => env('HELLOWIN_BAR_POUR_MS', 2500),
        'rumor_chance' => env('HELLOWIN_BAR_RUMOR_CHANCE', 0.33),
        'drink_ms' => env('HELLOWIN_DRINK_MS', 1200),
    ],

    // The end of the night (docs/spec/scenario.md, «Рапира»): the dawn ranks its shooters, `dawn_board` rows at most.
    // A pumpkin hands its beer over for `beer_handover_ms` milliseconds after the character arrives
    'rapier' => [
        'dawn_board' => env('HELLOWIN_DAWN_BOARD', 200),
        'beer_handover_ms' => env('HELLOWIN_BEER_HANDOVER_MS', 900),
    ],

    // The dice of finale step 1 (docs/spec/scenario.md, «Кости 6-6-6»): the faces are HMAC-SHA256 of the attempt under
    // `secret`; empty derives it from ENCRYPTER_KEY, and without that key the first throw draws a random one and keeps
    // it in Valkey. A player throws anew at most once in `cooldown_ms` milliseconds
    'dice' => [
        'secret' => env('HELLOWIN_DICE_SECRET', ''),
        'encrypter_key' => env('ENCRYPTER_KEY', ''),
        'cooldown_ms' => env('HELLOWIN_DICE_COOLDOWN_MS', 1800),
    ],

    // The players' chat (docs/spec/protocol.md, «Чат»): a message is `max` characters at most after the cleaning, and
    // the moderator's history keeps the last `history` messages
    'chat' => [
        'max' => env('HELLOWIN_CHAT_MAX', 120),
        'history' => env('HELLOWIN_CHAT_HISTORY', 50),
    ],

    // RPC limits per player (or per connection without one) and group of methods: "<per second>,<burst>" of a token
    // bucket, 0 turns the group's limit off; the moderator is never limited. `other` is every method not listed
    'rpc_rate' => [
        'click' => env('HELLOWIN_RPC_RATE_CLICK', '20,40'),
        'shoot' => env('HELLOWIN_RPC_RATE_SHOOT', '20,40'),
        'move' => env('HELLOWIN_RPC_RATE_MOVE', '10,20'),
        'say' => env('HELLOWIN_RPC_RATE_SAY', '1,3'),
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
    // hands out beer — from then on every character holds its own, a beer, nothing or the rapier — and at dawn
    // everyone holds a rapier
    'finale_items' => [0 => 'red-flame', 1 => null, 2 => null, 3 => null, 4 => null, 5 => 'beer', 7 => 'rapier'],

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
        // characters put back on the floor at once appear in the band `spawn`, a new one walks in through the entrance
        // door `door` (its threshold, where the back wall meets the floor), and two characters at most `clink_reach`
        // apart clink by themselves. The frontend draws the bar over these numbers, each table with its benches and the
        // counter half a character inside its obstacle: characters walk by their feet, so their bodies keep off the
        // furniture
        'bar' => [
            'area' => ['x1' => 3, 'y1' => 50, 'x2' => 94, 'y2' => 97],
            'obstacles' => [
                ['x1' => 62.5, 'y1' => 46, 'x2' => 95, 'y2' => 60],
                ['cx' => 22, 'cy' => 64, 'rx' => 13, 'ry' => 7],
                ['cx' => 44, 'cy' => 78, 'rx' => 13, 'ry' => 7],
                ['cx' => 76, 'cy' => 80, 'rx' => 13, 'ry' => 7],
            ],
            'counter' => ['x1' => 66, 'x2' => 92, 'y' => 62],
            'spawn' => ['y1' => 90, 'y2' => 94],
            'door' => ['x' => 12, 'y' => 50],
            // Percents of the screen a new character walks in from the threshold, down the screen
            'door_in' => 6,
            // RoadRunner behind the counter after the dawn, next to the owl: y the counter's back edge, as the owl's
            'roadrunner' => ['x' => 74, 'y' => 46],
            // Where a fly lands besides the table tops and the counter: a band x1..x2 at the height y (a point when
            // x1 == x2), y the top of the surface a fly stands on. The frontend draws these things exactly there: the
            // beam, the door's lintel, the hearth's mantel, the shelves over the counter, the shields' rims and the
            // barrels' lids
            'perches' => [
                ['x1' => 3, 'x2' => 97, 'y' => 8, 'name' => 'beam'],
                ['x1' => 9, 'x2' => 15, 'y' => 30, 'name' => 'lintel'],
                ['x1' => 45, 'x2' => 55, 'y' => 36, 'name' => 'mantel'],
                ['x1' => 67, 'x2' => 91, 'y' => 26, 'name' => 'shelf-top'],
                ['x1' => 67, 'x2' => 91, 'y' => 33, 'name' => 'shelf'],
                ['x1' => 25, 'x2' => 25, 'y' => 33, 'name' => 'shield-1'],
                ['x1' => 36, 'x2' => 36, 'y' => 33, 'name' => 'shield-2'],
                ['x1' => 31, 'x2' => 31, 'y' => 20, 'name' => 'shield-3'],
                ['x1' => 61, 'x2' => 61, 'y' => 20, 'name' => 'shield-4'],
                ['x1' => 18, 'x2' => 22, 'y' => 45, 'name' => 'barrels'],
            ],
            'clink_reach' => 10,
        ],
    ],
];
