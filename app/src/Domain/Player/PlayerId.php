<?php

declare(strict_types=1);

namespace App\Domain\Player;

/**
 * The public id of a player: a hash of the secret the browser keeps in localStorage. Anyone may see the id;
 * the secret cannot be recovered from it, so nobody can connect as another player.
 */
final class PlayerId
{
    /** Hex characters kept from the hash: 64 bits are plenty to tell the players of one event apart. */
    public const LENGTH = 16;

    /**
     * @return non-empty-string|null Null for a missing or malformed secret: the connection stays anonymous.
     */
    public static function fromSecret(mixed $secret): ?string
    {
        if (!\is_string($secret) || \preg_match('/^[A-Za-z0-9-]{16,128}$/', $secret) !== 1) {
            return null;
        }

        /** @var non-empty-string */
        return \substr(\hash('sha256', 'hellowin:player:' . $secret), 0, self::LENGTH);
    }

    /**
     * @psalm-assert-if-true non-empty-string $id
     */
    public static function isValid(mixed $id): bool
    {
        return \is_string($id) && \preg_match('/^[0-9a-f]{' . self::LENGTH . '}$/', $id) === 1;
    }
}
