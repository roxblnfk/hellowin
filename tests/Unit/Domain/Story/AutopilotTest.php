<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Story\Autopilot;
use App\Domain\Story\AutoRecord;
use App\Domain\Story\AutoScene;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The transition table of the autopilot (docs/spec/scenario.md, «Автопилот»), on the harness's speech table: staging of
 * finale steps 1–7 — 5200, 2200, 6800, 0, 5600, 2650, 8000 ms; a pause of 4000 ms, the dawn 300…600 s, the owl's dice
 * after 90 s. The final scene is 4.
 */
#[Test]
#[Covers(Autopilot::class)]
final class AutopilotTest
{
    private const NOW = 1_000_000;
    private const PAUSE = 4000;

    public function barWaitsForTheRoomWithoutTheStall(): void
    {
        [, $next, $due] = self::autopilot()->tick(self::scene(0, ready: false), null, 0, self::NOW);

        Assert::same($next, ['action' => 'bar.ready', 'wait' => 'room']);
        Assert::false($due);
    }

    public function barWavesWithForceOnceStalled(): void
    {
        $autopilot = self::autopilot(stall: 60000);
        [$record, $next] = $autopilot->tick(self::scene(0, ready: false), null, 0, self::NOW);

        [, , $due] = $autopilot->tick(self::scene(0, ready: false), $record, 0, self::NOW + 60000);

        Assert::same($next, ['action' => 'bar.ready', 'at' => self::NOW + 60000, 'force' => true]);
        Assert::true($due);
    }

    #[DataSet([0, 'bar'])]
    #[DataSet([1, 'day'])]
    #[DataSet([3, 'night'])]
    public function readySceneMovesOnceTheOwlHasFinishedAndThePausePassed(int $scene): void
    {
        $autopilot = self::autopilot();
        $talk = self::NOW + 10000;
        [$record, $next, $due] = $autopilot->tick(self::scene($scene, ready: true), null, $talk, self::NOW);

        [, , $early] = $autopilot->tick(self::scene($scene, ready: true), $record, $talk, $talk + self::PAUSE - 1);
        [, , $onTime] = $autopilot->tick(self::scene($scene, ready: true), $record, $talk, $talk + self::PAUSE);

        Assert::same($next, ['action' => 'scene.next', 'to' => $scene + 1, 'at' => $talk + self::PAUSE]);
        Assert::false($due);
        Assert::false($early);
        Assert::true($onTime);
    }

    public function readinessCountsFromTheFirstTickThatSawIt(): void
    {
        $autopilot = self::autopilot();
        [$record] = $autopilot->tick(self::scene(2, ready: false), null, 0, self::NOW);

        [$record, $next] = $autopilot->tick(self::scene(2, ready: true), $record, 0, self::NOW + 30000);

        Assert::same($record->readyAt, self::NOW + 30000);
        Assert::same($next, ['action' => 'scene.next', 'to' => 3, 'at' => self::NOW + 30000 + self::PAUSE]);
    }

    public function sceneWaitsForTheRoomOrMovesWithForceOnceStalled(): void
    {
        [, $waits] = self::autopilot()->tick(self::scene(2, ready: false), null, 0, self::NOW);
        [, $stalls] = self::autopilot(stall: 120000)->tick(self::scene(2, ready: false), null, 0, self::NOW);

        Assert::same($waits, ['action' => 'scene.next', 'to' => 3, 'wait' => 'room']);
        Assert::same($stalls, ['action' => 'scene.next', 'to' => 3, 'at' => self::NOW + 120000, 'force' => true]);
    }

    #[DataSet([0, 0], 'circle closed')]
    #[DataSet([1, 5200], 'first 6-6-6')]
    #[DataSet([5, 5600], 'cursed goals')]
    public function readyStepMovesAfterItsStagingAndTheOwl(int $step, int $staging): void
    {
        [, $next] = self::autopilot()->tick(self::step($step, ready: true), null, 0, self::NOW);
        [, $talking] = self::autopilot()->tick(self::step($step, ready: true), null, self::NOW + 20000, self::NOW);

        Assert::same($next, ['action' => 'finale.step', 'to' => $step + 1, 'at' => self::NOW + $staging + self::PAUSE]);
        Assert::same($talking['at'] ?? null, self::NOW + 20000 + self::PAUSE);
    }

