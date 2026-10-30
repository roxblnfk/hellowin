<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Lock\Locks;
use App\Domain\Story\OwlClock;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\FrozenClock;
use Tests\Fake\RitualHarness;

/**
 * When the owl stops talking (docs/spec/protocol.md, «Автопилот», «Счёт речи»), on the harness's speech table:
 * `bar-intro` talks 6000 ms and holds 3000, `finale-1` talks 5000 and holds 2000 after a delay of 3000; any other line
 * talks the longest, 9000, and holds 3000; the own text — 1000 + 100 per character, within 2000…9000.
 */
#[Test]
#[Covers(OwlClock::class)]
final class OwlClockTest
{
    private const NOW = 1_000_000;

    public function silentOwlHasFinished(): void
    {
        Assert::same(self::clock()[0]->until(), 0);
    }

    public function lineTalksItsLength(): void
    {
        [$owl] = self::clock();

        $owl->line('bar-intro');

        Assert::same($owl->until(), self::NOW + 6000);
    }

    public function delayedLineStartsAfterTheCountdown(): void
    {
        [$owl] = self::clock();

        $owl->line('finale-1');

        Assert::same($owl->until(), self::NOW + 3000 + 5000);
    }

    public function nextLineWaitsForTheBubbleHold(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 1000;

        $owl->line('scene-ready');

        Assert::same($owl->until(), self::NOW + 3000 + 9000);
    }

    public function lateLineStartsAtOnce(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 20000;

        $owl->line('bar-intro');

        Assert::same($owl->until(), self::NOW + 20000 + 6000);
    }

    public function ownTextCutsTheLineOnShowOff(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('scene-ready');
        $clock->now += 3000;

        $owl->text('Hi');

        Assert::same($owl->until(), self::NOW + 3000 + 2000);
    }

    public function newestWaitingLineReplacesTheOneBefore(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 500;
        $owl->line('scene-ready');
        $clock->now += 500;

        $owl->line('bar-intro');
        $clock->now += 3000;
        $owl->line('bar-intro');

        Assert::same($owl->until(), self::NOW + 3000 + 3000 + 6000);
    }

    public function asideTakesTheBubbleButLeavesTheSpeech(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 1000;

        $owl->line('ability', aside: true);

        Assert::same($owl->until(), self::NOW + 6000);
        Assert::same($owl->opens('bar-intro'), self::NOW + 3000);
    }

    public function asideOfTheSilentOwlSaysNothingToWaitFor(): void
    {
        [$owl] = self::clock();

        $owl->line('ability', aside: true);

        Assert::same($owl->until(), 0);
    }

    public function opensAtTheSlotOfTheWaitingLine(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 500;
        $owl->line('scene-ready');
        $clock->now += 500;

        $opens = $owl->opens('finale-1');

        Assert::same($opens, self::NOW + 1000 + 3000 + 1500);
    }

    public function unknownLineTalksTheLongest(): void
    {
        [$owl] = self::clock();

        $owl->line('never-written');

        Assert::same($owl->until(), self::NOW + 9000);
    }

    public function ownTextTalksByItsTrimmedLength(): void
    {
        [$owl, $clock] = self::clock();
        [$long] = self::clock();

        $owl->text('  ' . \str_repeat('ж', 30) . '  ');
        $long->text(\str_repeat('a', 200));

        Assert::same($owl->until(), $clock->now + 1000 + 30 * 100);
        Assert::same($long->until(), $clock->now + 9000);
    }

    public function busyLockLosesTheMarkNotTheLine(): void
    {
        $owl = new OwlClock(new ArrayStorage(), new Locks(new FakeLock(available: false)), RitualHarness::speech(), new FrozenClock(self::NOW));

        $owl->line('bar-intro');

        Assert::same($owl->until(), 0);
    }

    /**
     * @return array{OwlClock, FrozenClock}
     */
    private static function clock(): array
    {
        $clock = new FrozenClock(self::NOW);

        return [new OwlClock(new ArrayStorage(), new Locks(new FakeLock()), RitualHarness::speech(), $clock), $clock];
    }
}
