<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * When pumpkins and the scene move forward. The engine asks the policy before it queues a transition;
 * the transition itself only checks that the state is still one step behind the target.
 */
interface RitualPolicy
{
    /**
     * @return int<1, max>|null Stage the pumpkin may move to now, null when it stays.
     */
    public function pumpkinTransition(Pumpkin $pumpkin, int $scene): ?int;

    /**
     * @param list<Pumpkin> $pumpkins Every pumpkin of the ritual.
     * @return int<1, max>|null Scene to move to now, null when the scene stays.
     */
    public function sceneTransition(int $scene, array $pumpkins): ?int;

    /**
     * @return int<0, max> The last stage a pumpkin reaches in the scene.
     */
    public function stageMax(int $scene): int;

    /**
     * @return positive-int The scene where the ritual ends and nothing moves any more.
     */
    public function finalScene(): int;
}
