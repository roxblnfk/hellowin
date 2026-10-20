<?php

declare(strict_types=1);

namespace App\Application\Walk;

/**
 * The clink cannot start now; the error is the code for the client: `not cursed`, `no target` or `busy`.
 */
final class ClinkRefusedException extends \RuntimeException
{
    /**
     * @param non-empty-string $error
     */
    public function __construct(
        public readonly string $error,
    ) {
        parent::__construct($error);
    }
}
