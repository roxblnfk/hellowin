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

        $shooter = $this->players->shotFlyer($player, $flyer->points);
        $this->publisher->shot($this->ritual->round(), $flyer, $shooter);
        $this->metrics->flyerShot($flyer);

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
}
