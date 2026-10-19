<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Endpoint\Centrifugo\ConnectService;
use App\Endpoint\Centrifugo\RPCService;
use App\Endpoint\Centrifugo\SubscribeService;
use RoadRunner\Centrifugo\Request\RequestType;
use Spiral\Broadcasting\BroadcastManagerInterface;
use Spiral\RoadRunnerBridge\Centrifugo\RegistryInterface;
use Spiral\Testing\Attribute\Config;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Test;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class CentrifugoWiringTest extends TestCase
{
    public function servicesAreRegistered(): void
    {
        $registry = $this->getContainer()->get(RegistryInterface::class);

        Assert::instanceOf($registry->getService(RequestType::Connect), ConnectService::class);
        Assert::instanceOf($registry->getService(RequestType::Subscribe), SubscribeService::class);
        Assert::instanceOf($registry->getService(RequestType::RPC), RPCService::class);
    }

    #[Env('BROADCAST_CONNECTION', 'centrifugo')]
    public function centrifugoBroadcastDriverResolves(): void
    {
        $manager = $this->getContainer()->get(BroadcastManagerInterface::class);

        Assert::notBlank($manager->connection('centrifugo'));
    }
}
