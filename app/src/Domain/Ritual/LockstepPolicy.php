<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * Pumpkins move stage by stage up to the last stage of the current scene and wait there; the scene is
 * ready once every pumpkin has reached it. Nothing moves in the final scene.
 */
final readonly class LockstepPolicy implements RitualPolicy
{
    /** @var non-empty-list<positive-int> */
    private array $stageMax;

    /**
     * @param positive-int $clicksPerStage Clicks a pumpkin needs to move.
     * @param list<int> $stageMax The last pumpkin stage of each scene before the final
     *        one, strictly increasing; its length is the number of the final scene.
     */
    public function __construct(
        private int $clicksPerStage,
        array $stageMax,
    ) {
        $previous = 0;
        foreach ($stageMax as $stage) {
            $stage > $previous or throw new \InvalidArgumentException('Stage maximums must be a strictly increasing list of positive integers.');
            $previous = $stage;
        }
        $stageMax === [] and throw new \InvalidArgumentException('Stage maximums must not be empty.');

        /** @var non-empty-list<positive-int> $stageMax */
        $this->stageMax = $stageMax;
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
    public function sceneReady(int $scene, array $pumpkins): bool
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

    /**
     * The final scene keeps the last stage of the scene before it.
     */
    #[\Override]
    public function stageMax(int $scene): int
    {
        return $this->stageMax[\max(0, \min($scene, \count($this->stageMax) - 1))];
    }

    #[\Override]
    public function finalScene(): int
    {
        return \count($this->stageMax);
    }

    private function moving(int $scene): bool
    {
        return $scene >= 0 && $scene < $this->finalScene();
    }
}
