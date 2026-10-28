<?php

declare(strict_types=1);

namespace App\Application\Flyer;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Player\PlayerService;
use App\Application\Story\CursedService;
use App\Application\Story\OwlLine;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\TopService;
use App\Application\Walk\ActionStop;
use App\Domain\Balance\Burst;
use App\Domain\Clock\Clock;
use App\Domain\Flyer\Flyer;
use App\Domain\Flyer\FlyerFactory;
use App\Domain\Flyer\FlyerStore;
use App\Domain\Flyer\ZombieCrowd;
use App\Domain\Flyer\ZombieFactory;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Player;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\ShotTally;
use App\Domain\Story\Story;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Flyers over the patch: the ticker launches them and sweeps the escaped ones, players shoot them over RPC.
 * Flyers live beside the ritual and do not change it; events carry the round only because every event does.
 */
final readonly class FlyerService
{
    /** Kinds the moderator may launch by hand. */
    public const SPECIAL = [Flyer::KIND_WITCH, Flyer::KIND_ELEPHANT];

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
        private ?CursedService $cursed = null,
        private ?TopService $top = null,
        private ?ZombieCrowd $crowd = null,
        private ?WalkerStore $walkers = null,
        private ?ActionStop $stops = null,
        private ?Burst $burst = null,
        private ?RitualPolicy $policy = null,
    ) {}

    /**
     * Whether the sky is the dawn's: the ticker launches its birds at a pace of their own.
     */
    public function atDawn(): bool
    {
        return $this->ritual->story()->dawn();
    }

    /**
     * The ticker's launch: a flyer of the current scene, or of the cursed world's own set once it has come. The pause
     * holds the scene's progress, not the sky.
     *
     * @param int $now Unix time in milliseconds.
     * @return Flyer|null Null when the scene has no flyers and the launch did not turn out its rare one.
     */
    public function launch(int $now): ?Flyer
    {
        $ritual = $this->ritual->snapshot();
        $story = $ritual->story;
        $flyer = $this->factory->launch($now, $ritual->scene, $story->cursed(), $story->dawn());

        return $flyer === null ? null : $this->release($ritual->round, $flyer);
    }

    /**
     * @return int|null Unix time in milliseconds the last elephant took off, by the ticker or by hand; null — never.
     */
    public function lastElephantAt(): ?int
    {
        return $this->store->lastElephant()?->launchedAt;
    }

    /**
     * The ticker's elephant: anywhere but in the bar — it flies over the open sky — and never while another one is
     * still up. The pause holds the scene's progress, not the sky.
     *
     * @param int $now Unix time in milliseconds.
     * @return Flyer|null Null when the elephant has to wait.
     */
    public function elephant(int $now): ?Flyer
    {
        if ($this->store->lastElephant()?->flyingAt($now) === true) {
            return null;
        }
        $ritual = $this->ritual->snapshot();
        if ($this->policy?->bar($ritual->scene) === true) {
            return null;
        }

        return $this->release($ritual->round, $this->factory->elephant($now));
    }

    /**
     * The ticker's zombies of the cursed world: they stand up to keep the crowd {@see ZombieCrowd} wants, on a pause
     * too; out of the cursed world the ones left there are retired. Under the lock of the crowd, which also
     * reads the story, so a zombie never stands up after the dawn has retired the crowd.
     *
     * @param int $now Unix time in milliseconds.
     * @param bool $due The zombie interval has come round: one more may stand up beyond the minimum.
     * @return list<Flyer> Zombies that stood up.
     * @throws LockBusyException
     */
    public function wander(int $now, bool $due): array
    {
        $zombies = $this->zombies;
        if ($zombies === null || $this->crowd === null) {
            return [];
        }
        if (!$this->ritual->story()->cursed()) {
            $this->retire();

            return [];
        }

        return $this->store->herd(function (array $alive) use ($zombies, $now, $due): array {
            $ritual = $this->ritual->snapshot();
            if (!$ritual->story->cursed()) {
                return [];
            }

            $launched = [];
            $wanted = $this->crowd?->wanted(\count($alive), \count($this->walkers?->all() ?? []), $due) ?? 0;
            for ($i = 0; $i < $wanted; ++$i) {
                $launched[] = $this->release($ritual->round, $zombies->wander($now));
            }

            return $launched;
        });
    }

    /**
     * Takes the wandering zombies off the lawn unhurt, as the dawn comes or the round starts over: one `gone` for the
     * ones still there, none when there are none.
     *
     * @return list<non-empty-string> Ids of the zombies taken off.
     * @throws LockBusyException
     */
    public function retire(): array
    {
        if ($this->store->wanderers() === []) {
            return [];
        }

        return $this->store->herd(function (): array {
            $ids = \array_map(static fn(Flyer $flyer): string => $flyer->id, $this->store->retire());
            $ids === [] or $this->publisher->gone($this->ritual->round(), $ids);

            return $ids;
        });
    }

    /**
     * The wandering zombies of the snapshot: on the cursed world's steps, none elsewhere.
     *
     * @return list<array> Their `flyer` events, without `type` and `round`.
     */
    public function wanderers(Story $story): array
    {
        return $story->cursed()
            ? \array_map(static fn(Flyer $flyer): array => $flyer->toEvent(), $this->store->wanderers())
            : [];
    }

    /**
     * The moderator's special flyer.
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
     * Whether the sky has flyers to burst with now: the set of the scene, the cursed world or the dawn is not empty.
     */
    public function burstable(int $scene, Story $story): bool
    {
        return $this->factory->hasKinds($scene, $story->cursed(), $story->dawn());
    }

    /**
     * The moderator's burst: flyers of the current set, the rare one aside, all launched now. More figures on the
     * floor, more flyers.
     *
     * @param int $now Unix time in milliseconds.
     * @return positive-int Flyers launched.
     * @throws StoryRefusedException `no flyers` — the set is empty.
     * @throws LockBusyException
     */
    public function burst(int $now): int
    {
        $burst = $this->burst ?? throw new StoryRefusedException('no flyers');
        $ritual = $this->ritual->snapshot();
        $story = $ritual->story;
        $this->burstable($ritual->scene, $story) or throw new StoryRefusedException('no flyers');

        $count = $burst->count(\count($this->walkers?->all() ?? []));
        for ($i = 0; $i < $count; ++$i) {
            $flyer = $this->factory->launch($now, $ritual->scene, $story->cursed(), $story->dawn(), rare: false);
            $flyer === null or $this->release($ritual->round, $flyer);
        }

        return $count;
    }

    /**
     * The first shot at a flyer in the sky hits it; any other shot misses. Either way the shooter's character stops,
     * and an empty mug in its hand flies at the flyer (docs/spec/protocol.md, «Пустая кружка»). An elephant is always
     * missed, and the first shot at it makes it fart (docs/spec/protocol.md, «Слон»).
     *
     * @param non-empty-string|null $player
     * @return int|null Points for a hit, null for a miss.
     * @throws LockBusyException
     */
    public function shoot(string $flyerId, ?string $player): ?int
    {
        $now = $this->clock->now();
        $flyer = $this->store->shoot($flyerId, $now);
        $this->stops?->stop($player, ActionStop::SHOOT);
        $this->throwMug($player, $flyerId);
        if ($flyer === null) {
            $this->fart($flyerId, $player, $now);

            return null;
        }

        $round = $this->ritual->round();
        $shooter = $this->players->shotFlyer($player, $flyer->points);
        $this->publisher->shot($round, $flyer, $shooter);
        $shooter === null or $this->topHit($shooter);
        $this->metrics->flyerShot($flyer);
        $this->count($round);
        $flyer->kind === Flyer::KIND_ZOMBIE and $this->slain($round);
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
     * Empties the hand of a character holding an empty mug and tells everybody the mug flies at the flyer — only while
     * the character's own item is shown: in the bar and the cursed world. The throw is a show beside the shot: a
     * failure leaves the mug in hand and the shot stands.
     *
     * @param non-empty-string|null $player
     */
    private function throwMug(?string $player, string $flyer): void
    {
        if ($player === null || $this->walkers === null) {
            return;
        }

        try {
            // Most shots come from hands without a mug: those take no lock
            if (($this->walkers->all()[$player] ?? null)?->hasMug() !== true) {
                return;
            }
            $ritual = $this->ritual->snapshot();
            if (!$ritual->story->cursed() && $this->policy?->bar($ritual->scene) !== true) {
                return;
            }

            $threw = $this->walkers->update(
                /** @param array<non-empty-string, Walker> $walkers */
                static function (array $walkers) use ($player): array {
                    $walker = $walkers[$player] ?? null;
                    if ($walker === null || !$walker->hasMug()) {
                        return [$walkers, false];
                    }
                    $walkers[$player] = $walker->threwMug();

                    return [$walkers, true];
                },
            );
            $threw and $this->publisher->throw($ritual->round, $player, $flyer, $this->clock->now());
        } catch (\Throwable $e) {
            $this->logger->warning('Mug of {player} is not thrown: {message}', [
                'player' => $player,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * The fart is a show beside the miss: a failure there must not turn the miss into an error.
     *
     * @param non-empty-string|null $player
     * @param int $now Unix time in milliseconds of the shot.
     */
    private function fart(string $flyerId, ?string $player, int $now): void
    {
        try {
            $elephant = $this->store->fart($flyerId, $now);
            if ($elephant === null) {
                return;
            }
            $round = $this->ritual->round();
            $this->publisher->fart($round, $elephant, $player, $now);
            $this->publisher->owl($round, OwlLine::FART_TIME);
            $this->metrics->fart();
        } catch (\Throwable $e) {
            $this->logger->warning('Elephant {flyer} did not fart: {message}', [
                'flyer' => $flyerId,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * A slain zombie counts towards the goal of the cursed world; a failure there must not turn a hit into an error.
     */
    private function slain(int $round): void
    {
        try {
            $this->cursed?->slew($round);
        } catch (\Throwable $e) {
            $this->logger->warning('Zombie is not counted for the cursed world: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The shooters' top is a show beside the hit: a failure there must not turn the hit into an error.
     */
    private function topHit(Player $shooter): void
    {
        try {
            $this->top?->hit($shooter);
        } catch (\Throwable $e) {
            $this->logger->warning('Hit is not counted for the top: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
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
