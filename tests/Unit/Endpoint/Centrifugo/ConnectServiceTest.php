<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Config\HellowinConfig;
use App\Endpoint\Centrifugo\ConnectService;
use RoadRunner\Centrifugal\Proxy\DTO\V1\ConnectResponse;
use RoadRunner\Centrifugo\Request\Connect;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\SpyWorker;

#[Test]
#[Covers(ConnectService::class)]
final class ConnectServiceTest
{
    public function subscribesAnonymousClientToTheGameChannel(): void
    {
        $worker = new SpyWorker();
        $service = new ConnectService(new HellowinConfig(['channel' => 'spooky']));

        $service->handle(new Connect(
            worker: $worker,
            client: 'client-1',
            transport: 'websocket',
            protocol: 'json',
            encoding: 'json',
            data: [],
            name: null,
            version: null,
            channels: [],
            headers: [],
        ));

        $result = $worker->lastResponse(ConnectResponse::class)->getResult();
        Assert::notBlank($result);
        Assert::same($result->getUser(), '');
        Assert::same(\iterator_to_array($result->getChannels()), ['spooky']);
    }
}
