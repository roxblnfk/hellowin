<?php

declare(strict_types=1);

namespace App\Application\Ticker;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Player\PlayerCensus;
use App\Application\Story\AutopilotService;
use App\Application\Story\CursedService;
use App\Application\Story\RapierService;
use App\Application\Story\TopService;
use App\Application\Walk\WalkService;
use App\Domain\Story\TopBoard;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Periodic work of the game, driven by one long-running process ({@see \App\Endpoint\Console\TickerCommand}).
 * The schedule lives in this object: a restarted ticker starts a fresh one, and since only the holder of
 * {@see TickerLease} ticks, a second ticker never doubles the work.
 *
 * @psalm-import-type Row from TopBoard
 */
final class Ticker
{
    /** Milliseconds between comparisons of the lawn with the channel presence. */
    private const PRESENCE_INTERVAL = 1000;

    private ?int $nextTop = null;
    private ?int $nextCensus = null;
    private ?int $nextFlyer = null;
    private ?int $nextZombie = null;
    private ?int $nextPresence = null;
    private ?int $startedAt = null;

    /** Unix time in milliseconds the elephant pause counts from: the last elephant's take-off or the start. */
    private ?int $elephantFrom = null;

    private int $elephantPause = 0;

    /** @var list<Row>|null The shooters' top the clients were told last; null — none since this ticker started. */
    private ?array $toldTop = null;

    /**
     * @param positive-int $topInterval Milliseconds the shooters' top waits at least between two publications.
     * @param array{positive-int, positive-int} $flyerInterval Milliseconds between launches, min and max.
     * @param array{positive-int, positive-int} $zombieInterval Milliseconds between zombies, min and max.
     * @param array{positive-int, positive-int} $dawnInterval Milliseconds between launches at dawn, min and max.
     * @param array{positive-int, positive-int}|null $elephantInterval Milliseconds between elephants, min and max;
     *        null — no elephant comes by itself.
     */
    public function __construct(
        private readonly TopService $top,
        private readonly int $topInterval,
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
        private readonly ?PlayerCensus $census = null,
        private readonly ?AutopilotService $autopilot = null,
        private readonly ?array $elephantInterval = null,
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
        $this->startedAt ??= $now;
        $this->nextFlyer ??= $now + $this->flyerPause();
        if ($now >= $this->nextFlyer) {
            $this->nextFlyer = $now + $this->flyerPause();
            $this->safely('flyer launch', fn() => $this->flyers->launch($now));
        }

        // Zombies keep their own beat on top of the flyers; the minimum of the crowd does not wait for it
        $this->nextZombie ??= $now + $this->random->getInt(...$this->zombieInterval);
        $due = $now >= $this->nextZombie;
        $due and $this->nextZombie = $now + $this->random->getInt(...$this->zombieInterval);
        // Burnt zombies free their places before the crowd is topped up, so the minimum stands again on this tick
        $this->safely('zombie burns', fn() => $this->rapier?->burn($now));
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
            $this->safely('elephant', fn() => $this->elephant($now));
        }

        if ($this->nextTop === null || $now >= $this->nextTop) {
            $this->nextTop = $now + $this->topInterval;
            $this->safely('top', function (): void {
                $this->toldTop = $this->top->tell($this->toldTop);
            });
        }

        if ($this->nextCensus === null || $now >= $this->nextCensus) {
            $this->nextCensus = $now + PlayerCensus::INTERVAL;
            $this->safely('players', fn() => $this->census?->count());
        }

        $this->safely('autopilot', fn() => $this->autopilot?->tick($now));
    }

    /**
     * The pause counts from the last elephant in the store rather than from this ticker's own launch: the moderator's
     * elephant restarts it too, and so does a restart of the ticker. A pause is drawn once per elephant; an elephant
     * that has to wait — in the bar, or another still up — is tried again on the next beat.
     */
    private function elephant(int $now): void
    {
        if ($this->elephantInterval === null) {
            return;
        }

        $from = $this->flyers->lastElephantAt() ?? $this->startedAt ?? $now;
        if ($from !== $this->elephantFrom) {
            $this->elephantFrom = $from;
            $this->elephantPause = $this->random->getInt(...$this->elephantInterval);
        }

        $now >= $from + $this->elephantPause and $this->flyers->elephant($now);
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
