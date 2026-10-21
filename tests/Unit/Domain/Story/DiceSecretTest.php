<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Lock\Locks;
use App\Domain\Story\DiceSecret;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(DiceSecret::class)]
final class DiceSecretTest
{
    public function configuredSecretWinsAndIsNotStored(): void
    {
        $storage = new ArrayStorage();

        Assert::same((new DiceSecret('mine', $storage, new Locks(new FakeLock())))->get(), 'mine');
        Assert::null($storage->get('hellowin:dice:secret'));
    }

    public function withoutOneEveryWorkerSharesTheFirstRandomSecret(): void
    {
        $storage = new ArrayStorage();
        $lock = new FakeLock();

        $first = (new DiceSecret(null, $storage, new Locks($lock)))->get();

        Assert::string($first)->matchesRegex('/^[A-Za-z0-9+\/]{43}=$/');
        Assert::same((new DiceSecret(null, $storage, new Locks($lock)))->get(), $first);
        Assert::same($storage->get('hellowin:dice:secret'), $first);
    }
}