    #[DataSet([0])]
    #[DataSet([5])]
    public function stepWithATaskWaitsForTheRoom(int $step): void
    {
        [, $next] = self::autopilot()->tick(self::step($step, ready: false), null, 0, self::NOW);

        Assert::same($next, ['action' => 'finale.step', 'to' => $step + 1, 'wait' => 'room']);
    }

    #[DataSet([2, 2200], 'lightning')]
    #[DataSet([3, 6800], 'the run')]
    #[DataSet([4, 0], 'the numbers')]
    public function stagedStepMovesWhenItsStagingAndTheOwlAreOver(int $step, int $staging): void
    {
        [, $next, $due] = self::autopilot()->tick(self::step($step, ready: false), null, 0, self::NOW);

        Assert::same($next, ['action' => 'finale.step', 'to' => $step + 1, 'at' => self::NOW + $staging + self::PAUSE]);
        Assert::false($due);
    }

    public function bossStepLeavesTheDawnToTheRoomUnlessStalled(): void
    {
        [, $waits] = self::autopilot()->tick(self::step(6, ready: false), null, 0, self::NOW);
        [, $stalls] = self::autopilot(stall: 120000)->tick(self::step(6, ready: false), null, 0, self::NOW);

        Assert::same($waits, ['action' => 'finale.step', 'to' => 7, 'wait' => 'room']);
        Assert::same($stalls, ['action' => 'finale.step', 'to' => 7, 'at' => self::NOW + 120000, 'force' => true]);
    }

    public function owlThrowsTheDiceAfterTheRoomHadItsTimeAndOncePerPause(): void
    {
        $autopilot = self::autopilot();
        $first = self::NOW + 5200 + RitualHarness::AUTO_DICE;
        [$record, $next] = $autopilot->tick(self::step(1, ready: false, dice: true), null, 0, self::NOW);

        [$record, , $thrown] = $autopilot->tick(self::step(1, ready: false, dice: true), $record, 0, $first);
        [$record, $after, $again] = $autopilot->tick(self::step(1, ready: false, dice: true), $record, 0, $first + 1);
        [, , $next2] = $autopilot->tick(self::step(1, ready: false, dice: true), $record, 0, $first + self::PAUSE);

        Assert::same($next, ['action' => 'dice.owl', 'at' => $first]);
        Assert::true($thrown);
        Assert::same($record->owlRollAt, $first);
        Assert::same($after, ['action' => 'dice.owl', 'at' => $first + self::PAUSE]);
        Assert::false($again);
        Assert::true($next2);
    }

    public function owlDoesNotThrowWithTheDiceOffOrWithoutTheEnv(): void
    {
        [, $off] = self::autopilot()->tick(self::step(1, ready: false, dice: false), null, 0, self::NOW);
        [, $never] = self::autopilot(dice: 0)->tick(self::step(1, ready: false, dice: true), null, 0, self::NOW);

        Assert::same($off, ['action' => 'finale.step', 'to' => 2, 'wait' => 'room']);
        Assert::same($never, ['action' => 'finale.step', 'to' => 2, 'wait' => 'room']);
    }

    public function dawnDeadlineIsChosenOnceWithinTheBounds(): void
    {
        $autopilot = self::autopilot();
        [$record, $next] = $autopilot->tick(self::step(7, ready: false), null, 0, self::NOW);

        [$later, $still] = $autopilot->tick(self::step(7, ready: false), $record, self::NOW + 900000, self::NOW + 1000);

        Assert::int($record->dawnUntil ?? 0)
            ->greaterThanOrEqual(self::NOW + RitualHarness::AUTO_DAWN[0])
            ->lessThanOrEqual(self::NOW + RitualHarness::AUTO_DAWN[1]);
        Assert::same($next, ['action' => 'again', 'at' => $record->dawnUntil]);
        Assert::same($later->dawnUntil, $record->dawnUntil);
        Assert::same($still, ['action' => 'again', 'at' => self::NOW + 900000 + self::PAUSE]);
    }

