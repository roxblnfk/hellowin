<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

use App\Domain\Element\Element;

/**
 * When pumpkins and the centrepieces move forward and when a scene is ready for the next step. The engine asks the
 * policy before it queues a transition; the transition itself only checks that the state is still one step behind
 * the target. The scene itself moves only when the moderator says so.
 *
 * The centrepieces stand one at a time in the middle of the patch, each with its own threshold and stages. The
 * bonfire is lit after the pumpkins: in a scene that takes it further, it accepts clicks only once every pumpkin is
 * done, and the scene is ready once the bonfire is done too. The magic circle is drawn in the final scene before the
 * first finale step; the final scene is ready once the circle is closed.
 *
 * Every threshold is a limit ({@see \App\Domain\Balance\Limit}) of the figures on the floor scaled by the moderator's
 * multiplier `k` (`Story::$players`, `Story::$k`): the ritual fits the room without restarting it.
 */
interface RitualPolicy
{
    /**
     * @param float $k Multiplier in force.
     * @param int<0, max> $players Figures on the floor the limits are counted for.
     * @return int<1, max>|null Stage the pumpkin may move to now, null when it stays.
     */
    public function pumpkinTransition(Pumpkin $pumpkin, int $scene, float $k = 1.0, int $players = 1): ?int;

    /**
     * @param Element::TYPE_BONFIRE|Element::TYPE_CIRCLE $type
     * @param float $k Multiplier in force.
     * @param int<0, max> $players Figures on the floor the limits are counted for.
     * @return int<1, max>|null Stage the centrepiece may move to now, null when it stays.
     */
    public function centrepieceTransition(string $type, Pumpkin $centrepiece, int $scene, float $k = 1.0, int $players = 1): ?int;

    /**
     * @param Element::TYPE_BONFIRE|Element::TYPE_CIRCLE $type
     * @return bool Whether a click on the centrepiece counts now; false when the ritual has none of the type.
     */
    public function centrepieceOpen(string $type, RitualSnapshot $ritual): bool;

    /**
     * @return bool Whether the scene is done and waits for the moderator: the pumpkins and the bonfire before the
     *         final scene, the circle at the start of the final one.
     */
    public function sceneReady(RitualSnapshot $ritual): bool;

    /**
     * @param Element::TYPE_* $type
     * @return int<0, max> The last stage an element of the type reaches in the scene.
     */
    public function stageMax(int $scene, string $type = Element::TYPE_PUMPKIN): int;

    /**
     * @param Element::TYPE_* $type
     * @param float $k Multiplier in force.
     * @param int<0, max> $players Figures on the floor the limits are counted for; none counts as one.
     * @return positive-int Clicks an element of the type needs to move.
     */
    public function threshold(string $type = Element::TYPE_PUMPKIN, float $k = 1.0, int $players = 1): int;

    /**
     * @return bool Whether the scene is the bar: the evening gathers there before the ritual, it has no pumpkins to
     *         click; it gets ready once the room has drunk its goal.
     */
    public function bar(int $scene): bool;

    /**
     * @return positive-int The scene where the pumpkins stop moving and the finale begins.
     */
    public function finalScene(): int;
}
