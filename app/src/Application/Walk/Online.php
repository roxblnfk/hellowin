<?php

declare(strict_types=1);

namespace App\Application\Walk;

/**
 * Who is connected to the channel right now, as one read of its presence counts it.
 */
final readonly class Online
{
    /**
     * @param list<non-empty-string> $players Public ids of the players with at least one connection, each once.
     * @param int<0, max> $connections Every connection: each tab, anonymous ones included.
     */
    public function __construct(
        public array $players,
        public int $connections,
    ) {}

    /**
     * @return array{players: int<0, max>, connections: int<0, max>}
     */
    public function toArray(): array
    {
        return ['players' => \count($this->players), 'connections' => $this->connections];
    }
}
