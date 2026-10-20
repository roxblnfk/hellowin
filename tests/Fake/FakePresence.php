<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Application\Walk\Presence;

final class FakePresence implements Presence
{
    /**
     * @param list<non-empty-string> $online
     */
    public function __construct(
        public array $online = [],
    ) {}

    #[\Override]
    public function players(): array
    {
        return $this->online;
    }
}
