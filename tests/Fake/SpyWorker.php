<?php

declare(strict_types=1);

namespace Tests\Fake;

use Google\Protobuf\Internal\Message;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;

/**
 * Captures what a Centrifugo request sends back to RoadRunner.
 */
final class SpyWorker implements WorkerInterface
{
    /** @var list<Payload> */
    public array $responses = [];

    /**
     * Decodes the last response into the protobuf DTO the request type answers with.
     *
     * @template T of Message
     * @param class-string<T> $class
     * @return T
     */
    public function lastResponse(string $class): Message
    {
        $payload = \end($this->responses) ?: throw new \LogicException('No response was sent.');
        $message = new $class();
        $message->mergeFromString($payload->body);

        return $message;
    }

    #[\Override]
    public function respond(Payload $payload): void
    {
        $this->responses[] = $payload;
    }

    #[\Override]
    public function waitPayload(): ?Payload
    {
        return null;
    }

    #[\Override]
    public function error(string $error): void
    {
        throw new \LogicException($error);
    }

    #[\Override]
    public function stop(): void {}

    #[\Override]
    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    #[\Override]
    public function getPayload(?string $class = null): ?Payload
    {
        return null;
    }
}
