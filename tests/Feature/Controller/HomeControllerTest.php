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

    #[Env('HELLOWIN_CLICKS_PER_STAGE', '4')]
    public function homePageShowsStoredStage(): void
    {
        $storage = $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin');
        $storage->set('clicks', 7);
        $storage->set('stage', 1);

        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('<body data-stage="1">')
            ->assertBodyContains('data-clicks="7"')
            ->assertBodyContains('data-threshold="4"');
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

    public function homePageLinksToHowItWorks(): void
    {
        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('href="/how-it-works"');
    }

    public function howItWorksPageRendersDiagrams(): void
    {
        $this->fakeHttp()->get('/how-it-works')
            ->assertOk()
            ->assertBodyContains('<svg class="diagram__svg"')
            ->assertBodyContains('href="/"');
    }

    public function howItWorksPageSplitsSectionsIntoTabs(): void
    {
        $body = (string) $this->fakeHttp()->get('/how-it-works')
            ->assertOk()
            ->assertBodyContains('role="tablist"')
            ->assertBodyContains('<script src="/js/how-it-works.js"></script>')
            ->getOriginalResponse()
            ->getBody();

        \preg_match_all('/<a role="tab"[^>]*\baria-controls="([^"]+)"/', $body, $tabs);
        \preg_match_all('/<section [^>]*\bid="([^"]+)" role="tabpanel"/', $body, $panels);
        $sections = \substr_count($body, '<section class="how__section"');

        Assert::notBlank($panels[1]);
        Assert::count($panels[1], $sections);
        Assert::same($tabs[1], $panels[1]);
    }

    #[Env('HELLOWIN_CLICKS_PER_STAGE', '3')]
    public function howItWorksPageShowsConfiguredThreshold(): void
    {
        $this->fakeHttp()->get('/how-it-works')
            ->assertOk()
            ->assertBodyContains('Сейчас порог — 3')
            ->assertBodyContains('<b>1</b>с 3 кликов');
    }

    #[Env('DEBUG', 'false')]
    public function unknownPageRendersErrorView(): void
    {
        $this->fakeHttp()->get('/missing')
            ->assertNotFound()
            ->assertBodyContains('class="error__code">404<');
    }
}
