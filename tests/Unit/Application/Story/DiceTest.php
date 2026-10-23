<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Story\BadAttemptException;
use App\Application\Story\DiceService;
use App\Application\Story\EveningTally;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Application\Walk\NoCharacterException;
use App\Domain\Lock\LockBusyException;
use App\Domain\Story\DiceRoller;
use App\Domain\Story\Story;
use Spiral\Idempotency\FailurePolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The dice of finale step 1 (docs/spec/scenario.md, «Кости 6-6-6»). The harness has scene 1 as the final one and
 * a pause of 1800 ms between new throws of a player.
 */
#[Test]
#[Covers(DiceService::class)]
#[Covers(StoryService::class)]
final class DiceTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];
    private const COOLDOWN = 1800;

    public function firstStepTurnsTheDiceOnAndWaitsForThem(): void
    {
        $ritual = self::finale();
        $ritual->click(RitualHarness::CIRCLE, 3);
        $ritual->runJobs();
        Assert::true($ritual->snapshot->create()['ready']);
        Assert::null($ritual->snapshot->create()['dice']);
        $ritual->broadcast->published = [];

        $ritual->story->finaleStep(1);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['finale', 'limits', 'owl', 'dice']);
        Assert::same($ritual->broadcast->ofType('dice'), [['type' => 'dice', 'round' => 1, 'on' => true]]);
        $snapshot = $ritual->snapshot->create();
        Assert::false($snapshot['ready']);
        Assert::same($snapshot['dice'], ['on' => true, 'owl' => 0]);
        Assert::same(self::refusal(static fn() => $ritual->story->finaleStep(2)), 'not ready');
    }

    public function newAttemptThrowsForEveryone(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [2, 5, 3];

        $result = $ritual->dice->roll(self::JACK, 1);

        $dice = [2, 5, 3];
        Assert::same($result, ['attempt' => 1, 'dice' => $dice]);
        Assert::same($ritual->broadcast->ofType('roll'), [[
            'type' => 'roll',
            'round' => 1,
            'player' => ['id' => self::JACK, 'name' => 'Jack'],
            'attempt' => 1,
            'dice' => $dice,
            'at' => $ritual->clock->now,
        ]]);
        Assert::same($ritual->playerStore->find(self::JACK)?->rolls, 1);
    }

    public function repeatAnswersTheSameThrowWithoutEventCountOrPause(): void
    {
        $ritual = self::dice();
        $first = $ritual->dice->roll(self::JACK, 1);

        $again = $ritual->dice->roll(self::JACK, 1);

        Assert::same($again, $first);
        Assert::count($ritual->broadcast->ofType('roll'), 1);
        Assert::same($ritual->playerStore->find(self::JACK)?->rolls, 1);
        Assert::same($ritual->diceStore->get(1)->rolls, 1);
    }

    public function answerIsKeptByTheAttemptsKeyForItsTtlAndReleasedOnARefusal(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [4, 1, 2];

        $first = $ritual->dice->roll(self::JACK, 1);
        $refused = self::refusal(static fn() => $ritual->dice->roll(self::JACK, 2));

        Assert::same($refused, 'busy');
        Assert::same($ritual->diceReplies->kept, ['1:roll:' . self::JACK . ':1' => ['attempt' => 1, 'dice' => [4, 1, 2]]]);
        [$key, $options] = $ritual->diceReplies->calls[0];
        Assert::same([$key, $options?->ttl, $options?->failurePolicy], ['1:roll:' . self::JACK . ':1', RitualHarness::DICE_TTL, FailurePolicy::Release]);
        Assert::same($first['dice'], [4, 1, 2]);
    }

    public function facesAreWrittenWithTheAttempt(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [5, 3, 6];

        $ritual->dice->roll(self::JACK, 1);

        Assert::same($ritual->diceStore->get(1)->players[self::JACK] ?? null, ['attempt' => 1, 'at' => $ritual->clock->now, 'dice' => [5, 3, 6]]);
        Assert::same($ritual->diceStore->get(1)->lastDice(self::JACK), [5, 3, 6]);
    }

    public function repeatWithoutItsKeptAnswerFallsBackToTheRecord(): void
    {
        $ritual = self::dice();
        $first = $ritual->dice->roll(self::JACK, 1);
        $ritual->diceReplies->kept = [];

        $again = $ritual->dice->roll(self::JACK, 1);

        Assert::same($again, $first);
        Assert::count($ritual->broadcast->ofType('roll'), 1);
        Assert::same($ritual->diceStore->get(1)->rolls, 1);
    }

    public function nextAttemptDrawsAnewAndAnEarlierOneIsReplayed(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [1, 1, 2, 3, 4, 5];
        $first = $ritual->dice->roll(self::JACK, 1);
        $ritual->clock->now += self::COOLDOWN;

        $second = $ritual->dice->roll(self::JACK, 2);

        Assert::same([$first['dice'], $second['dice']], [[1, 1, 2], [3, 4, 5]]);
        Assert::same($ritual->dice->roll(self::JACK, 1), $first);
        Assert::count($ritual->broadcast->ofType('roll'), 2);
    }

    public function newThrowStopsAWalkingCharacterFirstAndARefusedOneDoesNot(): void
    {
        $ritual = self::dice();
        $ritual->walks->move(self::JACK, 80, 90);
        $ritual->clock->now += 500;
        $ritual->broadcast->published = [];

        $ritual->dice->roll(self::JACK, 1);
        $ritual->walks->move(self::JACK, 80, 90);
        $ritual->clock->now += 500;
        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, 2)), 'busy');
        $ritual->dice->roll(self::JACK, 1);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['move', 'roll', 'move']);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'], 'dice');
        Assert::false(isset($ritual->broadcast->ofType('move')[1]['stop']));
        Assert::true($ritual->walkerStore->all()[self::JACK]->walk->arrivesAt > $ritual->clock->now);
    }

    public function attemptOutOfOrderIsRefusedWithTheLastOne(): void
    {
        $ritual = self::dice();
        $ritual->dice->roll(self::JACK, 1);

        Assert::same(self::badAttempt(static fn() => $ritual->dice->roll(self::JACK, 3)), 1);
        Assert::same(self::badAttempt(static fn() => $ritual->dice->roll(self::JACK, 0)), 1);
        Assert::same(self::badAttempt(static fn() => $ritual->dice->roll(self::JACK, '2')), 1);
        Assert::same(self::badAttempt(static fn() => $ritual->dice->roll(self::JILL, 2)), 0);
        Assert::count($ritual->broadcast->ofType('roll'), 1);
    }

    public function newThrowWaitsForThePause(): void
    {
        $ritual = self::dice();
        $ritual->dice->roll(self::JACK, 1);

        $ritual->clock->now += self::COOLDOWN - 1;
        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, 2)), 'busy');
        // Another player has a pause of their own
        Assert::same($ritual->dice->roll(self::JILL, 1)['attempt'], 1);

        $ritual->clock->now += 1;
        Assert::same($ritual->dice->roll(self::JACK, 2)['attempt'], 2);
    }

    public function playerWithAnIdOfDigitsKeepsTheLastAttempt(): void
    {
        $ritual = self::dice();
        $digits = '1234567890123456';
        $ritual->players->register($digits, 'Joe', self::LOOK);
        $ritual->walks->move($digits, 40, 90);
        $ritual->dice->roll($digits, 1);

        $ritual->clock->now += self::COOLDOWN;

        Assert::same($ritual->dice->roll($digits, 2)['attempt'], 2);
    }

    public function rollIsRefusedOutsideTheDiceAndWithoutACharacter(): void
    {
        $ritual = self::finale();
        self::place($ritual, self::JACK);

        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, 1)), 'not now');
        Assert::same(self::refusal(static fn() => $ritual->dice->owl()), 'not now');
        $ritual->story->finaleStep(1, force: true);
        $ritual->story->finaleStep(2, force: true);
        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, 1)), 'not now');

        Expect::exception(NoCharacterException::class);

        $ritual->dice->roll(self::JILL, 1);
    }

    public function firstSixSixSixWinsAndMakesTheStepReady(): void
    {
        $ritual = self::dice();
        $attempt = 2;
        $ritual->dice->roll(self::JACK, 1);
        $ritual->clock->now += self::COOLDOWN;
        $ritual->diceFaces->faces = [6, 6, 6];
        $ritual->broadcast->published = [];

        $result = $ritual->dice->roll(self::JACK, $attempt);

        Assert::same($result, ['attempt' => $attempt, 'dice' => [6, 6, 6]]);
        $winner = ['player' => ['id' => self::JACK, 'name' => 'Jack'], 'attempt' => $attempt];
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['roll', 'dice', 'ready', 'owl']);
        Assert::same($ritual->broadcast->ofType('dice'), [['type' => 'dice', 'round' => 1, 'on' => false, 'winner' => $winner]]);
        Assert::same($ritual->broadcast->ofType('ready')[0]['scene'], 1);
        Assert::same($ritual->broadcast->ofType('owl'), [['type' => 'owl', 'round' => 1, 'line' => 'dice-win', 'params' => $winner]]);
        $snapshot = $ritual->snapshot->create();
        Assert::true($snapshot['ready']);
        Assert::same($snapshot['dice'], ['on' => false, 'winner' => $winner, 'owl' => 0]);

        // The winning request again, its answer lost on the way: the same throw and nothing new
        Assert::same($ritual->dice->roll(self::JACK, $attempt), $result);
        Assert::count($ritual->broadcast->ofType('dice'), 1);
        $ritual->clock->now += self::COOLDOWN;
        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, $attempt + 1)), 'not now');
        Assert::same(self::refusal(static fn() => $ritual->dice->owl()), 'not now');
    }

    public function ofTwoSixSixSixTheOneUnderTheLockFirstWins(): void
    {
        $ritual = self::dice();
        $jack = 1;
        $jill = 1;
        $ritual->diceFaces->faces = [6, 6, 6, 6, 6, 6];
        $ritual->broadcast->published = [];

        // While Jill's throw holds the lock, Jack's waits for it
        $ritual->lock->lock('dice', 'jill');
        Assert::true(self::lockBusy(static fn() => $ritual->dice->roll(self::JACK, $jack)));
        $ritual->lock->release('dice', 'jill');
        $ritual->dice->roll(self::JILL, $jill);

        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, $jack)), 'not now');
        Assert::same($ritual->snapshot->create()['dice']['winner']['player']['id'] ?? null, self::JILL);
        Assert::count($ritual->broadcast->ofType('ready'), 1);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['dice-win']);
    }

    public function secondStepAfterTheWinNeedsNoForce(): void
    {
        $ritual = self::dice();
        $ritual->dice->owl();
        $ritual->dice->owl();
        $ritual->dice->owl();
        $ritual->broadcast->published = [];

        Assert::same($ritual->story->finaleStep(2), 2);

        Assert::blank($ritual->broadcast->ofType('dice'));
        Assert::same($ritual->snapshot->create()['dice'], ['on' => false, 'winner' => ['owl' => true, 'attempt' => 3], 'owl' => 3]);
    }

    public function forcedSecondStepTurnsTheDiceOffWithoutWinner(): void
    {
        $ritual = self::dice();
        $ritual->broadcast->published = [];

        $ritual->story->finaleStep(2, force: true);

        Assert::same($ritual->broadcast->ofType('dice'), [['type' => 'dice', 'round' => 1, 'on' => false]]);
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type')[0], 'dice');
        Assert::same($ritual->snapshot->create()['dice'], ['on' => false, 'owl' => 0]);
    }

    public function owlWinsOnItsThirdThrow(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [6, 6, 6, 1, 2, 3, 4, 5, 6];
        $ritual->broadcast->published = [];

        $first = $ritual->dice->owl();
        $second = $ritual->dice->owl();
        $third = $ritual->dice->owl();

        // The first draw of 6-6-6 is drawn again
        Assert::same($first, ['attempt' => 1, 'dice' => [1, 2, 3]]);
        Assert::same($second, ['attempt' => 2, 'dice' => [4, 5, 6]]);
        Assert::same($third, ['attempt' => 3, 'dice' => DiceRoller::SIX]);
        Assert::same($ritual->broadcast->ofType('roll')[0], ['type' => 'roll', 'round' => 1, 'owl' => true, 'attempt' => 1, 'dice' => $first['dice'], 'at' => $ritual->clock->now]);
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['roll', 'roll', 'roll', 'dice', 'ready', 'owl']);
        Assert::same($ritual->broadcast->ofType('dice')[0]['winner'], ['owl' => true, 'attempt' => 3]);
        Assert::same($ritual->broadcast->ofType('owl'), [['type' => 'owl', 'round' => 1, 'line' => 'dice-owl-win']]);
        Assert::same(self::refusal(static fn() => $ritual->dice->owl()), 'not now');
        // The owl's throws are not the room's
        Assert::same($ritual->diceStore->get(1)->rolls, 0);
    }

    public function revealCountsTheRoomsNewThrows(): void
    {
        $ritual = self::dice();
        $ritual->dice->roll(self::JACK, 1);
        $ritual->dice->roll(self::JACK, 1);
        $ritual->dice->roll(self::JILL, 1);
        $ritual->dice->owl();

        foreach ([2, 3, 4] as $step) {
            $ritual->story->finaleStep($step, force: true);
        }

        $finale = $ritual->broadcast->ofType('finale');
        Assert::same(end($finale)['reveal']['rolls'] ?? null, 2);
        Assert::same($ritual->snapshot->create()['finale']['reveal']['rolls'] ?? null, 2);
    }

    public function everyRepeatIsBurnedOnceWhetherTheLeaseOrTheRecordAnswersIt(): void
    {
        $ritual = self::dice();
        $ritual->dice->roll(self::JACK, 1);
        Assert::same($ritual->tally->count(EveningTally::DROPPED), 0);

        $ritual->dice->roll(self::JACK, 1);
        $ritual->diceReplies->kept = [];
        $ritual->dice->roll(self::JACK, 1);
        self::badAttempt(static fn() => $ritual->dice->roll(self::JACK, 5));

        Assert::same($ritual->tally->count(EveningTally::DROPPED), 2);
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->burned, 2);
    }

    public function resetForgetsTheDice(): void
    {
        $ritual = self::dice();
        $ritual->dice->roll(self::JACK, 1);

        $ritual->reset->reset();

        Assert::null($ritual->snapshot->create()['dice']);
        Assert::same($ritual->diceStore->get(2)->last(self::JACK), 0);
    }

    private static function finale(): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);

        return $ritual;
    }

    /**
     * Step 1 with Jack and Jill on the lawn and the dice on.
     */
    private static function dice(): RitualHarness
    {
        $ritual = self::finale();
        self::place($ritual, self::JACK);
        $ritual->players->register(self::JILL, 'Jill', self::LOOK);
        $ritual->walks->move(self::JILL, 60, 90);
        $ritual->story->finaleStep(1, force: true);
        Assert::same($ritual->store->story()->finale, Story::DICE_STEP);
        // Both arrive, or the first throw would stop a walking character
        foreach ($ritual->walkerStore->all() as $walker) {
            $ritual->clock->now = \max($ritual->clock->now, $walker->walk->arrivesAt);
        }

        return $ritual;
    }

    /**
     * @param non-empty-string $id
     */
    private static function place(RitualHarness $ritual, string $id): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $ritual->walks->move($id, 20, 90);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (StoryRefusedException $e) {
            return $e->error;
        }

        return null;
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function badAttempt(\Closure $call): ?int
    {
        try {
            $call();
        } catch (BadAttemptException $e) {
            return $e->last;
        }

        return null;
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function lockBusy(\Closure $call): bool
    {
        try {
            $call();
        } catch (LockBusyException) {
            return true;
        }

        return false;
    }
}
