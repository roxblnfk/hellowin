<?php

declare(strict_types=1);

namespace App\Application\Flyer;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Player\PlayerService;
use App\Domain\Clock\Clock;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\ShotTally;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Flyers over the patch: the ticker launches them and sweeps the escaped ones, players shoot them over RPC.
 * Flyers live beside the ritual and do not change it; events carry the round only because every event does.
 */
final readonly class FlyerService
{
    public function __construct(
        private FlyerFactory $factory,
        private FlyerStore $store,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private PlayMetrics $metrics,
        private PlayerService $players,
        private Clock $clock,
        private ShotTally $tally,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function launch(int $now): Flyer
    {
        $flyer = $this->factory->launch($now);
        $this->store->add($flyer);
        $this->publisher->flyer($this->ritual->round(), $flyer);
        $this->metrics->flyerLaunched($flyer);

        return $flyer;
    }

    /**
     * The first shot at a flyer in the sky hits it; any other shot misses.
     *
     * @param non-empty-string|null $player
     * @return int|null Points for a hit, null for a miss.
     * @throws LockBusyException
     */
    public function shoot(string $flyerId, ?string $player): ?int
    {
        $flyer = $this->store->shoot($flyerId, $this->clock->now());
        if ($flyer === null) {
            return null;
        }

        $round = $this->ritual->round();
        $shooter = $this->players->shotFlyer($player, $flyer->points);
        $this->publisher->shot($round, $flyer, $shooter);
        $this->metrics->flyerShot($flyer);
        $this->count($round);

        return $flyer->points;
    }

    /**
     * @param int $now Unix time in milliseconds.
     * @return list<Flyer> Flyers that left the screen unhurt since the last sweep.
     * @throws LockBusyException
     */
    public function sweep(int $now): array
    {
        $escaped = $this->store->sweep($now);
        foreach ($escaped as $flyer) {
            $this->metrics->flyerEscaped($flyer);
        }

        return $escaped;
    }

    /**
     * The tally only feeds the finale reveal: a failure there must not turn a hit into an error.
     */
    private function count(int $round): void
    {
        try {
            $this->tally->add($round);
        } catch (\Throwable $e) {
            $this->logger->warning('Shot is not counted for the reveal: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
