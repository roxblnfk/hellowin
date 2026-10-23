<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Flyer\Flyer;
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
    public const FLYERS_LAUNCHED = 'hellowin_flyers_launched_total';
    public const FLYERS_SHOT = 'hellowin_flyers_shot_total';
    public const FLYERS_ESCAPED = 'hellowin_flyers_escaped_total';
    public const WALKERS = 'hellowin_walkers';
    public const RPC_LIMITED = 'hellowin_rpc_rate_limited_total';
    public const RPC_CALLS = 'hellowin_rpc_calls_total';
    public const RPC_DURATION = 'hellowin_rpc_duration_seconds';
    public const BEER_MUGS = 'hellowin_beer_mugs_total';
    public const BEER_LITRES = 'hellowin_beer_litres';
    public const PLACE_BAR = 'bar';
    public const PLACE_CURSED = 'cursed';

    /** Seconds: the handlers answer in a few milliseconds, a lock wait or a slow store reaches the top ones. */
    private const RPC_BUCKETS = [0.001, 0.0025, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0];

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
            self::FLYERS_LAUNCHED => Collector::counter()->withHelp('Flyers launched.')->withLabels('depth'),
            self::FLYERS_SHOT => Collector::counter()->withHelp('Flyers shot down.')->withLabels('depth'),
            self::FLYERS_ESCAPED => Collector::counter()->withHelp('Flyers that left the screen unhurt.')->withLabels('depth'),
            self::WALKERS => Collector::gauge()->withHelp('Characters on the lawn.'),
            self::RPC_LIMITED => Collector::counter()->withHelp('RPC calls refused by the rate limit.')->withLabels('group'),
            self::RPC_CALLS => Collector::counter()->withHelp('RPC calls by method and result: ok or the error code.')->withLabels('method', 'result'),
            self::RPC_DURATION => Collector::histogram(...self::RPC_BUCKETS)->withHelp('RPC handling time in PHP, seconds.')->withLabels('method'),
            self::BEER_MUGS => Collector::counter()->withHelp('Mugs drunk since the RoadRunner start, by place: bar or cursed.')->withLabels('place'),
            self::BEER_LITRES => Collector::gauge()->withHelp('Litres of beer drunk by all players ever.'),
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
     * @param int<0, max> $mugs Mugs in all the player records: they outlive a restart, unlike {@see BEER_MUGS}.
     * @param float $mugLitres Litres in one mug.
     */
    public function beer(int $mugs, float $mugLitres): void
    {
        $this->safely(fn() => $this->metrics->set(self::BEER_LITRES, (float) $mugs * $mugLitres));
    }

    /**
     * @param self::PLACE_* $place
     */
    public function mug(string $place): void
    {
        $this->safely(fn() => $this->metrics->add(self::BEER_MUGS, 1, [$place]));
    }

    /**
     * @param int<0, max> $count
     */
    public function walkers(int $count): void
    {
        $this->safely(fn() => $this->metrics->set(self::WALKERS, $count));
    }

    /**
     * @param non-empty-string $group
     */
    public function rpcLimited(string $group): void
    {
        $this->safely(fn() => $this->metrics->add(self::RPC_LIMITED, 1, [$group]));
    }

    /**
     * @param non-empty-string $method A known method or `unknown`: a client may send any name.
     * @param non-empty-string $result `ok`, the error code of the answer or `exception`.
     * @param float $seconds Handling time in PHP.
     */
    public function rpc(string $method, string $result, float $seconds): void
    {
        $this->safely(fn() => $this->metrics->add(self::RPC_CALLS, 1, [$method, $result]));
        $this->safely(fn() => $this->metrics->observe(self::RPC_DURATION, $seconds, [$method]));
    }

    public function flyerLaunched(Flyer $flyer): void
    {
        $this->safely(fn() => $this->metrics->add(self::FLYERS_LAUNCHED, 1, [(string) $flyer->depth]));
    }

    public function flyerShot(Flyer $flyer): void
    {
        $this->safely(fn() => $this->metrics->add(self::FLYERS_SHOT, 1, [(string) $flyer->depth]));
    }

    public function flyerEscaped(Flyer $flyer): void
    {
        $this->safely(fn() => $this->metrics->add(self::FLYERS_ESCAPED, 1, [(string) $flyer->depth]));
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
