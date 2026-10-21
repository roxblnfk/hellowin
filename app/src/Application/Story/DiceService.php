<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Player\PlayerService;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\ZombifiedException;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Player;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Dice;
use App\Domain\Story\DiceRoller;
use App\Domain\Story\DiceStore;
use App\Domain\Story\Story;
use App\Domain\Walk\WalkerStore;

/**
 * The dice of finale step 1 (docs/spec/scenario.md, «Кости 6-6-6»): the room rolls until someone rolls 6-6-6.
 *
 * An attempt is idempotent by its key `{round}:roll:{player}:{attempt}`: the faces are the HMAC of it, so a repeated
 * request is answered with the same throw and no event, and only the next attempt throws anew — a retry cannot
 * re-roll. Every throw takes the one lock `dice`, which decides whose 6-6-6 is the first.
 *
 * @psalm-import-type Winner from Dice
 */
final readonly class DiceService
{
    /**
     * @param int<0, max> $cooldown Milliseconds between new throws of a player.
     */
    public function __construct(
        private DiceStore $store,
        private DiceRoller $roller,
        private RitualStore $ritual,
        private RitualPolicy $policy,
        private WalkerStore $walkers,
        private PlayerService $players,
        private ChannelPublisher $publisher,
        private Clock $clock,
        private int $cooldown,
    ) {}

    /**
     * The player throws the attempt: the next one throws anew, the last one again answers what it threw.
     *
     * @param non-empty-string $id
     * @return array{attempt: positive-int, dice: array{int<1, 6>, int<1, 6>, int<1, 6>}}
     * @throws NoCharacterException
     * @throws ZombifiedException
     * @throws StoryRefusedException `not now` — not step 1 or the dice are off, `busy` — too soon after the last throw.
     * @throws BadAttemptException
     * @throws LockBusyException
     */
    public function roll(string $id, mixed $attempt): array
    {
        $walker = $this->walkers->all()[$id] ?? throw new NoCharacterException('no character');
        $walker->zombie() and throw new ZombifiedException();
        $ritual = $this->ritual->snapshot();
        $round = $ritual->round;
        $ritual->story->dice() or throw new StoryRefusedException('not now');
        $now = $this->clock->now();

        $thrown = $this->store->update($round, function (Dice $dice) use ($id, $attempt, $round, $now, $walker): array {
            $last = $dice->last($id);
            // A repeat answers even after the 6-6-6: its response may have been lost on the way
            if ($last > 0 && $attempt === $last) {
                return [$dice, [$last, $this->roller->roll($round, $id, $last), false, false]];
            }
            $dice->winner === null or throw new StoryRefusedException('not now');
            if (!\is_int($attempt) || $attempt !== $last + 1) {
                throw new BadAttemptException($last);
            }
            /** @var positive-int $attempt */
            $at = $dice->lastAt($id);
            $at !== null && $now - $at < $this->cooldown and throw new StoryRefusedException('busy');

            $faces = $this->roller->roll($round, $id, $attempt);
            $dice = $dice->rolled($id, $attempt, $now);
            $won = $faces === DiceRoller::SIX;
            $won and $dice = $dice->won(['player' => ['id' => $id, 'name' => $walker->name], 'attempt' => $attempt]);

            return [$dice, [$attempt, $faces, true, $won]];
        });
        [$attempt, $faces, $new, $won] = $thrown;

        if ($new) {
            $player = $this->players->rolled($id) ?? new Player($id, $walker->name);
            $this->publisher->roll($round, $player, $attempt, $faces, $now);
            $won and $this->win($round, ['player' => $player->ref(), 'attempt' => $attempt]);
        }

        return ['attempt' => $attempt, 'dice' => $faces];
    }

    /**
     * The moderator's «Сова бросает»: the owl's first two throws are anything but 6-6-6, the third is 6-6-6 and wins.
     *
     * @return array{attempt: positive-int, dice: array{int<1, 6>, int<1, 6>, int<1, 6>}}
     * @throws StoryRefusedException `not now` — not step 1 or the dice are off.
     * @throws LockBusyException
     */
    public function owl(): array
    {
        $ritual = $this->ritual->snapshot();
        $round = $ritual->round;
        $ritual->story->dice() or throw new StoryRefusedException('not now');
        $now = $this->clock->now();

        $thrown = $this->store->update($round, function (Dice $dice) use ($round): array {
            $dice->winner === null or throw new StoryRefusedException('not now');
            $attempt = $dice->owl + 1;
            $faces = $this->roller->owl($round, $attempt);
            $dice = $dice->owlRolled();
            $faces === DiceRoller::SIX and $dice = $dice->won(['owl' => true, 'attempt' => $attempt]);

            return [$dice, [$attempt, $faces]];
        });
        [$attempt, $faces] = $thrown;

        $this->publisher->roll($round, null, $attempt, $faces, $now);
        $faces === DiceRoller::SIX and $this->win($round, ['owl' => true, 'attempt' => $attempt]);

        return ['attempt' => $attempt, 'dice' => $faces];
    }

    /**
     * The dice as the snapshot carries them: from step 1 on, on until the first 6-6-6 or until the step is taken
     * without it.
     *
     * @return array{on: bool, winner?: Winner}|null
     */
    public function state(int $round, Story $story): ?array
    {
        if (($story->finale ?? 0) < Story::DICE_STEP) {
            return null;
        }

        $winner = $this->store->get($round)->winner;

        return $winner === null ? ['on' => $story->dice()] : ['on' => false, 'winner' => $winner];
    }

    /**
     * The dice come on with step 1.
     */
    public function begin(int $round): void
    {
        $this->publisher->dice($round, true);
    }

    /**
     * The moderator took step 2 without a 6-6-6: the dice go off with no winner.
     */
    public function end(int $round): void
    {
        $this->store->get($round)->winner === null and $this->publisher->dice($round, false);
    }

    /**
     * @return int<0, max> New throws of the players in the round.
     */
    public function rolls(int $round): int
    {
        return $this->store->get($round)->rolls;
    }

    /**
     * The first 6-6-6, decided under the lock: the dice go off, the step is ready, and the owl names who rolled it.
     *
     * @param Winner $winner
     */
    private function win(int $round, array $winner): void
    {
        $scene = $this->policy->finalScene();
        $this->publisher->dice($round, false, $winner);
        try {
            $this->ritual->markReady($round, $scene, null, Story::DICE_STEP) and $this->publisher->ready($round, $scene);
        } catch (RitualBusyException) {
        }
        isset($winner['owl'])
            ? $this->publisher->owl($round, OwlLine::DICE_OWL_WIN)
            : $this->publisher->owl($round, OwlLine::DICE_WIN, $winner);
    }
}
