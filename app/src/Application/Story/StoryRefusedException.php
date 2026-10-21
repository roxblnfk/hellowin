<?php

declare(strict_types=1);

namespace App\Application\Story;

/**
 * The console call, or the player's move of the rapier step, does not fit the story now; the message is the error code
 * for the client.
 */
final class StoryRefusedException extends \RuntimeException
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
