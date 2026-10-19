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

        Assert::equals($config->getDepths(), [new FlyerDepth(9000, 3), new FlyerDepth(6500, 2), new FlyerDepth(4000, 1)]);
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

    public function kindsDefaultToDucksAndChickens(): void
    {
        Assert::same((new FlyerConfig())->getKinds(), ['duck', 'chicken']);
        Assert::same((new FlyerConfig(['kinds' => ' duck , duck,bat']))->getKinds(), ['duck', 'bat']);
    }

    #[DataSet([''], 'empty')]
    #[DataSet(['duck,'], 'trailing comma')]
    #[DataSet(['Duck'], 'uppercase')]
    public function kindsRejectGarbage(mixed $value): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('HELLOWIN_FLYER_KINDS');

        (new FlyerConfig(['kinds' => $value]))->getKinds();
    }

    public function chanceFromEnvString(): void
    {
        Assert::same((new FlyerConfig(['bush_chance' => ' 0.25']))->getBushChance(), 0.25);
    }
}
