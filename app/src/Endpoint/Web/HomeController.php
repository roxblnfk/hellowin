<?php

declare(strict_types=1);

namespace App\Endpoint\Web;

use App\Application\Config\HellowinConfig;
use App\Application\Hellowin\SnapshotFactory;
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
    public function index(): string
    {
        return $this->views->render('home', $this->snapshot->create() + [
            'centrifugoUrl' => $this->config->getCentrifugoUrl(),
        ]);
    }

    #[Route(route: '/how-it-works', name: 'how-it-works', methods: ['GET'])]
    public function howItWorks(): string
    {
        return $this->views->render('how-it-works', [
            'channel' => $this->config->getChannel(),
            'clicksPerStage' => $this->config->getClicksPerStage(),
        ]);
    }
}
