<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Story\EveningTally;
use App\Application\Story\OwlLine;
use App\Application\Story\RevealBuilder;
use App\Application\Walk\WalkService;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Reveal;
use App\Domain\Walk\Walker;

/**
 * Starts the ritual over in a new round.
 *
 * Idempotent per round (`reset:{round}`): the store compares the round under the scene and pumpkin
 * locks, so of two resets of the same round only the first one acts; the other is a dropped repeat of the round's
 * tally ({@see EveningTally}). The moderator's reset starts the evening's numbers over; a return to the bar (`again`)
 * carries the finished round into them ({@see RevealBuilder::carry()}), so the numbers live through the cycles.
 */
final readonly class ResetService
{
    public function __construct(
        private RitualStore $store,
        private ChannelPublisher $publisher,
        private RitualMetrics $metrics,
        private ?WalkService $walks = null,
        private ?BarService $bar = null,
        private ?FlyerService $flyers = null,
        private ?LimitsService $limits = null,
        private ?EveningTally $tally = null,
        private ?RevealBuilder $reveal = null,
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
        // Read before the reset zeroes the elements: what the round gains between the read and the reset is lost
        $finished = $again && $this->reveal !== null ? $this->store->snapshot() : null;
        $numbers = $finished === null ? null : $this->reveal?->round($finished);

        $round = $this->store->reset($fromRound ?? $finished?->round ?? $this->store->snapshot()->round, $again);
        if ($round === null) {
            $this->tally?->add(EveningTally::DROPPED);
            return null;
        }
        if (!$again) {
            $this->tally?->clear();
            $this->reveal?->forget();
        } elseif ($finished !== null && $numbers !== null && $finished->round === $round - 1) {
            $this->carry($finished->round, $numbers);
        }

        $inBar = $this->walks?->barIn(0) === true;
        $inBar or $this->lift();
        $this->publisher->reset(
            $round,
            $inBar ? $this->backToBar() : null,
            $inBar ? $this->openBar($round) : null,
            $again,
        );
        $this->retire();
        $again and $this->publisher->owl($round, OwlLine::BAR_AGAIN);
        $this->limits?->nudge();
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
     * Wandering zombies left from the cursed world go; a busy lock leaves them to the ticker's next tick.
     */
    private function retire(): void
    {
        try {
            $this->flyers?->retire();
        } catch (LockBusyException) {
        }
    }

    /**
     * The round has started over already: a busy evening loses the finished round's numbers, not the reset.
     */
    private function carry(int $round, Reveal $numbers): void
    {
        try {
            $this->reveal?->carry($round, $numbers);
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
