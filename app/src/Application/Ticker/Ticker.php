<?php

declare(strict_types=1);

namespace App\Application\Ticker;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Player\LeaderboardService;
use App\Application\Story\CursedService;
use App\Application\Story\RapierService;
use App\Application\Walk\WalkService;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Periodic work of the game, driven by one long-running process ({@see \App\Endpoint\Console\TickerCommand}).
 * The schedule lives in this object: a restarted ticker starts a fresh one, and since only the holder of
 * {@see TickerLease} ticks, a second ticker never doubles the work.
 */
final class Ticker
{
    /** Milliseconds between comparisons of the lawn with the channel presence. */
    private const PRESENCE_INTERVAL = 1000;

    private ?int $nextLeaderboard = null;
    private ?int $nextFlyer = null;
    private ?int $nextZombie = null;
    private ?int $nextPresence = null;

    /**
     * @param positive-int $leaderboardInterval Milliseconds.
     * @param array{positive-int, positive-int} $flyerInterval Milliseconds between launches, min and max.
     * @param array{positive-int, positive-int} $zombieInterval Milliseconds between zombies, min and max.
     * @param array{positive-int, positive-int} $dawnInterval Milliseconds between launches at dawn, min and max.
     */
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly int $leaderboardInterval,
        private readonly FlyerService $flyers,
        private readonly array $flyerInterval,
        private readonly WalkService $walks,
        private readonly LoggerInterface $logger,
        private readonly array $zombieInterval = [4000, 9000],
        private readonly Randomizer $random = new Randomizer(),
        private readonly ?BarService $bar = null,
        private readonly array $dawnInterval = [1000, 2500],
        private readonly ?RapierService $rapier = null,
        private readonly ?CursedService $cursed = null,
        private readonly ?LimitsService $limits = null,
    ) {}

    /**
     * Runs whatever is due at the moment; a failing job is logged and does not stop the others.
     *
     * Flyers fly regardless of the ritual, in every scene. The first one waits a whole interval after the start,
     * so a ticker restarting in a loop does not flood the sky.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function tick(int $now): void
    {
        $this->nextFlyer ??= $now + $this->flyerPause();
        if ($now >= $this->nextFlyer) {
            $this->nextFlyer = $now + $this->flyerPause();
            $this->safely('flyer launch', fn() => $this->flyers->launchScheduled($now));
        }

        // Zombies keep their own beat on top of the flyers; the minimum of the crowd does not wait for it
        $this->nextZombie ??= $now + $this->random->getInt(...$this->zombieInterval);
        $due = $now >= $this->nextZombie;
        $due and $this->nextZombie = $now + $this->random->getInt(...$this->zombieInterval);
        $this->safely('zombies', fn() => $this->flyers->wander($now, $due));

        $this->safely('flyer sweep', fn() => $this->flyers->sweep($now));
        $this->safely('clinks', fn() => $this->walks->clinks());
        $this->safely('bar', fn() => $this->bar?->tick());
        $this->safely('beers', fn() => $this->walks->beers());
        $this->safely('zombie players', fn() => $this->rapier?->shamble());

        if ($this->nextPresence === null || $now >= $this->nextPresence) {
            $this->nextPresence = $now + self::PRESENCE_INTERVAL;
            $this->safely('walkers', fn() => $this->walks->sync());
            $this->safely('limits', fn() => $this->limits?->follow());
            $this->safely('cursed goals', fn() => $this->cursed?->follow());
        }

        if ($this->nextLeaderboard === null || $now >= $this->nextLeaderboard) {
            $this->nextLeaderboard = $now + $this->leaderboardInterval;
            $this->safely('leaderboard', fn() => $this->leaderboard->build());
        }
    }

    /**
     * The dawn has its own, shorter pace; a failing read of the story keeps the usual one.
     */
    private function flyerPause(): int
    {
        try {
            $dawn = $this->flyers->atDawn();
        } catch (\Throwable) {
            $dawn = false;
        }

        return $this->random->getInt(...($dawn ? $this->dawnInterval : $this->flyerInterval));
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
