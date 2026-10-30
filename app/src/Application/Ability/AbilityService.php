<?php

declare(strict_types=1);

namespace App\Application\Ability;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Story\StoryRefusedException;
use App\Application\Walk\ActionStop;
use App\Application\Walk\NoCharacterException;
use App\Domain\Clock\Clock;
use App\Domain\Flyer\Point;
use App\Domain\Flyer\Strike;
use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use App\Domain\Player\Ability;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use Psr\SimpleCache\CacheInterface;
use Random\Randomizer;

/**
 * A player uses the ability (docs/spec/protocol.md, «Способности»): everyone sees the `power`, the flyers' one also
 * launches a flock up from the character. The character walks on, a zombie may use it, the pause does not hold it —
 * but the lightning is a shot and stops the walk, and a pumpkin stands.
 *
 * Each ability has a cooldown of its own per player: `hellowin:ability:{id}` keeps when each one is ready again,
 * read and written under the lock `ability:{id}`, so two calls at once cannot both pass.
 */
final readonly class AbilityService
{
    private const KEY = 'hellowin:ability:';
    private const LOCK = 'ability:';

    /** The pumpkin's number stays exact in a JavaScript number. */
    private const MAX_N = 0x7FFFFFFF;

    /**
     * @param CacheInterface $storage Lives as long as the RoadRunner process: a cooldown needs no persistence.
     * @param array<Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN, int<0, max>> $cooldowns Milliseconds by ability.
     * @param positive-int $flock Flyers of one flock.
     */
    public function __construct(
        private WalkerStore $walkers,
        private PlayerStore $players,
        private FlyerService $flyers,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private PlayMetrics $metrics,
        private Clock $clock,
        private CacheInterface $storage,
        private Locks $locks,
        private array $cooldowns,
        private int $flock,
        private ?ActionStop $stops = null,
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * @param non-empty-string $id
     * @param mixed $flyer The lightning's target, as the client names it; the other abilities take none.
     * @return array{ability: Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN, readyAt: int, hit?: bool, n?: int<0, max>}
     * @throws NoCharacterException
     * @throws StoryRefusedException `no ability`, `no flyers` — the current set has none, `no target` — the lightning
     *         has no flyer in the sky to strike.
     * @throws AbilityBusyException
     * @throws LockBusyException
     */
    public function use(string $id, mixed $flyer = null): array
    {
        $walker = $this->walkers->all()[$id] ?? throw new NoCharacterException('no character');
        $ability = $this->players->find($id)?->ability ?? throw new StoryRefusedException('no ability');
        $now = $this->clock->now();

        [$readyAt, $strike, $stopped, $n] = $this->locks->run(
            [self::LOCK . $id],
            /** @return array{int, Strike|null, Walker|null, int<0, max>|null} */
            function () use ($id, $ability, $flyer, $now): array {
                $ready = $this->ready($id);
                $at = $ready[$ability] ?? 0;
                $now < $at and throw new AbilityBusyException($at);
                // Checked after the cooldown, as the order of the errors goes, and before the cooldown is spent
                $ability === Ability::FLYERS && !$this->flyersNow() and throw new StoryRefusedException('no flyers');
                // The bolt and the pumpkin take effect before the cooldown is spent: one with no flyer to strike,
                // or a lawn too busy for the pumpkin, costs the player nothing
                $strike = $ability === Ability::LIGHTNING ? $this->bolt($flyer, $now) : null;
                [$stopped, $n] = $ability === Ability::PUMPKIN ? $this->disguise($id, $now) : [null, null];

                $ready[$ability] = $now + ($this->cooldowns[$ability] ?? 0);
                $this->storage->set(self::KEY . $id, $ready, $this->ttl());

                return [$ready[$ability], $strike, $stopped, $n];
            },
        );

        $round = $this->ritual->round();
        $used = ['ability' => $ability, 'readyAt' => $readyAt];
        if ($strike !== null && \is_string($flyer)) {
            // A shot stops the walk before its event, and the bolt is shown before the flyer falls
            $this->stops?->stop($id, ActionStop::SHOOT);
            $this->publisher->power($round, $id, $ability, $now, ['flyer' => $flyer, 'hit' => $strike->hit !== null]);
            $this->flyers->landed($flyer, $strike->hit, $id, $now);
            $used['hit'] = $strike->hit !== null;
        } elseif ($n !== null) {
            $stopped === null or $this->publisher->move($round, $stopped, ActionStop::PUMPKIN);
            $this->publisher->power($round, $id, $ability, $now, ['n' => $n]);
            $used['n'] = $n;
        } else {
            $this->publisher->power($round, $id, $ability, $now);
            $ability === Ability::FLYERS and $this->flyers->rise($this->flock, self::rounded($walker->walk->positionAt($now)), $id, $now);
        }
        $this->metrics->abilityUsed($ability);

        return $used;
    }

    private static function rounded(Point $point): Point
    {
        return new Point(\round($point->x, 1), \round($point->y, 1));
    }

    /**
     * @param int $now Unix time in milliseconds.
     * @throws StoryRefusedException `no target`.
     * @throws LockBusyException
     */
    private function bolt(mixed $flyer, int $now): Strike
    {
        return (\is_string($flyer) ? $this->flyers->strike($flyer, $now) : null)
            ?? throw new StoryRefusedException('no target');
    }

    /**
     * Turns the character into a pumpkin where it is: a walking one stops in the same write, as a sip stops it.
     *
     * @param non-empty-string $id
     * @param int $now Unix time in milliseconds.
     * @return array{Walker|null, int<0, max>} The character stopped, null when it stood already or is a zombie the
     *         server walks; the pumpkin's number.
     * @throws NoCharacterException
     * @throws LockBusyException
     */
    private function disguise(string $id, int $now): array
    {
        /** @var int<0, max> $n */
        $n = $this->random->getInt(0, self::MAX_N);
        $stopped = $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            static function (array $walkers) use ($id, $now, $n): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $stopped = $walker->stoppedAt($now);
                $walkers[$id] = ($stopped ?? $walker)->disguised($n);

                return [$walkers, $stopped];
            },
        );

        return [$stopped, $n];
    }

    private function flyersNow(): bool
    {
        $ritual = $this->ritual->snapshot();

        return $this->flyers->burstable($ritual->scene, $ritual->story);
    }

    /**
     * @param non-empty-string $id
     * @return array<string, int> When each ability of the player is ready, by ability.
     */
    private function ready(string $id): array
    {
        /** @var mixed $stored */
        $stored = $this->storage->get(self::KEY . $id);
        $ready = [];
        /** @var mixed $at */
        foreach (\is_array($stored) ? $stored : [] as $ability => $at) {
            \is_int($at) and $ready[(string) $ability] = $at;
        }

        return $ready;
    }

    /**
     * @return positive-int Seconds the record outlives the longest cooldown; the KV keeps expiry to whole seconds.
     */
    private function ttl(): int
    {
        return \max(0, \intdiv(\max([0, ...\array_values($this->cooldowns)]), 1000)) + 2;
    }
}
