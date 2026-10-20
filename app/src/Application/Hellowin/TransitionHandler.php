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
     * Moves a pumpkin or the bonfire. The last pumpkin of a scene opens the bonfire when the scene takes it further,
     * otherwise makes the scene ready; the bonfire reaching its last stage of the scene makes it ready. A ready scene
     * waits for the moderator, and the owl says each of these once.
     *
     * @param non-empty-string|null $player Player of the click that queued the transition: it finished the element.
     * @return bool Whether the element moved.
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

        $ritual = $this->store->snapshot();
        $bonfire = $ritual->bonfire?->id === $moved->id;
        $bonfire ? $this->metrics->bonfireMoved($moved) : $this->metrics->pumpkinMoved($moved);
        if ($ritual->round !== $round) {
            return true;
        }

        // Elements finishing at once may each see the same; the store marks it for one of them
        $params = $finisher === null ? null : ['player' => $finisher->ref()];
        if ($this->policy->sceneReady($ritual->scene, $ritual->pumpkins, $ritual->bonfire)) {
            if ($this->store->markReady($round, $ritual->scene, $player)) {
                $this->publisher->ready($round, $ritual->scene);
                $params === null or $this->publisher->owl($round, $bonfire ? OwlLine::BONFIRE_DONE : OwlLine::LAST_PUMPKIN, $params);
                $this->publisher->owl($round, OwlLine::SCENE_READY);
            }
        } elseif (!$bonfire
            && $ritual->bonfire !== null
            && $this->policy->bonfireOpen($ritual->scene, $ritual->pumpkins, $ritual->bonfire)
            && $this->store->markBonfireOpen($round, $ritual->scene)) {
            $params === null or $this->publisher->owl($round, OwlLine::LAST_PUMPKIN, $params);
            $this->publisher->owl($round, OwlLine::BONFIRE_OPEN);
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

        $this->publisher->scene($round, $toScene, $this->policy->stageMax($toScene), $this->policy->bonfireStageMax($toScene));
        $final and $this->publisher->finale($round, 0);
        $this->publisher->owl($round, OwlLine::scene($toScene));
        $this->metrics->sceneMoved($toScene);

        return true;
    }
}
