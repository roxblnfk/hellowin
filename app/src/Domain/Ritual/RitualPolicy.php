<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * When pumpkins move forward and when a scene is ready for the next one. The engine asks the policy before it queues
 * a pumpkin transition; the transition itself only checks that the state is still one step behind the target. The
 * scene itself moves only when the moderator says so.
 */
interface RitualPolicy
{
    /**
     * @return int<1, max>|null Stage the pumpkin may move to now, null when it stays.
     */
    public function pumpkinTransition(Pumpkin $pumpkin, int $scene): ?int;

    /**
     * @param list<Pumpkin> $pumpkins Every pumpkin of the ritual.
     * @return bool Whether the scene is done and may move on; never in the final scene.
     */
    public function sceneReady(int $scene, array $pumpkins): bool;

    /**
     * @return int<0, max> The last stage a pumpkin reaches in the scene.
     */
    public function stageMax(int $scene): int;

    /**
     * @return positive-int The scene where the ritual ends and nothing moves any more.
     */
    public function finalScene(): int;
}
