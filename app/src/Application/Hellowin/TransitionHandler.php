<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;

/**
 * Applies queued transitions. The store applies one only while the state is a step behind it in the
 * same round, so a duplicate that slipped past the idempotency storage — or a job left over from a
 * round that was reset — changes nothing.
 */
final readonly class TransitionHandler
{
    public function __construct(
        private RitualStore $store,
        private RitualPolicy $policy,
        private TransitionQueue $queue,
        private ChannelPublisher $publisher,
        private RitualMetrics $metrics,
    ) {}

    /**
     * @return bool Whether the pumpkin moved.
     * @throws RitualBusyException
     */
    public function pumpkin(int $round, string $pumpkin, int $toStage): bool
    {
        $moved = $this->store->advancePumpkin($round, $pumpkin, $toStage);
        if ($moved === null) {
            return false;
        }

        $this->publisher->pumpkin($round, $moved);
        $this->metrics->pumpkinMoved($moved);

        $ritual = $this->store->snapshot();
        $toScene = $this->policy->sceneTransition($ritual->scene, $ritual->pumpkins);
        $toScene === null || $ritual->round !== $round or $this->queue->scene($round, $toScene);

        return true;
    }

    /**
     * @return bool Whether the scene moved.
     * @throws RitualBusyException
     */
    public function scene(int $round, int $toScene): bool
    {
        if (!$this->store->advanceScene($round, $toScene)) {
            return false;
        }

        $this->publisher->scene($round, $toScene, $this->policy->stageMax($toScene));
        $this->metrics->sceneMoved($toScene);

        return true;
    }
}
