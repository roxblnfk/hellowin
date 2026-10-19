<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use Spiral\Testing\Attribute\Config;
use Testo\Test;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class HomeControllerTest extends TestCase
{
    public function homePageRenders(): void
    {
        $this->fakeHttp()->get('/')->assertOk();
    }
}
