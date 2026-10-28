<?php

declare(strict_types=1);

namespace App\Application\Story;

/**
 * The owl has not reached the step's instruction yet: the dice or the word open at `$openAt`. Nothing is spent.
 */
final class WaitException extends \RuntimeException
{
    /**
     * @param int $openAt Unix time in milliseconds the input opens.
     */
    public function __construct(
        public readonly int $openAt,
    ) {
        parent::__construct('wait');
    }
}
