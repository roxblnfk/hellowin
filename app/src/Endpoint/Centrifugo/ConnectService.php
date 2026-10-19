<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Config\HellowinConfig;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Request\Connect;
use RoadRunner\Centrifugo\Request\RequestInterface;
use Spiral\RoadRunnerBridge\Centrifugo\ServiceInterface;

/**
 * Accepts anonymous connections and subscribes them to the game channel server-side,
 * so the client never calls subscribe itself.
 */
final readonly class ConnectService implements ServiceInterface
{
    public function __construct(
        private HellowinConfig $config,
    ) {}

    /**
     * @param Connect $request
     */
    #[\Override]
    public function handle(RequestInterface $request): void
    {
        $request->respond(new ConnectResponse(
            user: '',
            channels: [$this->config->getChannel()],
        ));
    }
}
