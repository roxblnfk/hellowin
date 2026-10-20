<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * When pumpkins and the bonfire move forward and when a scene is ready for the next one. The engine asks the policy
 * before it queues a transition; the transition itself only checks that the state is still one step behind the
 * target. The scene itself moves only when the moderator says so.
 *
 * The bonfire is lit after the pumpkins: in a scene that takes it further, it accepts clicks only once every pumpkin
 * is done, and the scene is ready once the bonfire is done too.
 */
interface RitualPolicy
{
    /**
     * @return int<1, max>|null Stage the pumpkin may move to now, null when it stays.
     */
    public function pumpkinTransition(Pumpkin $pumpkin, int $scene): ?int;

    /**
     * @return int<1, max>|null Stage the bonfire may move to now, null when it stays.
     */
    public function bonfireTransition(Pumpkin $bonfire, int $scene): ?int;

    /**
     * @param list<Pumpkin> $pumpkins Every pumpkin of the ritual.
     * @return bool Whether every pumpkin has reached the last stage of the scene; never in the final scene.
     */
    public function pumpkinsDone(int $scene, array $pumpkins): bool;

    /**
     * @param list<Pumpkin> $pumpkins
     * @return bool Whether a click on the bonfire counts: the pumpkins are done, the bonfire is not.
     */
    public function bonfireOpen(int $scene, array $pumpkins, Pumpkin $bonfire): bool;

    /**
     * @param list<Pumpkin> $pumpkins Every pumpkin of the ritual.
     * @param Pumpkin|null $bonfire Null when the patch has no bonfire.
     * @return bool Whether the scene is done and may move on; never in the final scene.
     */
    public function sceneReady(int $scene, array $pumpkins, ?Pumpkin $bonfire = null): bool;

    /**
     * @return int<0, max> The last stage a pumpkin reaches in the scene.
     */
    public function stageMax(int $scene): int;

    /**
     * @return int<0, max> The last stage the bonfire reaches in the scene.
     */
    public function bonfireStageMax(int $scene): int;

    /**
     * @return positive-int The scene where the ritual ends and nothing moves any more.
     */
    public function finalScene(): int;
}
