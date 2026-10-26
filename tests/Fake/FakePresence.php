<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Application\Walk\Online;
use App\Application\Walk\Presence;

final class FakePresence implements Presence
{
    /**
     * @param list<non-empty-string> $online
     * @param int<0, max>|null $connections Null — one connection per player.
     */
    public function __construct(
        public array $online = [],
        public ?int $connections = null,
    ) {}

    #[\Override]
    public function online(): Online
    {
        return new Online($this->online, $this->connections ?? \count($this->online));
    }
}
