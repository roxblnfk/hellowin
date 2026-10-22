<?php

declare(strict_types=1);

namespace Tests\Fake;

use Spiral\Views\ViewInterface;
use Spiral\Views\ViewsInterface;

/**
 * Renders through the real views and keeps the data of every render: a template variable the template does not print
 * yet is still checked.
 */
final class SpyViews implements ViewsInterface
{
    /** @var list<array{string, array}> */
    public array $rendered = [];

    public function __construct(
        private readonly ViewsInterface $views,
    ) {}

    #[\Override]
    public function get(string $path): ViewInterface
    {
        return $this->views->get($path);
    }

    #[\Override]
    public function compile(string $path): void
    {
        $this->views->compile($path);
    }

    #[\Override]
    public function reset(string $path): void
    {
        $this->views->reset($path);
    }

    #[\Override]
    public function render(string $path, array $data = []): string
    {
        $this->rendered[] = [$path, $data];

        return $this->views->render($path, $data);
    }
}
