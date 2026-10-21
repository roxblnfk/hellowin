<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Config;

use App\Application\Config\FlyerConfig;
use App\Domain\Flyer\FlyerDepth;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FlyerConfig::class)]
final class FlyerConfigTest
{
    public function defaultsMakeFartherFlyersSlowerAndDearer(): void
    {
        $config = new FlyerConfig();

        Assert::equals($config->getDepths(), [new FlyerDepth(16000, 3), new FlyerDepth(6500, 2), new FlyerDepth(4000, 1)]);
        Assert::same($config->getInterval(), [3000, 8000]);
        Assert::same($config->getBushChance(), 0.4);
        Assert::same($config->getArcChance(), 0.5);
    }

    #[DataSet(['500-900', [500, 900]], 'range')]
    #[DataSet([' 700 ', [700, 700]], 'fixed')]
    #[DataSet([1500, [1500, 1500]], 'integer')]
    public function intervalAcceptsARangeOrANumber(mixed $value, array $expected): void
    {
        Assert::same((new FlyerConfig(['interval' => $value]))->getInterval(), $expected);
    }

    #[DataSet(['900-500'], 'reversed')]
    #[DataSet(['0'], 'zero')]
    #[DataSet(['fast'], 'word')]
    public function intervalRejectsGarbage(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_INTERVAL');

        (new FlyerConfig(['interval' => $value]))->getInterval();
    }

    public function depthListsMustMatch(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('same number of depths');

        (new FlyerConfig(['duration' => '9000,4000', 'points' => '3,2,1']))->getDepths();
    }

    #[DataSet(['1.5'], 'above one')]
    #[DataSet(['-0.1'], 'negative')]
    #[DataSet(['often'], 'word')]
    public function chanceIsAProbability(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_ARC_CHANCE');

        (new FlyerConfig(['arc_chance' => $value]))->getArcChance();
    }

    public function kindsComeByScene(): void
    {
        Assert::same((new FlyerConfig())->getKinds(), [['fly'], ['duck', 'chicken'], [], ['bat'], ['worm']]);
        Assert::same((new FlyerConfig(['kinds' => ' duck , duck,bat']))->getKinds(), [['duck', 'bat']]);
        Assert::same((new FlyerConfig(['kinds' => 'duck; bat ']))->getKinds(), [['duck'], ['bat']]);
        Assert::same((new FlyerConfig(['kinds' => 'duck; ;bat']))->getKinds(), [['duck'], [], ['bat']]);
        Assert::same((new FlyerConfig(['kinds' => '']))->getKinds(), [[]]);
    }

    #[DataSet(['duck,'], 'trailing comma')]
    #[DataSet(['duck,,bat'], 'empty kind')]
    #[DataSet(['Duck'], 'uppercase')]
    #[DataSet([['duck']], 'array')]
    public function kindsRejectGarbage(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_KINDS');

        (new FlyerConfig(['kinds' => $value]))->getKinds();
    }

    public function cursedWorldHasOneSetOfItsOwn(): void
    {
        Assert::same((new FlyerConfig())->getCursedKinds(), ['worm', 'bat', 'firefly']);
        Assert::same((new FlyerConfig(['kinds_cursed' => ' bat , bat,firefly']))->getCursedKinds(), ['bat', 'firefly']);
        Assert::same((new FlyerConfig(['kinds_cursed' => ' ']))->getCursedKinds(), []);
    }

    #[DataSet(['bat;worm'], 'sets by scene')]
    #[DataSet(['bat,'], 'trailing comma')]
    #[DataSet(['Bat'], 'uppercase')]
    #[DataSet([['bat']], 'array')]
    public function cursedKindsRejectGarbage(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_KINDS_CURSED');

        (new FlyerConfig(['kinds_cursed' => $value]))->getCursedKinds();
    }

    public function fireflyPointsArePositive(): never
    {
        Assert::same((new FlyerConfig())->getFireflyPoints(), 1);
        Assert::same((new FlyerConfig(['firefly_points' => '3']))->getFireflyPoints(), 3);

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_FIREFLY_POINTS');
        (new FlyerConfig(['firefly_points' => 0]))->getFireflyPoints();
    }

    public function fliesHaveTheirOwnLandingsTimingAndPoints(): void
    {
        $defaults = new FlyerConfig();
        Assert::same(
            [$defaults->getFlyStops(), $defaults->getFlySit(), $defaults->getFlyLeg(), $defaults->getFlyPoints()],
            [[2, 3], [900, 1600], 900, 1],
        );
        $set = new FlyerConfig(['fly_stops' => '4', 'fly_sit' => '500-700', 'fly_leg' => '600', 'fly_points' => '2']);
        Assert::same([$set->getFlyStops(), $set->getFlySit(), $set->getFlyLeg(), $set->getFlyPoints()], [[4, 4], [500, 700], 600, 2]);
    }

    #[DataSet(['fly_stops', '3-2', 'HELLOWIN_FLYER_FLY_STOPS'], 'stops backwards')]
    #[DataSet(['fly_stops', '0', 'HELLOWIN_FLYER_FLY_STOPS'], 'no stops')]
    #[DataSet(['fly_sit', 'long', 'HELLOWIN_FLYER_FLY_SIT'], 'sit garbage')]
    #[DataSet(['fly_leg', 0, 'HELLOWIN_FLYER_FLY_LEG'], 'zero leg')]
    public function flyTimingRejectsGarbage(string $key, mixed $value, string $env): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($env);

        $config = new FlyerConfig([$key => $value]);
        $config->getFlyStops();
        $config->getFlySit();
        $config->getFlyLeg();
    }

    public function flyPointsArePositive(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_FLY_POINTS');
        (new FlyerConfig(['fly_points' => 'many']))->getFlyPoints();
    }

    public function rareFlyersComeByScene(): void
    {
        $config = new FlyerConfig();

        Assert::same($config->getRare(), [null, 'roadrunner', null, null, null]);
        Assert::same($config->getRareChance(), 0.08);
        Assert::same($config->getRarePoints(), 10);
        Assert::same((new FlyerConfig(['rare' => '']))->getRare(), [null]);
        Assert::same((new FlyerConfig(['rare_points' => '7']))->getRarePoints(), 7);
    }

    public function zombiesHaveTheirOwnPace(): void
    {
        $config = new FlyerConfig(['zombie_interval' => '1000-2000', 'zombie_duration' => '9000', 'zombie_points' => 3]);

        Assert::same((new FlyerConfig())->getZombieInterval(), [4000, 9000]);
        Assert::same($config->getZombieInterval(), [1000, 2000]);
        Assert::same($config->getZombieDuration(), 9000);
        Assert::same($config->getZombiePoints(), 3);
    }

    #[DataSet(['witch,bat'], 'two kinds')]
    #[DataSet(['Witch'], 'uppercase')]
    public function rareFlyerIsOneKind(string $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_RARE');

        (new FlyerConfig(['rare' => $value]))->getRare();
    }

    public function chanceFromEnvString(): void
    {
        Assert::same((new FlyerConfig(['bush_chance' => ' 0.25']))->getBushChance(), 0.25);
    }
}
