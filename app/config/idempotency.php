<?php

declare(strict_types=1);

use Spiral\Idempotency\Driver\Redis\RedisAtMostOnceConfig;
use Spiral\Idempotency\Queue\QueueKeyMiddleware;
use Spiral\Idempotency\Queue\QueueRetryMiddleware;

/**
 * @link https://github.com/spiral/idempotency
 */
return [
    'default' => 'transitions',

    // Resolution middleware per transport, outer → inner. Transition jobs take the key from the payload
    // (`#[Idempotent(key: 'payload.key')]`), so QueueKeyMiddleware only passes it through.
    'transports' => [
        'queue' => [QueueRetryMiddleware::class, QueueKeyMiddleware::class],
    ],

    'storages' => [
        // Duplicate transition jobs are expected: every click over the threshold pushes one until the first
        // is done. The marker is written before the handler runs, so a duplicate never reaches it. The
        // window is minutes, not a day: a worker crash between the marker and the effect loses the
        // transition, and the pumpkin can move again only once the marker expires — the stage check of
        // the handler keeps that retry from moving it twice.
        'transitions' => new RedisAtMostOnceConfig(
            keyPrefix: 'hellowin:amo:',
            retentionTtl: 300,
        ),
    ],
];
