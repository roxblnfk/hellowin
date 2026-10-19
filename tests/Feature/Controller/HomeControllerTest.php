<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use Testo\Test;
use Tests\TestCase;

#[Test]
final class HomeControllerTest extends TestCase
{
    public function homePageRenders(): void
    {
        $this->fakeHttp()->get('/')->assertOk();
    }
}
