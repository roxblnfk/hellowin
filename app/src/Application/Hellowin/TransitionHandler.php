<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Player\PlayerService;
use App\Application\Story\OwlLine;
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
        private ChannelPublisher $publisher,
        private RitualMetrics $metrics,
        private PlayerService $players,
    ) {}

    /**
     * A pumpkin that finishes the scene makes it ready: the scene waits for the moderator, the owl says so.
     *
     * @param non-empty-string|null $player Player of the click that queued the transition: it finished the pumpkin.
     * @return bool Whether the pumpkin moved.
     * @throws RitualBusyException
     */
    public function pumpkin(int $round, string $pumpkin, int $toStage, ?string $player = null): bool
    {
        $moved = $this->store->advancePumpkin($round, $pumpkin, $toStage);
        if ($moved === null) {
            return false;
        }

        $finisher = $this->players->finishedPumpkin($player);
        $this->publisher->pumpkin($round, $moved, $finisher);
        $this->metrics->pumpkinMoved($moved);

        // Pumpkins finishing at once may each see the scene done; the store marks it ready for one of them
        $ritual = $this->store->snapshot();
        if ($ritual->round === $round
            && $this->policy->sceneReady($ritual->scene, $ritual->pumpkins)
            && $this->store->markReady($round, $ritual->scene, $player)) {
            $this->publisher->ready($round, $ritual->scene);
            $finisher === null or $this->publisher->owl($round, OwlLine::LAST_PUMPKIN, ['player' => $finisher->ref()]);
            $this->publisher->owl($round, OwlLine::SCENE_READY);
        }

        return true;
    }

    /**
     * @return bool Whether the scene moved.
     * @throws RitualBusyException
     */
    public function scene(int $round, int $toScene): bool
    {
        $final = $toScene === $this->policy->finalScene();
        if (!$this->store->advanceScene($round, $toScene, $final)) {
            return false;
        }

        $this->publisher->scene($round, $toScene, $this->policy->stageMax($toScene));
        $final and $this->publisher->finale($round, 0);
        $this->publisher->owl($round, OwlLine::scene($toScene));
        $this->metrics->sceneMoved($toScene);

        return true;
    }
}
