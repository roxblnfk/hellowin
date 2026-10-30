<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ability;

use App\Application\Ability\AbilityBusyException;
use App\Application\Ability\AbilityService;
use App\Application\Hellowin\ResetService;
use App\Application\Walk\WalkService;
use App\Domain\Player\Ability;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The player turns the character into a pumpkin until it walks again (docs/spec/protocol.md, «Способности»,
 * «Молния и тыква»).
 */
#[Test]
#[Covers(AbilityService::class)]
#[Covers(WalkService::class)]
#[Covers(ResetService::class)]
final class PumpkinTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function standingCharacterBecomesAPumpkinEveryoneSees(): void
    {
        $ritual = self::lawn();
        $now = $ritual->clock->now;

        $used = $ritual->abilities->use(self::JACK);

        $n = $used['n'] ?? Assert::fail('The answer carries the number.');
        Assert::int($n)->greaterThanOrEqual(0);
        Assert::same($used, ['ability' => Ability::PUMPKIN, 'readyAt' => $now + RitualHarness::ABILITY_COOLDOWNS['pumpkin'], 'n' => $n]);
        Assert::same($ritual->broadcast->ofType('power'), [
            ['type' => 'power', 'round' => 1, 'player' => ['id' => self::JACK], 'ability' => Ability::PUMPKIN, 'at' => $now, 'n' => $n],
        ]);
        Assert::blank($ritual->broadcast->ofType('move'), 'it stood already');
        Assert::same($ritual->walkerStore->all()[self::JACK]->pumpkin, $n);
        Assert::same($ritual->snapshot->create()['walkers'][0]['pumpkin'] ?? null, $n);
        Assert::equals($ritual->counters()['hellowin_abilities_used_total{pumpkin}'] ?? null, 1);
    }

    public function walkingCharacterStopsFirst(): void
    {
        $ritual = self::lawn();
        $ritual->walks->move(self::JACK, 80, 60);
        $ritual->clock->now += 300;
        $ritual->broadcast->published = [];

        $n = $ritual->abilities->use(self::JACK)['n'] ?? null;

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['move', 'power']);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'], 'pumpkin');
        $walker = $ritual->walkerStore->all()[self::JACK];
        Assert::count($walker->walk->path, 1);
        Assert::same($walker->pumpkin, $n);
    }

    public function againAfterTheCooldownItIsAnotherPumpkin(): void
    {
        $ritual = self::lawn();
        $first = $ritual->abilities->use(self::JACK);
        Assert::same(self::busy($ritual), $first['readyAt']);
        $ritual->clock->now = $first['readyAt'];
        $second = $ritual->abilities->use(self::JACK);

        Assert::notSame($second['n'] ?? null, $first['n'] ?? null);
        Assert::same($ritual->walkerStore->all()[self::JACK]->pumpkin, $second['n'] ?? null);
    }

    public function moveTurnsItBack(): void
    {
        $ritual = self::pumpkin();

        $ritual->walks->move(self::JACK, 80, 60);

        Assert::null($ritual->walkerStore->all()[self::JACK]->pumpkin);
        Assert::false(\array_key_exists('pumpkin', $ritual->snapshot->create()['walkers'][0]));
    }

    public function scatterOfTheStoryTurnsItBack(): void
    {
        $ritual = self::pumpkin();

        $ritual->walks->scatter();

        Assert::null($ritual->walkerStore->all()[self::JACK]->pumpkin);
    }

    public function walkToTheCounterTurnsItBack(): void
    {
        $ritual = self::pumpkin(stageMax: [0, 1, 2]);

        $ritual->walks->toCounter(self::JACK);

        Assert::null($ritual->walkerStore->all()[self::JACK]->pumpkin);
    }

    public function resetOnTheLawnTurnsItBack(): void
    {
        $ritual = self::pumpkin();

        $ritual->reset->reset();

        Assert::null($ritual->walkerStore->all()[self::JACK]->pumpkin);
    }

    public function resetIntoTheBarTurnsItBack(): void
    {
        $ritual = self::pumpkin(stageMax: [0, 1, 2]);

        $ritual->reset->reset();

        Assert::null($ritual->walkerStore->all()[self::JACK]->pumpkin);
        Assert::false(\array_key_exists('pumpkin', $ritual->broadcast->ofType('reset')[0]['walkers'][0]));
    }

    public function shotInPlaceKeepsIt(): void
    {
        $ritual = self::pumpkin();
        $n = $ritual->walkerStore->all()[self::JACK]->pumpkin;

        $ritual->flyers->shoot('f-0000ffff', self::JACK);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jacky', self::LOOK));

        Assert::notNull($n);
        Assert::same($ritual->walkerStore->all()[self::JACK]->pumpkin, $n);
    }

    /**
     * @return int When the pumpkin is ready, as the refusal tells.
     */
    private static function busy(RitualHarness $ritual): int
    {
        try {
            $ritual->abilities->use(self::JACK);
        } catch (AbilityBusyException $e) {
            return $e->readyAt;
        }

        return Assert::fail('The pumpkin is cooling down.');
    }

    /**
     * Jack a pumpkin, standing; the events of getting there are cleared.
     *
     * @param non-empty-list<int<0, max>> $stageMax
     */
    private static function pumpkin(array $stageMax = [1, 2]): RitualHarness
    {
        $ritual = self::lawn($stageMax);
        $ritual->abilities->use(self::JACK);
        Assert::notNull($ritual->walkerStore->all()[self::JACK]->pumpkin);
        $ritual->broadcast->published = [];

        return $ritual;
    }

    /**
     * Jack standing in scene 0 with the pumpkin; the events of getting there are cleared.
     *
     * @param non-empty-list<int<0, max>> $stageMax
     */
    private static function lawn(array $stageMax = [1, 2]): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: $stageMax);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));
        $ritual->abilityGrants->grant(self::JACK, Ability::PUMPKIN);
        $ritual->broadcast->published = [];

        return $ritual;
    }
}
