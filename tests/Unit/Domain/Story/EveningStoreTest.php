<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Lock\Locks;
use App\Domain\Story\EveningStore;
use App\Domain\Story\Reveal;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;

#[Test]
#[Covers(EveningStore::class)]
#[Covers(Reveal::class)]
final class EveningStoreTest
{
    private const JACK = ['id' => 'aaaaaaaaaaaaaaaa', 'name' => 'Jack'];
    private const JILL = ['id' => 'bbbbbbbbbbbbbbbb', 'name' => null];

    public function roundsAddUpAndTheLatestLastPumpkinWins(): void
    {
        $evening = self::evening();

        $evening->fold(1, new Reveal(10, 4, 2, 1, self::JACK, 3, 1, 5, 2));
        $evening->fold(2, new Reveal(6, 3, 1, 0, self::JILL, 1));

        Assert::same($evening->carried(3)?->toArray(), [
            'clicks' => 16, 'calls' => 0, 'jobs' => 7, 'burned' => 3, 'shot' => 1,
            'drinks' => 4, 'slain' => 1, 'damage' => 5, 'rolls' => 2, 'lastPumpkin' => self::JILL,
        ]);
    }

    public function roundWithoutALastPumpkinKeepsTheCarriedOne(): void
    {
        $evening = self::evening();

        $evening->fold(1, new Reveal(1, 1, 0, 0, self::JACK));
        $evening->fold(2, new Reveal(1, 1, 0, 0, null));

        Assert::same($evening->carried(3)?->lastPumpkin, self::JACK);
    }

    public function roundFoldsOnce(): void
    {
        $evening = self::evening();

        $evening->fold(1, new Reveal(5, 1, 0, 0, null));
        $evening->fold(1, new Reveal(5, 1, 0, 0, null));

        Assert::same($evening->carried(2)?->clicks, 5);
    }

    public function nothingIsCarriedIntoTheRoundFoldedOrAfterForget(): void
    {
        $evening = self::evening();
        Assert::null($evening->carried(1));

        $evening->fold(2, new Reveal(5, 1, 0, 0, null));
        Assert::null($evening->carried(2));

        $evening->forget();
        Assert::null($evening->carried(3));
    }

    private static function evening(): EveningStore
    {
        return new EveningStore(new ArrayStorage(), new Locks(new FakeLock()));
    }
}
