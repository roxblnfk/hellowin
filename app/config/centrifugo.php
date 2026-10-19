<?php

declare(strict_types=1);

use App\Endpoint\Centrifugo;
use RoadRunner\Centrifugo\Request\RequestType;

/**
 * Handlers for requests Centrifugo proxies to RoadRunner.
 * Keys are {@see RequestType} values (`subrefresh`, not `sub_refresh`).
 *
 * @link https://spiral.dev/docs/websockets-services
 */
return [
    'services' => [
        RequestType::Connect->value => Centrifugo\ConnectService::class,
        RequestType::Subscribe->value => Centrifugo\SubscribeService::class,
        RequestType::RPC->value => Centrifugo\RPCService::class,
    ],
    'interceptors' => [],
];
