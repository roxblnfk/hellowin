<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ClickMetrics;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Game\GameState;
use App\Endpoint\Centrifugo\RPCService;
use Psr\Log\NullLogger;
use RoadRunner\Centrifugal\Proxy\DTO\V1\RPCResponse;
use RoadRunner\Centrifugo\Request\RPC;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\SpyBroadcast;
use Tests\Fake\SpyMetrics;
use Tests\Fake\SpyWorker;

#[Test]
#[Covers(RPCService::class)]
#[Covers(ClickHandler::class)]
final class RPCServiceTest
{
    private SpyBroadcast $broadcast;
    private SpyMetrics $metrics;
    private SpyWorker $worker;

    public function clickPublishesAnEmojiOverTheElement(): void
    {
        $service = $this->service(threshold: 5);

        $response = $this->call($service, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => true]);
        Assert::same($this->broadcast->published, [[
            'topic' => 'hellowin',
            'message' => ['type' => 'emoji', 'element' => 'pumpkin-1', 'emoji' => '🎃', 'clicks' => 1, 'threshold' => 5],
        ]]);
    }

    public function clickIsCountedInMetrics(): void
    {
        $service = $this->service(threshold: 5);

        $this->call($service, 'click', ['element' => 'pumpkin-1']);

        Assert::same($this->metrics->calls, [
            ['add', ClickMetrics::CLICKS, 1.0, ['pumpkin', 'pumpkin-1']],
            ['set', ClickMetrics::STAGE, 0.0, []],
        ]);
    }

    public function crossingAThresholdPublishesTheNewState(): void
    {
        $service = $this->service(threshold: 2);

        $this->call($service, 'click', ['element' => 'pumpkin-1']);
        $this->call($service, 'click', ['element' => 'pumpkin-1']);

        Assert::count($this->broadcast->ofType('emoji'), 2);
        Assert::same($this->broadcast->ofType('state'), [
            ['type' => 'state', 'stage' => 1, 'clicks' => 2, 'threshold' => 2],
        ]);
    }

    public function unknownElementIsRejected(): void
    {
        $service = $this->service(threshold: 5);

        $response = $this->call($service, 'click', ['element' => 'ghost-42']);

        Assert::same($response, ['ok' => false, 'error' => 'unknown element']);
        Assert::blank($this->broadcast->published);
        Assert::blank($this->metrics->calls);
    }

    public function missingElementIsRejected(): void
    {
        $response = $this->call($this->service(threshold: 5), 'click', []);

        Assert::same($response, ['ok' => false, 'error' => 'unknown element']);
    }

    public function unknownMethodIsRejected(): void
    {
        $response = $this->call($this->service(threshold: 5), 'explode', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => false, 'error' => 'unknown method']);
        Assert::blank($this->broadcast->published);
    }

    public function busyStateIsReported(): void
    {
        $service = $this->service(threshold: 5, lock: new FakeLock(available: false));

        $response = $this->call($service, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => false, 'error' => 'busy']);
        Assert::blank($this->broadcast->published);
    }

    public function failingMetricsDoNotBreakTheClick(): void
    {
        $this->metrics = new SpyMetrics(failing: true);
        $service = $this->service(threshold: 5);

        $response = $this->call($service, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => true]);
        Assert::count($this->broadcast->ofType('emoji'), 1);
    }

    #[BeforeTest]
    public function setUp(): void
    {
        $this->broadcast = new SpyBroadcast();
        $this->metrics = new SpyMetrics();
        $this->worker = new SpyWorker();
    }

    private function service(int $threshold, FakeLock $lock = new FakeLock()): RPCService
    {
        $handler = new ClickHandler(
            new ElementRegistry([new Element('pumpkin-1', 'pumpkin', 10, 20, ['🎃'])]),
            new GameState(new ArrayStorage(), $lock, $threshold),
            new ClickMetrics($this->metrics, new NullLogger()),
            new ChannelPublisher($this->broadcast, 'hellowin'),
        );

        return new RPCService($handler, new NullLogger());
    }

    private function call(RPCService $service, string $method, array $params): array
    {
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
