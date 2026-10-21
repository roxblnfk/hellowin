<?php

declare(strict_types=1);

namespace App\Application\Walk;

/**
 * The player's character is a zombie the server walks: the player can do nothing with it until someone cures it.
 */
final class ZombifiedException extends \RuntimeException
{
    /** @var non-empty-string The error code for the client. */
    public readonly string $error;

    public function __construct()
    {
        $this->error = 'zombified';
        parent::__construct($this->error);
    }
}
