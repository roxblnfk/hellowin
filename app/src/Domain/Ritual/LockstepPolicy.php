<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

use App\Domain\Element\Element;

/**
 * A scene whose last pumpkin stage is 0 can only be the first one: the bar, where the evening gathers before the
 * ritual. It has no pumpkins to move and takes no clicks on them; it gets ready by its drinks, outside the policy.
 *
 * Pumpkins move stage by stage up to the last stage of the current scene and wait there; then the bonfire does the
 * same by its own threshold and stage maximums, and the scene is ready. A scene that does not take the bonfire past
 * its stage is ready with the pumpkins alone. In the final scene the pumpkins stay; the circle is drawn by its own
 * threshold up to its last stage while the finale is at its first step, and makes the scene ready.
 */
final readonly class LockstepPolicy implements RitualPolicy
{
    /** @var non-empty-list<int<0, max>> */
    private array $stageMax;

    /** @var non-empty-list<int<0, max>> */
    private array $bonfireStageMax;

    /**
     * @param positive-int $clicksPerStage Clicks a pumpkin needs to move at k = 1.
     * @param list<int> $stageMax The last pumpkin stage of each scene before the final
     *        one, strictly increasing; its length is the number of the final scene. Only the first may be 0: scene 0
     *        is the bar then.
     * @param positive-int $bonfireClicksPerStage Clicks the bonfire needs to move at k = 1.
     * @param list<int> $bonfireStageMax The last bonfire stage of each scene, never decreasing; scenes past the list
     *        keep its last value. Empty leaves the bonfire at stage 0 everywhere.
     * @param positive-int $circleClicksPerStage Clicks the circle needs to move at k = 1.
     * @param positive-int $circleStages The last circle stage, in the final scene; before it the circle stays at 0.
     */
    public function __construct(
        private int $clicksPerStage,
        array $stageMax,
        private int $bonfireClicksPerStage = 1,
        array $bonfireStageMax = [],
        private int $circleClicksPerStage = 1,
        private int $circleStages = 1,
    ) {
        $previous = -1;
        foreach ($stageMax as $stage) {
            $stage > $previous or throw new \InvalidArgumentException('Stage maximums must be a strictly increasing list of integers from 0.');
            $previous = $stage;
        }
        $stageMax === [] and throw new \InvalidArgumentException('Stage maximums must not be empty.');

        $previous = 0;
        foreach ($bonfireStageMax as $stage) {
            $stage >= $previous or throw new \InvalidArgumentException('Bonfire stage maximums must be a non-decreasing list of integers from 0.');
            $previous = $stage;
        }

        /** @var non-empty-list<int<0, max>> $stageMax */
        $this->stageMax = $stageMax;
        /** @var list<int<0, max>> $bonfireStageMax */
        $this->bonfireStageMax = $bonfireStageMax === [] ? [0] : $bonfireStageMax;
    }

    #[\Override]
    public function pumpkinTransition(Pumpkin $pumpkin, int $scene, float $k = 1.0): ?int
    {
        return $this->transition(Element::TYPE_PUMPKIN, $pumpkin, $scene, $k);
    }

    #[\Override]
    public function centrepieceTransition(string $type, Pumpkin $centrepiece, int $scene, float $k = 1.0): ?int
    {
        return $this->transition($type, $centrepiece, $scene, $k);
    }

    #[\Override]
    public function centrepieceOpen(string $type, RitualSnapshot $ritual): bool
    {
        $centrepiece = $ritual->centrepiece($type);
        if ($centrepiece === null || $centrepiece->stage >= $this->stageMax($ritual->scene, $type)) {
            return false;
        }

        return $type === Element::TYPE_BONFIRE
            ? $this->pumpkinsDone($ritual->scene, $ritual->pumpkins)
            : $this->drawing($ritual);
    }

    #[\Override]
    public function sceneReady(RitualSnapshot $ritual): bool
    {
        // The bar has no pumpkins: it is ready once the room has drunk its goal or the moderator waves, not here
        if ($this->bar($ritual->scene)) {
            return false;
        }
        if ($ritual->scene >= $this->finalScene()) {
            return $this->drawing($ritual)
                && $ritual->circle !== null
                && $ritual->circle->stage >= $this->stageMax($ritual->scene, Element::TYPE_CIRCLE);
        }

        return $this->pumpkinsDone($ritual->scene, $ritual->pumpkins)
            && ($ritual->bonfire === null || $ritual->bonfire->stage >= $this->stageMax($ritual->scene, Element::TYPE_BONFIRE));
    }

    /**
     * The final scene keeps the last stage of the scene before it, for the pumpkins and the bonfire alike; the circle
     * has stages in the final scene only.
     */
    #[\Override]
    public function stageMax(int $scene, string $type = Element::TYPE_PUMPKIN): int
    {
        return match ($type) {
            Element::TYPE_PUMPKIN => $this->stageMax[$this->index($scene, \count($this->stageMax))],
            Element::TYPE_BONFIRE => $this->bonfireStageMax[
                $this->index(\min($scene, $this->finalScene() - 1), \count($this->bonfireStageMax))
            ],
            Element::TYPE_CIRCLE => $scene >= $this->finalScene() ? $this->circleStages : 0,
        };
    }

    #[\Override]
    public function threshold(string $type = Element::TYPE_PUMPKIN, float $k = 1.0): int
    {
        $base = match ($type) {
            Element::TYPE_PUMPKIN => $this->clicksPerStage,
            Element::TYPE_BONFIRE => $this->bonfireClicksPerStage,
            Element::TYPE_CIRCLE => $this->circleClicksPerStage,
        };

        return \max(1, (int) \round((float) $base * $k));
    }

    #[\Override]
    public function bar(int $scene): bool
    {
        return $scene === 0 && $this->stageMax[0] === 0;
    }

    #[\Override]
    public function finalScene(): int
    {
        return \count($this->stageMax);
    }

    /**
     * Pumpkins and the bonfire move before the final scene, the circle only in it.
     *
     * @param Element::TYPE_* $type
     * @return int<1, max>|null
     */
    private function transition(string $type, Pumpkin $element, int $scene, float $k): ?int
    {
        return $scene >= 0
            && ($scene >= $this->finalScene()) === ($type === Element::TYPE_CIRCLE)
            && $element->stage < $this->stageMax($scene, $type)
            && $element->clicks >= $this->threshold($type, $k)
            ? $element->stage + 1
            : null;
    }

    /**
     * @param list<Pumpkin> $pumpkins
     * @return bool Whether every pumpkin has reached the last stage of the scene; never in the final scene.
     */
    private function pumpkinsDone(int $scene, array $pumpkins): bool
    {
        if ($scene < 0 || $scene >= $this->finalScene() || $pumpkins === []) {
            return false;
        }

        $last = $this->stageMax($scene);
        foreach ($pumpkins as $pumpkin) {
            if ($pumpkin->stage < $last) {
                return false;
            }
        }

        return true;
    }

    /**
     * The circle is drawn in the final scene until the moderator takes the first finale step.
     */
    private function drawing(RitualSnapshot $ritual): bool
    {
        return $ritual->scene >= $this->finalScene() && $ritual->story->finale === 0;
    }

    /**
     * @param positive-int $length
     * @return int<0, max>
     */
    private function index(int $scene, int $length): int
    {
        return \max(0, \min($scene, $length - 1));
    }
}
