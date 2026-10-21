<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Story\MagicWord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

/**
 * The word of the rapier: its forms and typos pass, other words do not (docs/spec/scenario.md, «Шаг 6 — сова и рапира»).
 */
#[Test]
#[Covers(MagicWord::class)]
final class MagicWordTest
{
    #[DataSet(['рапира'])]
    #[DataSet(['Рапиру!'])]
    #[DataSet(['RAPIRA'])]
    #[DataSet(['рапиры'])]
    #[DataSet(['рапирой'])]
    #[DataSet(['  рапира  '])]
    #[DataSet(['ра-пи-ра'])]
    #[DataSet(['rapyra'], 'one typo')]
    #[DataSet(['рапра'], 'a letter short')]
    #[DataSet(['rapiro'], 'the stem')]
    #[DataSet(['рапера'], 'one typo in Cyrillic')]
    #[DataSet(['apira'], 'the first letter missed')]
    #[DataSet(['Rapier'], 'sounds the same')]
    public function rightWords(string $text): void
    {
        Assert::true(MagicWord::matches($text), MagicWord::normalize($text));
    }

    #[DataSet(['пиво'])]
    #[DataSet(['шпага'])]
    #[DataSet(['roadrunner'])]
    #[DataSet(['rap'], 'too short')]
    #[DataSet(['rpr'], 'too short even if it sounds alike')]
    #[DataSet(['рапунцель'])]
    #[DataSet(['paris'])]
    #[DataSet(['пира'], 'two edits away')]
    #[DataSet(['рапа'], 'two edits away')]
    #[DataSet(['рапорт'], 'two edits away')]
    #[DataSet(['сапфира'])]
    #[DataSet(['raptor'])]
    #[DataSet(['!!!'], 'no letters')]
    #[DataSet(['12345'], 'no letters')]
    public function wrongWords(string $text): void
    {
        Assert::false(MagicWord::matches($text), MagicWord::normalize($text));
    }

    public function normalizationSpellsCyrillicInLatinLetters(): void
    {
        Assert::same(MagicWord::normalize('Щука, ёж и Яша!'), 'shchukaezhiyasha');
        Assert::same(MagicWord::normalize('RAPIRA 42'), 'rapira');
    }
}
