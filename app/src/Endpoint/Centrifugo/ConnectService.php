<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Config\HellowinConfig;
use App\Domain\Player\PlayerId;
use RoadRunner\Centrifugo\Payload\ConnectResponse;
use RoadRunner\Centrifugo\Request\Connect;
use RoadRunner\Centrifugo\Request\RequestInterface;
use Spiral\RoadRunnerBridge\Centrifugo\ServiceInterface;

/**
 * Accepts every connection and subscribes it to the game channel server-side, so the client never calls
 * subscribe itself.
 *
 * A connection carrying `playerSecret` in its data becomes that player: the response names its public id as the
 * user, and Centrifugo puts it into every RPC of the connection, so a client cannot act as someone else. Without
 * a valid secret the connection is anonymous. The secret itself is neither logged nor passed on.
 */
final readonly class ConnectService implements ServiceInterface
{
    public function __construct(
        private HellowinConfig $config,
    ) {}

    #[\Override]
    public function handle(RequestInterface $request): void
    {
        \assert($request instanceof Connect);

        $request->respond(new ConnectResponse(
            user: PlayerId::fromSecret($request->getData()['playerSecret'] ?? null) ?? '',
            channels: [$this->config->getChannel()],
        ));
    }
}
