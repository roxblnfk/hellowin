<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Hellowin\PlayMetrics;
use App\Application\Walk\Presence;
use RoadRunner\Lock\LockInterface;
use Spiral\Broadcasting\BroadcastInterface;
use Spiral\RoadRunner\Metrics\MetricsInterface;
use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\FakePresence;
use Tests\Fake\SpyBroadcast;
use Tests\Fake\SpyMetrics;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class TickerCommandTest extends TestCase
{
    private SpyBroadcast $broadcast;
    private SpyMetrics $metrics;
    private FakeLock $lock;

    public function tickerRunsTheDueJobsAndLetsTheLeaseGo(): void
    {
        $this->runCommand('hellowin:ticker', ['--ticks' => 2, '--step' => 1]);

        Assert::count($this->broadcast->ofType('leaderboard'), 1);
        Assert::true(isset($this->metrics->declared[PlayMetrics::PLAYERS_REGISTERED]));
        Assert::blank($this->lock->held);
    }

    public function tickerWithoutTheLeaseDoesNothing(): void
    {
        $this->lock->lock('ticker', 'another-ticker');

        $this->runCommand('hellowin:ticker', ['--ticks' => 2, '--step' => 1]);

        Assert::blank($this->broadcast->published);
    }

    #[BeforeTest]
    public function fakeInfrastructure(): void
    {
        $this->broadcast = new SpyBroadcast();
        $this->metrics = new SpyMetrics();
        $this->lock = new FakeLock();
        $this->getContainer()->bindSingleton(BroadcastInterface::class, $this->broadcast);
        $this->getContainer()->bindSingleton(LockInterface::class, $this->lock);
        $this->getContainer()->bindSingleton(MetricsInterface::class, $this->metrics);
        $this->getContainer()->bindSingleton(Presence::class, new FakePresence());
    }
}
