<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Player\PlayerService;
use App\Application\Story\OwlLine;
use App\Domain\Element\Element;
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
     * Moves a pumpkin or a centrepiece. The last pumpkin of a scene opens the bonfire when the scene takes it further,
     * otherwise makes the scene ready; the bonfire reaching its last stage of the scene, or the circle its last stage,
     * makes it ready. A ready scene waits for the moderator, and the owl says each of these once.
     *
     * @param non-empty-string|null $player Player of the click that queued the transition: it finished the element.
     * @return bool Whether the element moved.
     * @throws RitualBusyException
     */
    public function pumpkin(int $round, string $pumpkin, int $toStage, ?string $player = null): bool
    {
        // The threshold in force when the job runs is what the transition takes; the reveal counts the rest as burned
        $type = $this->store->typeOf($pumpkin);
        $cost = $this->policy->threshold($type, $this->store->story()->k);
        $moved = $this->store->advancePumpkin($round, $pumpkin, $toStage, $cost);
        if ($moved === null) {
            return false;
        }

        $finisher = $this->players->finishedPumpkin($player);
        $this->publisher->pumpkin($round, $moved, $finisher);

        $ritual = $this->store->snapshot();
        $this->metrics->moved($type, $moved);
        if ($ritual->round !== $round) {
            return true;
        }

        // Elements finishing at once may each see the same; the store marks it for one of them
        $params = $finisher === null ? null : ['player' => $finisher->ref()];
        if ($this->policy->sceneReady($ritual)) {
            if ($this->store->markReady($round, $ritual->scene, $player)) {
                $this->publisher->ready($round, $ritual->scene);
                $params === null or $this->publisher->owl($round, OwlLine::finished($type), $params);
                $this->publisher->owl($round, OwlLine::SCENE_READY);
            }
        } elseif ($type === Element::TYPE_PUMPKIN
            && $this->policy->centrepieceOpen(Element::TYPE_BONFIRE, $ritual)
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

        $this->publisher->scene(
            $round,
            $toScene,
            $this->policy->stageMax($toScene),
            $this->policy->stageMax($toScene, Element::TYPE_BONFIRE),
            $this->policy->stageMax($toScene, Element::TYPE_CIRCLE),
            $final ? 0 : null,
        );
        $final and $this->publisher->finale($round, $toScene, 0);
        $this->publisher->owl($round, OwlLine::scene($toScene));
        $this->metrics->sceneMoved($toScene);

        return true;
    }
}
