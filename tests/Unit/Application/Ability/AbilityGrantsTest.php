<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ability;

use App\Application\Ability\AbilityGrants;
use App\Application\Story\StoryRefusedException;
use App\Domain\Flyer\Flyer;
use App\Domain\Player\Ability;
use App\Domain\Player\Player;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * Who gets an ability (docs/spec/scenario.md, «Способности»): a triple of the dice, the hit that carries the points
 * across a mark, the moderator.
 */
#[Test]
#[Covers(AbilityGrants::class)]
final class AbilityGrantsTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];
    private const MARK = 10;

    #[DataSet([[1, 1, 1]], '1-1-1')]
    #[DataSet([[4, 4, 4]], '4-4-4')]
    #[DataSet([[6, 6, 6]], '6-6-6')]
    public function tripleOfANewThrowGrantsAnAbility(array $faces): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = $faces;
        $ritual->broadcast->published = [];

        $answer = $ritual->dice->roll(self::JACK, 1);

        $ability = $ritual->playerStore->find(self::JACK)?->ability;
        Assert::true(Ability::isValid($ability));
        Assert::same($answer['ability'] ?? null, $ability);
        Assert::same($ritual->broadcast->ofType('ability'), [[
            'type' => 'ability',
            'round' => 1,
            'player' => ['id' => self::JACK, 'name' => 'Jack'],
            'ability' => $ability,
            'source' => 'dice',
            'at' => $ritual->clock->now,
        ]]);
        Assert::same($ritual->walkerStore->all()[self::JACK]->ability, $ability);
        Assert::equals($ritual->counters()["hellowin_abilities_granted_total{{$ability},dice}"] ?? null, 1);
    }

    public function newTripleAlwaysChangesTheAbility(): void
    {
        $ritual = self::dice();
        $seen = [];

        for ($i = 0; $i < 30; ++$i) {
            $before = $ritual->playerStore->find(self::JACK)?->ability;
            $after = $ritual->abilityGrants->rolled(1, self::JACK, [2, 2, 2]);
            Assert::notSame($after, $before);
            $seen[$after] = true;
        }

        Assert::array(\array_keys($seen))->sameElementsAs(Ability::ALL);
    }

    public function throwWithoutATripleGrantsNothing(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [2, 2, 5];

        $answer = $ritual->dice->roll(self::JACK, 1);

        Assert::same($answer, ['attempt' => 1, 'dice' => [2, 2, 5]]);
        Assert::null($ritual->playerStore->find(self::JACK)?->ability);
        Assert::blank($ritual->broadcast->ofType('ability'));
    }

    public function owlsThrowsGrantNothing(): void
    {
        $ritual = self::dice();

        $ritual->dice->owl();
        $ritual->dice->owl();
        $ritual->dice->owl();

        Assert::same(\array_column($ritual->broadcast->ofType('roll'), 'dice')[2], [6, 6, 6]);
        Assert::blank($ritual->broadcast->ofType('ability'));
    }

    public function repeatOfTheAttemptGrantsNothingMore(): void
    {
        $ritual = self::dice();
        $ritual->diceFaces->faces = [3, 3, 3];
        $first = $ritual->dice->roll(self::JACK, 1);
        $ability = $ritual->playerStore->find(self::JACK)?->ability;

        Assert::same($ritual->dice->roll(self::JACK, 1), $first);
        $ritual->diceReplies->kept = [];
        $ritual->dice->roll(self::JACK, 1);

        Assert::count($ritual->broadcast->ofType('ability'), 1);
        Assert::same($ritual->playerStore->find(self::JACK)?->ability, $ability);
    }

    public function hitAcrossAMarkGrantsFart(): void
    {
        $ritual = self::lawn();
        self::points($ritual, self::MARK - 1);

        $this->shoot($ritual, 1);

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::FART);
        $events = \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
        Assert::same(\array_slice($events, \max(\array_keys($events, 'shot', true))), ['shot', 'ability', 'owl']);
        Assert::same($ritual->broadcast->ofType('ability')[0]['source'], 'flyers');
        Assert::equals($ritual->counters()['hellowin_abilities_granted_total{fart,flyers}'] ?? null, 1);
    }

    public function hitShortOfTheNextMarkGrantsNothing(): void
    {
        $ritual = self::lawn();
        self::points($ritual, self::MARK);

        $this->shoot($ritual, 1);

        Assert::int($ritual->playerStore->find(self::JACK)?->points)->lessThan(2 * self::MARK);
        Assert::null($ritual->playerStore->find(self::JACK)?->ability);
        Assert::blank($ritual->broadcast->ofType('ability'));
    }

    public function hitAcrossSeveralMarksGrantsOnce(): void
    {
        $ritual = self::lawn(mark: 3);
        $witch = $ritual->flyers->launchSpecial(Flyer::KIND_WITCH, $ritual->clock->now) ?? Assert::fail('A witch is up.');

        $ritual->flyers->shoot($witch->id, self::JACK);

        Assert::int($ritual->playerStore->find(self::JACK)?->points)->greaterThanOrEqual(2 * 3);
        Assert::same(\array_column($ritual->broadcast->ofType('ability'), 'ability'), [Ability::FART]);
    }

    public function markLeavesAnAbilityThePlayerHas(): void
    {
        $ritual = self::lawn();
        $ritual->abilityGrants->grant(self::JACK, Ability::MEEP);
        self::points($ritual, self::MARK - 1);
        $ritual->broadcast->published = [];

        $this->shoot($ritual, 1);

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::MEEP);
        Assert::blank($ritual->broadcast->ofType('ability'));
    }

    public function nextMarkGrantsAgainOnceTheAbilityIsTaken(): void
    {
        $ritual = self::lawn();
        self::points($ritual, self::MARK - 1);
        $this->shoot($ritual, 1);
        $ritual->abilityGrants->grant(self::JACK, null);
        self::points($ritual, 2 * self::MARK - 1);

        $this->shoot($ritual, 1);

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::FART);
        Assert::same(\array_column($ritual->broadcast->ofType('ability'), 'source'), ['flyers', 'moderator', 'flyers']);
    }

    public function marksCountAnewAfterTheStatisticsReset(): void
    {
        $ritual = self::lawn();
        self::points($ritual, self::MARK + 5);
        $ritual->stats->reset();

        while (($ritual->playerStore->find(self::JACK)?->points ?? 0) < self::MARK) {
            $this->shoot($ritual, 1);
        }

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::FART);
        Assert::count($ritual->broadcast->ofType('ability'), 1);
    }

    public function owlNamesTheAbilityOncePerGap(): void
    {
        $ritual = self::lawn();

        $ritual->abilityGrants->grant(self::JACK, Ability::FART);
        $ritual->clock->now += RitualHarness::ABILITY_OWL_GAP - 1;
        $ritual->abilityGrants->grant(self::JACK, Ability::MEEP);
        $ritual->clock->now += 1;
        $ritual->abilityGrants->grant(self::JACK, Ability::FLYERS);

        Assert::count($ritual->broadcast->ofType('ability'), 3);
        $lines = $ritual->broadcast->ofType('owl');
        Assert::same(\array_column($lines, 'line'), ['ability', 'ability']);
        Assert::same(\array_column(\array_column($lines, 'params'), 'ability'), [Ability::FART, Ability::FLYERS]);
        Assert::same($lines[0]['params']['player'], ['id' => self::JACK, 'name' => 'Jack']);
        Assert::int($lines[0]['params']['n'])->greaterThanOrEqual(0);
    }

    public function moderatorGivesAndTakesAway(): void
    {
        $ritual = self::lawn();

        Assert::same($ritual->abilityGrants->grant(self::JACK, Ability::FLYERS), Ability::FLYERS);
        Assert::same($ritual->abilityGrants->grant(self::JACK, Ability::FLYERS), Ability::FLYERS);
        Assert::null($ritual->abilityGrants->grant(self::JACK, null));

        Assert::same(\array_column($ritual->broadcast->ofType('ability'), 'ability'), [Ability::FLYERS, null]);
        Assert::same(\array_column($ritual->broadcast->ofType('ability'), 'source'), ['moderator', 'moderator']);
        Assert::count($ritual->broadcast->ofType('owl'), 1, 'taking away is not named');
        Assert::null($ritual->playerStore->find(self::JACK)?->ability);
        Assert::false(isset($ritual->walkerStore->all()[self::JACK]->toArray()['ability']));
        Assert::equals($ritual->counters()['hellowin_abilities_granted_total{flyers,moderator}'] ?? null, 1);
    }

    #[DataSet([Ability::LIGHTNING], 'lightning')]
    #[DataSet([Ability::PUMPKIN], 'pumpkin')]
    public function moderatorGivesTheSecondSetToo(string $ability): void
    {
        $ritual = self::lawn();

        Assert::same($ritual->abilityGrants->grant(self::JACK, $ability), $ability);

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, $ability);
        Assert::same($ritual->walkerStore->all()[self::JACK]->ability, $ability);
        Assert::same(\array_column($ritual->broadcast->ofType('ability'), 'ability'), [$ability]);
    }

    public function moderatorNamesNoPlayerWithoutARecord(): never
    {
        $ritual = new RitualHarness();

        Expect::exception(StoryRefusedException::class)->withMessage('no target');

        $ritual->abilityGrants->grant('bbbbbbbbbbbbbbbb', Ability::MEEP);
    }

    #[DataSet(['magic'])]
    #[DataSet([1])]
    #[DataSet([''])]
    public function moderatorGivesOnlyAnAbilityThereIs(mixed $ability): never
    {
        $ritual = self::lawn();

        Expect::exception(StoryRefusedException::class)->withMessage('invalid ability');

        $ritual->abilityGrants->grant(self::JACK, $ability);
    }

    public function abilitySurvivesResetsAndTheStatisticsReset(): void
    {
        $ritual = self::lawn();
        $ritual->abilityGrants->grant(self::JACK, Ability::MEEP);

        $ritual->stats->reset();
        $ritual->reset->reset(1);
        $ritual->reset->reset(2, again: true);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));

        Assert::same($ritual->playerStore->find(self::JACK)?->ability, Ability::MEEP);
        Assert::same($ritual->snapshot->create()['walkers'][0]['ability'] ?? null, Ability::MEEP);
    }

    public function characterCarriesTheAbilityInTheSnapshotAndItsEvent(): void
    {
        $ritual = self::lawn();
        $ritual->abilityGrants->grant(self::JACK, Ability::FART);
        $ritual->walkerStore->update(static fn(array $walkers): array => [[], null]);
        $ritual->broadcast->published = [];

        $ritual->walks->move(self::JACK, 30, 90);

        Assert::same($ritual->broadcast->ofType('walker')[0]['walker']['ability'] ?? null, Ability::FART);
        Assert::same($ritual->snapshot->create()['walkers'][0]['ability'] ?? null, Ability::FART);
        Assert::same($ritual->players->find(self::JACK)?->toArray()['ability'], Ability::FART);
    }

    /**
     * Jack on the lawn of scene 0, its flyers ducks of one to three points.
     *
     * @param positive-int $mark
     */
    private static function lawn(int $mark = self::MARK): RitualHarness
    {
        $ritual = new RitualHarness(fartPoints: $mark);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));

        return $ritual;
    }

    /**
     * Step 1 with Jack standing on the lawn and the dice open.
     */
    private static function dice(): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        $ritual->walks->enter($ritual->players->register(self::JACK, 'Jack', self::LOOK));
        $ritual->story->finaleStep(1, force: true);
        Assert::same($ritual->store->story()->finale, Story::DICE_STEP);
        $ritual->owlExplained();
        $ritual->broadcast->published = [];

        return $ritual;
    }

    /**
     * @param int<0, max> $points
     */
    private static function points(RitualHarness $ritual, int $points): void
    {
        $ritual->playerStore->update(
            self::JACK,
            static fn(Player $player): Player => Player::fromArray(self::JACK, ['points' => $points] + $player->toArray()),
        );
    }

    /**
     * Shoots down the given number of flyers as Jack.
     */
    private function shoot(RitualHarness $ritual, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $flyer = $ritual->flyers->launch($ritual->clock->now) ?? Assert::fail('The scene has flyers.');
            Assert::notNull($ritual->flyers->shoot($flyer->id, self::JACK));
        }
    }
}
