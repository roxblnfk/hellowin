<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Ritual\Pumpkin;
use Spiral\Broadcasting\BroadcastInterface;

/**
 * Publishes ritual events to the shared channel. Message shapes are the client contract: docs/spec/protocol.md.
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

    /**
     * @param positive-int $threshold
     */
    public function click(int $round, Pumpkin $pumpkin, string $emoji, int $threshold): void
    {
        $this->publish([
            'type' => 'click',
            'round' => $round,
            'element' => $pumpkin->id,
            'emoji' => $emoji,
            'clicks' => $pumpkin->clicks,
            'threshold' => $threshold,
            'stage' => $pumpkin->stage,
        ]);
    }

    public function pumpkin(int $round, Pumpkin $pumpkin): void
    {
        $this->publish(['type' => 'pumpkin', 'round' => $round, 'element' => $pumpkin->id, 'stage' => $pumpkin->stage]);
    }

    public function scene(int $round, int $scene, int $stageMax): void
    {
        $this->publish(['type' => 'scene', 'round' => $round, 'scene' => $scene, 'stageMax' => $stageMax]);
    }

    public function reset(int $round): void
    {
        $this->publish(['type' => 'reset', 'round' => $round]);
    }

    private function publish(array $message): void
    {
        $this->broadcast->publish(
            $this->channel,
            \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }
}
