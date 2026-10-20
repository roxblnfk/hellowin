<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Player;

use App\Domain\Player\Look;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Look::class)]
final class LookTest
{
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function keepsThePartsAsTheyAre(): void
    {
        $look = Look::fromArray(['hat' => 'top-hat-2'] + self::LOOK);

        Assert::same($look?->toArray(), ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'top-hat-2']);
    }

    #[DataSet([['hat' => 'Pumpkin'] + self::LOOK], 'uppercase')]
    #[DataSet([['hat' => 'witch_hat'] + self::LOOK], 'underscore')]
    #[DataSet([['hat' => ''] + self::LOOK], 'empty part')]
    #[DataSet([['hat' => 'abcdefghijklmnopqrstuvwxy'] + self::LOOK], 'too long')]
    #[DataSet([['hat' => 7] + self::LOOK], 'number')]
    #[DataSet([['hat' => null] + self::LOOK], 'null part')]
    #[DataSet([['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green']], 'missing slot')]
    #[DataSet([['mask' => 'fox'] + self::LOOK], 'unknown slot')]
    #[DataSet(['skeleton'], 'string')]
    #[DataSet([null], 'null')]
    public function rejectsAnythingElse(mixed $value): void
    {
        Assert::null(Look::fromArray($value));
    }
}
