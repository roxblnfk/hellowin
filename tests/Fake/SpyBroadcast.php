<?php

declare(strict_types=1);

namespace Tests\Fake;

use Spiral\Broadcasting\BroadcastInterface;

final class SpyBroadcast implements BroadcastInterface
{
    /** @var list<array{topic: string, message: array}> Messages decoded from JSON. */
    public array $published = [];

    #[\Override]
    public function publish(iterable|string|\Stringable $topics, iterable|string $messages): void
    {
        $topics = \is_iterable($topics) ? $topics : [$topics];
        $messages = \is_iterable($messages) ? $messages : [$messages];

        foreach ($topics as $topic) {
            foreach ($messages as $message) {
                $this->published[] = [
                    'topic' => (string) $topic,
                    'message' => (array) \json_decode((string) $message, true, flags: \JSON_THROW_ON_ERROR),
                ];
            }
        }
    }

    /**
     * @return list<array> Messages of the given type, in publish order.
     */
    public function ofType(string $type): array
    {
        return \array_values(\array_filter(
            \array_column($this->published, 'message'),
            static fn(array $message): bool => ($message['type'] ?? null) === $type,
        ));
    }
}