    public function pauseHoldsTheMoveAndOneMorePauseFollowsItsLift(): void
    {
        $autopilot = self::autopilot();
        [$record] = $autopilot->tick(self::scene(1, ready: true), null, 0, self::NOW);

        [$record, $paused, $due] = $autopilot->tick(self::scene(1, ready: true, paused: true), $record, 0, self::NOW + 60000);
        [$record, $lifted, $early] = $autopilot->tick(self::scene(1, ready: true), $record, 0, self::NOW + 70000);

        Assert::same($paused, ['action' => 'scene.next', 'to' => 2, 'wait' => 'pause']);
        Assert::false($due);
        Assert::same($record->resumedAt, self::NOW + 70000);
        Assert::same($lifted, ['action' => 'scene.next', 'to' => 2, 'at' => self::NOW + 70000 + self::PAUSE]);
        Assert::false($early);
    }

    public function pauseKeepsTheWaitForTheRoomAndTheDawnDeadline(): void
    {
        $autopilot = self::autopilot();
        [$record] = $autopilot->tick(self::step(7, ready: false), null, 0, self::NOW);

        [$held, $next] = $autopilot->tick(self::step(7, ready: false, paused: true), $record, 0, self::NOW + 1000);
        [, $room] = $autopilot->tick(self::scene(2, ready: false, paused: true), null, 0, self::NOW);

        Assert::same($next, ['action' => 'again', 'wait' => 'pause']);
        Assert::same($held->dawnUntil, $record->dawnUntil);
        Assert::same($room, ['action' => 'scene.next', 'to' => 3, 'wait' => 'pause']);
    }

    public function moveIsRepeatedOnlyWhenItChangedNothingForFiveSeconds(): void
    {
        $autopilot = self::autopilot();
        $at = self::NOW + self::PAUSE;
        [$record] = $autopilot->tick(self::scene(1, ready: true), null, 0, self::NOW);
        [$record, , $first] = $autopilot->tick(self::scene(1, ready: true), $record, 0, $at);

        [, , $soon] = $autopilot->tick(self::scene(1, ready: true), $record, 0, $at + Autopilot::RETRY_MS - 1);
        [, , $later] = $autopilot->tick(self::scene(1, ready: true), $record, 0, $at + Autopilot::RETRY_MS);

        Assert::true($first);
        Assert::same($record->acted, ['key' => 'scene.next:2', 'at' => $at]);
        Assert::false($soon);
        Assert::true($later);
    }

    public function newSceneStartsTheClockOverAndKeepsThePauseLift(): void
    {
        $record = new AutoRecord('1:1:-', self::NOW - 90000, readyAt: self::NOW - 80000, acted: ['key' => 'scene.next:2', 'at' => self::NOW - 1], resumedAt: self::NOW - 2000);

        [$moved] = self::autopilot()->tick(self::scene(2, ready: false), $record, 0, self::NOW);

        Assert::equals($moved, new AutoRecord('1:2:-', self::NOW, resumedAt: self::NOW - 2000));
    }

    /**
     * @param int<0, max> $stall
     * @param int<0, max> $dice
     */
    private static function autopilot(int $stall = 0, int $dice = RitualHarness::AUTO_DICE): Autopilot
    {
        return new Autopilot(
            RitualHarness::speech(),
            self::PAUSE,
            RitualHarness::AUTO_DAWN[0],
            RitualHarness::AUTO_DAWN[1],
            $dice,
            $stall,
            new Randomizer(new Mt19937(1)),
        );
    }

    /**
     * @param int<0, 3> $scene
     */
    private static function scene(int $scene, bool $ready, bool $paused = false): AutoScene
    {
        return new AutoScene(1, $scene, 4, $scene === 0, $ready, null, $paused);
    }

    /**
     * @param int<0, 7> $step
     */
    private static function step(int $step, bool $ready, bool $paused = false, bool $dice = false): AutoScene
    {
        return new AutoScene(1, 4, 4, false, $ready, $step, $paused, $dice);
    }
}
