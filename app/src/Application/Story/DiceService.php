<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Ability\AbilityGrants;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Player\PlayerService;
use App\Application\Walk\ActionStop;
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
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Idempotency;

/**
 * The dice of finale step 1 (docs/spec/scenario.md, «Кости 6-6-6»): the room rolls until someone rolls 6-6-6.
 *
 * The faces are a real random draw, so a retry must never draw again. An attempt is idempotent by its key
 * `{round}:roll:{player}:{attempt}` in an AtLeastOnce lease ({@see $replies}): the answer of the first run is kept and
 * replayed to a repeat, with no event and no count. The lease may run the throw twice — a worker dies after the throw
 * but before the answer is kept, or the kept answer expires — so the throw dedups itself as well: under the lock
 * `dice` the attempt number and its faces are written in one record, and a run that finds its attempt there answers
 * those faces instead of drawing. The same lock decides whose 6-6-6 is the first.
 *
 * @psalm-import-type Faces from Dice
 * @psalm-import-type Winner from Dice
 */
final readonly class DiceService
{
    /** Seconds a run holds the attempt's key before a repeat may take it over: a throw takes milliseconds. */
    private const LOCK_TTL = 5;

    /** The idempotency storage alias that keeps the answers, app/config/idempotency.php. */
    public const STORAGE = 'dice';

    /**
     * @param int<0, max> $cooldown Milliseconds between new throws of a player.
     * @param Idempotency $replies AtLeastOnce lease storage that keeps the answer of every attempt.
     * @param positive-int $replyTtl Seconds the answer of an attempt is kept for a repeat.
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
        private Idempotency $replies,
        private int $replyTtl,
        private ?ActionStop $stops = null,
        private ?EveningTally $tally = null,
        private ?AbilityGrants $abilities = null,
    ) {}

    /**
     * @return non-empty-string
     */
    public static function key(int $round, string $player, int $attempt): string
    {
        return \sprintf('%d:roll:%s:%d', $round, $player, $attempt);
    }

    /**
     * The player throws the attempt: the next one throws anew, one thrown before answers what it threw. On a pause of
     * the progress the dice roll all the same, but a 6-6-6 wins nothing; a repeat answers with the same `counted`.
     *
     * @param non-empty-string $id
     * @return array{attempt: positive-int, dice: Faces, counted?: false, ability?: non-empty-string}
     * @throws NoCharacterException
     * @throws ZombifiedException
     * @throws StoryRefusedException `not now` — not step 1 or the dice are off, `busy` — too soon after the last throw
     *         or the same attempt is being thrown right now.
     * @throws WaitException The owl has not reached the instruction yet: no attempt is spent.
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
        $openAt = $this->store->get($round)->openAt;
        $openAt !== null && $this->clock->now() < $openAt and throw new WaitException($openAt);

        // A repeat is answered from the lease without the throw, or by the throw from the record
        $repeat = true;
        $throw = function () use (&$repeat, $round, $id, $attempt, $walker, $ritual): array {
            [$answer, $new] = $this->throw($round, $id, $attempt, $walker->name, !$ritual->story->paused);
            $repeat = !$new;

            return $answer;
        };
        // Not a number of an attempt: nothing to keep, the throw refuses it with the last one
        if (!\is_int($attempt) || $attempt < 1) {
            return $throw();
        }

        try {
            // A refusal is not an answer: released, so the attempt throws once the refusal is gone
            $answer = $this->replies->execute(
                self::key($round, $id, $attempt),
                $throw,
                new ExecuteOptions(lockTtl: self::LOCK_TTL, ttl: $this->replyTtl, failurePolicy: FailurePolicy::Release),
            );
        } catch (LockedException) {
            throw new StoryRefusedException('busy');
        }
        $repeat and $this->tally?->add(EveningTally::DROPPED);

        return $answer;
    }

    /**
     * The moderator's «Сова бросает»: the owl's first two throws are anything but 6-6-6, the third is 6-6-6 and wins.
     *
     * @return array{attempt: positive-int, dice: Faces}
     * @throws StoryRefusedException `not now` — not step 1 or the dice are off.
     * @throws LockBusyException
     */
    public function owl(): array
    {
        $ritual = $this->ritual->snapshot();
        $round = $ritual->round;
        $ritual->story->dice() or throw new StoryRefusedException('not now');
        $now = $this->clock->now();

        $thrown = $this->store->update($round, function (Dice $dice): array {
            $dice->winner === null or throw new StoryRefusedException('not now');
            $attempt = $dice->owl + 1;
            $faces = $this->roller->owl($attempt);
            $dice = $dice->owlRolled();
            $faces === DiceRoller::SIX and $dice = $dice->won(['owl' => true, 'attempt' => $attempt]);

            return [$dice, [$attempt, $faces]];
        });
        [$attempt, $faces] = $thrown;

        $this->publisher->roll($round, null, $attempt, $faces, $now);
        $faces === DiceRoller::SIX and $this->win($round, ['owl' => true, 'attempt' => $attempt], evenPaused: true);

        return ['attempt' => $attempt, 'dice' => $faces];
    }

    /**
     * The dice as the snapshot carries them: from step 1 on, on until the first 6-6-6 or until the step is taken
     * without it; `owl` — the owl's throws so far, for the console.
     *
     * @return array{on: bool, winner?: Winner, owl: int<0, max>, openAt?: int}|null
     */
    public function state(int $round, Story $story): ?array
    {
        if (($story->finale ?? 0) < Story::DICE_STEP) {
            return null;
        }

        $dice = $this->store->get($round);
        $state = $dice->winner === null
            ? ['on' => $story->dice(), 'owl' => $dice->owl]
            : ['on' => false, 'winner' => $dice->winner, 'owl' => $dice->owl];
        $dice->openAt === null or $state['openAt'] = $dice->openAt;

        return $state;
    }

    /**
     * The dice come on with step 1, open for the players from `$openAt`. A busy lock loses the moment for the server,
     * not for the clients: the event carries it all the same.
     *
     * @param int|null $openAt Unix time in milliseconds; null — open at once.
     */
    public function begin(int $round, ?int $openAt = null): void
    {
        if ($openAt !== null) {
            try {
                $openAt = $this->store->update($round, static function (Dice $dice) use ($openAt): array {
                    $dice = $dice->opening($openAt);

                    return [$dice, $dice->openAt];
                });
            } catch (LockBusyException) {
            }
        }

        $this->publisher->dice($round, true, openAt: $openAt);
    }

    /**
     * The moderator took step 2 without a 6-6-6: the dice go off with no winner.
     */
    public function end(int $round): void
    {
        $this->store->get($round)->winner === null and $this->publisher->dice($round, false);
    }

    /**
     * The pause is lifted: a 6-6-6 thrown just as it came on makes the step ready now.
     *
     * @throws RitualBusyException
     */
    public function resume(): void
    {
        $ritual = $this->ritual->snapshot();
        $scene = $this->policy->finalScene();
        if ($ritual->story->dice()
            && $this->store->get($ritual->round)->winner !== null
            && $this->ritual->markReady($ritual->round, $scene, null, Story::DICE_STEP)) {
            $this->publisher->ready($ritual->round, $scene);
        }
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
     * @param bool $evenPaused The owl's throw: the moderator's own, which a pause does not hold.
     */
    private function win(int $round, array $winner, bool $evenPaused = false): void
    {
        $scene = $this->policy->finalScene();
        $this->publisher->dice($round, false, $winner);
        try {
            $this->ritual->markReady($round, $scene, null, Story::DICE_STEP, $evenPaused) and $this->publisher->ready($round, $scene);
        } catch (RitualBusyException) {
        }
        isset($winner['owl'])
            ? $this->publisher->owl($round, OwlLine::DICE_OWL_WIN)
            : $this->publisher->owl($round, OwlLine::DICE_WIN, $winner);
    }

    /**
     * The throw under the lock `dice`: the attempt found in the record answers its faces, the next one draws.
     *
     * @param non-empty-string $id
     * @param non-empty-string|null $name
     * @param bool $counted False on a pause of the progress: a new throw cannot win.
     * @return array{array{attempt: positive-int, dice: Faces, counted?: false, ability?: non-empty-string}, bool} The answer,
     *         and whether the throw is new rather than a repeat: only a new one brings an ability.
     * @throws StoryRefusedException
     * @throws BadAttemptException
     * @throws LockBusyException
     */
    private function throw(int $round, string $id, mixed $attempt, ?string $name, bool $counted): array
    {
        $now = $this->clock->now();
        $thrown = $this->store->update($round, function (Dice $dice) use ($id, $attempt, $now, $name, $counted): array {
            $last = $dice->last($id);
            // A repeat answers even after the 6-6-6: its response may have been lost on the way
            if ($last > 0 && $attempt === $last) {
                $faces = $dice->lastDice($id) ?? throw new \LogicException('The last attempt keeps its faces.');

                return [$dice, [$last, $faces, $dice->lastCounted($id), false, false]];
            }
            $dice->winner === null or throw new StoryRefusedException('not now');
            if (!\is_int($attempt) || $attempt !== $last + 1) {
                throw new BadAttemptException($last);
            }
            /** @var positive-int $attempt */
            $at = $dice->lastAt($id);
            $at !== null && $now - $at < $this->cooldown and throw new StoryRefusedException('busy');

            $faces = $this->roller->roll();
            $dice = $dice->rolled($id, $attempt, $now, $faces, $counted);
            $won = $counted && $faces === DiceRoller::SIX;
            $won and $dice = $dice->won(['player' => ['id' => $id, 'name' => $name], 'attempt' => $attempt]);

            return [$dice, [$attempt, $faces, $counted, true, $won]];
        });
        [$attempt, $faces, $counted, $new, $won] = $thrown;

        // A repeat answers a throw that has stopped the character already
        $ability = null;
        if ($new) {
            $this->stops?->stop($id, ActionStop::DICE);
            $player = $this->players->rolled($id) ?? new Player($id, $name);
            $this->publisher->roll($round, $player, $attempt, $faces, $now, $counted);
            $won and $this->win($round, ['player' => $player->ref(), 'attempt' => $attempt]);
            // After the win: the owl names the winner of the dice before the ability
            $ability = $this->abilities?->rolled($round, $id, $faces);
        }

        $answer = $counted ? ['attempt' => $attempt, 'dice' => $faces] : ['attempt' => $attempt, 'dice' => $faces, 'counted' => false];
        $ability === null or $answer['ability'] = $ability;

        return [$answer, $new];
    }
}
