<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Domain\Story\DiceRoller;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DiceRoller::class)]
final class DiceRollerTest
{
    private const PLAYER = 'aaaaaaaaaaaaaaaa';

    public function sameKeyThrowsTheSameDice(): void
    {
        $roller = DiceRoller::withSecret('secret');

        $first = $roller->roll(1, self::PLAYER, 7);

        Assert::same(DiceRoller::withSecret('secret')->roll(1, self::PLAYER, 7), $first);
        Assert::same($roller->roll(1, self::PLAYER, 7), $first);
    }

    public function everyPartOfTheKeyAndTheSecretChangeTheThrows(): void
    {
        $series = static fn(DiceRoller $roller, int $round, string $player): array => \array_map(
            static fn(int $attempt): array => $roller->roll($round, $player, $attempt),
            \range(1, 20),
        );
        $roller = DiceRoller::withSecret('secret');
        $base = $series($roller, 1, self::PLAYER);

        Assert::notSame($series($roller, 2, self::PLAYER), $base);
        Assert::notSame($series($roller, 1, 'bbbbbbbbbbbbbbbb'), $base);
        Assert::notSame($series(DiceRoller::withSecret('another'), 1, self::PLAYER), $base);
    }

    public function facesAreEvenlySpreadFromOneToSix(): void
    {
        $roller = DiceRoller::withSecret('secret');
        $counts = \array_fill(1, 6, 0);
        $throws = 6000;

        for ($attempt = 1; $attempt <= $throws; ++$attempt) {
            foreach ($roller->roll(1, self::PLAYER, $attempt) as $face) {
                ++$counts[$face];
            }
        }

        Assert::same(\array_keys($counts), [1, 2, 3, 4, 5, 6]);
        // 18 000 faces, 3000 expected each; chi-square of five degrees of freedom stays under 20.5 with p = 0.999
        $chi = 0.0;
        foreach ($counts as $count) {
            $chi += ($count - 3000) ** 2 / 3000;
        }
        Assert::float($chi)->lessThan(20.5);
    }

    public function owlRollsSixSixSixOnlyOnItsThirdThrow(): void
    {
        $roller = DiceRoller::withSecret('secret');

        foreach (\range(1, 300) as $round) {
            Assert::notSame($roller->owl($round, 1), DiceRoller::SIX);
            Assert::notSame($roller->owl($round, 2), DiceRoller::SIX);
        }

        Assert::same($roller->owl(1, 3), DiceRoller::SIX);
        Assert::same($roller->owl(1, 1), $roller->owl(1, 1));
    }

    public function owlStepsOverSixSixSix(): void
    {
        [$secret, $round] = self::owlSixSixSix();
        $roller = DiceRoller::withSecret($secret);

        $dice = $roller->owl($round, 1);

        Assert::notSame($dice, DiceRoller::SIX);
        Assert::count($dice, 3);
    }

    /**
     * A secret and round whose first raw triple for the owl's first throw is 6-6-6, so the owl has to step over it.
     *
     * @return array{non-empty-string, int}
     */
    private static function owlSixSixSix(): array
    {
        for ($i = 0; ; ++$i) {
            $secret = 's' . $i;
            $bytes = \array_values(\array_filter(
                \unpack('C*', \hash_hmac('sha256', '1:owl:1', $secret, true)) ?: [],
                static fn(int $byte): bool => $byte < 252,
            ));
            if (\array_map(static fn(int $byte): int => $byte % 6 + 1, \array_slice($bytes, 0, 3)) === [6, 6, 6]) {
                return [$secret, 1];
            }
        }
    }
}
