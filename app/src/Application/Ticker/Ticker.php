<?php

declare(strict_types=1);

namespace App\Application\Ticker;

use App\Application\Player\LeaderboardService;
use Psr\Log\LoggerInterface;

/**
 * Periodic work of the game, driven by one long-running process ({@see \App\Endpoint\Console\TickerCommand}).
 * The schedule lives in this object: a restarted ticker starts a fresh one, and since only the holder of
 * {@see TickerLease} ticks, a second ticker never doubles the work.
 */
final class Ticker
{
    private ?int $nextLeaderboard = null;

    /**
     * @param positive-int $leaderboardInterval Milliseconds.
     */
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly int $leaderboardInterval,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Runs whatever is due at the moment; a failing job is logged and does not stop the others.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function tick(int $now): void
    {
        if ($this->nextLeaderboard === null || $now >= $this->nextLeaderboard) {
            $this->nextLeaderboard = $now + $this->leaderboardInterval;
            $this->safely('leaderboard', fn() => $this->leaderboard->build());
        }
    }

    /**
     * @param \Closure(): mixed $job
     */
    private function safely(string $name, \Closure $job): void
    {
        try {
            $job();
        } catch (\Throwable $e) {
            $this->logger->error('Ticker job {job} failed: {message}', ['job' => $name, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
