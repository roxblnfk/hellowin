<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\Metrics\Collector;
use Spiral\RoadRunner\Metrics\MetricsInterface;

/**
 * Metrics of the games around the ritual: players and flyers. Failures are logged and swallowed, as in
 * {@see RitualMetrics}.
 */
final readonly class PlayMetrics
{
    public const PLAYERS = 'hellowin_players';
    public const PLAYERS_REGISTERED = 'hellowin_players_registered';

    public function __construct(
        private MetricsInterface $metrics,
        private LoggerInterface $logger,
    ) {}

    /**
     * Workers and the ticker call this on boot; a repeated declaration failing on the RoadRunner side is harmless.
     */
    public function declare(): void
    {
        $collectors = [
            self::PLAYERS => Collector::gauge()->withHelp('Players known by their secret, named or not.'),
            self::PLAYERS_REGISTERED => Collector::gauge()->withHelp('Players who registered a name.'),
        ];

        foreach ($collectors as $name => $collector) {
            $this->safely(fn() => $this->metrics->declare($name, $collector));
        }
    }

    /**
     * @param int<0, max> $known
     * @param int<0, max> $registered
     */
    public function players(int $known, int $registered): void
    {
        $this->safely(fn() => $this->metrics->set(self::PLAYERS, $known));
        $this->safely(fn() => $this->metrics->set(self::PLAYERS_REGISTERED, $registered));
    }

    /**
     * @param \Closure(): void $operation
     */
    private function safely(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->logger->warning('[Metrics] {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
