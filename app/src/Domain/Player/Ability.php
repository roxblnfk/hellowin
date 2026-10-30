<?php

declare(strict_types=1);

namespace App\Domain\Player;

/**
 * A player's trick shown to the whole room (docs/spec/scenario.md, «Способности»): one at most, kept across resets.
 */
final class Ability
{
    /** A flock of flyers of the current set takes off from the character. */
    public const FLYERS = 'flyers';

    /** Fart Time over the character, as the elephant's. */
    public const FART = 'fart';

    /** «Мип-мип!» in RoadRunner's voice over the character. */
    public const MEEP = 'meep';

    /** A bolt from the character at the flyer the client names: a shot of the player's. */
    public const LIGHTNING = 'lightning';

    /** The character turns into a pumpkin until it walks again. */
    public const PUMPKIN = 'pumpkin';

    public const ALL = [self::FLYERS, self::FART, self::MEEP, self::LIGHTNING, self::PUMPKIN];

    /**
     * @psalm-assert-if-true self::FLYERS|self::FART|self::MEEP|self::LIGHTNING|self::PUMPKIN $value
     */
    public static function isValid(mixed $value): bool
    {
        return \in_array($value, self::ALL, true);
    }

    /**
     * @return self::FLYERS|self::FART|self::MEEP|self::LIGHTNING|self::PUMPKIN|null
     */
    public static function fromValue(mixed $value): ?string
    {
        return self::isValid($value) ? $value : null;
    }
}
