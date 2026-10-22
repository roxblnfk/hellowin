<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarRefusedException;
use App\Application\Hellowin\UnknownElementException;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Application\Walk\NoCharacterException;
use App\Domain\Flyer\Flyer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The pause of the scene's progress (docs/spec/protocol.md, «Пауза прогресса»): the room's clicks, sips, strikes and
 * throws are refused after their own checks, no goal makes a scene ready, the sky goes on, and lifting the pause catches
 * up with what the room reached.
 */
#[Test]
#[Covers(StoryService::class)]
#[Covers(LimitsService::class)]
final class PauseTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function clickIsRefusedAfterTheElementCheckAndTheWalkGoesOn(): void
    {
        $ritual = self::walking(new RitualHarness());
        $walk = $ritual->walkerStore->all()[self::JACK]->walk;
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        Assert::same(self::refusal(static fn() => $ritual->clicks->click('ghost-42', self::JACK)), UnknownElementException::class);
        Assert::same(self::refusal(static fn() => $ritual->clicks->click('pumpkin-1', self::JACK)), 'paused');
        Assert::same(self::refusal(static fn() => $ritual->clicks->click('pumpkin-1')), 'paused');

        Assert::blank($ritual->broadcast->published);
        Assert::blank($ritual->queue->pushed);
        Assert::same($ritual->store->snapshot()->pumpkins[0]->clicks, 0);
        Assert::equals($ritual->walkerStore->all()[self::JACK]->walk, $walk);
    }

    public function drinkIsRefusedAfterItsOwnChecksAndKeepsTheMug(): void
    {
        $ritual = self::walking(self::bar());
        $ritual->story->pause(true);
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'no beer');
        self::give($ritual);
        $walk = $ritual->walkerStore->all()[self::JACK]->walk;
        $ritual->broadcast->published = [];

        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::JACK)), 'paused');

        Assert::blank($ritual->broadcast->published);
        Assert::same($ritual->walkerStore->all()[self::JACK]->item, 'beer');
        Assert::equals($ritual->walkerStore->all()[self::JACK]->walk, $walk);
        Assert::same($ritual->barStore->drinks(1), 0);
    }

    public function strikeIsRefusedAfterItsOwnChecks(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::place($ritual);
        for ($step = 1; $step <= 6; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
        $ritual->story->pause(true);
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike('bbbbbbbbbbbbbbbb')), NoCharacterException::class);
        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JACK)), 'not now');
        Assert::true($ritual->rapier->word(self::JACK, 'Рапира'));
        $hp = $ritual->rapier->state(1)['hp'] ?? null;
        $ritual->broadcast->published = [];

        Assert::same(self::refusal(static fn() => $ritual->rapier->strike(self::JACK)), 'paused');

        Assert::blank($ritual->broadcast->ofType('strike'));
        Assert::same($ritual->rapier->state(1)['hp'] ?? null, $hp);
    }

    public function newThrowIsRefusedWhileARepeatStillAnswers(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        self::place($ritual);
        $ritual->story->finaleStep(1, force: true);
        $ritual->clock->now += 10_000;
        $first = $ritual->dice->roll(self::JACK, 1);
        $ritual->clock->now += 10_000;
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        Assert::same($ritual->dice->roll(self::JACK, 1), $first);
        Assert::same(self::refusal(static fn() => $ritual->dice->roll(self::JACK, 2)), 'paused');

        Assert::blank($ritual->broadcast->published);
        Assert::same($ritual->diceStore->get(1)->last(self::JACK), 1);
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

    public function zombieShotOnAPauseCountsForThePlayerButNotForTheGoal(): void
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
        Assert::count($ritual->broadcast->ofType('shot'), 1);
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
