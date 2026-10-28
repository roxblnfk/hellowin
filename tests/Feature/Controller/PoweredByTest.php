<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Endpoint\Web\Middleware\PoweredByMiddleware;
use Spiral\Testing\Attribute\Config;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\TestCase;

#[Test]
#[Covers(PoweredByMiddleware::class)]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class PoweredByTest extends TestCase
{
    public function pageNamesTheFramework(): void
    {
        $this->fakeHttp()->get('/how-it-works')->assertOk()->assertHasHeader('X-Powered-By', 'Spiral 3');
    }

    public function errorPageNamesTheFrameworkToo(): void
    {
        $this->fakeHttp()->get('/missing')->assertNotFound()->assertHasHeader('X-Powered-By', 'Spiral 3');
    }
}
