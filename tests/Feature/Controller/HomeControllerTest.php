<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Application\Config\HellowinConfig;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\Point;
use App\Domain\Story\Story;
use App\Domain\Walk\PresenceStore;
use RoadRunner\Lock\LockInterface;
use Spiral\Cache\CacheStorageProviderInterface;
use Spiral\Testing\Attribute\Config;
use Spiral\Testing\Attribute\Env;
use Spiral\Views\ViewsInterface;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\SpyViews;
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

    public function homePageCarriesTheBarLayout(): void
    {
        $layout = $this->getContainer()->get(HellowinConfig::class)->getBarLayout()?->toArray();
        Assert::notNull($layout);

        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains(\sprintf('data-bar-layout="%s"', \htmlspecialchars(\json_encode($layout, \JSON_THROW_ON_ERROR), \ENT_QUOTES)));
    }

    #[Env('HELLOWIN_CHAT_MAX', '80')]
    public function homePageCarriesTheChatLimit(): void
    {
        $views = new SpyViews($this->getContainer()->get(ViewsInterface::class));
        $this->getContainer()->bindSingleton(ViewsInterface::class, $views);

        $this->fakeHttp()->get('/')->assertOk();

        Assert::same($views->rendered[0][0], 'home');
        Assert::same($views->rendered[0][1]['chatMax'], 80);
    }

    #[Env('HELLOWIN_EMOTES', '🍺,🎃')]
    public function homePageCarriesTheEmotes(): void
    {
        $views = new SpyViews($this->getContainer()->get(ViewsInterface::class));
        $this->getContainer()->bindSingleton(ViewsInterface::class, $views);

        $this->fakeHttp()->get('/')->assertOk();

        Assert::same($views->rendered[0][1]['emotes'], ['🍺', '🎃']);
    }

    public function homePageCarriesTheLimitsOfTheScene(): void
    {
        $body = (string) $this->fakeHttp()->get('/')->assertOk()->getOriginalResponse()->getBody();

        Assert::same(\preg_match('/data-limits="([^"]*)"/', $body, $match), 1);
        $limits = \json_decode(\htmlspecialchars_decode($match[1]), true, flags: \JSON_THROW_ON_ERROR);
        Assert::array($limits)->hasKeys('players', 'limits');
    }

    public function pagesPointTheLinkPreviewAtTheirOwnHost(): void
    {
        foreach (['/', '/how-it-works'] as $page) {
            $this->fakeHttp()->get('https://halloween.test:8443' . $page)
                ->assertOk()
                ->assertBodyContains('property="og:image" content="https://halloween.test:8443/og.png"')
                ->assertBodyContains('property="og:url" content="https://halloween.test:8443/"')
                ->assertBodyContains('name="twitter:card" content="summary_large_image"');
        }
    }

    public function homePageCarriesWhoIsOnline(): void
    {
        $this->getContainer()->get(PresenceStore::class)->tell(['players' => 2, 'connections' => 3]);

        $body = (string) $this->fakeHttp()->get('/')->assertOk()->getOriginalResponse()->getBody();

        Assert::same(\preg_match('/data-presence="([^"]*)"/', $body, $match), 1);
        $presence = \json_decode(\htmlspecialchars_decode($match[1]), true, flags: \JSON_THROW_ON_ERROR);
        Assert::same($presence, ['players' => 2, 'connections' => 3]);
    }

    public function homePageCarriesNoWanderingZombiesOutOfTheCursedWorld(): void
    {
        $this->fakeHttp()->get('/')->assertOk()->assertBodyContains('data-flyers="[]"');
    }

    public function homePageCarriesTheWanderingZombiesOfTheCursedWorld(): void
    {
        $zombie = Flyer::wanderer('f-0000beef', 1, new Point(38.5, 81), 2, 1_000);
        $this->getContainer()->get(FlyerStore::class)->add($zombie);
        $storage = $this->getContainer()->get(CacheStorageProviderInterface::class)->storage('hellowin');
        $storage->set('hellowin:scene', 3);
        $storage->set('hellowin:story', (new Story(finale: Story::CURSED_STEP))->toArray());

        $this->fakeHttp()->get('/')
            ->assertOk()
            ->assertBodyContains(\sprintf('data-flyers="%s"', \htmlspecialchars(\json_encode([$zombie->toEvent()], \JSON_THROW_ON_ERROR), \ENT_QUOTES)));
    }

    #[Env('HELLOWIN_SCENE_STAGE_MAX', '4,6,8')]
    #[Env('HELLOWIN_PUMPKIN_BASE', '5')]
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

    #[Env('HELLOWIN_PUMPKIN_BASE', '4')]
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

    public function centrifugoUrlFollowsThePageHost(): void
    {
        $this->fakeHttp()->get('http://192.168.1.5:8080/')
            ->assertOk()
            ->assertBodyContains('data-centrifugo-url="ws://192.168.1.5:8000/connection/websocket"');
    }

    public function grafanaLinkFollowsThePageHost(): void
    {
        $this->fakeHttp()->get('http://127.0.0.1:8080/how-it-works')
            ->assertOk()
            ->assertBodyContains('href="http://127.0.0.1:3000/"');
    }

    #[Env('GRAFANA_URL', 'https://grafana.example.com/d/hellowin')]
    public function grafanaLinkComesFromEnv(): void
    {
        $this->fakeHttp()->get('/how-it-works')
            ->assertOk()
            ->assertBodyContains('href="https://grafana.example.com/d/hellowin"');
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

    #[Env('HELLOWIN_PUMPKIN_BASE', '3')]
    public function howItWorksPageShowsConfiguredThreshold(): void
    {
        $this->fakeHttp()->get('/how-it-works')
            ->assertOk()
            ->assertBodyContains('Игрок один на поле, множитель — 1: порог — 3 кликов на каждую стадию')
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
