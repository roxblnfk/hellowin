<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ritual;

use App\Domain\Ritual\Pumpkin;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Story;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(RitualStore::class)]
final class RitualStoreTest
{
    public function freshStoreIsRoundOneSceneZero(): void
    {
        $snapshot = self::store()->snapshot();

        Assert::same($snapshot->round, 1);
        Assert::same($snapshot->scene, 0);
        Assert::equals($snapshot->pumpkins, [new Pumpkin('a'), new Pumpkin('b')]);
    }

    public function clickCountsPerPumpkin(): void
    {
        $store = self::store();

        $store->click('a');
        $outcome = $store->click('a');
        $store->click('b');

        Assert::equals($outcome->pumpkin, new Pumpkin('a', 0, 2, 2));
        Assert::same($outcome->round, 1);
        Assert::same($outcome->scene, 0);
        Assert::equals($store->snapshot()->pumpkin('b'), new Pumpkin('b', 0, 1, 1));
    }

    public function advancingAPumpkinStartsItsClicksOver(): void
    {
        $store = self::store();
        $store->click('a');
        $store->click('a');

        $moved = $store->advancePumpkin(1, 'a', 1, 1);

        Assert::equals($moved, new Pumpkin('a', 1, 0, 2, 1));
        Assert::equals($store->snapshot()->pumpkin('a'), new Pumpkin('a', 1, 0, 2, 1));
    }

    public function repeatedPumpkinTransitionIsRejected(): void
    {
        $store = self::store();
        $store->advancePumpkin(1, 'a', 1, 1);
        $store->click('a');

        Assert::null($store->advancePumpkin(1, 'a', 1, 1));
        Assert::equals($store->snapshot()->pumpkin('a'), new Pumpkin('a', 1, 1, 1));
    }

    public function pumpkinCannotSkipAStage(): void
    {
        Assert::null(self::store()->advancePumpkin(1, 'a', 2, 1));
    }

    public function transitionOfAnotherRoundIsRejected(): void
    {
        $store = self::store();
        $store->reset(1);

        Assert::null($store->advancePumpkin(1, 'a', 1, 1));
        Assert::false($store->advanceScene(1, 1));
        Assert::equals($store->snapshot()->pumpkin('a'), new Pumpkin('a'));
    }

    public function sceneMovesOneStepAtATime(): void
    {
        $store = self::store();

        Assert::false($store->advanceScene(1, 2));
        Assert::true($store->advanceScene(1, 1));
        Assert::false($store->advanceScene(1, 1));
        Assert::same($store->snapshot()->scene, 1);
    }

    public function resetStartsTheNextRoundFromScratch(): void
    {
        $store = self::store();
        $store->click('a');
        $store->advancePumpkin(1, 'b', 1, 1);
        $store->advanceScene(1, 1);

        $round = $store->reset(1);

        $snapshot = $store->snapshot();
        Assert::same($round, 2);
        Assert::same($snapshot->round, 2);
        Assert::same($snapshot->scene, 0);
        Assert::equals($snapshot->pumpkins, [new Pumpkin('a'), new Pumpkin('b')]);
    }

    public function sceneIsMarkedReadyOnce(): void
    {
        $store = self::store();

        Assert::true($store->markReady(1, 0, 'aaaaaaaaaaaaaaaa'));
        Assert::false($store->markReady(1, 0, 'bbbbbbbbbbbbbbbb'));
        Assert::false($store->markReady(1, 1, null));
        Assert::false($store->markReady(2, 0, null));

        $snapshot = $store->snapshot();
        Assert::true($snapshot->ready());
        Assert::same($snapshot->story->lastPumpkin, 'aaaaaaaaaaaaaaaa');
    }

    public function nextSceneWaitsAgainAndTheFinalOneStartsTheFinale(): void
    {
        $store = self::store();
        $store->markReady(1, 0, null);

        $store->advanceScene(1, 1);
        Assert::false($store->snapshot()->ready());
        Assert::null($store->snapshot()->story->finale);

        $store->advanceScene(1, 2, final: true);
        Assert::same($store->snapshot()->story->finale, 0);
    }

    public function resetStartsTheStoryOverButKeepsThePause(): void
    {
        $store = self::store();
        $store->markReady(1, 0, 'aaaaaaaaaaaaaaaa');
        $store->updateStory(static fn(Story $story): Story => $story->withPaused(true)->withZombies(true));

        $store->reset(1);

        Assert::equals($store->snapshot()->story, new Story(paused: true));
    }

    public function storyChangeReportsBothSides(): void
    {
        $store = self::store();

        [$before, $after] = $store->updateStory(static fn(Story $story): Story => $story->withZombies(true));

        Assert::false($before->zombies);
        Assert::true($after->zombies);
        Assert::true($store->snapshot()->story->zombies);
    }

