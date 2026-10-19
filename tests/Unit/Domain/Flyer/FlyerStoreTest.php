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
use Testo\Test;
use Tests\Fake\FakeLock;

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
}
