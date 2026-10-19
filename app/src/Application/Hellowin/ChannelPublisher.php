<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Element\Element;
use App\Domain\Game\ClickResult;
use Spiral\Broadcasting\BroadcastInterface;

/**
 * Publishes game events to the shared channel. Message shapes are the client contract, see docs/architecture.md.
 */
final readonly class ChannelPublisher
{
    /**
     * @param non-empty-string $channel
     */
    public function __construct(
        private BroadcastInterface $broadcast,
        private string $channel,
    ) {}

    public function emoji(Element $element, string $emoji, ClickResult $result): void
    {
        $this->publish([
            'type' => 'emoji',
            'element' => $element->id,
            'emoji' => $emoji,
            'clicks' => $result->clicks,
            'threshold' => $result->threshold,
        ]);
    }

    public function state(ClickResult $result): void
    {
        $this->publish([
            'type' => 'state',
            'stage' => $result->stage,
            'clicks' => $result->clicks,
            'threshold' => $result->threshold,
        ]);
    }

    private function publish(array $message): void
    {
        $this->broadcast->publish(
            $this->channel,
            \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }
}
