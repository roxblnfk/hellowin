<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Config\HellowinConfig;
use App\Endpoint\Centrifugo\SubscribeService;
use RoadRunner\Centrifugal\Proxy\DTO\V1\SubscribeResponse;
use RoadRunner\Centrifugo\Request\Subscribe;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\SpyWorker;

#[Test]
#[Covers(SubscribeService::class)]
final class SubscribeServiceTest
{
    public function allowsTheGameChannel(): void
    {
        $response = self::subscribe('hellowin');

        Assert::null($response->getError());
    }

    public function deniesOtherChannels(): void
    {
        $response = self::subscribe('admin');

        Assert::same($response->getError()?->getCode(), 103);
    }

    private static function subscribe(string $channel): SubscribeResponse
    {
        $worker = new SpyWorker();
        $service = new SubscribeService(new HellowinConfig(['channel' => 'hellowin']));

        $service->handle(new Subscribe(
            worker: $worker,
            client: 'client-1',
            transport: 'websocket',
            protocol: 'json',
            encoding: 'json',
            user: '',
            channel: $channel,
            token: '',
            meta: [],
            data: [],
            headers: [],
        ));

        return $worker->lastResponse(SubscribeResponse::class);
    }
}
