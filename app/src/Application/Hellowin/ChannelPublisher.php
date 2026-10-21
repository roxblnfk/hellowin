<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Flyer\Flyer;
use App\Domain\Player\Player;
use App\Domain\Ritual\Pumpkin;
use App\Domain\Story\Story;
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
     * @param array|null $bar The counter of the bar after the reset, as the snapshot shows it.
     * @param bool $again A new cycle of the evening the dawn led into, not the console's reset.
     */
    public function reset(int $round, ?array $walkers = null, ?array $bar = null, bool $again = false): void
    {
        $item = $this->items->held(0);
        $message = ['type' => 'reset', 'round' => $round, 'item' => $item];
        $walkers === null or $message['walkers'] = self::walkers($walkers, $item);
        $bar === null or $message['bar'] = $bar;
        $again and $message['again'] = true;

        $this->publish($message);
    }

    /**
     * The owl began pouring a mug.
     *
     * @param array{since: int, until: int, rumor?: int} $pouring
     */
    public function pour(int $round, array $pouring): void
    {
        $this->publish(['type' => 'pour', 'round' => $round] + $pouring);
    }

    /**
     * The owl put a poured mug on the counter.
     *
     * @param int<0, max> $mugs Mugs on the counter now.
     * @param positive-int $max Mugs the owl pours up to for the players on the floor.
     */
    public function mugs(int $round, int $mugs, int $max): void
    {
        $this->publish(['type' => 'mugs', 'round' => $round, 'mugs' => $mugs, 'max' => $max]);
    }

    /**
     * A character reached the counter: with a mug, or there was none left.
     *
     * @param non-empty-string $player
     * @param int<0, max>|null $mugs Mugs left on the counter; null when none was taken.
     */
    public function take(int $round, string $player, ?int $mugs): void
    {
        $message = ['type' => 'take', 'round' => $round, 'player' => ['id' => $player], 'ok' => $mugs !== null];
        $mugs === null or $message['mugs'] = $mugs;

        $this->publish($message);
    }

    /**
     * @param non-empty-string $player
     */
    public function drink(int $round, string $player): void
    {
        $this->publish(['type' => 'drink', 'round' => $round, 'player' => ['id' => $player]]);
    }

    /**
     * @param int<0, max> $drunk Mugs the room has drunk in the round.
     * @param positive-int $target Mugs the bar wants drunk before the room leaves.
     */
    public function drinks(int $round, int $drunk, int $target): void
    {
        $this->publish(['type' => 'drinks', 'round' => $round, 'drunk' => $drunk, 'target' => $target]);
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
     * Carries the item every character holds from this step on — but on the rapier step, where each holds its own and
     * the clients leave the items as they are.
     *
     * @param array|null $reveal From step {@see Story::REVEAL_STEP} on.
     */
    public function finale(int $round, int $scene, int $step, ?array $reveal = null): void
    {
        $message = ['type' => 'finale', 'round' => $round, 'step' => $step];
        $step === Story::RAPIER_STEP or $message['item'] = $this->items->held($scene, $step);
        $reveal === null or $message['reveal'] = $reveal;

        $this->publish($message);
    }

    /**
     * The counters and goals of the cursed world.
     *
     * @param array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int} $cursed
     */
    public function cursed(int $round, array $cursed): void
    {
        $this->publish(['type' => 'cursed', 'round' => $round] + $cursed);
    }

    /**
     * A beer out of a pumpkin: the pumpkins of the cursed world never run dry.
     *
     * @param non-empty-string $player
     * @param non-empty-string $pumpkin
     */
    public function beer(int $round, string $player, string $pumpkin): void
    {
        $this->publish(['type' => 'take', 'round' => $round, 'player' => ['id' => $player], 'pumpkin' => ['id' => $pumpkin], 'ok' => true]);
    }

    /**
     * The player said the word: right — the rapier in hand, wrong — a zombie. The word itself stays on the server.
     */
    public function word(int $round, Player $player, bool $ok): void
    {
        $this->publish(['type' => 'word', 'round' => $round, 'player' => $player->ref(), 'ok' => $ok]);
    }

    /**
     * @param Player $player The zombie, cured.
     * @param Player $by Who cured it.
     */
    public function cure(int $round, Player $player, Player $by): void
    {
        $this->publish(['type' => 'cure', 'round' => $round, 'player' => $player->ref(), 'by' => $by->ref()]);
    }

    /**
     * FrankenPHP woke up, or the moderator tuned its health.
     *
     * @param array{hp: int<0, max>, max: positive-int} $boss
     */
    public function boss(int $round, array $boss): void
    {
        $this->publish(['type' => 'boss', 'round' => $round] + $boss);
    }

    /**
     * @param int<0, max> $hp FrankenPHP's health after the strike.
     */
    public function strike(int $round, Player $player, int $hp): void
    {
        $this->publish(['type' => 'strike', 'round' => $round, 'player' => $player->ref(), 'hp' => $hp]);
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
