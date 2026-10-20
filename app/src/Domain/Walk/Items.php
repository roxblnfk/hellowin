<?php

declare(strict_types=1);

namespace App\Domain\Walk;

/**
 * What every character holds in each scene of the ritual; the frontend draws the item by its id.
 */
final readonly class Items
{
    /**
     * @param list<non-empty-string|null> $byScene Item id per scene number, null for empty hands.
     */
    public function __construct(
        private array $byScene = [],
    ) {}

    /**
     * @return non-empty-string|null Null for empty hands, also in a scene the list does not reach.
     */
    public function forScene(int $scene): ?string
    {
        return $this->byScene[$scene] ?? null;
    }
}
