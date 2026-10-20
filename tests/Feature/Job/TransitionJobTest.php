<?php

declare(strict_types=1);

namespace Tests\Feature\Job;

use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\Job\TransitionScene;
use App\Application\Hellowin\TransitionQueue;
use App\Domain\Element\ElementRegistry;
use RoadRunner\Lock\LockInterface;
use Spiral\Broadcasting\BroadcastInterface;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\Core\Scope;
use Spiral\Idempotency\Driver\Memory\MemoryLeaseConfig;
use Spiral\Queue\Interceptor\Consume\Handler;
use Spiral\RoadRunner\Metrics\MetricsInterface;
use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\SpyBroadcast;
use Tests\Fake\SpyMetrics;
use Tests\TestCase;

/**
 * Transition jobs go through the consume pipeline of the app — retry policy, idempotency interceptor,
 * job handler — with the idempotency storage in memory instead of Valkey.
 */
#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
#[Config('idempotency.storages.transitions', new MemoryLeaseConfig())]
#[Config('hellowin.scene_stage_max', '2,3')]
final class TransitionJobTest extends TestCase
{
    private SpyBroadcast $broadcast;

    public function pumpkinJobMovesThePumpkin(): void
    {
        $this->consume(TransitionPumpkin::class, self::pumpkinPayload(1, 'pumpkin-1', 1));

        Assert::same($this->stored('hellowin:pumpkin:pumpkin-1'), ['stage' => 1, 'clicks' => 0, 'accepted' => 0]);
        Assert::same($this->broadcast->ofType('pumpkin'), [
            ['type' => 'pumpkin', 'round' => 1, 'element' => 'pumpkin-1', 'stage' => 1],
        ]);
    }

    public function redeliveredJobIsDroppedByItsKey(): void
    {
        $payload = self::pumpkinPayload(1, 'pumpkin-1', 1);
        $this->consume(TransitionPumpkin::class, $payload);
        // Back to stage 0: only the idempotency key can stop the second delivery now.
        $this->store('hellowin:pumpkin:pumpkin-1', ['stage' => 0, 'clicks' => 0]);

        $this->consume(TransitionPumpkin::class, $payload);

        Assert::same($this->stored('hellowin:pumpkin:pumpkin-1'), ['stage' => 0, 'clicks' => 0]);
        Assert::count($this->broadcast->ofType('pumpkin'), 1);
    }

    public function sameTransitionInTheNextRoundRunsAgain(): void
    {
        $this->consume(TransitionPumpkin::class, self::pumpkinPayload(1, 'pumpkin-1', 1));
        $this->store('hellowin:round', 2);
        $this->store('hellowin:pumpkin:pumpkin-1', ['stage' => 0, 'clicks' => 0]);

        $this->consume(TransitionPumpkin::class, self::pumpkinPayload(2, 'pumpkin-1', 1));

        Assert::same($this->stored('hellowin:pumpkin:pumpkin-1'), ['stage' => 1, 'clicks' => 0, 'accepted' => 0]);
        Assert::same(\array_column($this->broadcast->ofType('pumpkin'), 'round'), [1, 2]);
    }

    public function lastPumpkinMakesTheSceneReadyForTheModerator(): void
    {
        $queue = $this->fakeQueue()->getConnection();
        foreach ($this->getContainer()->get(ElementRegistry::class)->all() as $element) {
            $this->store('hellowin:pumpkin:' . $element->id, ['stage' => $element->id === 'pumpkin-1' ? 1 : 2, 'clicks' => 0]);
        }

        $this->consume(TransitionPumpkin::class, self::pumpkinPayload(1, 'pumpkin-1', 2));

        $queue->assertNotPushed(TransitionScene::class);
        Assert::same($this->broadcast->ofType('ready'), [['type' => 'ready', 'round' => 1, 'scene' => 0]]);
        Assert::same($this->stored('hellowin:story')['ready'] ?? null, 0);
    }

    public function sceneJobMovesTheSceneOnce(): void
    {
        $payload = ['key' => TransitionQueue::sceneKey(1, 1), 'round' => 1, 'toScene' => 1];

        $this->consume(TransitionScene::class, $payload);
        $this->consume(TransitionScene::class, $payload);

        Assert::same($this->stored('hellowin:scene'), 1);
        Assert::same($this->broadcast->ofType('scene'), [['type' => 'scene', 'round' => 1, 'scene' => 1, 'stageMax' => 3, 'bonfireStageMax' => 4, 'circleStageMax' => 0, 'item' => 'candle']]);
    }

    #[BeforeTest]
    public function fakeInfrastructure(): void
    {
        $this->broadcast = new SpyBroadcast();
        $this->getContainer()->bindSingleton(BroadcastInterface::class, $this->broadcast);
        $this->getContainer()->bindSingleton(LockInterface::class, new FakeLock());
        $this->getContainer()->bindSingleton(MetricsInterface::class, new SpyMetrics());
        $this->fakeQueue();
    }

    private static function pumpkinPayload(int $round, string $pumpkin, int $toStage): array
    {
        return [
            'key' => TransitionQueue::pumpkinKey($round, $pumpkin, $toStage),
            'round' => $round,
            'pumpkin' => $pumpkin,
            'toStage' => $toStage,
        ];
    }

    /**
     * Delivers the job the way the RoadRunner queue dispatcher does: inside the `queue` scope.
     */
    private function consume(string $job, array $payload): void
    {
        $this->getContainer()->runScope(
            new Scope('queue'),
            static fn(Handler $handler): mixed => $handler->handle($job, 'roadrunner', 'transitions', 'job-1', $payload),
        );
    }

    private function store(string $key, mixed $value): void
    {
        $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin')->set($key, $value);
    }

    private function stored(string $key): mixed
    {
        return $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin')->get($key);
    }
}
