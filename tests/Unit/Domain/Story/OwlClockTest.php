<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Lock\Locks;
use App\Domain\Story\OwlClock;
use App\Domain\Story\SpeechTable;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\FrozenClock;
use Tests\Fake\RitualHarness;

/**
 * When the owl stops talking (docs/spec/protocol.md, «Автопилот», «Счёт речи»), on the harness's speech table:
 * `bar-intro` talks 6000 ms and holds 3000, `finale-1` talks 5000 and holds 2000 after a delay of 3000, `finale-6` talks
 * 9000 and holds 3000 after 2650, leading 4000 to its instruction; `ability` is a line aside; any other line talks the
 * longest, 9000, and holds 3000; the own text — 1000 + 100 per character, within 2000…9000.
 */
#[Test]
#[Covers(OwlClock::class)]
final class OwlClockTest
{
    private const NOW = 1_000_000;
    private const TABLE = __DIR__ . '/../../../../public/autopilot.json';

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

    public function asideIntoABusyBubbleIsNotSaid(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 1000;

        $start = $owl->line('ability');

        Assert::null($start);
        Assert::same($owl->until(), self::NOW + 6000);
        Assert::same($owl->opens('scene-ready'), self::NOW + 3000);
    }

    public function asideWhileTheStoryStillSpeaksIsNotSaid(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 4000;

        Assert::null($owl->line('ability'));
        Assert::same($owl->opens('scene-ready'), self::NOW + 4000);
    }

    public function asideDoesNotPushOutTheWaitingLine(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 1000;
        $owl->line('scene-ready');
        $clock->now += 1000;

        Assert::null($owl->line('ability'));
        Assert::same($owl->until(), self::NOW + 3000 + 9000);
    }

    public function asideIntoAFreeBubbleTakesItButLeavesTheSpeech(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 7000;

        $start = $owl->line('ability');

        Assert::same($start, self::NOW + 7000);
        Assert::same($owl->until(), self::NOW + 6000);
    }

    public function asideOfTheSilentOwlSaysNothingToWaitFor(): void
    {
        [$owl] = self::clock();

        Assert::same($owl->line('ability'), self::NOW);
        Assert::same($owl->until(), 0);
    }

    public function storyLineAfterTheStoryCutsTheAsideOff(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('ability');
        $clock->now += 1000;

        $owl->line('scene-ready');

        Assert::same($owl->until(), self::NOW + 1000 + 9000);
    }

    public function lineInPartsHoldsTheBubbleThroughThem(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('finale-6');
        $clock->now += 5000;

        $owl->line('scene-ready');

        Assert::same($owl->until(), self::NOW + 2650 + 3000 + 9000);
    }

    public function opensAtTheInstructionWhateverTheLineSays(): void
    {
        [$owl, $clock] = self::clock();
        $owl->line('bar-intro');
        $clock->now += 1000;

        $opens = $owl->opens('finale-6', 4, 6);

        Assert::same($owl->line('finale-6', [], 4, 6), $opens);
        Assert::same($opens, self::NOW + 1000 + 2650 + 4000);
    }

    /**
     * Events replayed through `createSpeech` of public/dev/autopilot.js on the same table give these states (KV
     * `hellowin:owl`, ms from the first event) and starts: a waiting line replaced, asides dropped and said, a story line
     * cutting an aside off once the story is over, the variants of the scene and finale step, placeholders filled.
     */
    public function realTableReplaysAsTheClientReference(): void
    {
        $clock = new FrozenClock(self::NOW);
        $storage = new ArrayStorage();
        $owl = new OwlClock($storage, new Locks(new FakeLock()), SpeechTable::fromFile(self::TABLE), $clock);
        $events = [
            [0, 'scene-ready', [], 2, null, 0, [12242, 5600, 0]],
            [1000, 'ability', ['player' => ['id' => 'a', 'name' => 'Jack'], 'ability' => 'fart', 'n' => 11], 2, null, null, [12242, 5600, 0]],
            [2000, 'bonfire-done', ['player' => ['id' => 'a', 'name' => '  Jill  ']], 2, null, 5600, [17210, 11200, 5600]],
            [2500, 'scene-3', [], 3, null, 5600 + 13190, [27872, 24390, 5600]],
            [40000, 'witch-down', [], 3, null, 40000, [27872, 45600, 40000]],
            [41000, 'dice-win', ['player' => ['id' => 'a', 'name' => ''], 'attempt' => 12], 4, 1, 41000, [54980, 46600, 41000]],
            [41500, 'hurry', [], 4, 5, 46600, [62950, 52200, 46600]],
            [70000, 'ability', ['player' => ['id' => 'b', 'name' => 'Ann'], 'ability' => 'meep', 'n' => 3], 4, 5, 70000, [62950, 75600, 70000]],
            [72000, 'finale-6', [], 4, 6, 74650 + 17614, [102768, 97864, 72000]],
            [80000, 'word-hint', [], 4, 6, 97864, [109474, 103464, 97864]],
            [81000, 'ability', ['player' => ['id' => 'b', 'name' => 'Ann'], 'ability' => 'pumpkin', 'n' => 0], 4, 6, null, [109474, 103464, 97864]],
        ];

        $said = [];
        foreach ($events as [$at, $id, $params, $scene, $step]) {
            $clock->now = self::NOW + $at;
            $start = $owl->line($id, $params, $scene, $step);
            /** @var array{until: int, hold: int, slot: int} $state */
            $state = $storage->get('hellowin:owl');
            $said[] = [$start === null ? null : $start - self::NOW, [$state['until'] - self::NOW, $state['hold'] - self::NOW, $state['slot'] - self::NOW]];
        }

        Assert::same($said, \array_map(static fn(array $event): array => [$event[5], $event[6]], $events));
        $clock->now = self::NOW + 90000;
        Assert::same($owl->opens('finale-7', 4, 6), self::NOW + 97864);
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
