<?php

declare(strict_types=1);

namespace App\Application\Chat;

/**
 * A chat call the server does not take; the message is the error code for the client.
 */
final class ChatRefusedException extends \RuntimeException
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
