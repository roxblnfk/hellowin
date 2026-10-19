<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Player\PlayerService;
use App\Domain\Element\ElementRegistry;
use App\Domain\Ritual\ClickOutcome;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use Random\Randomizer;

final readonly class ClickHandler
{
    /**
     * @param positive-int $threshold Clicks a pumpkin needs to move, sent to clients for the progress bar.
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
    ) {}

    /**
     * Counts the click, tells every client about it and, when the policy lets the pumpkin move, queues
     * its transition. Every click past the threshold queues one again until the transition is done:
     * the job deduplicates by its idempotency key, and the player of the job that runs finished the pumpkin.
     *
     * @param non-empty-string|null $player Public id of the clicking player, null for an anonymous connection.
     * @throws UnknownElementException
     * @throws RitualBusyException
     */
    public function click(string $elementId, ?string $player = null): ClickOutcome
    {
        $element = $this->elements->find($elementId)
            ?? throw new UnknownElementException(\sprintf('Unknown element "%s".', $elementId));

        $this->metrics->click($element);
        $outcome = $this->store->click($element->id);
        $stats = $this->players->clicked($player);

        $emoji = $element->emojis[$this->randomizer->getInt(0, \count($element->emojis) - 1)];
        $this->publisher->click($outcome->round, $outcome->pumpkin, $emoji, $this->threshold, $stats);

        $toStage = $this->policy->pumpkinTransition($outcome->pumpkin, $outcome->scene);
        $toStage === null or $this->transitions->pumpkin($outcome->round, $element->id, $toStage, $player);

        return $outcome;
    }
}
