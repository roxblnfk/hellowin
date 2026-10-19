<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Element\ElementRegistry;
use App\Domain\Game\ClickResult;
use App\Domain\Game\GameBusyException;
use App\Domain\Game\GameState;
use Random\Randomizer;

final readonly class ClickHandler
{
    public function __construct(
        private ElementRegistry $elements,
        private GameState $state,
        private ClickMetrics $metrics,
        private ChannelPublisher $publisher,
        private Randomizer $randomizer = new Randomizer(),
    ) {}

    /**
     * Counts the click and tells every client: an emoji over the element, plus the new state when a threshold
     * is crossed.
     *
     * @throws UnknownElementException
     * @throws GameBusyException
     */
    public function click(string $elementId): ClickResult
    {
        $element = $this->elements->find($elementId)
            ?? throw new UnknownElementException(\sprintf('Unknown element "%s".', $elementId));

        $this->metrics->click($element);
        $result = $this->state->registerClick();
        $this->metrics->stage($result->stage);

        $emoji = $element->emojis[$this->randomizer->getInt(0, \count($element->emojis) - 1)];
        $this->publisher->emoji($element, $emoji, $result);
        $result->stageChanged and $this->publisher->state($result);

        return $result;
    }
}
