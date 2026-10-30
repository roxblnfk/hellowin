<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ability;

use App\Application\Ability\AbilityBusyException;
use App\Application\Ability\AbilityService;
use App\Application\Story\StoryRefusedException;
use App\Application\Walk\NoCharacterException;
use App\Domain\Flyer\Flyer;
use App\Domain\Player\Ability;
use App\Domain\Player\Player;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * A player uses the ability (docs/spec/protocol.md, «Способности»).
 */
#[Test]
#[Covers(AbilityService::class)]
final class AbilityServiceTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function meepGoesToEveryoneAndCoolsDown(): void
    {
        $ritual = self::lawn(Ability::MEEP);
        $now = $ritual->clock->now;

        $used = $ritual->abilities->use(self::JACK);

        $readyAt = $now + RitualHarness::ABILITY_COOLDOWNS['meep'];
        Assert::same($used, ['ability' => Ability::MEEP, 'readyAt' => $readyAt]);
        Assert::same($ritual->broadcast->ofType('power'), [
            ['type' => 'power', 'round' => 1, 'player' => ['id' => self::JACK], 'ability' => Ability::MEEP, 'at' => $now],
        ]);
        Assert::same(self::busy($ritual), $readyAt);
        $ritual->clock->now = $readyAt;
        Assert::same($ritual->abilities->use(self::JACK)['ability'], Ability::MEEP);
        Assert::count($ritual->broadcast->ofType('power'), 2);
        Assert::equals($ritual->counters()['hellowin_abilities_used_total{meep}'] ?? null, 2);
    }

    public function everyAbilityCoolsDownByItself(): void
    {
        $ritual = self::lawn(Ability::MEEP);
        $ritual->abilities->use(self::JACK);
        $ritual->abilityGrants->grant(self::JACK, Ability::FART);

        $used = $ritual->abilities->use(self::JACK);

        Assert::same($used, ['ability' => Ability::FART, 'readyAt' => $ritual->clock->now + RitualHarness::ABILITY_COOLDOWNS['fart']]);
        Assert::same(self::busy($ritual), $used['readyAt']);
    }

    public function flyersRiseFromTheCharacterAndAreShotAsAnyOther(): void
    {
        $ritual = self::lawn(Ability::FLYERS);
        $at = $ritual->walkerStore->all()[self::JACK]->walk->positionAt($ritual->clock->now);

        $used = $ritual->abilities->use(self::JACK);

        Assert::same($used['readyAt'], $ritual->clock->now + RitualHarness::ABILITY_COOLDOWNS['flyers']);
        $flyers = $ritual->broadcast->ofType('flyer');
        Assert::count($flyers, RitualHarness::ABILITY_FLOCK);
        foreach ($flyers as $flyer) {
            Assert::same($flyer['by'], ['id' => self::JACK]);
            Assert::same($flyer['from'], ['x' => \round($at->x, 1), 'y' => \round($at->y, 1)]);
            Assert::same($flyer['kind'], Flyer::KIND_DUCK);
            Assert::same($flyer['depth'], 2, 'the nearest depth');
            Assert::true($flyer['to']['y'] < 0 || $flyer['to']['x'] < 0 || $flyer['to']['x'] > 100, 'out behind an edge');
            Assert::true($flyer['to']['y'] < $flyer['from']['y'], 'up');
        }
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type')[0], 'power');
        Assert::same($ritual->flyers->shoot($flyers[0]['flyer'], 'bbbbbbbbbbbbbbbb'), 1);
    }

    public function flyersNeedASetWithFlyers(): void
    {
        $ritual = self::lawn(Ability::FLYERS, [[]]);

        Assert::same(self::refusal($ritual), 'no flyers');
        Assert::same(self::refusal($ritual), 'no flyers', 'the refusal spends no cooldown');
        Assert::blank($ritual->broadcast->published);
    }

    public function cooldownComesBeforeTheEmptySet(): void
    {
        $ritual = self::lawn(Ability::FLYERS, [[Flyer::KIND_DUCK], []]);
        $readyAt = $ritual->abilities->use(self::JACK)['readyAt'];
        $ritual->transitions->scene(1, 1);

        Assert::same(self::busy($ritual), $readyAt);
        $ritual->clock->now = $readyAt;
        Assert::same(self::refusal($ritual), 'no flyers');
    }

    public function playerWithoutACharacterHasNothingToShow(): never
    {
        $ritual = new RitualHarness();
        $ritual->playerStore->update(self::JACK, static fn(Player $player): Player => $player->withAbility(Ability::MEEP));

        Expect::exception(NoCharacterException::class);

        $ritual->abilities->use(self::JACK);
    }

    public function playerWithoutAnAbilityIsRefused(): void
    {
        $ritual = self::lawn(null);

        Assert::same(self::refusal($ritual), 'no ability');
    }

    public function zombieShowsItAndTheWalkGoesOn(): void
    {
        $ritual = self::lawn(Ability::FART);
        $now = $ritual->clock->now;
        $ritual->walkerStore->update(static fn(array $walkers): array => [
            [self::JACK => $walkers[self::JACK]->zombified($now, $now + 2000)],
            null,
        ]);
        $walk = $ritual->walkerStore->all()[self::JACK]->walk;

        Assert::same($ritual->abilities->use(self::JACK)['ability'], Ability::FART);

        Assert::equals($ritual->walkerStore->all()[self::JACK]->walk, $walk);
        Assert::blank($ritual->broadcast->ofType('move'));
    }

    public function walkingCharacterKeepsWalking(): void
    {
        $ritual = self::lawn(Ability::FLYERS);
        $ritual->walks->move(self::JACK, 80, 60);
        $walk = $ritual->walkerStore->all()[self::JACK]->walk;
        $ritual->clock->now += 500;
        $ritual->broadcast->published = [];

        $ritual->abilities->use(self::JACK);

        Assert::equals($ritual->walkerStore->all()[self::JACK]->walk, $walk);
        Assert::blank($ritual->broadcast->ofType('move'));
        $at = $walk->positionAt($ritual->clock->now);
        Assert::same($ritual->broadcast->ofType('flyer')[0]['from'], ['x' => \round($at->x, 1), 'y' => \round($at->y, 1)]);
    }

    public function pauseDoesNotHoldIt(): void
    {
        $ritual = self::lawn(Ability::FLYERS);
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        $ritual->abilities->use(self::JACK);

        Assert::count($ritual->broadcast->ofType('power'), 1);
        Assert::count($ritual->broadcast->ofType('flyer'), RitualHarness::ABILITY_FLOCK);
    }

    /**
     * @return int When the ability is ready, as the refusal tells.
     */
    private static function busy(RitualHarness $ritual): int
    {
        try {
            $ritual->abilities->use(self::JACK);
        } catch (AbilityBusyException $e) {
            return $e->readyAt;
        }

        return Assert::fail('The ability is cooling down.');
    }

    private static function refusal(RitualHarness $ritual): string
    {
        try {
            $ritual->abilities->use(self::JACK);
        } catch (StoryRefusedException $e) {
            return $e->error;
        }

        return Assert::fail('The use is refused.');
    }

    /**
     * Jack standing on the lawn of scene 0 with the ability; the events of getting there are cleared.
     *
     * @param Ability::FLYERS|Ability::FART|Ability::MEEP|null $ability
     * @param non-empty-list<list<non-empty-string>>|null $kinds Flyer kinds by scene.
     */
    private static function lawn(?string $ability, ?array $kinds = null): RitualHarness
    {
        $ritual = new RitualHarness(flyerKinds: $kinds);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));
        $ability === null or $ritual->abilityGrants->grant(self::JACK, $ability);
        Assert::instanceOf($ritual->walkerStore->all()[self::JACK] ?? null, Walker::class);
        $ritual->broadcast->published = [];

        return $ritual;
    }
}
