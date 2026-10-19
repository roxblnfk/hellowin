<?php

declare(strict_types=1);

namespace App\Endpoint\Web;

use App\Application\Config\HellowinConfig;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Game\GameState;
use Spiral\Router\Annotation\Route;
use Spiral\Views\ViewsInterface;

final readonly class HomeController
{
    public function __construct(
        private ViewsInterface $views,
        private ElementRegistry $elements,
        private GameState $state,
        private HellowinConfig $config,
    ) {}

    #[Route(route: '/', name: 'index', methods: ['GET'])]
    public function index(): string
    {
        return $this->views->render('home', [
            'elements' => \array_map(static fn(Element $e): array => $e->toArray(), $this->elements->all()),
            'state' => $this->state->snapshot()->toArray(),
            'centrifugoUrl' => $this->config->getCentrifugoUrl(),
        ]);
    }
}
