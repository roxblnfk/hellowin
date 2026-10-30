<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarRefusedException;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Hellowin\UnknownElementException;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Application\Walk\NoCharacterException;
use App\Domain\Flyer\Flyer;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The pause of the scene's progress (docs/spec/protocol.md, «Пауза прогресса»): the room's clicks, sips, strikes and
 * throws go through after their own checks but do not count toward the scene's task, no goal makes a scene ready, the
 * sky goes on, and lifting the pause catches up with what the room reached.
 */
#[Test]
#[Covers(StoryService::class)]
#[Covers(LimitsService::class)]
final class PauseTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function clickGoesThroughUncountedAndStopsTheWalk(): void
    {
        $ritual = self::walking(new RitualHarness());
        $ritual->click('pumpkin-1');
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];
        $ritual->queue->pushed = [];

        Assert::same(self::refusal(static fn() => $ritual->clicks->click('ghost-42', self::JACK)), UnknownElementException::class);
        $outcome = $ritual->clicks->click('pumpkin-1', self::JACK);
        $ritual->click('pumpkin-1', 2);

        Assert::false($outcome->counted);
        $pumpkin = $ritual->store->snapshot()->pumpkins[0];
        Assert::same([$pumpkin->stage, $pumpkin->clicks, $pumpkin->accepted], [0, 1, 4]);
        Assert::blank($ritual->queue->pushed);
        $click = $ritual->broadcast->ofType('click')[0];
        Assert::same([$click['clicks'], $click['stage'], $click['counted'] ?? null], [1, 0, false]);
        Assert::same(\array_column($ritual->broadcast->ofType('click'), 'counted'), [false, false, false]);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'] ?? null, 'click');
        Assert::same($ritual->playerStore->find(self::JACK)?->clicks, 1);

        $ritual->story->pause(false);
        $ritual->broadcast->published = [];
        Assert::true($ritual->clicks->click('pumpkin-1', self::JACK)->counted);

        Assert::false(\array_key_exists('counted', $ritual->broadcast->ofType('click')[0]));
        Assert::notBlank($ritual->queue->pushed);
    }

    public function lockedClickIsRefusedOnAPauseToo(): void
    {
        $ritual = new RitualHarness(bonfire: [1, 1]);
        $ritual->story->pause(true);

        Assert::same(self::refusal(static fn() => $ritual->clicks->click(RitualHarness::BONFIRE)), ElementLockedException::class);
        Assert::blank($ritual->broadcast->ofType('click'));
    }

    public function drinkInTheBarGoesThroughWithoutMovingTheGoal(): void
    {
        $ritual = self::walking(self::bar());
        $ritual->story->pause(true);
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'no beer');
        self::give($ritual);
        $ritual->broadcast->published = [];

        Assert::false($ritual->bar->drink(self::JACK));

        Assert::same($ritual->walkerStore->all()[self::JACK]->item, Walker::MUG);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'] ?? null, 'drink');
        Assert::same($ritual->broadcast->ofType('drink'), [['type' => 'drink', 'round' => 1, 'player' => ['id' => self::JACK], 'counted' => false]]);
        Assert::blank($ritual->broadcast->ofType('drinks'));
        Assert::same($ritual->barStore->drinks(1), 0);
        Assert::same($ritual->playerStore->find(self::JACK)?->drinks, 1);
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->drinks, 1);
    }

    public function drinkInTheCursedWorldGoesThroughWithoutMovingTheGoal(): void
    {
        $ritual = self::cursed();
        $ritual->story->pause(true);
        self::give($ritual);
        $ritual->broadcast->published = [];

        Assert::false($ritual->bar->drink(self::JACK));

        Assert::same($ritual->broadcast->ofType('drink')[0]['counted'] ?? null, false);
        Assert::blank($ritual->broadcast->ofType('cursed'));
        Assert::same($ritual->cursed->state(1, $ritual->store->story())['drunk'] ?? null, 0);
        Assert::same($ritual->playerStore->find(self::JACK)?->drinks, 1);
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->drinks, 1);
    }

    public function strikeGoesThroughAndTheHealthStays(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::place($ritual);
        for ($step = 1; $step <= 6; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
        $ritual->owlExplained();
        $ritual->story->pause(true);
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike('bbbbbbbbbbbbbbbb')), NoCharacterException::class);
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JACK)), 'not now');
        Assert::true($ritual->rapier->word(self::JACK, 'Рапира'));
        $hp = $ritual->rapier->state(1)['hp'] ?? 0;
        $ritual->rapier->tune(1 - $hp);
        $ritual->broadcast->published = [];

        Assert::same($ritual->rapier->strike(self::JACK), ['hp' => 1, 'counted' => false]);

        Assert::same($ritual->broadcast->ofType('strike'), [['type' => 'strike', 'round' => 1, 'player' => ['id' => self::JACK, 'name' => 'Jack'], 'hp' => 1, 'counted' => false]]);
        Assert::blank($ritual->broadcast->ofType('owl'));
        Assert::blank($ritual->queue->pushed);
        Assert::same($ritual->rapier->state(1)['hp'] ?? null, 1);
        Assert::same($ritual->playerStore->find(self::JACK)?->damage, 1);
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->damage, 1);
    }

    public function sixesOnAPauseRollButDoNotWin(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::place($ritual);
        $ritual->story->finaleStep(1, force: true);
        $ritual->clock->now += 10_000;
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];
        $ritual->diceFaces->faces = [6, 6, 6];

        $first = $ritual->dice->roll(self::JACK, 1);

        Assert::same($first, ['attempt' => 1, 'dice' => [6, 6, 6], 'counted' => false, 'ability' => $ritual->playerStore->find(self::JACK)?->ability]);
        Assert::same($ritual->broadcast->ofType('roll')[0]['counted'] ?? null, false);
        Assert::blank($ritual->broadcast->ofType('dice'));
        Assert::null($ritual->diceStore->get(1)->winner);
        Assert::same($ritual->snapshot->create()['dice']['on'] ?? null, true);
        Assert::same([$ritual->diceStore->get(1)->rolls, $ritual->playerStore->find(self::JACK)?->rolls], [1, 1]);

        // A repeat answers the same, from the kept answer and, once it is lost, from the record — which keeps no ability
        Assert::same($ritual->dice->roll(self::JACK, 1), $first);
        $ritual->diceReplies->kept = [];
        Assert::same($ritual->dice->roll(self::JACK, 1), \array_diff_key($first, ['ability' => true]));
        Assert::count($ritual->broadcast->ofType('roll'), 1);

        $ritual->story->pause(false);
        $ritual->clock->now += 10_000;
        $ritual->diceFaces->faces = [6, 6, 6];

        Assert::same($ritual->dice->roll(self::JACK, 2), ['attempt' => 2, 'dice' => [6, 6, 6], 'ability' => $ritual->playerStore->find(self::JACK)?->ability]);
        Assert::count($ritual->broadcast->ofType('dice'), 1);
    }

    public function burstAndSpecialsFlyOnAPause(): void
    {
        $ritual = new RitualHarness();
        $ritual->story->pause(true);

        Assert::same($ritual->flyers->burst($ritual->clock->now), 2);
        Assert::notNull($ritual->flyers->launchSpecial(Flyer::KIND_WITCH, $ritual->clock->now));
        Assert::notNull($ritual->flyers->launch($ritual->clock->now));

        Assert::count($ritual->broadcast->ofType('flyer'), 4);
    }

    public function zombieShotOnAPauseCountsForThePlayerAndTheRevealButNotForTheGoal(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::place($ritual);
        $ritual->story->pause(true);
        for ($step = 1; $step <= 5; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
        $zombie = $ritual->broadcast->ofType('flyer')[0]['flyer'] ?? '';

        $points = $ritual->flyers->shoot($zombie, self::JACK);

        Assert::same($points, 2);
        Assert::same($ritual->players->find(self::JACK)?->flyers, 1);
        Assert::same($ritual->cursed->state(1, $ritual->store->story())['slain'] ?? null, 0);
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->slain, 1);
        Assert::count($ritual->broadcast->ofType('shot'), 1);

        // Unpaused, the hit goes to the goal and is counted once
        $ritual->story->pause(false);
        $ritual->flyers->shoot($ritual->broadcast->ofType('flyer')[1]['flyer'] ?? '', self::JACK);

        Assert::same($ritual->cursed->state(1, $ritual->store->story())['slain'] ?? null, 1);
        Assert::same($ritual->reveal->reveal($ritual->store->snapshot())->slain, 2);
    }

    public function sceneIsNotReadyOnAPauseAndCatchesUpOnRelease(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 2);
        $ritual->click('pumpkin-2', 2);
        $ritual->story->pause(true);

        $ritual->runJobs();

        Assert::count($ritual->broadcast->ofType('pumpkin'), 2);
        Assert::blank($ritual->broadcast->ofType('ready'));
        Assert::false($ritual->store->snapshot()->ready());

        $ritual->story->pause(false);

        Assert::count($ritual->broadcast->ofType('ready'), 1);
        Assert::true($ritual->store->snapshot()->ready());
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['scene-ready']);
    }

    public function lowerLimitOnAPauseMovesTheStagesOnlyOnRelease(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2');
        $ritual->story->pause(true);

        $ritual->story->thresholds(0.5);
        $ritual->runJobs();

        Assert::count($ritual->broadcast->ofType('limits'), 1);
        Assert::blank($ritual->broadcast->ofType('pumpkin'));

        $ritual->story->pause(false);
        $ritual->runJobs();

        Assert::count($ritual->broadcast->ofType('pumpkin'), 2);
        Assert::count($ritual->broadcast->ofType('ready'), 1);
    }

    public function barGoalReachedOnAPauseIsReadyOnRelease(): void
    {
        $ritual = self::bar();
        self::place($ritual);
        self::give($ritual);
        $ritual->bar->drink(self::JACK);
        $ritual->story->pause(true);

        $ritual->bar->moveTarget(-3);
        $ritual->limits->nudge();

        Assert::blank($ritual->broadcast->ofType('ready'));

        $ritual->story->pause(false);

        Assert::count($ritual->broadcast->ofType('ready'), 1);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['bar-intro']);
    }

    public function moderatorsWaveIsNotHeldByThePause(): void
    {
        $ritual = self::bar();
        $ritual->story->pause(true);

        $ritual->bar->wave();

        Assert::count($ritual->broadcast->ofType('ready'), 1);
    }

    public function resetAndAgainLiftThePauseWithoutItsEvent(): void
    {
        $ritual = new RitualHarness();
        $ritual->story->thresholds(2.0);
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        $ritual->reset->reset(1);

        Assert::false($ritual->snapshot->create()['paused']);
        Assert::same($ritual->store->story()->k, 2.0);
        Assert::blank($ritual->broadcast->ofType('pause'));

        $ritual->story->pause(true);
        $ritual->reset->reset(2, again: true);

        Assert::false($ritual->store->story()->paused);
        Assert::count($ritual->broadcast->ofType('pause'), 1);
    }

    private static function bar(): RitualHarness
    {
        return new RitualHarness(stageMax: [0, 1, 2]);
    }

    /**
     * Jack stands in the cursed world, finale step 5.
     */
    private static function cursed(): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::place($ritual);
        for ($step = 1; $step <= 5; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }

        return $ritual;
    }

    private static function place(RitualHarness $ritual): void
    {
        $ritual->players->register(self::JACK, 'Jack', self::LOOK);
        $walk = $ritual->walks->move(self::JACK, 20, 90);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * Jack stands at (20, 90) and sets off to (80, 90); half a second later he is on the way.
     */
    private static function walking(RitualHarness $ritual): RitualHarness
    {
        self::place($ritual);
        $ritual->walks->move(self::JACK, 80, 90);
        $ritual->clock->now += 500;

        return $ritual;
    }

    private static function give(RitualHarness $ritual): void
    {
        $ritual->walkerStore->update(static function (array $walkers): array {
            $walkers[self::JACK] = $walkers[self::JACK]->tookMug(true)->clinkedMug(0);

            return [$walkers, null];
        });
    }

    /**
     * @param \Closure(): mixed $call
     * @return string|null The refusal, or the class of the exception that is no refusal.
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (StoryRefusedException|BarRefusedException $e) {
            return $e->error;
        } catch (\Throwable $e) {
            return $e::class;
        }

        return null;
    }
}
