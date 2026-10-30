<?php

declare(strict_types=1);

namespace App\Application\Ability;

/**
 * The cooldown of the player's ability is not over: nothing is used.
 */
final class AbilityBusyException extends \RuntimeException
{
    /**
     * @param int $readyAt Unix time in milliseconds the ability may be used again.
     */
    public function __construct(
        public readonly int $readyAt,
    ) {
        parent::__construct('busy');
    }
}
