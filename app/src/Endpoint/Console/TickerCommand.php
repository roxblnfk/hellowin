<?php

declare(strict_types=1);

namespace App\Endpoint\Console;

use App\Application\Hellowin\PlayMetrics;
use App\Application\Ticker\Ticker;
use App\Application\Ticker\TickerLease;
use App\Domain\Clock\Clock;
use Psr\Log\LoggerInterface;
use Spiral\Console\Attribute\AsCommand;
use Spiral\Console\Attribute\Option;
use Spiral\Console\Command;

/**
 * The periodic process of the game. RoadRunner keeps it running as a `service` (.rr.yaml); it reaches the KV,
 * lock, metrics and Centrifugo plugins over RPC like any worker.
 */
#[AsCommand(name: 'hellowin:ticker', description: 'Run the periodic jobs of the game until stopped')]
final class TickerCommand extends Command
{
    #[Option(description: 'Milliseconds between ticks')]
    private int $step = 200;

    #[Option(description: 'Stop after this many ticks; runs until killed by default')]
    private ?int $ticks = null;

    public function __invoke(Ticker $ticker, TickerLease $lease, Clock $clock, PlayMetrics $metrics, LoggerInterface $logger): int
    {
        $metrics->declare();
        $leading = null;

        for ($tick = 0; $this->ticks === null || $tick < $this->ticks; ++$tick) {
            try {
                $holds = $lease->hold($clock->now());
            } catch (\Throwable $e) {
                $logger->warning('Ticker lease is unavailable: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                $holds = false;
            }

            $holds === $leading or $logger->info($holds ? 'Ticker leads.' : 'Another ticker leads, waiting.');
            $leading = $holds;
            $holds and $ticker->tick($clock->now());

            \usleep(\max(1, $this->step) * 1000);
        }

        $lease->release();

        return self::SUCCESS;
    }
}
