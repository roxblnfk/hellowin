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
}
