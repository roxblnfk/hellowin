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
     * @param \Throwable|null $error Thrown by every read in place of the presence, as by an unreachable Centrifugo.
     */
    public function __construct(
        public array $online = [],
        public ?int $connections = null,
        public ?\Throwable $error = null,
    ) {}

    #[\Override]
    public function online(): Online
    {
        $this->error === null or throw $this->error;

        return new Online($this->online, $this->connections ?? \count($this->online));
    }
}
