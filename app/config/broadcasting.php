<?php

declare(strict_types=1);

use Spiral\Broadcasting\Driver\LogBroadcast;
use Spiral\Broadcasting\Driver\NullBroadcast;

/**
 * @link https://spiral.dev/docs/websockets-broadcasting
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'centrifugo'),
    'authorize' => [
        'path' => null,
        'topics' => [],
    ],
    'aliases' => [],
    'connections' => [
        'centrifugo' => [
            'driver' => 'centrifugo',
        ],
        'log' => [
            'driver' => 'log',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
    // `centrifugo` is appended by CentrifugoBootloader
    'driverAliases' => [
        'null' => NullBroadcast::class,
        'log' => LogBroadcast::class,
    ],
];
