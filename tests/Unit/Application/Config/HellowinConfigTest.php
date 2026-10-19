<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Config;

use App\Application\Config\HellowinConfig;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Point;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HellowinConfig::class)]
final class HellowinConfigTest
{
    public function clicksPerStageDefaultsToFive(): void
    {
        Assert::same((new HellowinConfig())->getClicksPerStage(), 5);
    }

    #[DataSet([' 12 ', 12], 'env string')]
    #[DataSet([42, 42], 'integer')]
    public function acceptsAPositiveInteger(mixed $value, int $expected): void
    {
        Assert::same((new HellowinConfig(['clicks_per_stage' => $value]))->getClicksPerStage(), $expected);
    }

    #[DataSet(['0'], 'zero')]
    #[DataSet([-5], 'negative')]
    #[DataSet(['2.5'], 'fraction')]
    #[DataSet(['five'], 'word')]
    #[DataSet(['5,10,15'], 'list')]
    #[DataSet([''], 'empty string')]
    #[DataSet([null], 'missing')]
    public function rejectsInvalidClicksPerStage(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('"hellowin.clicks_per_stage" (env HELLOWIN_CLICKS_PER_STAGE) must be a positive integer');

        (new HellowinConfig(['clicks_per_stage' => $value]))->getClicksPerStage();
    }

    public function sceneStageMaxDefaultsToThreeScenes(): void
    {
        Assert::same((new HellowinConfig())->getSceneStageMax(), [4, 6, 8]);
    }

    #[DataSet([' 4, 7 ,9', [4, 7, 9]], 'env string')]
    #[DataSet(['3', [3]], 'single scene')]
    #[DataSet([[2, 5], [2, 5]], 'list')]
    public function acceptsAnIncreasingStageMaxList(mixed $value, array $expected): void
    {
        Assert::same((new HellowinConfig(['scene_stage_max' => $value]))->getSceneStageMax(), $expected);
    }

    #[DataSet(['4,4'], 'repeated')]
    #[DataSet(['6,4'], 'decreasing')]
    #[DataSet(['0,4'], 'zero')]
    #[DataSet(['4,,6'], 'gap')]
    #[DataSet(['four'], 'word')]
    #[DataSet([''], 'empty string')]
    #[DataSet([[]], 'empty list')]
    #[DataSet([null], 'missing')]
    public function rejectsInvalidStageMax(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('"hellowin.scene_stage_max" (env HELLOWIN_SCENE_STAGE_MAX) must be a strictly increasing list');

        (new HellowinConfig(['scene_stage_max' => $value]))->getSceneStageMax();
    }

    #[DataSet(['xl'], 'unknown')]
    #[DataSet([null], 'missing')]
    public function rejectsUnknownElementSize(mixed $size): never
    {
        $config = new HellowinConfig(['elements' => [
            ['id' => 'p', 'type' => 'pumpkin', 'x' => 1, 'y' => 2, 'size' => $size, 'emojis' => ['🎃']],
        ]]);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('size');

        $config->getElements();
    }

    public function bushDepthComesFromTheConfig(): void
    {
        $config = new HellowinConfig(['bushes' => [['id' => 'bush-1', 'x' => 4, 'y' => 86, 'depth' => 1]]]);

        Assert::equals($config->getBushes(3), [new Bush('bush-1', new Point(4, 86), 1)]);
    }

    public function bushWithoutDepthLaunchesTheNearestFlyers(): void
    {
        $config = new HellowinConfig(['bushes' => [['id' => 'bush-1', 'x' => 4, 'y' => 86]]]);

        Assert::same($config->getBushes(3)[0]->depth, 2);
    }

    #[DataSet([3], 'beyond the depths')]
    #[DataSet([-1], 'negative')]
    #[DataSet(['1'], 'string')]
    public function rejectsABushDepthOutsideTheFlyerDepths(mixed $depth): never
    {
        $config = new HellowinConfig(['bushes' => [['id' => 'bush-7', 'x' => 4, 'y' => 86, 'depth' => $depth]]]);

        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('bush "bush-7" has depth')
            ->withMessageContaining('HELLOWIN_FLYER_DURATION');

        $config->getBushes(3);
    }
}
