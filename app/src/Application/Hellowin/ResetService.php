<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

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
    ) {}

    /**
     * A ritual that starts in the bar takes every character back there.
     *
     * @param int|null $fromRound Round to finish; null takes the current one.
     * @return positive-int|null The new round, null when `$fromRound` is already over.
     * @throws RitualBusyException
     */
    public function reset(?int $fromRound = null): ?int
    {
        $round = $this->store->reset($fromRound ?? $this->store->snapshot()->round);
        if ($round === null) {
            return null;
        }

        $this->publisher->reset($round, $this->walks?->barIn(0) === true ? $this->backToBar() : null);
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
}
