<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Player\PlayerService;
use App\Domain\Element\ElementRegistry;
use App\Domain\Ritual\ClickOutcome;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Ritual\RitualStore;
use Random\Randomizer;

final readonly class ClickHandler
{
    /**
     * @param positive-int $threshold Clicks a pumpkin needs to move, sent to clients for the progress bar.
     * @param positive-int $bonfireThreshold Clicks the bonfire needs to move.
     */
    public function __construct(
        private ElementRegistry $elements,
        private RitualStore $store,
        private RitualPolicy $policy,
        private TransitionQueue $transitions,
        private RitualMetrics $metrics,
        private ChannelPublisher $publisher,
        private PlayerService $players,
        private int $threshold,
        private Randomizer $randomizer = new Randomizer(),
        private int $bonfireThreshold = 1,
    ) {}

    /**
     * Counts the click, tells every client about it and, when the policy lets the element move, queues its
     * transition. Every click past the threshold queues one again until the transition is done: the job deduplicates
     * by its idempotency key, and the player of the job that runs finished the element.
     *
     * @param non-empty-string|null $player Public id of the clicking player, null for an anonymous connection.
     * @throws UnknownElementException
     * @throws ElementLockedException A click on the bonfire while it is closed: not counted, not published.
     * @throws RitualBusyException
     */
    public function click(string $elementId, ?string $player = null): ClickOutcome
    {
        $element = $this->elements->find($elementId)
            ?? throw new UnknownElementException(\sprintf('Unknown element "%s".', $elementId));
        $bonfire = $element->bonfire();

        // The pumpkins are read under the bonfire lock, so a reset cannot slip between the check and the count
        $outcome = $this->store->click($element->id, $bonfire ? $this->bonfireOpen(...) : null)
            ?? throw new ElementLockedException(\sprintf('The bonfire "%s" takes no clicks now.', $element->id));
        $this->metrics->click($element);
        $stats = $this->players->clicked($player);

        $emoji = $element->emojis[$this->randomizer->getInt(0, \count($element->emojis) - 1)];
        $threshold = $bonfire ? $this->bonfireThreshold : $this->threshold;
        $this->publisher->click($outcome->round, $outcome->pumpkin, $emoji, $threshold, $stats);

        $toStage = $bonfire
            ? $this->policy->bonfireTransition($outcome->pumpkin, $outcome->scene)
            : $this->policy->pumpkinTransition($outcome->pumpkin, $outcome->scene);
        $toStage === null or $this->transitions->pumpkin($outcome->round, $element->id, $toStage, $player, $bonfire);

        return $outcome;
    }

    private function bonfireOpen(RitualSnapshot $ritual): bool
    {
        return $ritual->bonfire !== null && $this->policy->bonfireOpen($ritual->scene, $ritual->pumpkins, $ritual->bonfire);
    }
}
