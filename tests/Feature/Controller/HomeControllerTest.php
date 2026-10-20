<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Application\Config\HellowinConfig;
use RoadRunner\Lock\LockInterface;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\Testing\Attribute\Config;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\TestCase;

#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
#[Config('cache.storages.process', ['type' => 'array'])]
final class HomeControllerTest extends TestCase
{
    /**
     * The start time is noted under a RoadRunner lock: no RoadRunner here.
     */
    #[BeforeTest]
    public function fakeLock(): void
    {
        $this->getContainer()->bindSingleton(LockInterface::class, new FakeLock());
    }

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

    #[Env('HELLOWIN_SCENE_STAGE_MAX', '4,6,8')]
    #[Env('HELLOWIN_CLICKS_PER_STAGE', '5')]
    public function homePageStartsAtSceneZero(): void
    {
        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('data-scene="0"')
            ->assertBodyContains('data-round="1"')
            ->assertBodyContains('data-scene-stages="3"')
            ->assertBodyContains('data-threshold="5"')
            ->assertBodyContains('data-stage="0"')
            ->assertBodyContains('data-clicks="0"');
    }

    #[Env('HELLOWIN_CLICKS_PER_STAGE', '4')]
    public function homePageShowsStoredRitual(): void
    {
        $storage = $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin');
        $storage->set('hellowin:round', 3);
        $storage->set('hellowin:scene', 1);
        $storage->set('hellowin:pumpkin:pumpkin-2', ['stage' => 2, 'clicks' => 3]);

        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains('data-scene="1"')
            ->assertBodyContains('data-round="3"')
            ->assertBodyContains('data-threshold="4"')
            ->assertBodyContains('data-stage="2"')
            ->assertBodyContains('data-clicks="3"');
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
