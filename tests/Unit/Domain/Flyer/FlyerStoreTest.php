<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Flyer;

use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\Point;
use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Fiber\Coroutine;
use Testo\Fiber\RunInFiber;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\YieldingLock;

#[Test]
#[Covers(FlyerStore::class)]
final class FlyerStoreTest
{
    public function firstShotHitsTheSecondMisses(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));

        Assert::equals($store->shoot('f-00000001', 1_000), self::flyer('f-00000001'));
        Assert::null($store->shoot('f-00000001', 1_001));
    }

    public function shotAfterTheFlightAndItsLatencyMisses(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));

        Assert::null($store->shoot('f-00000001', 5_000 + 500));
    }

    public function lateShotWithinTheLatencyHits(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));

        Assert::notNull($store->shoot('f-00000001', 5_000 + 499));
    }

    public function wandererOutlivesTheSweepAndEndsShotOrRetired(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));
        $store->add(Flyer::wanderer('f-0000000a', 1, new Point(50, 80), 2, 1_000));
        $store->add(Flyer::wanderer('f-0000000b', 1, new Point(30, 70), 2, 1_000));

        Assert::same(\array_map(static fn(Flyer $f): string => $f->id, $store->sweep(PHP_INT_MAX - 1_000)), ['f-00000001']);
        Assert::same(\array_map(static fn(Flyer $f): string => $f->id, $store->wanderers()), ['f-0000000a', 'f-0000000b']);

        Assert::notNull($store->shoot('f-0000000a', 999_999_999));
        Assert::null($store->shoot('f-0000000a', 999_999_999));
        Assert::same(\array_map(static fn(Flyer $f): string => $f->id, $store->retire()), ['f-0000000b']);
        Assert::blank($store->retire());
        Assert::blank($store->wanderers());
        Assert::null($store->shoot('f-0000000b', 1_000));
    }

    public function sweepReturnsOnlyEscapedFlyersAndOnlyOnce(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));
        $store->add(self::flyer('f-00000002'));
        $store->add(self::flyer('f-00000003', launchedAt: 10_000));
        $store->shoot('f-00000002', 2_000);

        Assert::blank($store->sweep(5_499));
        $escaped = $store->sweep(5_500);
        $again = $store->sweep(60_000);

        Assert::same(\array_map(static fn(Flyer $f): string => $f->id, $escaped), ['f-00000001']);
        Assert::same(\array_map(static fn(Flyer $f): string => $f->id, $again), ['f-00000003']);
        Assert::blank($store->sweep(120_000));
    }

    public function elephantIsNeverShotDownAndFartsOnce(): void
    {
        $store = self::store();
        $store->add(self::elephant('f-0000000e'));

        Assert::null($store->shoot('f-0000000e', 2_000));
        Assert::same($store->fart('f-0000000e', 2_000)?->id, 'f-0000000e');
        Assert::null($store->fart('f-0000000e', 2_001));
        Assert::same(\array_map(static fn(Flyer $f): string => $f->id, $store->sweep(5_500)), ['f-0000000e'], 'escapes, farted or not');
    }

    #[RunInFiber]
    public function twoShotsAtOnceMakeTheElephantFartOnce(): void
    {
        $lock = new YieldingLock();
        $store = new FlyerStore(new ArrayStorage(), new Locks($lock));
        $store->add(self::elephant('f-0000000e'));

        $farts = Coroutine::concurrently(
            static fn(): ?Flyer => $store->fart('f-0000000e', 2_000),
            static fn(): ?Flyer => $store->fart('f-0000000e', 2_000),
        );

        Assert::contains($lock->waited, 'flyer:f-0000000e', 'the second shot came while the first was inside');
        Assert::count(\array_filter($farts), 1);
    }

    public function elephantFartsUntilItsFlightAndLatencyAreOver(): void
    {
        $store = self::store();
        $store->add(self::elephant('f-0000000e'));
        $store->add(self::elephant('f-0000000f'));

        Assert::notNull($store->fart('f-0000000e', 5_000 + 499));
        Assert::null($store->fart('f-0000000f', 5_000 + 500));
    }

    public function onlyAnElephantFarts(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));

        Assert::null($store->fart('f-00000001', 2_000));
        Assert::null($store->fart('f-missing', 2_000));
        Assert::notNull($store->shoot('f-00000001', 2_000));
    }

    public function lastElephantOutlivesItsFlight(): void
    {
        $store = self::store();
        Assert::null($store->lastElephant());

        $store->add(self::elephant('f-0000000e'));
        $store->add(self::elephant('f-0000000f', launchedAt: 3_000));
        $store->add(self::flyer('f-00000001', launchedAt: 4_000));
        $store->sweep(60_000);

        Assert::same([$store->lastElephant()?->id, $store->lastElephant()?->launchedAt], ['f-0000000f', 3_000]);
    }

    public function sweptFlyerCannotBeShot(): void
    {
        $store = self::store();
        $store->add(self::flyer('f-00000001'));
        $store->sweep(5_500);

        Assert::null($store->shoot('f-00000001', 1_000));
    }

    public function malformedIdTakesNoLock(): void
    {
        $lock = new FakeLock();

        Assert::null((new FlyerStore(new ArrayStorage(), new Locks($lock)))->shoot('pumpkin:1', 1_000));
        Assert::same($lock->acquired, 0);
    }

    public function busyFlyerLockFails(): void
    {
        $store = new FlyerStore(new ArrayStorage(), new Locks(new FakeLock(available: false)));

        Expect::exception(LockBusyException::class);

        $store->shoot('f-00000001', 1_000);
    }

    private static function store(): FlyerStore
    {
        return new FlyerStore(new ArrayStorage(), new Locks(new FakeLock()));
    }

    /**
     * Flies from 1000 to 5000 ms.
     *
     * @param non-empty-string $id
     */
    private static function flyer(string $id, int $launchedAt = 1_000): Flyer
    {
        return new Flyer($id, 1, new Point(-8, 20), new Point(108, 10), null, null, 4_000, 2, $launchedAt);
    }

    /**
     * Flies from 1000 to 5000 ms.
     *
     * @param non-empty-string $id
     */
    private static function elephant(string $id, int $launchedAt = 1_000): Flyer
    {
        return new Flyer($id, 0, new Point(-10, 12), new Point(110, 14), null, null, 4_000, 0, $launchedAt, Flyer::KIND_ELEPHANT);
    }
}
