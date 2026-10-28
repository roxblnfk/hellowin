<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * What the autopilot sees of the story on a tick.
 */
final readonly class AutoScene
{
    /**
     * @param positive-int $round
     * @param int<0, max> $scene
     * @param positive-int $sceneStages The final scene.
     * @param bool $bar The scene is the bar.
     * @param int<0, 7>|null $step The finale step; null before the final scene.
     * @param bool $dice The dice of finale step 1 are on: no 6-6-6 yet.
     */
    public function __construct(
        public int $round,
        public int $scene,
        public int $sceneStages,
        public bool $bar,
        public bool $ready,
        public ?int $step,
        public bool $paused,
        public bool $dice = false,
    ) {}

    /**
     * @return non-empty-string The point the autopilot counts from: a new one starts its clock over.
     */
    public function ctx(): string
    {
        return \sprintf('%d:%d:%s', $this->round, $this->scene, $this->step ?? '-');
    }
}
