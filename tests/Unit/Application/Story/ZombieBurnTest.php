<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Hellowin\PlayMetrics;
use App\Application\Story\RapierService;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * Zombies burn and heal FrankenPHP (docs/spec/scenario.md, «Шаг 6 — босс FrankenPHP»): on the rapier step, while the
 * boss is up, a wandering zombie burns `burnMs` after it stood up or the boss woke, whichever is later. The harness
 * keeps two zombies alive and burns them 5 s on, healing 3; FrankenPHP wakes with 50 health for two players.
 */
#[Test]
#[Covers(RapierService::class)]
#[Covers(FlyerStore::class)]
final class ZombieBurnTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    /** Milliseconds from the zombies of step 5 standing up to the boss waking. */
    private const WAKE_AFTER = 10_000;

    public function zombiesStandingSinceBeforeTheWakingBurnFromIt(): void
    {
        $ritual = self::awake(strikes: 5);
        $awakeAt = $ritual->clock->now;
        $zombies = self::ids($ritual);
        $ritual->broadcast->published = [];

        $ritual->clock->now = $awakeAt + RitualHarness::ZOMBIE_BURN_MS - 1;
        Assert::blank($ritual->rapier->burn($ritual->clock->now));

        // The tick comes late: the burn is still told at the deadline
        $ritual->clock->now = $awakeAt + RitualHarness::ZOMBIE_BURN_MS + 150;
        Assert::same($ritual->rapier->burn($ritual->clock->now), $zombies);

        $deadline = $awakeAt + RitualHarness::ZOMBIE_BURN_MS;
        $boss = ['type' => 'boss', 'round' => 1, 'max' => 50, 'burnMs' => RitualHarness::ZOMBIE_BURN_MS, 'awakeAt' => $awakeAt];
        Assert::same(\array_column($ritual->broadcast->published, 'message'), [
            ['type' => 'burn', 'round' => 1, 'flyer' => $zombies[0], 'at' => $deadline, 'heal' => 3],
            ['type' => 'boss', 'round' => 1, 'hp' => 48] + $boss,
            ['type' => 'burn', 'round' => 1, 'flyer' => $zombies[1], 'at' => $deadline, 'heal' => 2],
            ['type' => 'boss', 'round' => 1, 'hp' => 50] + $boss,
        ]);
        Assert::same($ritual->snapshot->create()['boss'], ['hp' => 50, 'max' => 50, 'burnMs' => RitualHarness::ZOMBIE_BURN_MS, 'awakeAt' => $awakeAt]);
        Assert::blank($ritual->snapshot->create()['flyers']);
        Assert::same($ritual->counters()[PlayMetrics::ZOMBIE_BURNS . '{true}'], 2.0);
        Assert::same($ritual->counters()[PlayMetrics::BOSS_HEAL . '{}'], 5.0);
        Assert::blank($ritual->rapier->burn($ritual->clock->now + 60_000));
    }

    public function burntZombiesMakeRoomForNewOnesThatBurnFromTheirOwnStanding(): void
    {
        $ritual = self::awake();
        $ritual->clock->now += RitualHarness::ZOMBIE_BURN_MS;
        $burnt = $ritual->rapier->burn($ritual->clock->now);
        $ritual->clock->now += 1_000;

        $risen = $ritual->flyers->wander($ritual->clock->now, false);

        Assert::count($risen, 2);
        Assert::same(\array_column($ritual->snapshot->create()['flyers'], 'flyer'), \array_map(static fn(Flyer $f): string => $f->id, $risen));
        Assert::blank(\array_intersect($burnt, self::ids($ritual)));
        Assert::blank($ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS - 1));
        Assert::count($ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS), 2);
    }

    public function fullHealthTakesNoHealingAndTellsNoBoss(): void
    {
        $ritual = self::awake();
        $ritual->broadcast->published = [];

        $ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['burn', 'burn']);
        Assert::same(\array_column($ritual->broadcast->ofType('burn'), 'heal'), [0, 0]);
        Assert::false(isset($ritual->counters()[PlayMetrics::BOSS_HEAL . '{}']));
    }

    public function pauseBurnsZombiesButHealsNothing(): void
    {
        $ritual = self::awake(strikes: 5);
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        $ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['burn', 'burn']);
        Assert::same(\array_column($ritual->broadcast->ofType('burn'), 'heal'), [0, 0]);
        Assert::same(\array_column($ritual->broadcast->ofType('burn'), 'counted'), [false, false]);
        Assert::same($ritual->snapshot->create()['boss']['hp'] ?? null, 45);
        Assert::same($ritual->counters()[PlayMetrics::ZOMBIE_BURNS . '{false}'], 2.0);
    }

    public function burntZombieIsNeitherSlainNorScored(): void
    {
        $ritual = self::awake();
        $points = \array_map(static fn(string $id): ?int => $ritual->playerStore->find($id)?->points, [self::JACK, self::JILL]);

        $ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS);

        Assert::same($ritual->snapshot->create()['cursed']['slain'] ?? null, 0);
        Assert::same(\array_map(static fn(string $id): ?int => $ritual->playerStore->find($id)?->points, [self::JACK, self::JILL]), $points);
        Assert::blank($ritual->broadcast->ofType('shot'));
        Assert::false(isset($ritual->counters()[PlayMetrics::FLYERS_SHOT . '{1}']));
    }

    public function shotZombieDoesNotBurn(): void
    {
        $ritual = self::awake();
        [$shot, $left] = self::ids($ritual);
        Assert::notNull($ritual->flyers->shoot($shot, self::JACK));

        Assert::same($ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS), [$left]);
        Assert::same(\array_column($ritual->broadcast->ofType('burn'), 'flyer'), [$left]);
    }

    public function zombiePlayerDoesNotBurn(): void
    {
        $ritual = self::awake();
        $ritual->rapier->word(self::JILL, 'пиво');

        $ritual->rapier->burn($ritual->clock->now + RitualHarness::ZOMBIE_BURN_MS);

        Assert::true($ritual->walkerStore->all()[self::JILL]->zombie());
    }

    public function nothingBurnsOnTheCursedStep(): void
    {
        $ritual = self::cursed();

        Assert::blank($ritual->rapier->burn($ritual->clock->now + 600_000));
        Assert::count($ritual->flyerStore->wanderers(), 2);
    }

    public function nothingBurnsBeforeTheBossWakes(): void
    {
        $ritual = self::cursed();
        $ritual->story->finaleStep(6, force: true);

        Assert::blank($ritual->rapier->burn($ritual->clock->now + 600_000));
        Assert::count($ritual->flyerStore->wanderers(), 2);
    }

    public function nothingBurnsOnceTheBossIsDown(): void
    {
        $ritual = self::awake();
        $ritual->rapier->tune(-49);
        $ritual->rapier->strike(self::JACK);

        Assert::blank($ritual->rapier->burn($ritual->clock->now + 600_000));
        Assert::count($ritual->flyerStore->wanderers(), 2);
    }

    public function nothingBurnsAtDawn(): void
    {
        $ritual = self::awake();
        $ritual->story->finaleStep(7, force: true);
        $ritual->broadcast->published = [];

        Assert::blank($ritual->rapier->burn($ritual->clock->now + 600_000));
        Assert::blank($ritual->broadcast->ofType('burn'));
    }

    public function resetForgetsTheBoss(): void
    {
        $ritual = self::awake();

        $ritual->reset->reset();

        Assert::null($ritual->snapshot->create()['boss']);
        Assert::blank($ritual->rapier->burn($ritual->clock->now + 600_000));
    }

    /**
     * The cursed world with Jack and Jill on the lawn, two zombies standing.
     */
    private static function cursed(): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        foreach ([self::JACK => 20.0, self::JILL => 60.0] as $id => $x) {
            $ritual->players->register($id, 'Jack', self::LOOK);
            $walk = $ritual->walks->move($id, $x, 90);
            $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
            $ritual->countPlayers();
        }
        for ($step = 1; $step <= 5; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
        foreach ($ritual->walkerStore->all() as $walker) {
            $ritual->clock->now = \max($ritual->clock->now, $walker->walk->arrivesAt);
        }

        return $ritual;
    }

    /**
     * The rapier step, FrankenPHP woken by Jack a while after the zombies stood up and struck `$strikes` times.
     */
    private static function awake(int $strikes = 0): RitualHarness
    {
        $ritual = self::cursed();
        $ritual->story->finaleStep(6, force: true);
        $ritual->owlExplained();
        $ritual->clock->now += self::WAKE_AFTER;
        Assert::true($ritual->rapier->word(self::JACK, 'rapira'));
        for ($i = 0; $i < $strikes; ++$i) {
            $ritual->rapier->strike(self::JACK);
        }

        return $ritual;
    }

    /**
     * @return list<non-empty-string>
     */
    private static function ids(RitualHarness $ritual): array
    {
        return \array_map(static fn(Flyer $f): string => $f->id, $ritual->flyerStore->wanderers());
    }
}
