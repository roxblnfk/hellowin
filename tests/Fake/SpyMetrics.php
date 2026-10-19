<?php

declare(strict_types=1);

namespace Tests\Fake;

use Spiral\RoadRunner\Metrics\CollectorInterface;
use Spiral\RoadRunner\Metrics\Exception\MetricsException;
use Spiral\RoadRunner\Metrics\MetricsInterface;

final class SpyMetrics implements MetricsInterface
{
    /** @var list<array{string, string, float, list<string>}> operation, name, value, labels */
    public array $calls = [];

    /** @var array<string, CollectorInterface> */
    public array $declared = [];

    public function __construct(
        private readonly bool $failing = false,
    ) {}

    #[\Override]
    public function add(string $name, float $value, array $labels = []): void
    {
        $this->record('add', $name, $value, $labels);
    }

    #[\Override]
    public function sub(string $name, float $value, array $labels = []): void
    {
        $this->record('sub', $name, $value, $labels);
    }

    #[\Override]
    public function observe(string $name, float $value, array $labels = []): void
    {
        $this->record('observe', $name, $value, $labels);
    }

    #[\Override]
    public function set(string $name, float $value, array $labels = []): void
    {
        $this->record('set', $name, $value, $labels);
    }

    #[\Override]
    public function declare(string $name, CollectorInterface $collector): void
    {
        $this->failing and throw new MetricsException('metrics are down');
        $this->declared[$name] = $collector;
    }

    #[\Override]
    public function unregister(string $name): void
    {
        unset($this->declared[$name]);
    }

    private function record(string $operation, string $name, float $value, array $labels): void
    {
        $this->failing and throw new MetricsException('metrics are down');
        $this->calls[] = [$operation, $name, $value, \array_values(\array_map(\strval(...), $labels))];
    }
}
