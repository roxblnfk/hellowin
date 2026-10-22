<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Player\PlayerService;
use App\Application\Walk\ActionStop;
use App\Domain\Element\ElementRegistry;
use App\Domain\Ritual\ClickOutcome;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Ritual\RitualStore;
use Random\Randomizer;

final readonly class ClickHandler
{
    public function __construct(
        private ElementRegistry $elements,
        private RitualStore $store,
        private RitualPolicy $policy,
        private TransitionQueue $transitions,
        private RitualMetrics $metrics,
        private ChannelPublisher $publisher,
        private PlayerService $players,
        private Randomizer $randomizer = new Randomizer(),
        private ?ActionStop $stops = null,
    ) {}

    /**
     * Counts the click, tells every client about it and, when the policy lets the element move, queues its
     * transition. Every click past the threshold queues one again until the transition is done: the job deduplicates
     * by its idempotency key, and the player of the job that runs finished the element.
     *
     * @param non-empty-string|null $player Public id of the clicking player, null for an anonymous connection.
     * @throws UnknownElementException
     * @throws ElementLockedException A click on a closed centrepiece or on a pumpkin in the bar: not counted, not published.
     * @throws RitualBusyException
     */
    public function click(string $elementId, ?string $player = null): ClickOutcome
    {
        $element = $this->elements->find($elementId)
            ?? throw new UnknownElementException(\sprintf('Unknown element "%s".', $elementId));
        $type = $element->type;

        // A centrepiece reads the whole ritual under its own lock, so a reset cannot slip between the check and the count
        // A pumpkin only checks the scene: there are no pumpkins to click in the bar
        $accepts = null;
        $acceptsIn = null;
        if ($element->centrepiece()) {
            $centrepiece = $element->type;
            $accepts = fn(RitualSnapshot $ritual): bool => $this->policy->centrepieceOpen($centrepiece, $ritual);
        } else {
            $acceptsIn = fn(int $scene): bool => !$this->policy->bar($scene);
        }
        $outcome = $this->store->click($element->id, $accepts, $acceptsIn)
            ?? throw new ElementLockedException(\sprintf('The %s "%s" takes no clicks now.', $type, $element->id));
        $this->stops?->stop($player, ActionStop::CLICK);
        $this->metrics->click($element);
        $stats = $this->players->clicked($player);

        $emoji = $element->emojis[$this->randomizer->getInt(0, \count($element->emojis) - 1)];
        $threshold = $this->policy->threshold($type, $outcome->k, $outcome->players);
        $this->publisher->click($outcome->round, $outcome->pumpkin, $emoji, $threshold, $stats);

        // Clicks gathered before the threshold came down count: the next click moves the element
        $toStage = $element->centrepiece()
            ? $this->policy->centrepieceTransition($element->type, $outcome->pumpkin, $outcome->scene, $outcome->k, $outcome->players)
            : $this->policy->pumpkinTransition($outcome->pumpkin, $outcome->scene, $outcome->k, $outcome->players);
        $toStage === null or $this->transitions->pumpkin($outcome->round, $element->id, $toStage, $player, $type);

        return $outcome;
    }
}
