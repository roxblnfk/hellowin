<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Config;

use App\Application\Config\HellowinConfig;
use App\Domain\Bar\Perch;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Point;
use App\Domain\RateLimit\TokenBucket;
use App\Domain\Walk\Box;
use App\Domain\Walk\Ellipse;
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

        Assert::equals($config->getWalkZone(), WalkZone::lawn(1, 2, 99, 98, 50, 60, 30, 20.5));
    }

    public function barLayoutTellsTablesFromTheCounter(): void
    {
        $config = new HellowinConfig(['walk' => ['bar' => [
            'area' => ['x1' => 0, 'y1' => 50, 'x2' => 100, 'y2' => 100],
            'obstacles' => [['x1' => 60, 'y1' => 40, 'x2' => 100, 'y2' => 60], ['cx' => 20, 'cy' => 70, 'rx' => 8, 'ry' => 4]],
            'counter' => ['x1' => 62, 'x2' => 98, 'y' => 62],
            'spawn' => ['y1' => 90, 'y2' => 95],
            'clink_reach' => 9,
        ]]]);

        $layout = $config->getBarLayout();

        Assert::equals($layout?->zone, new WalkZone(0, 50, 100, 100, new Box(60, 40, 100, 60), new Ellipse(20, 70, 8, 4)));
        Assert::same($layout->counter, [62.0, 98.0, 62.0]);
        Assert::same($layout->spawnY, [90.0, 95.0]);
        Assert::same($layout->clinkReach, 9.0);
    }

    public function barPerchesComeFromTheConfig(): void
    {
        $config = new HellowinConfig(['walk' => ['bar' => self::bar(['perches' => [
            ['x1' => 3, 'x2' => 97, 'y' => 8, 'name' => 'beam'],
            ['x1' => 25, 'x2' => 25, 'y' => 33.5, 'name' => 'shield-1'],
        ]])]]);

        $layout = $config->getBarLayout();

        Assert::equals($layout?->perches, [new Perch(3, 97, 8, 'beam'), new Perch(25, 25, 33.5, 'shield-1')]);
        Assert::same($layout->toArray()['perches'][1], ['x1' => 25.0, 'x2' => 25.0, 'y' => 33.5, 'name' => 'shield-1']);
        Assert::same((new HellowinConfig(['walk' => ['bar' => self::bar()]]))->getBarLayout()?->perches, []);
    }

    public function barDoorComesFromTheConfig(): void
    {
        $layout = (new HellowinConfig(['walk' => ['bar' => self::bar(['door' => ['x' => 12, 'y' => 50], 'door_in' => 5])]]))->getBarLayout();

        Assert::equals([$layout?->door, $layout->doorIn], [new Point(12, 50), 5.0]);
        Assert::equals($layout->doorway(), [new Point(12, 50), new Point(12, 55)]);
        Assert::same([$layout->toArray()['door'], $layout->toArray()['door_in']], [['x' => 12.0, 'y' => 50.0], 5.0]);

        $roadrunner = (new HellowinConfig(['walk' => ['bar' => self::bar(['roadrunner' => ['x' => 74, 'y' => 46]])]]))->getBarLayout();
        Assert::equals($roadrunner?->roadrunner, new Point(74, 46));
        Assert::same($roadrunner->toArray()['roadrunner'], ['x' => 74.0, 'y' => 46.0]);

        $none = (new HellowinConfig(['walk' => ['bar' => self::bar()]]))->getBarLayout();
        Assert::null($none?->roadrunner);
        Assert::same([$none?->door, $none->doorIn, $none->doorway()], [null, 6.0, null]);
        Assert::false(\array_key_exists('door', $none->toArray()));
    }

    public function barDoorNeedsAPoint(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('walk.bar.door');

        (new HellowinConfig(['walk' => ['bar' => self::bar(['door' => ['x' => 12]])]]))->getBarLayout();
    }

    #[DataSet([[['x1' => 5, 'x2' => 4, 'y' => 8, 'name' => 'beam']]], 'x1 past x2')]
    #[DataSet([[['x1' => 3, 'x2' => 4, 'y' => '8', 'name' => 'beam']]], 'y not a number')]
    #[DataSet([[['x1' => 3, 'x2' => 4, 'y' => 8]]], 'no name')]
    #[DataSet([[['x1' => 3, 'x2' => 4, 'y' => 8, 'name' => 'a'], ['x1' => 5, 'x2' => 6, 'y' => 9, 'name' => 'a']]], 'same name twice')]
    #[DataSet(['beam'], 'not a list')]
    public function rejectsInvalidPerches(mixed $perches): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('walk.bar.perches');

        (new HellowinConfig(['walk' => ['bar' => self::bar(['perches' => $perches])]]))->getBarLayout();
    }

    public function rapierNumbersComeFromTheConfig(): void
    {
        $config = new HellowinConfig();
        Assert::same([$config->getCursedPerPlayer(), $config->getBossHpBase(), $config->getBossHpPerPlayer()], [5, 40, 5]);

        $config = new HellowinConfig(['rapier' => ['cursed_per_player' => '3', 'boss_hp_base' => '100', 'boss_hp_per_player' => '0']]);
        Assert::same([$config->getCursedPerPlayer(), $config->getBossHpBase(), $config->getBossHpPerPlayer()], [3, 100, 0]);
    }

    #[DataSet(['cursed_per_player', '0', 'HELLOWIN_CURSED_PER_PLAYER'])]
    #[DataSet(['boss_hp_base', 'many', 'HELLOWIN_BOSS_HP_BASE'])]
    #[DataSet(['boss_hp_per_player', '-1', 'HELLOWIN_BOSS_HP_PER_PLAYER'])]
    public function rejectsInvalidRapierNumbers(string $key, string $value, string $env): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($env);

        $config = new HellowinConfig(['rapier' => [$key => $value] + ['cursed_per_player' => 2, 'boss_hp_base' => 40, 'boss_hp_per_player' => 5]]);
        $config->getCursedPerPlayer();
        $config->getBossHpBase();
        $config->getBossHpPerPlayer();
    }

    public function dawnBoardComesFromTheConfig(): void
    {
        Assert::same((new HellowinConfig())->getDawnBoard(), 200);
        Assert::same((new HellowinConfig(['rapier' => ['dawn_board' => '10']]))->getDawnBoard(), 10);
    }

    public function animationLengthsComeFromTheConfig(): void
    {
        $config = new HellowinConfig();
        Assert::same([$config->getDrinkMs(), $config->getBeerHandoverMs()], [1200, 900]);

        $config = new HellowinConfig(['bar' => ['drink_ms' => '1500'], 'rapier' => ['beer_handover_ms' => '600']]);
        Assert::same([$config->getDrinkMs(), $config->getBeerHandoverMs()], [1500, 600]);
    }

    public function rejectsAnEmptySip(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_DRINK_MS');

        (new HellowinConfig(['bar' => ['drink_ms' => '0']]))->getDrinkMs();
    }

    public function rejectsAnEmptyDawnBoard(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_DAWN_BOARD');

        (new HellowinConfig(['rapier' => ['dawn_board' => '0']]))->getDawnBoard();
    }

    public function diceSecretIsConfiguredOrDerivedFromTheEncryptionKey(): void
    {
        Assert::null((new HellowinConfig())->getDiceSecret());
        Assert::same((new HellowinConfig(['dice' => ['secret' => 'mine', 'encrypter_key' => 'key']]))->getDiceSecret(), 'mine');

        $derived = (new HellowinConfig(['dice' => ['secret' => '', 'encrypter_key' => 'key']]))->getDiceSecret();

        Assert::same($derived, \hash_hmac('sha256', 'hellowin:dice', 'key'));
        Assert::notSame($derived, 'key');
    }

    public function diceCooldownComesFromTheConfig(): void
    {
        Assert::same((new HellowinConfig())->getDiceCooldown(), 1800);
        Assert::same((new HellowinConfig(['dice' => ['cooldown_ms' => '0']]))->getDiceCooldown(), 0);
    }

    public function rejectsAnInvalidDiceCooldown(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_DICE_COOLDOWN_MS');

        (new HellowinConfig(['dice' => ['cooldown_ms' => '-1']]))->getDiceCooldown();
    }

    public function barNumbersComeFromTheConfig(): void
    {
        $config = new HellowinConfig();
        Assert::same([$config->getBarMugs(), $config->getBarPourMs(), $config->getBarDrinks(), $config->getBarRumorChance()], [12, 2500, 30, 0.33]);

        $config = new HellowinConfig(['bar' => ['mugs' => '3', 'pour_ms' => '1000', 'drinks' => '7', 'rumor_chance' => '0']]);
        Assert::same([$config->getBarMugs(), $config->getBarPourMs(), $config->getBarDrinks(), $config->getBarRumorChance()], [3, 1000, 7, 0.0]);
    }

    public function barRumorChanceIsAProbability(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_BAR_RUMOR_CHANCE');

        (new HellowinConfig(['bar' => ['rumor_chance' => '1.5']]))->getBarRumorChance();
    }

    public function withoutABarLayoutTheBarIsTheLawn(): void
    {
        Assert::null((new HellowinConfig())->getBarLayout());
    }

    public function obstacleOfNeitherShapeIsAConfigError(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('walk.bar.obstacles');

        (new HellowinConfig(['walk' => ['bar' => [
            'area' => ['x1' => 0, 'y1' => 50, 'x2' => 100, 'y2' => 100],
            'obstacles' => [['x' => 1]],
            'counter' => ['x1' => 62, 'x2' => 98, 'y' => 62],
            'spawn' => ['y1' => 90, 'y2' => 95],
        ]]]))->getBarLayout();
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

    /**
     * A valid `walk.bar` with the given keys replaced.
     */
    private static function bar(array $with = []): array
    {
        return [
            'area' => ['x1' => 0, 'y1' => 50, 'x2' => 100, 'y2' => 100],
            'obstacles' => [['x1' => 60, 'y1' => 40, 'x2' => 100, 'y2' => 60]],
            'counter' => ['x1' => 62, 'x2' => 98, 'y' => 62],
            'spawn' => ['y1' => 90, 'y2' => 95],
            ...$with,
        ];
    }
}
