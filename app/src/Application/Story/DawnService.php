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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The top of the dawn (docs/spec/scenario.md, «Топ-10 рассвета»): flyers shot down over the whole evening, shown at
 * dawn. Every counted hit is recorded in any scene; at dawn it also sends the whole ranking, so each page finds its
 * own place in it and no response carries anything personal.
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
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * A flyer shot down by a player, whose record already counts it: the board takes the record's count, so a repeat
     * never counts twice. At dawn everyone gets the new board.
     *
     * @throws LockBusyException
     */
    public function hit(int $round, Story $story, Player $shooter): void
    {
        $now = $this->clock->now();
        $board = $this->store->update(
            static fn(DawnBoard $board): DawnBoard => $board->reached($shooter->id, $shooter->name, $shooter->flyers, $now),
        );
        $story->dawn() and $this->publisher->dawn($round, $board->board($this->limit));
    }

    /**
     * The dawn has come: everyone gets the board the evening has gathered so far. The board is a show beside the step,
     * so a failure here must not fail the step.
     */
    public function begin(int $round): void
    {
        try {
            $this->publisher->dawn($round, $this->store->get()->board($this->limit));
        } catch (\Throwable $e) {
            $this->logger->warning('Dawn board is not published: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The board as the snapshot carries it: at dawn only.
     *
     * @return array{board: list<Row>}|null
     */
    public function state(Story $story): ?array
    {
        return $story->dawn() ? ['board' => $this->store->get()->board($this->limit)] : null;
    }
}
