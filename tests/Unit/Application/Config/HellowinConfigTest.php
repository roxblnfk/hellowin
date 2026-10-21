<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Config;

use App\Application\Config\HellowinConfig;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Point;
use App\Domain\RateLimit\TokenBucket;
use App\Domain\Walk\WalkZone;
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

    #[DataSet([null, null], 'default')]
    #[DataSet(['  ', null], 'blank')]
    #[DataSet([' owl ', 'owl'], 'trimmed')]
    public function moderatorSecretIsOptional(?string $value, ?string $expected): void
    {
        $config = $value === null ? new HellowinConfig() : new HellowinConfig(['moderator_secret' => $value]);

        Assert::same($config->getModeratorSecret(), $expected);
    }

    public function sceneStageMaxDefaultsToTheBarAndThreeScenes(): void
    {
        Assert::same((new HellowinConfig())->getSceneStageMax(), [0, 4, 8, 11]);
    }

    #[DataSet([' 4, 7 ,9', [4, 7, 9]], 'env string')]
    #[DataSet(['0,4', [0, 4]], 'bar first')]
    #[DataSet(['3', [3]], 'single scene')]
    #[DataSet([[2, 5], [2, 5]], 'list')]
    public function acceptsAnIncreasingStageMaxList(mixed $value, array $expected): void
    {
        Assert::same((new HellowinConfig(['scene_stage_max' => $value]))->getSceneStageMax(), $expected);
    }

    #[DataSet(['4,4'], 'repeated')]
    #[DataSet(['6,4'], 'decreasing')]
    #[DataSet(['4,0'], 'zero after the first')]
    #[DataSet(['-1,4'], 'negative')]
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

    public function bonfireHasNoSize(): void
    {
        $config = new HellowinConfig(['elements' => [
            ['id' => 'bonfire', 'type' => 'bonfire', 'x' => 50, 'y' => 63, 'emojis' => ['🔥']],
        ]]);

        Assert::same($config->getElements()[0]->toArray(), ['id' => 'bonfire', 'type' => 'bonfire', 'x' => 50.0, 'y' => 63.0]);
    }

    public function pumpkinsWithinReachOfTheLawnAreReachable(): void
    {
        $config = new HellowinConfig(['elements' => [
            // 2.5 below the top edge of the patch, which touches the lawn there; then 12 inside it
            ['id' => 'edge', 'type' => 'pumpkin', 'x' => 50, 'y' => 40.5, 'size' => 's', 'emojis' => ['🎃']],
            ['id' => 'deep', 'type' => 'pumpkin', 'x' => 50, 'y' => 76, 'size' => 's', 'emojis' => ['🎃']],
        ], 'walk' => [
            'area' => ['x1' => 3, 'y1' => 30, 'x2' => 97, 'y2' => 97],
            'patch' => ['cx' => 50, 'cy' => 63, 'rx' => 31, 'ry' => 25],
            'reach' => 3,
        ]]);

        Assert::same(\array_map(static fn($element): bool => $element->reachable, $config->getElements()), [true, false]);
        Assert::true($config->getElements()[0]->toArray()['reachable']);
        Assert::same([$config->getClinkGap(), $config->getClinkDuration()], [4.0, 3200]);
    }

    public function rejectsAnUnknownElementType(): never
    {
        $config = new HellowinConfig(['elements' => [
            ['id' => 'p', 'type' => 'ghost', 'x' => 1, 'y' => 2, 'size' => 's', 'emojis' => ['🎃']],
        ]]);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('type');

        $config->getElements();
    }

    public function bonfireDefaultsLeaveItOutOfTheFirstScene(): void
    {
        $config = new HellowinConfig();

        Assert::same($config->getBonfireStageMax(), [0, 0, 4, 7]);
        Assert::same($config->getBonfireClicksPerStage(), 20);
        Assert::same((new HellowinConfig(['bonfire_stage_max' => ' 0, 0 ,3']))->getBonfireStageMax(), [0, 0, 3]);
        Assert::same((new HellowinConfig(['bonfire_clicks_per_stage' => '40']))->getBonfireClicksPerStage(), 40);
    }

    public function circleHasNoSizeAndItsOwnNumbers(): void
    {
        $config = new HellowinConfig(['elements' => [
            ['id' => 'circle', 'type' => 'circle', 'x' => 50, 'y' => 63, 'size' => 'l', 'emojis' => ['🩸']],
        ]]);

        Assert::same($config->getElements()[0]->toArray(), ['id' => 'circle', 'type' => 'circle', 'x' => 50.0, 'y' => 63.0]);
        Assert::same([$config->getCircleClicksPerStage(), $config->getCircleStages()], [20, 4]);
        Assert::same((new HellowinConfig(['circle_stages' => ' 6 ']))->getCircleStages(), 6);
    }

    #[DataSet(['circle_stages', 0, 'HELLOWIN_CIRCLE_STAGES'])]
    #[DataSet(['circle_clicks_per_stage', 'x', 'HELLOWIN_CIRCLE_CLICKS_PER_STAGE'])]
    public function rejectsInvalidCircleNumbers(string $key, mixed $value, string $env): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($env);

        $config = new HellowinConfig([$key => $value]);
        $config->getCircleStages();
        $config->getCircleClicksPerStage();
    }

    #[DataSet(['0,4,3'], 'decreasing')]
    #[DataSet(['-1,2'], 'negative')]
    #[DataSet([''], 'empty')]
    #[DataSet(['0,x'], 'not a number')]
    public function rejectsInvalidBonfireStageMax(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_BONFIRE_STAGE_MAX');

        (new HellowinConfig(['bonfire_stage_max' => $value]))->getBonfireStageMax();
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

    public function walkZoneComesFromTheConfig(): void
    {
        $config = new HellowinConfig(['walk' => [
            'area' => ['x1' => 1, 'y1' => 2, 'x2' => 99, 'y2' => 98],
            'patch' => ['cx' => 50, 'cy' => 60, 'rx' => 30, 'ry' => 20.5],
        ]]);

        Assert::equals($config->getWalkZone(), new WalkZone(1, 2, 99, 98, 50, 60, 30, 20.5));
    }

    #[DataSet([' 7.5 ', 7.5], 'env string')]
    #[DataSet([12, 12.0], 'integer')]
    public function acceptsAPositiveWalkSpeed(mixed $value, float $expected): void
    {
        Assert::same((new HellowinConfig(['walk' => ['speed' => $value]]))->getWalkSpeed(), $expected);
    }

    #[DataSet(['0'], 'zero')]
    #[DataSet([-3], 'negative')]
    #[DataSet(['fast'], 'word')]
    public function rejectsAnInvalidWalkSpeed(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_WALK_SPEED');

        (new HellowinConfig(['walk' => ['speed' => $value]]))->getWalkSpeed();
    }

    public function walkDefaultsMatchTheSpec(): void
    {
        $config = new HellowinConfig();

        Assert::same($config->getWalkSpeed(), 12.0);
        Assert::same($config->getWalkLeaveAfter(), 5000);
        Assert::same($config->getWalkSpawn(), [86.0, 90.0]);
    }

    public function itemsGoByScene(): void
    {
        $items = (new HellowinConfig(['items' => [null, 'torch']]))->getItems();

        Assert::same([$items->held(0), $items->held(1), $items->held(2)], [null, 'torch', null]);
    }

    public function finaleItemsGoFromTheirStepOn(): void
    {
        $items = (new HellowinConfig(['items' => [null, 'torch'], 'finale_items' => [3 => 'beer']]))->getItems();

        Assert::same([$items->held(1, 2), $items->held(1, 3), $items->held(1, 5)], ['torch', 'beer', 'beer']);
    }

    public function nullFinaleItemEmptiesTheHands(): void
    {
        $items = (new HellowinConfig(['items' => [null, 'torch'], 'finale_items' => [0 => 'flame', 1 => null]]))->getItems();

        Assert::same([$items->held(1, 0), $items->held(1, 1), $items->held(1, 3)], ['flame', null, null]);
    }

    #[DataSet([['five' => 'beer']], 'not by step')]
    #[DataSet([[-1 => 'beer']], 'negative step')]
    #[DataSet([[5 => 'Beer']], 'uppercase')]
    #[DataSet(['beer'], 'not an array')]
    public function rejectsInvalidFinaleItems(mixed $items): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('hellowin.finale_items');

        (new HellowinConfig(['finale_items' => $items]))->getItems();
    }

    #[DataSet([['Torch']], 'uppercase')]
    #[DataSet([[42]], 'number')]
    #[DataSet(['torch'], 'not a list')]
    public function rejectsInvalidItems(mixed $items): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('hellowin.items');

        (new HellowinConfig(['items' => $items]))->getItems();
    }

    public function rpcRatesDefaultToEveryGroup(): void
    {
        Assert::equals((new HellowinConfig())->getRpcRates(), [
            'click' => new TokenBucket(20.0, 40),
            'shoot' => new TokenBucket(20.0, 40),
            'move' => new TokenBucket(10.0, 20),
            'other' => new TokenBucket(10.0, 30),
        ]);
    }

    #[DataSet([' 2.5 , 5 ', 2.5, 5], 'fraction with spaces')]
    #[DataSet(['15,15', 15.0, 15], 'integers')]
    public function rpcRateIsPerSecondAndBurst(string $value, float $rate, int $burst): void
    {
        Assert::equals(
            (new HellowinConfig(['rpc_rate' => ['click' => $value]]))->getRpcRates(),
            ['click' => new TokenBucket($rate, $burst)],
        );
    }

    #[DataSet(['0'], 'zero string')]
    #[DataSet([0], 'zero')]
    #[DataSet([''], 'empty')]
    #[DataSet([false], 'false')]
    public function rpcRateOffLeavesTheGroupOut(mixed $value): void
    {
        Assert::equals(
            (new HellowinConfig(['rpc_rate' => ['click' => $value, 'move' => '1,1']]))->getRpcRates(),
            ['move' => new TokenBucket(1.0, 1)],
        );
    }

    #[DataSet(['20'], 'no burst')]
    #[DataSet(['20,0'], 'zero burst')]
    #[DataSet(['0,5'], 'zero rate')]
    #[DataSet(['fast'], 'word')]
    public function rejectsInvalidRpcRate(string $value): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('"hellowin.rpc_rate.click" (env HELLOWIN_RPC_RATE_CLICK) must be "<per second>,<burst>"');

        (new HellowinConfig(['rpc_rate' => ['click' => $value]]))->getRpcRates();
    }
}
