<?php

declare(strict_types=1);

namespace App\Endpoint\Web;

use App\Application\Config\HellowinConfig;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Locale\Language;
use App\Application\Web\ServiceUrl;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Router\Annotation\Route;
use Spiral\Stempler\StemplerEngine;
use Spiral\Translator\TranslatorInterface;
use Spiral\Views\LoaderInterface;
use Spiral\Views\ViewsInterface;

final readonly class HomeController
{
    public function __construct(
        private ViewsInterface $views,
        private SnapshotFactory $snapshot,
        private HellowinConfig $config,
        private TranslatorInterface $translator,
        private LoaderInterface $viewLoader,
    ) {}

    #[Route(route: '/', name: 'index', methods: ['GET'])]
    public function index(ServerRequestInterface $request): string
    {
        return $this->views->render('home', $this->snapshot->create() + [
            'centrifugoUrl' => ServiceUrl::resolve($this->config->getCentrifugoUrl(), $request->getUri(), websocket: true),
            'grafanaUrl' => ServiceUrl::resolve($this->config->getGrafanaUrl(), $request->getUri()),
            // The floor, tables and counter of the bar: config, not state, so the snapshot does not carry it
            'barLayout' => $this->config->getBarLayout()?->toArray(),
            // The chat field's maxlength: the server refuses a longer message anyway
            'chatMax' => $this->config->getChatMax(),
        ]);
    }

    #[Route(route: '/how-it-works', name: 'how-it-works', methods: ['GET'])]
    public function howItWorks(ServerRequestInterface $request): string
    {
        return $this->views->render($this->howItWorksView(), [
            'channel' => $this->config->getChannel(),
            // A pumpkin's clicks per stage for a lone player at k = 1: the page explains the formula, not the room
            'clicksPerStage' => $this->config->getLimit(HellowinConfig::LIMIT_PUMPKIN)->of(1),
            'grafanaUrl' => ServiceUrl::resolve($this->config->getGrafanaUrl(), $request->getUri()),
        ]);
    }

    /**
     * The page is whole prose per language, not `[[...]]` keys; an English page not written yet falls back to the
     * Russian one.
     */
    private function howItWorksView(): string
    {
        $view = 'how-it-works';
        $locale = $this->translator->getLocale();
        if ($locale === Language::DEFAULT->value) {
            return $view;
        }

        // A view name with a dot is taken for a file name, so the extension is spelled out
        $localized = \sprintf('%s.%s.%s', $view, $locale, StemplerEngine::EXTENSION);

        return $this->viewLoader->withExtension(StemplerEngine::EXTENSION)->exists($localized) ? $localized : $view;
    }
}
