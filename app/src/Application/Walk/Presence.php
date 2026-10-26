<?php

declare(strict_types=1);

namespace App\Application\Walk;

/**
 * Who is connected right now.
 */
interface Presence
{
    public function online(): Online;
}
