<?php

declare(strict_types=1);

use Spiral\Cache\Storage\ArrayStorage;

/**
 * @link https://spiral.dev/docs/basics-cache
 */
return [
    'default' => 'hellowin',
    'aliases' => [],
    'storages' => [
        // `driver` is the RoadRunner kv section name in .rr.yaml
        'hellowin' => [
            'type' => 'roadrunner',
            'driver' => 'hellowin',
        ],
        // In the memory of the RoadRunner process: gone with it, kept across worker restarts
        'process' => [
            'type' => 'roadrunner',
            'driver' => 'process',
        ],
    ],
    // `roadrunner` is appended by the RoadRunner bridge CacheBootloader
    'typeAliases' => [
        'array' => ArrayStorage::class,
    ],
];
