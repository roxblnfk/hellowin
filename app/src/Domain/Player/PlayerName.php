<?php

declare(strict_types=1);

namespace App\Domain\Player;

final class PlayerName
{
    public const MAX_LENGTH = 24;

    /**
     * Letters, digits, single spaces and `_ . -`, with at least one letter or digit; surrounding spaces are trimmed.
     *
     * @return non-empty-string|null Null when the name is not acceptable.
     */
    public static function normalize(mixed $name): ?string
    {
        if (!\is_string($name)) {
            return null;
        }

        $name = \trim($name);
        $valid = \preg_match('/^[\p{L}\p{N} _.\-]{1,' . self::MAX_LENGTH . '}$/u', $name) === 1
            && \preg_match('/[\p{L}\p{N}]/u', $name) === 1
            && !\str_contains($name, '  ');

        return $valid && $name !== '' ? $name : null;
    }
}
