<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Bar\BarService;
use App\Application\Story\OwlLine;
use App\Application\Walk\WalkService;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\Walker;

/**
 * Starts the ritual over in a new round.
 *
 * Idempotent per round (`reset:{round}`): the store compares the round under the scene and pumpkin
 * locks, so of two resets of the same round only the first one acts.
 */
final readonly class ResetService
{
    public function __construct(
        private RitualStore $store,
        private ChannelPublisher $publisher,
        private RitualMetrics $metrics,
        private ?WalkService $walks = null,
        private ?BarService $bar = null,
    ) {}

    /**
     * A ritual that starts in the bar takes every character back there with empty hands and fills the counter.
     *
     * @param int|null $fromRound Round to finish; null takes the current one.
     * @param bool $again A new cycle of the evening after the dawn: the reset says so, and the owl offers a beer.
     * @return positive-int|null The new round, null when `$fromRound` is already over.
     * @throws RitualBusyException
     */
    public function reset(?int $fromRound = null, bool $again = false): ?int
    {
        $round = $this->store->reset($fromRound ?? $this->store->snapshot()->round);
        if ($round === null) {
            return null;
        }

        $inBar = $this->walks?->barIn(0) === true;
        $inBar or $this->lift();
        $this->publisher->reset(
            $round,
            $inBar ? $this->backToBar() : null,
            $inBar ? $this->openBar($round) : null,
            $again,
        );
        $again and $this->publisher->owl($round, OwlLine::BAR_AGAIN);
        $this->metrics->state($this->store->snapshot());

        return $round;
    }

    /**
     * The round has started over already: a busy lawn leaves the characters where they stood.
     *
     * @return list<Walker>|null
     */
    private function backToBar(): ?array
    {
        try {
            return $this->walks?->relocate(0);
        } catch (LockBusyException) {
            return null;
        }
    }

    /**
     * Without the bar the characters stay where they are, but the curse is lifted from the zombies; a busy lawn keeps
     * them as they were, and the next dawn or reset lifts it.
     */
    private function lift(): void
    {
        try {
            $this->walks?->cureAll();
        } catch (LockBusyException) {
        }
    }

    /**
     * A busy counter keeps what it had; the owl pours the rest.
     */
    private function openBar(int $round): ?array
    {
        try {
            return $this->bar?->open($round);
        } catch (LockBusyException) {
            return null;
        }
    }
}
