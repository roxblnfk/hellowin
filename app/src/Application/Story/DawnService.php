<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Player;
use App\Domain\Story\DawnBoard;
use App\Domain\Story\DawnStore;
use App\Domain\Story\Story;

/**
 * The top of the dawn (docs/spec/scenario.md, «Топ-10 рассвета»): hits on finale step 7 ranked live. Every counted hit
 * sends the whole ranking, so each page finds its own place in it and no response carries anything personal.
 *
 * @psalm-import-type Row from DawnBoard
 */
final readonly class DawnService
{
    /**
     * @param positive-int $limit Rows the board sends at most.
     */
    public function __construct(
        private DawnStore $store,
        private ChannelPublisher $publisher,
        private Clock $clock,
        private int $limit,
    ) {}

    /**
     * A flyer shot down by a player: at dawn it counts, and everyone gets the new board.
     *
     * @throws LockBusyException
     */
    public function hit(int $round, Story $story, Player $shooter): void
    {
        if (!$story->dawn()) {
            return;
        }

        $now = $this->clock->now();
        $board = $this->store->update($round, static fn(DawnBoard $board): DawnBoard => $board->hit($shooter->id, $shooter->name, $now));
        $this->publisher->dawn($round, $board->board($this->limit));
    }

    /**
     * The board as the snapshot carries it: at dawn only.
     *
     * @return array{board: list<Row>}|null
     */
    public function state(int $round, Story $story): ?array
    {
        return $story->dawn() ? ['board' => $this->store->get($round)->board($this->limit)] : null;
    }
}
