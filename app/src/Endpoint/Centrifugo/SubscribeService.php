<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Config\HellowinConfig;
use RoadRunner\Centrifugo\Payload\SubscribeResponse;
use RoadRunner\Centrifugo\Request\RequestInterface;
use RoadRunner\Centrifugo\Request\Subscribe;
use Spiral\RoadRunnerBridge\Centrifugo\ServiceInterface;

/**
 * Client-side subscriptions are allowed only to the game channel.
 * Takes effect only when Centrifugo proxies subscribe requests to the app.
 */
final readonly class SubscribeService implements ServiceInterface
{
    /** Centrifugo client error code "permission denied". */
    private const PERMISSION_DENIED = 103;

    public function __construct(
        private HellowinConfig $config,
    ) {}

    /**
     * @param Subscribe $request
     */
    #[\Override]
    public function handle(RequestInterface $request): void
    {
        if ($request->channel !== $this->config->getChannel()) {
            $request->error(self::PERMISSION_DENIED, 'permission denied');
            return;
        }

        $request->respond(new SubscribeResponse());
    }
}
