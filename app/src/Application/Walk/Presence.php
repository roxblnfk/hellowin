<?php

declare(strict_types=1);

namespace App\Application\Walk;

/**
 * Who is connected right now.
 */
interface Presence
{
    /**
     * @return list<non-empty-string> Public ids of the players with at least one connection, each once.
     */
    public function players(): array;
}
