<?php

declare(strict_types=1);

namespace App\Endpoint\Web;

use Spiral\Router\Annotation\Route;
use Spiral\Views\ViewsInterface;

final readonly class HomeController
{
    public function __construct(
        private ViewsInterface $views,
    ) {}

    #[Route(route: '/', name: 'index', methods: ['GET'])]
    public function index(): string
    {
        return $this->views->render('home');
    }
}
