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
     * @param bool $counted False on a pause of the progress: the action went through, the scene's task did not move.
     */
    public function click(int $round, Pumpkin $pumpkin, string $emoji, int $threshold, ?Player $player = null, bool $counted = true): void
    {
        $this->publish(self::counted(self::withPlayer([
            'type' => 'click',
            'round' => $round,
            'element' => $pumpkin->id,
            'emoji' => $emoji,
            'clicks' => $pumpkin->clicks,
            'threshold' => $threshold,
            'stage' => $pumpkin->stage,
        ], $player), $counted));
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
    /**
     * @param bool $counted False on a pause of the progress: the mug is drunk, the goal did not move.
     */
    public function drink(int $round, string $player, bool $counted = true): void
    {
        $this->publish(self::counted(['type' => 'drink', 'round' => $round, 'player' => ['id' => $player]], $counted));
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

    /**
     * Wandering zombies taken off the lawn unhurt: the dawn has come or the round started over.
     *
     * @param non-empty-list<non-empty-string> $flyers
     */
    public function gone(int $round, array $flyers): void
    {
        $this->publish(['type' => 'gone', 'round' => $round, 'flyers' => $flyers]);
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
     * A walk to a clink carries its target, so every client knows the character is going to clink; a stop names the
     * action that stopped it, so every client holds the pose of that action.
     *
     * @param non-empty-string|null $stop The action that stopped the character ({@see \App\Application\Walk\ActionStop}).
     */
    public function move(int $round, Walker $walker, ?string $stop = null): void
    {
        $message = [
            'type' => 'move',
            'round' => $round,
            'player' => ['id' => $walker->id],
            'walk' => $walker->walk->toArray(),
        ];
        $walker->clink === null or $message['clink'] = $walker->clink->toTarget();
        $stop === null or $message['stop'] = $stop;

        $this->publish($message);
    }

    /**
     * @param non-empty-string $player Who came up.
     * @param array<'walker'|'pumpkin'|'roadrunner', array{id: non-empty-string}|true> $target `walker`, `pumpkin` or `roadrunner`: what the player clinks with.
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
     * The limits of the current context changed: the room, the multiplier, a bonus, the scene or the step.
     *
     * @param array{players: positive-int, k: float, burst: bool, limits: array|\stdClass} $limits
     */
    public function limits(int $round, array $limits): void
    {
        $this->publish(['type' => 'limits', 'round' => $round] + $limits);
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
     * @param int $until Unix time in milliseconds when the mug is in hand: the hand-over plays until then.
     */
    public function beer(int $round, string $player, string $pumpkin, int $until): void
    {
        $this->publish(['type' => 'take', 'round' => $round, 'player' => ['id' => $player], 'pumpkin' => ['id' => $pumpkin], 'ok' => true, 'until' => $until]);
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
     * @param bool $counted False on a pause of the progress: the lightning strikes, the health stays.
     */
    public function strike(int $round, Player $player, int $hp, bool $counted = true): void
    {
        $this->publish(self::counted(['type' => 'strike', 'round' => $round, 'player' => $player->ref(), 'hp' => $hp], $counted));
    }

    /**
     * The dice of finale step 1 come on or go off; off with the winner after the first 6-6-6.
     *
     * @param array|null $winner `{player: {id, name}, attempt}` or `{owl: true, attempt}`.
     */
    public function dice(int $round, bool $on, ?array $winner = null): void
    {
        $message = ['type' => 'dice', 'round' => $round, 'on' => $on];
        $winner === null or $message['winner'] = $winner;

        $this->publish($message);
    }

    /**
     * A new throw of the dice: a player's, or the owl's when there is no player.
     *
     * @param positive-int $attempt
     * @param array{int, int, int} $dice
     * @param int $at Unix time in milliseconds of the throw.
     * @param bool $counted False on a pause of the progress: a 6-6-6 then wins nothing.
     */
    public function roll(int $round, ?Player $player, int $attempt, array $dice, int $at, bool $counted = true): void
    {
        $message = ['type' => 'roll', 'round' => $round];
        $message += $player === null ? ['owl' => true] : ['player' => $player->ref()];

        $this->publish(self::counted($message + ['attempt' => $attempt, 'dice' => $dice, 'at' => $at], $counted));
    }

    /**
     * RoadRunner says «Мип-мип!» on the moderator's click: over the runner `$flyer`, or over the one of the scene.
     *
     * @param non-empty-string|null $flyer
     * @param int $at Unix time in milliseconds.
     */
    public function meep(int $round, ?string $flyer, int $at): void
    {
        $message = ['type' => 'meep', 'round' => $round];
        $flyer === null or $message['flyer'] = $flyer;

        $this->publish($message + ['at' => $at]);
    }

    /**
     * A player said something in the chat.
     *
     * @param array{id: non-empty-string, name: non-empty-string|null} $player
     * @param non-empty-string|null $text Null — a zombie spoke: the page picks a moan of its own.
     */
    public function say(int $round, array $player, ?string $text, int $at): void
    {
        $message = ['type' => 'say', 'round' => $round, 'player' => $player];
        $message += $text === null ? ['zombie' => true] : ['text' => $text];

        $this->publish($message + ['at' => $at]);
    }

    /**
     * The whole ranking of the evening's shooters, once it has changed.
     *
     * @param list<array{player: array{id: non-empty-string, name: non-empty-string|null}, points: positive-int}> $board
     */
    public function top(int $round, array $board): void
    {
        $this->publish(['type' => 'top', 'round' => $round, 'board' => $board]);
    }

    /**
     * The counts of the channel presence, once they have changed.
     *
     * @param array{players: int<0, max>, connections: int<0, max>} $counts
     */
    public function presence(int $round, array $counts): void
    {
        $this->publish(['type' => 'presence', 'round' => $round] + $counts);
    }

    /**
     * A line of the owl's catalogue;the frontend holds the texts and fills the params in.
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

    /**
     * Only an action the pause did not count says so: `counted` is absent otherwise.
     */
    private static function counted(array $message, bool $counted): array
    {
        $counted or $message['counted'] = false;

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
