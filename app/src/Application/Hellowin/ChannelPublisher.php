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
     *
     * @param int|null $finale The finale step the new scene starts at: 0 for the final scene, null before it.
     * @param list<Walker>|null $walkers Every character, put into the new location: the room has left the bar.
     */
    public function scene(
        int $round,
        int $scene,
        int $stageMax,
        int $bonfireStageMax = 0,
        int $circleStageMax = 0,
        ?int $finale = null,
        ?array $walkers = null,
    ): void {
        $item = $this->items->held($scene, $finale);
        $message = [
            'type' => 'scene',
            'round' => $round,
            'scene' => $scene,
            'stageMax' => $stageMax,
            'bonfireStageMax' => $bonfireStageMax,
            'circleStageMax' => $circleStageMax,
            'item' => $item,
        ];
        $walkers === null or $message['walkers'] = self::walkers($walkers, $item);

        $this->publish($message);
    }

    /**
     * @param list<Walker>|null $walkers Every character, put back into the bar with empty hands.
     */
    public function reset(int $round, ?array $walkers = null): void
    {
        $item = $this->items->held(0);
        $message = ['type' => 'reset', 'round' => $round, 'item' => $item];
        $walkers === null or $message['walkers'] = self::walkers($walkers, $item);

        $this->publish($message);
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

    /**
     * A walk to a clink carries its target, so every client knows the character is going to clink.
     */
    public function move(int $round, Walker $walker): void
    {
        $message = [
            'type' => 'move',
            'round' => $round,
            'player' => ['id' => $walker->id],
            'walk' => $walker->walk->toArray(),
        ];
        $walker->clink === null or $message['clink'] = $walker->clink->toTarget();

        $this->publish($message);
    }

    /**
     * @param non-empty-string $player Who came up.
     * @param array<'walker'|'pumpkin', array{id: non-empty-string}> $target `walker` or `pumpkin`: what the player clinks with.
     * @param int $toast Index of the toast in the frontend's catalogue, modulo its length; never negative.
     * @param int $at Unix time in milliseconds of the meeting.
     */
    public function clink(int $round, string $player, array $target, int $toast, int $at): void
    {
        $this->publish(['type' => 'clink', 'round' => $round, 'player' => ['id' => $player]] + $target + [
            'toast' => $toast,
            'at' => $at,
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
     * Carries the item every character holds from this step on.
     *
     * @param array|null $reveal From step {@see \App\Domain\Story\Story::REVEAL_STEP} on.
     */
    public function finale(int $round, int $scene, int $step, ?array $reveal = null): void
    {
        $message = ['type' => 'finale', 'round' => $round, 'step' => $step, 'item' => $this->items->held($scene, $step)];
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
     * @param list<Walker> $walkers
     * @param non-empty-string|null $item
     * @return list<array>
     */
    private static function walkers(array $walkers, ?string $item): array
    {
        return \array_map(static fn(Walker $walker): array => $walker->toArray($item), $walkers);
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
