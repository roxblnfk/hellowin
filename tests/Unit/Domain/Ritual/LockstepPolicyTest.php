<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ritual;

use App\Domain\Element\Element;
use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\Pumpkin;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(LockstepPolicy::class)]
final class LockstepPolicyTest
{
    #[DataSet([0, 0, 5, 1], 'threshold reached')]
    #[DataSet([0, 0, 9, 1], 'clicks over the threshold')]
    #[DataSet([3, 0, 5, 4], 'last stage of the scene')]
    #[DataSet([4, 1, 5, 5], 'next scene')]
    #[DataSet([0, 0, 4, null], 'below the threshold')]
    #[DataSet([4, 0, 9, null], 'waits for the scene')]
    #[DataSet([8, 3, 9, null], 'final scene')]
    public function pumpkinMovesUpToTheLastStageOfTheScene(int $stage, int $scene, int $clicks, ?int $expected): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6, 8]);

        Assert::same($policy->pumpkinTransition(new Pumpkin('p', $stage, $clicks), $scene), $expected);
    }

    public function sceneIsReadyWhenEveryPumpkinReachedItsLastStage(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6, 8]);

        Assert::true($policy->sceneReady(self::ritual(0, [new Pumpkin('a', 4), new Pumpkin('b', 4)])));
        Assert::true($policy->sceneReady(self::ritual(2, [new Pumpkin('a', 8), new Pumpkin('b', 8)])));
    }

    public function sceneWaitsForTheLastPumpkin(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6, 8]);

        Assert::false($policy->sceneReady(self::ritual(0, [new Pumpkin('a', 4), new Pumpkin('b', 3)])));
    }

    public function finalSceneIsNotReadyWithoutTheCircle(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6]);

        Assert::same($policy->finalScene(), 2);
        Assert::false($policy->sceneReady(self::ritual(2, [new Pumpkin('a', 6)], story: new Story(finale: 0))));
    }

    public function finalSceneKeepsTheLastStageMax(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6]);

        Assert::same([$policy->stageMax(0), $policy->stageMax(1), $policy->stageMax(2)], [4, 6, 6]);
    }

    public function leadingZeroMakesSceneZeroTheBarWithoutPumpkinsToFinish(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 1, stageMax: [0, 4], bonfireStageMax: [0, 0, 3]);

        Assert::true($policy->bar(0));
        Assert::false($policy->bar(1));
        Assert::same($policy->finalScene(), 2);
        // The bar gets ready by its drinks, not by the pumpkins
        Assert::false($policy->sceneReady(self::ritual(0, [new Pumpkin('a', 0, 9)], bonfire: new Pumpkin('bonfire'))));
        Assert::null($policy->pumpkinTransition(new Pumpkin('a', 0, 9), 0));
        Assert::false($policy->centrepieceOpen(Element::TYPE_BONFIRE, self::ritual(0, [new Pumpkin('a')], bonfire: new Pumpkin('bonfire'))));
        Assert::same($policy->pumpkinTransition(new Pumpkin('a', 0, 1), 1), 1);
    }

    public function withoutALeadingZeroThereIsNoBar(): void
    {
        Assert::false((new LockstepPolicy(5, [4, 6]))->bar(0));
    }

    public function sceneWithoutPumpkinsStays(): void
    {
        Assert::false((new LockstepPolicy(5, [4]))->sceneReady(self::ritual(0, [])));
    }

    #[DataSet([[]], 'empty')]
    #[DataSet([[4, 4]], 'not increasing')]
    #[DataSet([[2, 0]], 'zero after the first')]
    #[DataSet([[-1, 2]], 'negative')]
    public function rejectsInvalidStageMax(array $stageMax): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new LockstepPolicy(5, $stageMax);
    }

    #[DataSet([0, 0], 'no bonfire in scene 0')]
    #[DataSet([1, 4], 'scene 1')]
    #[DataSet([2, 7], 'scene 2')]
    #[DataSet([3, 7], 'final scene keeps the one before it')]
    #[DataSet([9, 7], 'past the list')]
    public function bonfireStageMaxGoesByScene(int $scene, int $expected): void
    {
        Assert::same(self::policy()->stageMax($scene, Element::TYPE_BONFIRE), $expected);
    }

    public function bonfireOpensOnceThePumpkinsAreDoneAndUntilItIsDone(): void
    {
        $policy = self::policy();
        $done = [new Pumpkin('a', 6), new Pumpkin('b', 6)];
        $open = static fn(int $scene, array $pumpkins, Pumpkin $bonfire): bool
            => $policy->centrepieceOpen(Element::TYPE_BONFIRE, self::ritual($scene, $pumpkins, bonfire: $bonfire));

        Assert::false($open(1, [new Pumpkin('a', 6), new Pumpkin('b', 5)], new Pumpkin('f', 0)));
        Assert::true($open(1, $done, new Pumpkin('f', 3)));
        Assert::false($open(1, $done, new Pumpkin('f', 4)));
        Assert::false($open(0, [new Pumpkin('a', 4)], new Pumpkin('f', 0)));
        Assert::false($open(3, [new Pumpkin('a', 8)], new Pumpkin('f', 4)));
        Assert::false($policy->centrepieceOpen(Element::TYPE_BONFIRE, self::ritual(1, $done)));
    }

    public function sceneWithTheBonfireIsReadyOnceTheBonfireIsDone(): void
    {
        $policy = self::policy();
        $done = [new Pumpkin('a', 6), new Pumpkin('b', 6)];

        Assert::false($policy->sceneReady(self::ritual(1, $done, bonfire: new Pumpkin('f', 3))));
        Assert::true($policy->sceneReady(self::ritual(1, $done, bonfire: new Pumpkin('f', 4))));
        Assert::true($policy->sceneReady(self::ritual(0, [new Pumpkin('a', 4)], bonfire: new Pumpkin('f', 0))));
        Assert::true($policy->sceneReady(self::ritual(1, $done)));
    }

    #[DataSet([0, 1, 20, 1], 'threshold reached')]
    #[DataSet([0, 1, 19, null], 'below the threshold')]
    #[DataSet([4, 1, 20, null], 'last stage of the scene')]
    #[DataSet([4, 2, 25, 5], 'next scene')]
    #[DataSet([0, 0, 20, null], 'no bonfire in the scene')]
    #[DataSet([3, 3, 20, null], 'final scene')]
    public function bonfireMovesByItsOwnThreshold(int $stage, int $scene, int $clicks, ?int $expected): void
    {
        Assert::same(
            self::policy()->centrepieceTransition(Element::TYPE_BONFIRE, new Pumpkin('f', $stage, $clicks), $scene),
            $expected,
        );
    }

    public function bonfireStageMaxNeverGoesDown(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new LockstepPolicy(5, [4, 6], 20, [0, 4, 3]);
    }

    #[DataSet([0, 0], 'scene 0')]
    #[DataSet([2, 0], 'scene before the final one')]
    #[DataSet([3, 4], 'final scene')]
    public function circleHasStagesInTheFinalSceneOnly(int $scene, int $expected): void
    {
        Assert::same(self::policy()->stageMax($scene, Element::TYPE_CIRCLE), $expected);
    }

    #[DataSet([0, 3, 15, 1], 'threshold reached')]
    #[DataSet([0, 3, 14, null], 'below the threshold')]
    #[DataSet([3, 3, 15, 4], 'closes')]
    #[DataSet([4, 3, 30, null], 'closed')]
    #[DataSet([0, 2, 15, null], 'before the final scene')]
    public function circleMovesByItsOwnThresholdInTheFinalScene(int $stage, int $scene, int $clicks, ?int $expected): void
    {
        Assert::same(
            self::policy()->centrepieceTransition(Element::TYPE_CIRCLE, new Pumpkin('c', $stage, $clicks), $scene),
            $expected,
        );
    }

    #[DataSet([3, 0, 0, true], 'start of the finale')]
    #[DataSet([3, 0, 3, true], 'half drawn')]
    #[DataSet([3, 0, 4, false], 'closed')]
    #[DataSet([3, 1, 0, false], 'after the first step')]
    #[DataSet([2, null, 0, false], 'before the final scene')]
    public function circleIsOpenAtTheStartOfTheFinale(int $scene, ?int $step, int $stage, bool $expected): void
    {
        $ritual = self::ritual($scene, [new Pumpkin('a', 8)], new Story(finale: $step), circle: new Pumpkin('c', $stage));

        Assert::same(self::policy()->centrepieceOpen(Element::TYPE_CIRCLE, $ritual), $expected);
    }

    public function closedCircleMakesTheFinalSceneReady(): void
    {
        $policy = self::policy();
        $ritual = static fn(int $stage, int $step): RitualSnapshot
            => self::ritual(3, [new Pumpkin('a', 8)], new Story(finale: $step), circle: new Pumpkin('c', $stage));

        Assert::false($policy->sceneReady($ritual(3, 0)));
        Assert::true($policy->sceneReady($ritual(4, 0)));
        Assert::false($policy->sceneReady($ritual(4, 1)));
    }

    public function everyTypeHasItsThreshold(): void
    {
        $policy = self::policy();

        Assert::same(
            [$policy->threshold(), $policy->threshold(Element::TYPE_BONFIRE), $policy->threshold(Element::TYPE_CIRCLE)],
            [5, 20, 15],
        );
    }

    #[DataSet([1.0, [5, 20, 15]], 'as configured')]
    #[DataSet([0.5, [3, 10, 8]], 'halves round half up')]
    #[DataSet([0.1, [1, 2, 2]], 'never below one click')]
    #[DataSet([1.25, [6, 25, 19]], 'one step up')]
    #[DataSet([10.0, [50, 200, 150]], 'the most')]
    public function multiplierScalesEveryThreshold(float $k, array $expected): void
    {
        $policy = self::policy();

        Assert::same(
            [$policy->threshold(k: $k), $policy->threshold(Element::TYPE_BONFIRE, $k), $policy->threshold(Element::TYPE_CIRCLE, $k)],
            $expected,
        );
    }

    public function lowerThresholdLetsTheGatheredClicksMove(): void
    {
        $policy = self::policy();
        $pumpkin = new Pumpkin('p', 0, 3);

        Assert::null($policy->pumpkinTransition($pumpkin, 0));
        Assert::same($policy->pumpkinTransition($pumpkin, 0, 0.5), 1);
        Assert::same($policy->centrepieceTransition(Element::TYPE_BONFIRE, new Pumpkin('f', 0, 10), 1, 0.5), 1);
        Assert::null($policy->centrepieceTransition(Element::TYPE_BONFIRE, new Pumpkin('f', 0, 10), 1, 0.6));
    }

    private static function policy(): LockstepPolicy
    {
        return new LockstepPolicy(
            clicksPerStage: 5,
            stageMax: [4, 6, 8],
            bonfireClicksPerStage: 20,
            bonfireStageMax: [0, 4, 7],
            circleClicksPerStage: 15,
            circleStages: 4,
        );
    }

    /**
     * @param list<Pumpkin> $pumpkins
     */
    private static function ritual(
        int $scene,
        array $pumpkins,
        Story $story = new Story(),
        ?Pumpkin $bonfire = null,
        ?Pumpkin $circle = null,
    ): RitualSnapshot {
        return new RitualSnapshot(1, $scene, $pumpkins, $story, $bonfire, $circle);
    }
}
