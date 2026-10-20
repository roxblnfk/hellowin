<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Flyer\Flyer;
use App\Domain\Player\Player;
use App\Domain\Ritual\Pumpkin;
use App\Domain\Walk\Items;
use App\Domain\Walk\Walker;
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
        private Items $items = new Items(),
    ) {}

    /**
     * @param positive-int $threshold
     */
    public function click(int $round, Pumpkin $pumpkin, string $emoji, int $threshold, ?Player $player = null): void
    {
        $this->publish(self::withPlayer([
            'type' => 'click',
            'round' => $round,
            'element' => $pumpkin->id,
            'emoji' => $emoji,
            'clicks' => $pumpkin->clicks,
            'threshold' => $threshold,
            'stage' => $pumpkin->stage,
        ], $player));
    }

    public function pumpkin(int $round, Pumpkin $pumpkin, ?Player $player = null): void
    {
        $this->publish(self::withPlayer(
            ['type' => 'pumpkin', 'round' => $round, 'element' => $pumpkin->id, 'stage' => $pumpkin->stage],
            $player,
        ));
    }

    /**
     * Carries the item every character holds in the new scene and the last stages of the centrepieces in it.
     */
    public function scene(int $round, int $scene, int $stageMax, int $bonfireStageMax = 0, int $circleStageMax = 0): void
    {
        $this->publish([
            'type' => 'scene',
            'round' => $round,
            'scene' => $scene,
            'stageMax' => $stageMax,
            'bonfireStageMax' => $bonfireStageMax,
            'circleStageMax' => $circleStageMax,
            'item' => $this->items->forScene($scene),
        ]);
    }

    public function reset(int $round): void
    {
        $this->publish(['type' => 'reset', 'round' => $round, 'item' => $this->items->forScene(0)]);
    }

    public function flyer(int $round, Flyer $flyer): void
    {
        $this->publish(['type' => 'flyer', 'round' => $round] + $flyer->toEvent());
    }

    public function shot(int $round, Flyer $flyer, ?Player $player = null): void
    {
        $this->publish(self::withPlayer(
            ['type' => 'shot', 'round' => $round, 'flyer' => $flyer->id, 'points' => $flyer->points],
            $player,
        ));
    }

    /**
     * @param non-empty-string|null $item
     */
    public function walker(int $round, Walker $walker, ?string $item): void
    {
        $this->publish(['type' => 'walker', 'round' => $round, 'walker' => $walker->toArray($item)]);
    }

    public function move(int $round, Walker $walker): void
    {
        $this->publish([
            'type' => 'move',
            'round' => $round,
            'player' => ['id' => $walker->id],
            'walk' => $walker->walk->toArray(),
        ]);
    }

    public function leave(int $round, string $player): void
    {
        $this->publish(['type' => 'leave', 'round' => $round, 'player' => ['id' => $player]]);
    }

    /**
     * @param list<array> $top Players as {@see Player::toArray()}, best first.
     * @param int<0, max> $online Players with a character on the lawn.
     */
    public function leaderboard(int $round, array $top, int $online = 0): void
    {
        $this->publish(['type' => 'leaderboard', 'round' => $round, 'top' => $top, 'online' => $online]);
    }

    /**
     * @param array<non-empty-string, positive-int> $thresholds Thresholds in force by element type.
     */
    public function thresholds(int $round, float $k, array $thresholds): void
    {
        $this->publish(['type' => 'thresholds', 'round' => $round, 'k' => $k, 'thresholds' => $thresholds]);
    }

    public function ready(int $round, int $scene): void
    {
        $this->publish(['type' => 'ready', 'round' => $round, 'scene' => $scene]);
    }

    /**
     * @param array|null $reveal From step {@see \App\Domain\Story\Story::REVEAL_STEP} on.
     */
    public function finale(int $round, int $step, ?array $reveal = null): void
    {
        $message = ['type' => 'finale', 'round' => $round, 'step' => $step];
        $reveal === null or $message['reveal'] = $reveal;

        $this->publish($message);
    }

    /**
     * A line of the owl's catalogue; the frontend holds the texts and fills the params in.
     *
     * @param non-empty-string $line
     * @param array<non-empty-string, mixed> $params
     */
    public function owl(int $round, string $line, array $params = []): void
    {
        $message = ['type' => 'owl', 'round' => $round, 'line' => $line];
        $params === [] or $message['params'] = $params;

        $this->publish($message);
    }

    /**
     * The moderator's own words.
     *
     * @param non-empty-string $text
     */
    public function owlText(int $round, string $text): void
    {
        $this->publish(['type' => 'owl', 'round' => $round, 'text' => $text]);
    }

    public function pause(int $round, bool $on): void
    {
        $this->publish(['type' => 'pause', 'round' => $round, 'on' => $on]);
    }

    public function zombies(int $round, bool $on): void
    {
        $this->publish(['type' => 'zombies', 'round' => $round, 'on' => $on]);
    }

    /**
     * Anonymous actions carry no `player` field at all.
     */
    private static function withPlayer(array $message, ?Player $player): array
    {
        $player === null or $message['player'] = $player->ref();

        return $message;
    }

    private function publish(array $message): void
    {
        $this->broadcast->publish(
            $this->channel,
            \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );
    }
}
