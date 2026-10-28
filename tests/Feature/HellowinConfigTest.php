<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Config\FlyerConfig;
use App\Application\Config\HellowinConfig;
use App\Application\Story\DiceService;
use App\Domain\Balance\Limit;
use App\Domain\Flyer\FlyerDepth;
use App\Domain\Flyer\Point;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProvider;
use Spiral\Idempotency\IdempotencyRegistry;
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

    /**
     * The frontend draws a character at the mug of a pumpkin when its walk ends within `PUMPKIN_STAND.near` (2.5) of
     * the point beside the jumped pumpkin (public/js/layout.js): the lawn has to come that close for every one.
     */
    public function shippedPumpkinStandsAreOnTheLawnNearTheMug(): void
    {
        $config = $this->getContainer()->get(HellowinConfig::class);
        $lawn = $config->getWalkZone();
        $root = $lawn->patch();
        $stand = $config->getPumpkinStand();

        Assert::same([$stand->out, $stand->gap], [1.18, ['s' => 8.0, 'm' => 9.0, 'l' => 10.0]]);
        foreach ($config->getElements() as $pumpkin) {
            if (!$pumpkin->reachable) {
                continue;
            }
            $side = $pumpkin->x < $root->cx || $pumpkin->x === $root->cx && (\crc32($pumpkin->id) & 1) === 1 ? -1 : 1;
            $beside = new Point(
                $root->cx + ($pumpkin->x - $root->cx) * 1.18 + $side * $stand->gap[$pumpkin->size ?? 'm'],
                $root->cy + ($pumpkin->y - $root->cy) * 1.18,
            );

            $at = $stand->at($pumpkin, $lawn);

            Assert::true($lawn->contains($at), \sprintf('%s: (%.2f, %.2f) is off the lawn', $pumpkin->id, $at->x, $at->y));
            Assert::numeric(\hypot($at->x - $beside->x, $at->y - $beside->y))->lessThanOrEqual(2.5);
        }
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

    public function shippedPerchesAreDistinctSurfacesOnScreen(): void
    {
        $perches = $this->getContainer()->get(HellowinConfig::class)->getBarLayout()?->perches ?? [];

        Assert::notBlank($perches);
        foreach ($perches as $perch) {
            Assert::numeric($perch->x1)->between(0, 100);
            Assert::numeric($perch->x2)->between(0, 100);
            Assert::numeric($perch->y)->between(0, 100);
        }
    }

    public function shippedDoorLeadsOntoTheFloor(): void
    {
        $layout = $this->getContainer()->get(HellowinConfig::class)->getBarLayout();

        [$door, $inside] = $layout?->doorway() ?? Assert::fail('The shipped bar has a door.');
        Assert::equals($inside, new Point($door->x, $door->y + 6));
        Assert::true($layout->zone->contains($inside));
        Assert::same(\count($layout->zone->route($door, $inside)), 2);
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

    public function shippedScenesHaveNoRareFlyer(): void
    {
        Assert::same($this->getContainer()->get(FlyerConfig::class)->getRare(), [null, null, null, null, null]);
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

    #[Env('HELLOWIN_DICE_IDEMPOTENCY_TTL', '60')]
    public function diceAnswersAreKeptInALeaseForTheTtlFromEnv(): void
    {
        $registry = $this->getContainer()->get(IdempotencyRegistry::class);

        Assert::same($this->getContainer()->get(HellowinConfig::class)->getDiceIdempotencyTtl(), 60);
        $storage = $registry->get(DiceService::STORAGE);
        Assert::instanceOf($storage, GuaranteeProvider::class);
        Assert::same($storage->guarantee(), Guarantee::AtLeastOnce);
    }

    #[Env('HELLOWIN_PUMPKIN_BASE', '3')]
    #[Env('HELLOWIN_PUMPKIN_PER_PLAYER', '0.5')]
    #[Env('HELLOWIN_BOSS_HP_PER_PLAYER', '10')]
    #[Env('HELLOWIN_FLYER_BURST_BASE', '7')]
    public function limitsComeFromEnv(): void
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Assert::equals($config->getLimit(HellowinConfig::LIMIT_PUMPKIN), new Limit(3, 0.5));
        Assert::equals($config->getLimit(HellowinConfig::LIMIT_BOSS), new Limit(45, 10));
        Assert::same($this->getContainer()->get(FlyerConfig::class)->getBurst()->base, 7.0);
    }

    #[Env('HELLOWIN_SCENE_STAGE_MAX', '2,3')]
    public function sceneStageMaxComesFromEnv(): void
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Assert::same($config->getSceneStageMax(), [2, 3]);
    }

    #[Env('HELLOWIN_BAR_BASE', 'five')]
    public function invalidEnvValueIsAConfigError(): never
    {
        $config = $this->getContainer()->get(HellowinConfig::class);

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Config "hellowin.limits.bar.base" (env HELLOWIN_BAR_BASE) must be a number above zero.');

        $config->getLimit(HellowinConfig::LIMIT_BAR);
    }
}
