<?php

declare(strict_types=1);

namespace App\Endpoint\Web;

use App\Application\Config\HellowinConfig;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Web\ServiceUrl;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Router\Annotation\Route;
use Spiral\Views\ViewsInterface;

final readonly class HomeController
{
    public function __construct(
        private ViewsInterface $views,
        private SnapshotFactory $snapshot,
        private HellowinConfig $config,
    ) {}

    #[Route(route: '/', name: 'index', methods: ['GET'])]
    public function index(ServerRequestInterface $request): string
    {
        return $this->views->render('home', $this->snapshot->create() + [
            'centrifugoUrl' => ServiceUrl::resolve($this->config->getCentrifugoUrl(), $request->getUri(), websocket: true),
            'grafanaUrl' => ServiceUrl::resolve($this->config->getGrafanaUrl(), $request->getUri()),
            // The floor, tables and counter of the bar: config, not state, so the snapshot does not carry it
            'barLayout' => $this->config->getBarLayout()?->toArray(),
        ]);
    }

    #[Route(route: '/how-it-works', name: 'how-it-works', methods: ['GET'])]
    public function howItWorks(ServerRequestInterface $request): string
    {
        return $this->views->render('how-it-works', [
            'channel' => $this->config->getChannel(),
            'clicksPerStage' => $this->config->getClicksPerStage(),
            'grafanaUrl' => ServiceUrl::resolve($this->config->getGrafanaUrl(), $request->getUri()),
        ]);
    }
}
