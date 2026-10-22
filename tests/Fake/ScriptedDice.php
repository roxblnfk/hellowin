<?php

declare(strict_types=1);

namespace Tests\Fake;

use Random\Engine;

/**
 * A random engine for the dice: `Randomizer::getInt(1, 6)` over it gives the scripted faces in order, then 1, 2, …, 6
 * round and round. Each output is the face less one as an unsigned 32-bit number, which the randomizer takes modulo 6.
 */
final class ScriptedDice implements Engine
{
    private int $next = 0;

    /**
     * @param list<int<1, 6>> $faces
     */
    public function __construct(
        public array $faces = [],
    ) {}

    #[\Override]
    public function generate(): string
    {
        $face = \array_shift($this->faces) ?? $this->next++ % 6 + 1;

        return \pack('V', $face - 1);
    }
}
