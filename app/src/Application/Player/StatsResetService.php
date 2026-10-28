<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Application\Story\TopService;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\PlayerStore;
use Psr\Log\LoggerInterface;

/**
 * The moderator's reset of the players' statistics (docs/spec/players.md, «Статистика»): every record by the index
 * loses its counters and the shooters' top empties. The reveal, the story and the Prometheus counters stay.
 */
final readonly class StatsResetService
{
    public function __construct(
        private PlayerStore $players,
        private TopService $top,
        private LoggerInterface $logger,
    ) {}

    /**
     * A player whose lock stays busy past the lock's wait is skipped and logged rather than failing the whole reset:
     * a repeat catches it.
     *
     * @return int<0, max> Records zeroed; skipped ones and ids without a record are not counted.
     * @throws LockBusyException When the top's lock is busy.
     */
    public function reset(): int
    {
        $zeroed = 0;
        foreach ($this->players->ids() as $id) {
            try {
                $this->players->zero($id) === null or ++$zeroed;
            } catch (LockBusyException $e) {
                $this->logger->warning('Player {player} statistics are not reset: {message}', [
                    'player' => $id,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        // After the records, not before: a hit during the loop would put its old score back on an emptied board

        $this->top->clear();

        return $zeroed;
    }
}
