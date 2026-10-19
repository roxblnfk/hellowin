<?php

declare(strict_types=1);

/**
 * Logs go to the `roadrunner` channel by default: into RoadRunner's log under rr serve, to stderr otherwise.
 *
 * @link https://spiral.dev/docs/basics-logging
 */
return [
    'default' => env('MONOLOG_DEFAULT_CHANNEL', 'roadrunner'),
    'handlers' => [],
];
