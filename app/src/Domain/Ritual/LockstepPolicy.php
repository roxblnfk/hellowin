<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * Pumpkins move stage by stage up to the last stage of the current scene and wait there; then the bonfire does the
 * same by its own threshold and stage maximums, and the scene is ready. A scene that does not take the bonfire past
 * its stage is ready with the pumpkins alone. Nothing moves in the final scene.
 */
final readonly class LockstepPolicy implements RitualPolicy
{
    /** @var non-empty-list<positive-int> */
    private array $stageMax;

    /** @var non-empty-list<int<0, max>> */
    private array $bonfireStageMax;

    /**
     * @param positive-int $clicksPerStage Clicks a pumpkin needs to move.
     * @param list<int> $stageMax The last pumpkin stage of each scene before the final
     *        one, strictly increasing; its length is the number of the final scene.
     * @param positive-int $bonfireClicksPerStage Clicks the bonfire needs to move.
     * @param list<int> $bonfireStageMax The last bonfire stage of each scene, never decreasing; scenes past the list
     *        keep its last value. Empty leaves the bonfire at stage 0 everywhere.
     */
    public function __construct(
        private int $clicksPerStage,
        array $stageMax,
        private int $bonfireClicksPerStage = 1,
        array $bonfireStageMax = [],
    ) {
        $previous = 0;
        foreach ($stageMax as $stage) {
            $stage > $previous or throw new \InvalidArgumentException('Stage maximums must be a strictly increasing list of positive integers.');
            $previous = $stage;
        }
        $stageMax === [] and throw new \InvalidArgumentException('Stage maximums must not be empty.');

        $previous = 0;
        foreach ($bonfireStageMax as $stage) {
            $stage >= $previous or throw new \InvalidArgumentException('Bonfire stage maximums must be a non-decreasing list of integers from 0.');
            $previous = $stage;
        }

        /** @var non-empty-list<positive-int> $stageMax */
        $this->stageMax = $stageMax;
        /** @var list<int<0, max>> $bonfireStageMax */
        $this->bonfireStageMax = $bonfireStageMax === [] ? [0] : $bonfireStageMax;
    }

    #[\Override]
    public function pumpkinTransition(Pumpkin $pumpkin, int $scene): ?int
    {
        return $this->moving($scene)
            && $pumpkin->stage < $this->stageMax($scene)
            && $pumpkin->clicks >= $this->clicksPerStage
            ? $pumpkin->stage + 1
            : null;
    }

    #[\Override]
    public function bonfireTransition(Pumpkin $bonfire, int $scene): ?int
    {
        return $this->moving($scene)
            && $bonfire->stage < $this->bonfireStageMax($scene)
            && $bonfire->clicks >= $this->bonfireClicksPerStage
            ? $bonfire->stage + 1
            : null;
    }

    #[\Override]
    public function pumpkinsDone(int $scene, array $pumpkins): bool
    {
        if (!$this->moving($scene) || $pumpkins === []) {
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

    #[\Override]
    public function bonfireOpen(int $scene, array $pumpkins, Pumpkin $bonfire): bool
    {
        return $bonfire->stage < $this->bonfireStageMax($scene) && $this->pumpkinsDone($scene, $pumpkins);
    }

    #[\Override]
    public function sceneReady(int $scene, array $pumpkins, ?Pumpkin $bonfire = null): bool
    {
        return $this->pumpkinsDone($scene, $pumpkins)
            && ($bonfire === null || $bonfire->stage >= $this->bonfireStageMax($scene));
    }

    /**
     * The final scene keeps the last stage of the scene before it.
     */
    #[\Override]
    public function stageMax(int $scene): int
    {
        return $this->stageMax[$this->index($scene, \count($this->stageMax))];
    }

    /**
     * The final scene keeps the last stage of the scene before it.
     */
    #[\Override]
    public function bonfireStageMax(int $scene): int
    {
        return $this->bonfireStageMax[$this->index(\min($scene, $this->finalScene() - 1), \count($this->bonfireStageMax))];
    }

    #[\Override]
    public function finalScene(): int
    {
        return \count($this->stageMax);
    }

    /**
     * @param positive-int $length
     * @return int<0, max>
     */
    private function index(int $scene, int $length): int
    {
        return \max(0, \min($scene, $length - 1));
    }

    private function moving(int $scene): bool
    {
        return $scene >= 0 && $scene < $this->finalScene();
    }
}
