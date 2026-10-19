<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Application\Config\HellowinConfig;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\Testing\Attribute\Config;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Test;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
final class HomeControllerTest extends TestCase
{
    public function homePageRendersEveryElement(): void
    {
        $elements = $this->getContainer()->get(HellowinConfig::class)->getElements();
        Assert::notBlank($elements);

        $response = $this->fakeHttp()->get('/')->assertOk();

        foreach ($elements as $element) {
            $response->assertBodyContains(\sprintf('data-element="%s"', $element->id));
            $response->assertBodyContains(\sprintf('data-type="%s"', $element->type));
        }
    }

    public function homePageStartsAtStageZero(): void
    {
        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('<body data-stage="0">')
            ->assertBodyContains('data-clicks="0"')
            ->assertBodyContains('data-threshold="5"');
    }

    #[Env('HELLOWIN_THRESHOLDS', '5,10')]
    public function homePageShowsStoredStage(): void
    {
        $storage = $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin');
        $storage->set('clicks', 7);
        $storage->set('stage', 1);

        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('<body data-stage="1">')
            ->assertBodyContains('data-clicks="7"')
            ->assertBodyContains('data-threshold="10"');
    }

    public function centrifugoUrlDefaultsToRelativePath(): void
    {
        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('data-centrifugo-url="/connection/websocket"');
    }

    #[Env('CENTRIFUGO_WS_URL', 'wss://ws.example.com/connection/websocket')]
    public function centrifugoUrlComesFromEnv(): void
    {
        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('data-centrifugo-url="wss://ws.example.com/connection/websocket"');
    }

    #[Env('DEBUG', 'false')]
    public function unknownPageRendersErrorView(): void
    {
        $this->fakeHttp()->get('/missing')
            ->assertNotFound()
            ->assertBodyContains('class="error__code">404<');
    }
}
