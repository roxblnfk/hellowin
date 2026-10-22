<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Player;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\TopBoard;
use App\Domain\Story\TopStore;

/**
 * The shooters' top of the evening (docs/spec/protocol.md, «Топ стрелков»): points for flyers shot down over the whole
 * evening, on screen in every scene. Every counted hit is recorded at once, while the clients get the whole ranking from
 * the ticker at most once in its interval: the full list to every client on every hit is too much for a full room. Each
 * page finds its own place in the list, so no response carries anything personal.
 *
 * @psalm-import-type Row from TopBoard
 */
final readonly class TopService
{
    /**
     * @param positive-int $limit Rows the board sends at most.
     */
    public function __construct(
        private TopStore $store,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private Clock $clock,
        private int $limit,
    ) {}

    /**
     * A flyer shot down by a player, whose record already counts its points: the board takes the record's score, so a
     * repeat never counts twice.
     *
     * @throws LockBusyException
     */
    public function hit(Player $shooter): void
    {
        $now = $this->clock->now();
        $this->store->update(
            static fn(TopBoard $board): TopBoard => $board->reached($shooter->id, $shooter->name, $shooter->points, $now),
        );
    }

    /**
     * The ticker's publication: the board goes out when it differs from the one the clients were told last.
     *
     * @param list<Row>|null $told The board published last; null — none since the ticker started.
     * @return list<Row> The board the clients have now.
     */
    public function tell(?array $told): array
    {
        $board = $this->store->get()->board($this->limit);
        $board === $told or $this->publisher->top($this->ritual->round(), $board);

        return $board;
    }

    /**
     * The board as the snapshot carries it, in every scene.
     *
     * @return array{board: list<Row>}
     */
    public function state(): array
    {
        return ['board' => $this->store->get()->board($this->limit)];
    }
}
