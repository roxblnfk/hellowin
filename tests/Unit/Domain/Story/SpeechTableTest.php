<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Application\Story\OwlLine;
use App\Domain\Story\SpeechTable;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * public/autopilot.json, which the frontend keeps and the server reads at start (docs/spec/protocol.md, «Автопилот»).
 */
#[Test]
#[Covers(SpeechTable::class)]
final class SpeechTableTest
{
    private const FILE = __DIR__ . '/../../../../public/autopilot.json';

    public function everyLineTheServerSaysIsInTheTable(): void
    {
        $table = SpeechTable::fromFile(self::FILE);
        $ids = [
            ...\array_values((new \ReflectionClass(OwlLine::class))->getConstants()),
            ...\array_map(OwlLine::scene(...), \range(1, 4)),
            ...\array_map(OwlLine::finale(...), \range(1, Story::LAST_STEP)),
        ];

        $missing = \array_values(\array_filter($ids, static fn(string $id): bool => !$table->has($id)));

        Assert::same($missing, []);
    }

    public function tableNamesTheStagingOfTheFinaleSteps(): void
    {
        $table = SpeechTable::fromFile(self::FILE);

        Assert::true($table->step(Story::DICE_STEP) > 0);
        Assert::true($table->step(Story::DAWN_STEP) > 0);
        Assert::same($table->step(42), 0);
    }

    public function lineCarriesItsDelay(): void
    {
        $table = SpeechTable::fromArray(['lines' => ['finale-1' => ['ms' => 5000, 'hold' => 2000, 'delay' => 3000], 'scene-1' => ['ms' => 100, 'hold' => 50]]]);

        Assert::same($table->line('finale-1'), ['ms' => 5000, 'hold' => 2000, 'delay' => 3000]);
        Assert::same($table->line('scene-1'), ['ms' => 100, 'hold' => 50, 'delay' => 0]);
    }

    public function unknownLineTalksAsLongAsAnyText(): void
    {
        $table = SpeechTable::fromArray(['showMs' => ['base' => 1000, 'perChar' => 100, 'min' => 2000, 'max' => 9000], 'holdMs' => 3000]);

        Assert::same($table->line('never-written'), ['ms' => 9000, 'hold' => 3000, 'delay' => 0]);
    }

    public function ownTextHoldsNoLongerThanItTalks(): void
    {
        $table = SpeechTable::fromArray(['showMs' => ['base' => 1000, 'perChar' => 100, 'min' => 2000, 'max' => 9000], 'holdMs' => 3000]);

        Assert::same($table->text(' Hi '), ['ms' => 2000, 'hold' => 2000, 'delay' => 0]);
        Assert::same($table->text(\str_repeat('ы', 40)), ['ms' => 5000, 'hold' => 3000, 'delay' => 0]);
    }

    public function missingFileIsAnError(): never
    {
        Expect::exception(\RuntimeException::class);

        SpeechTable::fromFile(__DIR__ . '/no-such-table.json');
    }
}
