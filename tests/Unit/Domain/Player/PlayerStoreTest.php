<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Player;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(PlayerStore::class)]
#[Covers(Player::class)]
final class PlayerStoreTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';

    public function updateCreatesThePlayerAndIndexesItOnce(): void
    {
        $lock = new FakeLock();
        $store = new PlayerStore(new ArrayStorage(), new Locks($lock));

        $store->update(self::A, static fn(Player $p): Player => $p->clicked());
        $store->update(self::A, static fn(Player $p): Player => $p->clicked());
        $store->update(self::B, static fn(Player $p): Player => $p->shotFlyer(3));

        Assert::same($store->ids(), [self::A, self::B]);
        Assert::equals($store->all(), [new Player(self::A, clicks: 2), new Player(self::B, flyers: 1, points: 3)]);
        Assert::blank($lock->held);
    }

    public function findReturnsNullForAnUnknownPlayer(): void
    {
        Assert::null((new PlayerStore(new ArrayStorage(), new Locks(new FakeLock())))->find(self::A));
    }

    public function nameSurvivesLaterChanges(): void
    {
        $store = new PlayerStore(new ArrayStorage(), new Locks(new FakeLock()));

        $store->update(self::A, static fn(Player $p): Player => $p->named('Jack'));
        $store->update(self::A, static fn(Player $p): Player => $p->finishedPumpkin());

        Assert::equals($store->find(self::A), new Player(self::A, 'Jack', finished: 1));
    }

    public function busyPlayerLockFails(): void
    {
        $store = new PlayerStore(new ArrayStorage(), new Locks(new FakeLock(available: false)));

        Expect::exception(LockBusyException::class);

        $store->update(self::A, static fn(Player $p): Player => $p->clicked());
    }
}
