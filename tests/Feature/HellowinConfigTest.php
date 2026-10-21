<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Config\FlyerConfig;
use App\Application\Config\HellowinConfig;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\Point;
use Spiral\Testing\Attribute\Env;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Tests\TestCase;

#[Test]
final class HellowinConfigTest extends TestCase
{
    public function shippedElementsAreOnScreen(): void
    {
        $elements = $this->getContainer()->get(HellowinConfig::class)->getElements();

        Assert::int(\count($elements))->greaterThanOrEqual(8);
        foreach ($elements as $element) {
            Assert::numeric($element->x)->between(0, 100);
            Assert::numeric($element->y)->between(0, 100);
            Assert::notBlank($element->emojis);
        }
    }

    public function shippedEdgePumpkinsAreReachable(): void
    {
        $reachable = [];
        foreach ($this->getContainer()->get(HellowinConfig::class)->getElements() as $element) {
            $element->reachable and $reachable[] = $element->id;
        }

        Assert::same($reachable, ['pumpkin-3', 'pumpkin-5', 'pumpkin-7', 'pumpkin-9', 'pumpkin-10', 'pumpkin-11', 'pumpkin-13', 'pumpkin-15', 'pumpkin-17']);
    }

    public function shippedBarHasRoomAtTheCounterAndInTheSpawnBand(): void
    {
        $layout = $this->getContainer()->get(HellowinConfig::class)->getBarLayout();

        Assert::notNull($layout);
        [$x1, $x2, $y] = $layout->counter;
        for ($x = $x1; $x <= $x2; $x += 1.0) {
            Assert::true($layout->zone->contains(new Point($x, $y)), \sprintf('(%.1f, %.1f) at the counter is off the floor', $x, $y));
        }
        Assert::numeric($layout->spawnY[0])->greaterThanOrEqual($layout->zone->y1);
        Assert::numeric($layout->spawnY[1])->lessThanOrEqual($layout->zone->y2);
        Assert::count($layout->zone->obstacles, 4);
    }

    public function shippedBushesAreOnTheGround(): void
    {
        $depths = \count($this->getContainer()->get(FlyerConfig::class)->getDepths());
        $bushes = $this->getContainer()->get(HellowinConfig::class)->getBushes($depths);

        Assert::notBlank($bushes);
        foreach ($bushes as $bush) {
            Assert::numeric($bush->at->x)->between(0, 100);
            Assert::numeric($bush->at->y)->between(30, 100);
        }
    }

    public function shippedItemsHandOutBeerInTheCursedWorld(): void
    {
        $items = $this->getContainer()->get(HellowinConfig::class)->getItems();

        Assert::same(
            [$items->held(0), $items->held(1), $items->held(4), $items->held(4, 0), $items->held(4, 1), $items->held(4, 4), $items->held(4, 5)],
            [null, 'pistol', 'red-flame', 'red-flame', null, null, 'beer'],
        );
    }

    #[Env('HELLOWIN_FLYER_INTERVAL', '100-200')]
    #[Env('HELLOWIN_FLYER_POINTS', '5,1')]
    #[Env('HELLOWIN_FLYER_DURATION', '3000,1000')]
    public function flyersComeFromEnv(): void
    {
        $config = $this->getContainer()->get(FlyerConfig::class);

        Assert::same($config->getInterval(), [100, 200]);
        Assert::equals($config->getDepths(), [new FlyerDepth(3000, 5), new FlyerDepth(1000, 1)]);
    }

    #[Env('HELLOWIN_CLICKS_PER_STAGE', '2')]
    public function clicksPerStageComesFromEnv(): void
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Assert::same($config->getClicksPerStage(), 2);
    }

    #[Env('HELLOWIN_SCENE_STAGE_MAX', '2,3')]
    public function sceneStageMaxComesFromEnv(): void
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Assert::same($config->getSceneStageMax(), [2, 3]);
    }

    #[Env('HELLOWIN_CLICKS_PER_STAGE', 'five')]
    public function invalidEnvValueIsAConfigError(): never
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Config "hellowin.clicks_per_stage" (env HELLOWIN_CLICKS_PER_STAGE) must be a positive integer, got "five".');

        $config->getClicksPerStage();
    }
}
