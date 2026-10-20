<?php

declare(strict_types=1);

namespace App\Domain\Walk;

/**
 * What every character holds: the item of the scene, and in the finale the item of its step once one is given; the
 * frontend draws the item by its id.
 */
final readonly class Items
{
    /**
     * @param list<non-empty-string|null> $byScene Item id per scene number, null for empty hands.
     * @param array<int<0, max>, non-empty-string|null> $byFinaleStep Item id from a finale step on, until a later step
     *        gives another; null for empty hands.
     */
    public function __construct(
        private array $byScene = [],
        private array $byFinaleStep = [],
    ) {}

    /**
     * @param int|null $finale Finale step; null outside the final scene.
     * @return non-empty-string|null Null for empty hands, also in a scene the list does not reach.
     */
    public function held(int $scene, ?int $finale = null): ?string
    {
        $item = $this->byScene[$scene] ?? null;
        if ($finale === null) {
            return $item;
        }

        $from = -1;
        foreach ($this->byFinaleStep as $step => $stepItem) {
            if ($step <= $finale && $step > $from) {
                [$from, $item] = [$step, $stepItem];
            }
        }

        return $item;
    }
}
