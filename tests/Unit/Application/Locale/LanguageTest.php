<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Locale;

use App\Application\Locale\Language;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Language::class)]
final class LanguageTest
{
    #[DataSet(['en', Language::En])]
    #[DataSet([' RU ', Language::Ru])]
    #[DataSet(['de', null])]
    #[DataSet(['', null])]
    #[DataSet([['en'], null])]
    #[DataSet([null, null])]
    public function inputTakesOnlyAKnownCode(mixed $value, ?Language $expected): void
    {
        Assert::same(Language::fromInput($value), $expected);
    }

    #[DataSet(['en-US,en;q=0.9,ru;q=0.8', Language::En])]
    #[DataSet(['de-DE,de;q=0.9,ru;q=0.5,en;q=0.7', Language::En])]
    #[DataSet(['ru;q=0.3, en-GB;q=0.6', Language::En])]
    #[DataSet(['en;q=0.4,ru', Language::Ru])]
    #[DataSet(['ru, en', Language::Ru])]
    #[DataSet(['en, ru', Language::En])]
    #[DataSet(['EN-us', Language::En])]
    #[DataSet(['en;q=0, ru;q=0.1', Language::Ru])]
    #[DataSet(['en;q=0', null])]
    #[DataSet(['de, fr;q=0.8', null])]
    #[DataSet(['*', null])]
    #[DataSet(['', null])]
    public function acceptLanguageGivesTheHeaviestKnownLanguage(string $header, ?Language $expected): void
    {
        Assert::same(Language::fromAcceptLanguage($header), $expected);
    }
}
