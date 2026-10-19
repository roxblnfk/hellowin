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
use Testo\Data\DataSet;
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
    private const PLAYER = '0123456789abcdef';

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

    public function clickOfAPlayerIsAttributed(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER);

        Assert::same($ritual->broadcast->ofType('click')[0]['player'], ['id' => self::PLAYER, 'name' => null]);
        Assert::same($ritual->playerStore->find(self::PLAYER)?->clicks, 1);
    }

    public function anonymousClickCarriesNoPlayer(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::false(\array_key_exists('player', $ritual->broadcast->ofType('click')[0]));
        Assert::blank($ritual->playerStore->ids());
    }

    public function registerNamesThePlayer(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 2, self::PLAYER);

        $response = $this->call($ritual, 'register', ['name' => '  Jack O. Lantern '], self::PLAYER);

        Assert::same($response, ['ok' => true, 'player' => [
            'id' => self::PLAYER,
            'name' => 'Jack O. Lantern',
            'clicks' => 2,
            'finished' => 0,
            'flyers' => 0,
            'points' => 0,
        ]]);
    }

    #[DataSet([''], 'empty')]
    #[DataSet(['   '], 'spaces')]
    #[DataSet(['---'], 'punctuation only')]
    #[DataSet(['<script>'], 'markup')]
    #[DataSet(['a  b'], 'double space')]
    #[DataSet(['abcdefghijklmnopqrstuvwxy'], 'too long')]
    #[DataSet([42], 'number')]
    #[DataSet([null], 'missing')]
    public function registerRejectsAnInvalidName(mixed $name): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'register', ['name' => $name], self::PLAYER);

        Assert::same($response, ['ok' => false, 'error' => 'invalid name']);
        Assert::null($ritual->playerStore->find(self::PLAYER));
    }

    public function anonymousConnectionCannotRegister(): void
    {
        $response = $this->call(new RitualHarness(), 'register', ['name' => 'Jack']);

        Assert::same($response, ['ok' => false, 'error' => 'anonymous']);
    }

    public function meReturnsTheStatistics(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-2', 3, self::PLAYER);

        $ritual->leaderboard->build();

        $response = $this->call($ritual, 'me', [], self::PLAYER);

        Assert::same($response['player']['clicks'] ?? null, 3);
        Assert::same(\array_column($response['top'] ?? [], 'id'), [self::PLAYER]);
    }

    public function meIsNullForAnUnknownOrAnonymousPlayer(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'me', [], self::PLAYER), ['ok' => true, 'player' => null, 'top' => []]);
        Assert::same($this->call($ritual, 'me', []), ['ok' => true, 'player' => null, 'top' => []]);
    }

    #[BeforeTest]
    public function createWorker(): void
    {
        $this->worker = new SpyWorker();
    }

    private function call(RitualHarness $ritual, string $method, array $params, string $user = ''): array
    {
        $service = new RPCService($ritual->clicks, $ritual->snapshot, $ritual->players, $ritual->leaderboard, new NullLogger());
        $service->handle(new RPC(
            worker: $this->worker,
            client: 'client-1',
            transport: 'websocket',
            protocol: 'json',
            encoding: 'json',
            user: $user,
            method: $method,
            meta: [],
            data: $params,
            headers: [],
        ));

        $data = $this->worker->lastResponse(RPCResponse::class)->getResult()?->getData() ?? '';

        return (array) \json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
    }
}
