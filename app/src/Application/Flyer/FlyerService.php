<?php

declare(strict_types=1);

namespace App\Application\Flyer;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Player\PlayerService;
use App\Application\Story\OwlLine;
use App\Domain\Clock\Clock;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\ZombieFactory;
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
    /** Kinds the moderator may launch by hand. */
    public const SPECIAL = [Flyer::KIND_WITCH, Flyer::KIND_ROADRUNNER];

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
        private ?ZombieFactory $zombies = null,
    ) {}

    /**
     * Launches a flyer of the current scene.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function launch(int $now): Flyer
    {
        $ritual = $this->ritual->snapshot();

        return $this->release($ritual->round, $this->factory->launch($now, $ritual->scene));
    }

    /**
     * The ticker's launch: nothing while the moderator pauses the sky.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function launchScheduled(int $now): ?Flyer
    {
        $ritual = $this->ritual->snapshot();

        return $ritual->story->paused ? null : $this->release($ritual->round, $this->factory->launch($now, $ritual->scene));
    }

    /**
     * The ticker's zombie: only in the cursed world, with zombies let out and the sky not paused.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function launchZombie(int $now): ?Flyer
    {
        $ritual = $this->ritual->snapshot();
        $story = $ritual->story;

        return $this->zombies !== null && $story->cursed() && $story->zombies && !$story->paused
            ? $this->release($ritual->round, $this->zombies->launch($now))
            : null;
    }

    /**
     * The moderator's special flyer, launched even on a pause.
     *
     * @param int $now Unix time in milliseconds.
     * @return Flyer|null Null for a kind that is not special.
     */
    public function launchSpecial(string $kind, int $now): ?Flyer
    {
        return \in_array($kind, self::SPECIAL, true)
            ? $this->release($this->ritual->round(), $this->factory->special($kind, $now))
            : null;
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
        $flyer->kind === Flyer::KIND_WITCH and $this->publisher->owl(
            $round,
            OwlLine::WITCH_DOWN,
            $shooter === null ? [] : ['player' => $shooter->ref()],
        );

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

    private function release(int $round, Flyer $flyer): Flyer
    {
        $this->store->add($flyer);
        $this->publisher->flyer($round, $flyer);
        $this->metrics->flyerLaunched($flyer);

        return $flyer;
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
