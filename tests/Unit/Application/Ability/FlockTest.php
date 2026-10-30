<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ability;

use App\Application\Ability\AbilityService;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Ticker\Ticker;
use App\Domain\Flyer\Flyer;
use App\Domain\Player\Ability;
use App\Domain\Story\Story;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The flock of ducks and chickens rises in any scene (docs/spec/protocol.md, «Способности», «Стая») and lives as any
 * flyer does: shot for points into the top, swept by the ticker as escaped, counted by depth.
 */
#[Test]
#[Covers(AbilityService::class)]
final class FlockTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];
    private const NEAR = '2';

    public function ducksAndChickensRiseInTheBar(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));

        self::liveThroughTheFlock($ritual);
    }

    public function ducksAndChickensRiseAtDawn(): void
    {
        $ritual = new RitualHarness(threshold: 1, stageMax: [0, 1, 2]);
        $ritual->players->register(self::JACK, 'Jack', self::LOOK);
        $ritual->walks->move(self::JACK, 40, 92);
        foreach ([0, 1, 2] as $scene) {
            $ritual->story->nextScene($scene, true);
            $ritual->runJobs();
        }
        $ritual->store->updateStory(static fn(Story $story): Story => new Story(finale: Story::DAWN_STEP, k: $story->k, barBonus: $story->barBonus, players: $story->players));
        Assert::true($ritual->store->snapshot()->story->dawn());

        self::liveThroughTheFlock($ritual);
    }

    /**
     * Jack launches the flock and shoots its first flyer; the ticker sweeps the rest once they are out.
     */
    private static function liveThroughTheFlock(RitualHarness $ritual): void
    {
        $ritual->abilityGrants->grant(self::JACK, Ability::FLYERS);
        $ritual->broadcast->published = [];
        $ritual->metrics->calls = [];
        $ticker = new Ticker($ritual->top, 500, $ritual->flyers, [999_999, 999_999], $ritual->walks, new NullLogger());

        $ritual->abilities->use(self::JACK);
        $flock = $ritual->broadcast->ofType('flyer');
        $points = $ritual->flyers->shoot($flock[0]['flyer'], self::JACK);
        $ticker->tick(\max(\array_column($flock, 'expiresAt')) + 1_000);

        Assert::count($flock, RitualHarness::ABILITY_FLOCK);
        foreach ($flock as $flyer) {
            Assert::contains([Flyer::KIND_DUCK, 'chicken'], $flyer['kind']);
            Assert::same($flyer['depth'], (int) self::NEAR);
            Assert::same($flyer['by'], ['id' => self::JACK]);
        }
        Assert::same($points, $flock[0]['points']);
        Assert::same($ritual->top->state()['board'][0]['points'] ?? null, $points);
        Assert::same($ritual->broadcast->ofType('top')[0]['board'][0]['player'] ?? null, ['id' => self::JACK, 'name' => 'Jack']);
        $counters = $ritual->counters();
        Assert::equals($counters[PlayMetrics::FLYERS_LAUNCHED . '{' . self::NEAR . '}'] ?? null, RitualHarness::ABILITY_FLOCK);
        Assert::equals($counters[PlayMetrics::FLYERS_SHOT . '{' . self::NEAR . '}'] ?? null, 1);
        Assert::equals($counters[PlayMetrics::FLYERS_ESCAPED . '{' . self::NEAR . '}'] ?? null, RitualHarness::ABILITY_FLOCK - 1);
    }
}
