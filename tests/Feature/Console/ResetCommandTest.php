<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use RoadRunner\Lock\LockInterface;
use Spiral\Broadcasting\BroadcastInterface;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\RoadRunner\Metrics\MetricsInterface;
use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\SpyBroadcast;
use Tests\Fake\SpyMetrics;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class ResetCommandTest extends TestCase
{
    private SpyBroadcast $broadcast;

    public function resetsTheCurrentRound(): void
    {
        $this->storage()->set('hellowin:round', 4);
        $this->storage()->set('hellowin:scene', 2);

        $this->runCommand('hellowin:reset');

        Assert::same($this->storage()->get('hellowin:round'), 5);
        Assert::same($this->storage()->get('hellowin:scene'), 0);
        Assert::same($this->broadcast->ofType('reset'), [['type' => 'reset', 'round' => 5, 'item' => null, 'walkers' => []]]);
    }

    public function roundThatIsOverIsLeftAlone(): void
    {
        $this->storage()->set('hellowin:round', 4);

        $output = $this->runCommand('hellowin:reset', ['--round' => 3]);

        Assert::string($output)->contains('Round 3 is already over');
        Assert::same($this->storage()->get('hellowin:round'), 4);
        Assert::blank($this->broadcast->published);
    }

    #[BeforeTest]
    public function fakeInfrastructure(): void
    {
        $this->broadcast = new SpyBroadcast();
        $this->getContainer()->bindSingleton(BroadcastInterface::class, $this->broadcast);
        $this->getContainer()->bindSingleton(LockInterface::class, new FakeLock());
        $this->getContainer()->bindSingleton(MetricsInterface::class, new SpyMetrics());
    }

    private function storage(): \Psr\SimpleCache\CacheInterface
    {
        return $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin');
    }
}
