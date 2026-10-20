<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ritual;

use App\Domain\Ritual\LockstepPolicy;
use App\Domain\Ritual\Pumpkin;
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

        Assert::true($policy->sceneReady(0, [new Pumpkin('a', 4), new Pumpkin('b', 4)]));
        Assert::true($policy->sceneReady(2, [new Pumpkin('a', 8), new Pumpkin('b', 8)]));
    }

    public function sceneWaitsForTheLastPumpkin(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6, 8]);

        Assert::false($policy->sceneReady(0, [new Pumpkin('a', 4), new Pumpkin('b', 3)]));
    }

    public function nothingMovesInTheFinalScene(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6]);

        Assert::same($policy->finalScene(), 2);
        Assert::false($policy->sceneReady(2, [new Pumpkin('a', 6)]));
    }

    public function finalSceneKeepsTheLastStageMax(): void
    {
        $policy = new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6]);

        Assert::same([$policy->stageMax(0), $policy->stageMax(1), $policy->stageMax(2)], [4, 6, 6]);
    }

    public function sceneWithoutPumpkinsStays(): void
    {
        Assert::false((new LockstepPolicy(5, [4]))->sceneReady(0, []));
    }

    #[DataSet([[]], 'empty')]
    #[DataSet([[4, 4]], 'not increasing')]
    #[DataSet([[0, 2]], 'zero')]
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
        Assert::same(self::withBonfire()->bonfireStageMax($scene), $expected);
    }

    public function bonfireOpensOnceThePumpkinsAreDoneAndUntilItIsDone(): void
    {
        $policy = self::withBonfire();
        $done = [new Pumpkin('a', 6), new Pumpkin('b', 6)];

        Assert::false($policy->bonfireOpen(1, [new Pumpkin('a', 6), new Pumpkin('b', 5)], new Pumpkin('f', 0)));
        Assert::true($policy->bonfireOpen(1, $done, new Pumpkin('f', 3)));
        Assert::false($policy->bonfireOpen(1, $done, new Pumpkin('f', 4)));
        Assert::false($policy->bonfireOpen(0, [new Pumpkin('a', 4)], new Pumpkin('f', 0)));
        Assert::false($policy->bonfireOpen(3, [new Pumpkin('a', 8)], new Pumpkin('f', 4)));
    }

    public function sceneWithTheBonfireIsReadyOnceTheBonfireIsDone(): void
    {
        $policy = self::withBonfire();
        $done = [new Pumpkin('a', 6), new Pumpkin('b', 6)];

        Assert::true($policy->pumpkinsDone(1, $done));
        Assert::false($policy->sceneReady(1, $done, new Pumpkin('f', 3)));
        Assert::true($policy->sceneReady(1, $done, new Pumpkin('f', 4)));
        Assert::true($policy->sceneReady(0, [new Pumpkin('a', 4)], new Pumpkin('f', 0)));
        Assert::true($policy->sceneReady(1, $done));
    }

    #[DataSet([0, 1, 20, 1], 'threshold reached')]
    #[DataSet([0, 1, 19, null], 'below the threshold')]
    #[DataSet([4, 1, 20, null], 'last stage of the scene')]
    #[DataSet([4, 2, 25, 5], 'next scene')]
    #[DataSet([0, 0, 20, null], 'no bonfire in the scene')]
    #[DataSet([7, 3, 20, null], 'final scene')]
    public function bonfireMovesByItsOwnThreshold(int $stage, int $scene, int $clicks, ?int $expected): void
    {
        Assert::same(self::withBonfire()->bonfireTransition(new Pumpkin('f', $stage, $clicks), $scene), $expected);
    }

    public function bonfireStageMaxNeverGoesDown(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        new LockstepPolicy(5, [4, 6], 20, [0, 4, 3]);
    }

    private static function withBonfire(): LockstepPolicy
    {
        return new LockstepPolicy(clicksPerStage: 5, stageMax: [4, 6, 8], bonfireClicksPerStage: 20, bonfireStageMax: [0, 4, 7]);
    }
}
