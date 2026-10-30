<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ability;

use App\Application\Ability\AbilityBusyException;
use App\Application\Ability\AbilityService;
use App\Application\Flyer\FlyerService;
use App\Application\Story\StoryRefusedException;
use App\Domain\Flyer\Flyer;
use App\Domain\Player\Ability;
use App\Domain\Player\Player;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The lightning strikes the flyer the client names, as a shot of the player's (docs/spec/protocol.md, «Способности»,
 * «Молния и тыква»).
 */
#[Test]
#[Covers(AbilityService::class)]
#[Covers(FlyerService::class)]
final class LightningTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const BOB = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function boltHitsTheFlyerForThePlayerAndStopsTheWalk(): void
    {
        $ritual = self::lawn();
        $flyer = self::launch($ritual);
        $ritual->walks->move(self::JACK, 80, 60);
        $ritual->clock->now += 300;
        $ritual->broadcast->published = [];
        $now = $ritual->clock->now;

        $used = $ritual->abilities->use(self::JACK, $flyer->id);

        Assert::same($used, ['ability' => Ability::LIGHTNING, 'readyAt' => $now + RitualHarness::ABILITY_COOLDOWNS['lightning'], 'hit' => true]);
        $types = \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
        Assert::same(\array_values(\array_intersect($types, ['move', 'power', 'shot'])), ['move', 'power', 'shot']);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'], 'shoot');
        Assert::count($ritual->walkerStore->all()[self::JACK]->walk->path, 1, 'stands');
        Assert::same($ritual->broadcast->ofType('power'), [[
            'type' => 'power',
            'round' => 1,
            'player' => ['id' => self::JACK],
            'ability' => Ability::LIGHTNING,
            'at' => $now,
            'flyer' => $flyer->id,
            'hit' => true,
        ]]);
        Assert::same($ritual->broadcast->ofType('shot')[0]['player']['id'] ?? null, self::JACK);
        $player = $ritual->playerStore->find(self::JACK);
        Assert::same([$player?->flyers, $player?->points], [1, $flyer->points]);
        Assert::count($ritual->topStore->get()->players, 1);
        Assert::equals($ritual->counters()['hellowin_abilities_used_total{lightning}'] ?? null, 1);
        Assert::null($ritual->flyers->shoot($flyer->id, self::BOB), 'down already');
    }

    public function boltAtAFlyerShotFirstStrikesInVainAndSpendsTheCooldown(): void
    {
        $ritual = self::lawn();
        $flyer = self::launch($ritual);
        $ritual->flyers->shoot($flyer->id, self::BOB);
        $ritual->broadcast->published = [];

        $used = $ritual->abilities->use(self::JACK, $flyer->id);

        Assert::false($used['hit'] ?? null);
        Assert::same($ritual->broadcast->ofType('power')[0]['hit'] ?? null, false);
        Assert::blank($ritual->broadcast->ofType('shot'));
        Assert::same($ritual->playerStore->find(self::JACK)?->flyers, 0);
        Assert::same(self::busy($ritual, $flyer->id), $used['readyAt']);
    }

    #[DataSet([null], 'no flyer')]
    #[DataSet([42], 'not an id')]
    #[DataSet(['f-0000ffff'], 'unknown')]
    public function boltWithNothingToStrikeCostsNothing(mixed $target): void
    {
        $ritual = self::lawn();

        Assert::same(self::refusal($ritual, $target), 'no target');

        Assert::blank($ritual->broadcast->published);
        Assert::same($ritual->abilities->use(self::JACK, self::launch($ritual)->id)['hit'] ?? null, true, 'no cooldown spent');
    }

    public function flyerGoneOffTheScreenIsNoTarget(): void
    {
        $ritual = self::lawn();
        $flyer = self::launch($ritual);
        $ritual->broadcast->published = [];
        $ritual->clock->now = $flyer->expiresAt() + 500;

        Assert::same(self::refusal($ritual, $flyer->id), 'no target');
        Assert::blank($ritual->broadcast->published);
    }

    public function cooldownComesBeforeTheTarget(): void
    {
        $ritual = self::lawn();
        $readyAt = $ritual->abilities->use(self::JACK, self::launch($ritual)->id)['readyAt'];

        Assert::same(self::busy($ritual, 'f-0000ffff'), $readyAt);
    }

    public function boltAtTheElephantMakesItFart(): void
    {
        $ritual = self::lawn();
        $elephant = $ritual->flyers->launchSpecial(Flyer::KIND_ELEPHANT, $ritual->clock->now) ?? Assert::fail('An elephant is up.');
        $ritual->broadcast->published = [];

        $used = $ritual->abilities->use(self::JACK, $elephant->id);

        Assert::false($used['hit'] ?? null);
        $types = \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
        Assert::same(\array_values(\array_intersect($types, ['power', 'fart', 'shot'])), ['power', 'fart']);
        Assert::same($ritual->broadcast->ofType('fart')[0]['player'] ?? null, ['id' => self::JACK]);
    }

    public function boltAcrossAMarkKeepsTheLightning(): void
    {
        $ritual = self::lawn(fartPoints: 1);

        $ritual->abilities->use(self::JACK, self::launch($ritual)->id);

        Assert::int($ritual->playerStore->find(self::JACK)?->points)->greaterThanOrEqual(1);
        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::LIGHTNING, 'a mark brings fart only to a player without an ability');
        Assert::blank($ritual->broadcast->ofType('ability'));
    }

    public function boltsPointsCountTowardsTheMark(): void
    {
        $ritual = self::lawn(fartPoints: 100);
        $flyer = self::launch($ritual);
        $ritual->playerStore->update(
            self::JACK,
            static fn(Player $player): Player => Player::fromArray(self::JACK, ['points' => 99 - $flyer->points] + $player->toArray()),
        );
        $ritual->abilities->use(self::JACK, $flyer->id);
        Assert::same($ritual->playerStore->find(self::JACK)?->points, 99);
        $ritual->abilityGrants->grant(self::JACK, null);
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot(self::launch($ritual)->id, self::JACK);

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::FART);
        Assert::count($ritual->broadcast->ofType('ability'), 1);
    }

    private static function launch(RitualHarness $ritual): Flyer
    {
        return $ritual->flyers->launch($ritual->clock->now) ?? Assert::fail('A flyer is up.');
    }

    private static function busy(RitualHarness $ritual, mixed $flyer): int
    {
        try {
            $ritual->abilities->use(self::JACK, $flyer);
        } catch (AbilityBusyException $e) {
            return $e->readyAt;
        }

        return Assert::fail('The lightning is cooling down.');
    }

    private static function refusal(RitualHarness $ritual, mixed $flyer): string
    {
        try {
            $ritual->abilities->use(self::JACK, $flyer);
        } catch (StoryRefusedException $e) {
            return $e->error;
        }

        return Assert::fail('The bolt is refused.');
    }

    /**
     * Jack standing on the lawn of scene 0 with the lightning; the events of getting there are cleared.
     *
     * @param positive-int $fartPoints
     */
    private static function lawn(int $fartPoints = 100): RitualHarness
    {
        $ritual = new RitualHarness(fartPoints: $fartPoints);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));
        $ritual->abilityGrants->grant(self::JACK, Ability::LIGHTNING);
        $ritual->broadcast->published = [];

        return $ritual;
    }
}
