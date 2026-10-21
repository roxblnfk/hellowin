<?php

declare(strict_types=1);

namespace App\Application\Story;

/**
 * The throw named an attempt that is neither the player's last one nor the next: the client takes `last + 1` and
 * throws again.
 */
final class BadAttemptException extends \RuntimeException
{
    /**
     * @param int<0, max> $last The player's last attempt in the round; 0 — none yet.
     */
    public function __construct(
        public readonly int $last,
    ) {
        parent::__construct('bad attempt');
    }
}