    public function resetRunsOncePerRound(): void
    {
        $store = self::store();

        Assert::same($store->reset(1), 2);
        Assert::null($store->reset(1));
        Assert::same($store->snapshot()->round, 2);
    }

    public function resetHoldsTheSceneAndEveryPumpkinLock(): void
    {
        $lock = new FakeLock();
        $store = self::store($lock);
        $lock->held['pumpkin:b'] = 'someone-else';

        try {
            $store->reset(1);
            Assert::fail('Reset must not run while a pumpkin is locked.');
        } catch (RitualBusyException) {
        }

        Assert::same($lock->held, ['pumpkin:b' => 'someone-else']);
        Assert::same($store->snapshot()->round, 1);
    }

    public function busyPumpkinLockFailsTheClick(): never
    {
        $store = self::store(new FakeLock(available: false));

        Expect::exception(RitualBusyException::class);

        $store->click('a');
    }

    public function locksAreReleased(): void
    {
        $lock = new FakeLock();
        $store = self::store($lock);

        $store->click('a');
        $store->advancePumpkin(1, 'a', 1, 1);
        $store->advanceScene(1, 1);
        $store->reset(1);

        Assert::same($lock->acquired, 6);
        Assert::same($lock->released, 6);
        Assert::blank($lock->held);
    }

    public function unknownPumpkinIsRejected(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        self::store()->click('ghost');
    }

    public function bonfireKeepsItsOwnKeyAndLock(): void
    {
        $storage = new ArrayStorage();
        $lock = new FakeLock();
        $store = new RitualStore($storage, $lock, ['a'], bonfire: 'fire');
        $lock->held['bonfire'] = 'someone-else';

        try {
            $store->click('fire');
            Assert::fail('The bonfire click must wait for the bonfire lock.');
        } catch (RitualBusyException) {
        }
        unset($lock->held['bonfire']);
        $store->click('fire');
        $store->advancePumpkin(1, 'fire', 1, 1);

        Assert::same($storage->get('hellowin:bonfire'), ['stage' => 1, 'clicks' => 0, 'accepted' => 1, 'spent' => 1]);
        Assert::equals($store->snapshot()->bonfire, new Pumpkin('fire', 1, 0, 1, 1));
        Assert::equals($store->snapshot()->pumpkins, [new Pumpkin('a')]);
    }

    public function circleKeepsItsOwnKeyAndLockBesideTheBonfire(): void
    {
        $storage = new ArrayStorage();
        $lock = new FakeLock();
        $store = new RitualStore($storage, $lock, ['a'], bonfire: 'fire', circle: 'ring');
        $lock->held['circle'] = 'someone-else';

        try {
            $store->click('ring');
            Assert::fail('The circle click must wait for the circle lock.');
        } catch (RitualBusyException) {
        }
        $store->click('fire');
        unset($lock->held['circle']);
        $store->click('ring');
        $store->advancePumpkin(1, 'ring', 1, 1);

        Assert::same($storage->get('hellowin:circle'), ['stage' => 1, 'clicks' => 0, 'accepted' => 1, 'spent' => 1]);
        Assert::equals($store->snapshot()->circle, new Pumpkin('ring', 1, 0, 1, 1));
        Assert::equals($store->snapshot()->bonfire, new Pumpkin('fire', 0, 1, 1));

        $store->reset(1);

        Assert::equals($store->snapshot()->circle, new Pumpkin('ring'));
    }

    public function refusedClickWritesNothing(): void
    {
        $store = new RitualStore(new ArrayStorage(), new FakeLock(), ['a'], bonfire: 'fire');

        Assert::null($store->click('fire', static fn(): bool => false));
        Assert::equals($store->snapshot()->bonfire, new Pumpkin('fire'));
    }

    public function bonfireOpensOncePerSceneAndNotInAReadyOne(): void
    {
        $store = new RitualStore(new ArrayStorage(), new FakeLock(), ['a'], bonfire: 'fire');

        Assert::true($store->markBonfireOpen(1, 0));
        Assert::false($store->markBonfireOpen(1, 0));
        Assert::false($store->markBonfireOpen(2, 0));
        $store->advanceScene(1, 1);
        Assert::null($store->snapshot()->story->bonfire);
        $store->markReady(1, 1, null);
        Assert::false($store->markBonfireOpen(1, 1));
    }

    private static function store(FakeLock $lock = new FakeLock()): RitualStore
    {
        return new RitualStore(new ArrayStorage(), $lock, ['a', 'b']);
    }
}
