<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Hellowin\SnapshotFactory;
use App\Endpoint\Centrifugo\RPCService;
use Psr\Log\NullLogger;
use RoadRunner\Centrifugal\Proxy\DTO\V1\RPCResponse;
use RoadRunner\Centrifugo\Request\RPC;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\RitualHarness;
use Tests\Fake\SpyWorker;

#[Test]
#[Covers(RPCService::class)]
#[Covers(SnapshotFactory::class)]
final class RPCServiceTest
{
    private SpyWorker $worker;

    public function clickIsAccepted(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => true]);
        Assert::count($ritual->broadcast->ofType('click'), 1);
    }

    public function unknownElementIsRejected(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'click', ['element' => 'ghost-42']);

        Assert::same($response, ['ok' => false, 'error' => 'unknown element']);
        Assert::blank($ritual->broadcast->published);
        Assert::blank($ritual->metrics->calls);
    }

    public function missingElementIsRejected(): void
    {
        $response = $this->call(new RitualHarness(), 'click', []);

        Assert::same($response, ['ok' => false, 'error' => 'unknown element']);
    }

    public function unknownMethodIsRejected(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'explode', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => false, 'error' => 'unknown method']);
        Assert::blank($ritual->broadcast->published);
    }

    public function busyRitualIsReported(): void
    {
        $ritual = new RitualHarness(lock: new FakeLock(available: false));

        $response = $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => false, 'error' => 'busy']);
        Assert::blank($ritual->broadcast->published);
    }

    public function stateReturnsTheSnapshot(): void
    {
        $ritual = new RitualHarness(threshold: 4, stageMax: [4, 6, 8]);
        $ritual->click('pumpkin-2', 2);

        $response = $this->call($ritual, 'state', []);

        Assert::equals($response, ['ok' => true, 'state' => [
            'round' => 1,
            'scene' => 0,
            'sceneStages' => 3,
            'stageMax' => 4,
            'threshold' => 4,
            'elements' => [
                ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 10.0, 'y' => 20.0, 'size' => 'm', 'stage' => 0, 'clicks' => 0],
                ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 10.0, 'y' => 20.0, 'size' => 'm', 'stage' => 0, 'clicks' => 2],
            ],
        ]]);
    }

    #[BeforeTest]
    public function createWorker(): void
    {
        $this->worker = new SpyWorker();
    }

    private function call(RitualHarness $ritual, string $method, array $params): array
    {
        $service = new RPCService($ritual->clicks, $ritual->snapshot, new NullLogger());
        $service->handle(new RPC(
            worker: $this->worker,
            client: 'client-1',
            transport: 'websocket',
            protocol: 'json',
            encoding: 'json',
            user: '',
            method: $method,
            meta: [],
            data: $params,
            headers: [],
        ));

        $data = $this->worker->lastResponse(RPCResponse::class)->getResult()?->getData() ?? '';

        return (array) \json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
    }
}
