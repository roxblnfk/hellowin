<?php

declare(strict_types=1);

namespace Tests\Fake;

use Spiral\Queue\OptionsInterface;
use Spiral\Queue\QueueInterface;

final class SpyQueue implements QueueInterface
{
    /** @var list<array{name: string, payload: array}> */
    public array $pushed = [];

    #[\Override]
    public function push(string $name, array $payload = [], ?OptionsInterface $options = null): string
    {
        $this->pushed[] = ['name' => $name, 'payload' => $payload];

        return 'job-' . \count($this->pushed);
    }

    /**
     * @return list<array> Payloads of the given job, in push order.
     */
    public function payloads(string $name): array
    {
        return \array_values(\array_column(
            \array_filter($this->pushed, static fn(array $job): bool => $job['name'] === $name),
            'payload',
        ));
    }
}
