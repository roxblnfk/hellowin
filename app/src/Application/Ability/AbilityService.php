<?php

declare(strict_types=1);

namespace App\Application\Ability;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Story\StoryRefusedException;
use App\Application\Walk\NoCharacterException;
use App\Domain\Clock\Clock;
use App\Domain\Flyer\Point;
use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use App\Domain\Player\Ability;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\WalkerStore;
use Psr\SimpleCache\CacheInterface;

/**
 * A player uses the ability (docs/spec/protocol.md, «Способности»): everyone sees the `power`, the flyers' one also
 * launches a flock up from the character. The character walks on, a zombie may use it, the pause does not hold it.
 *
 * Each ability has a cooldown of its own per player: `hellowin:ability:{id}` keeps when each one is ready again,
 * read and written under the lock `ability:{id}`, so two calls at once cannot both pass.
 */
final readonly class AbilityService
{
    private const KEY = 'hellowin:ability:';
    private const LOCK = 'ability:';

    /**
     * @param CacheInterface $storage Lives as long as the RoadRunner process: a cooldown needs no persistence.
     * @param array<Ability::FLYERS|Ability::FART|Ability::MEEP, int<0, max>> $cooldowns Milliseconds by ability.
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
    ) {}

    /**
     * @param non-empty-string $id
     * @return array{ability: Ability::FLYERS|Ability::FART|Ability::MEEP, readyAt: int}
     * @throws NoCharacterException
     * @throws StoryRefusedException `no ability`, `no flyers` — the current set has none.
     * @throws AbilityBusyException
     * @throws LockBusyException
     */
    public function use(string $id): array
    {
        $walker = $this->walkers->all()[$id] ?? throw new NoCharacterException('no character');
        $ability = $this->players->find($id)?->ability ?? throw new StoryRefusedException('no ability');
        $now = $this->clock->now();

        $readyAt = $this->locks->run([self::LOCK . $id], function () use ($id, $ability, $now): int {
            $ready = $this->ready($id);
            $at = $ready[$ability] ?? 0;
            $now < $at and throw new AbilityBusyException($at);
            // Checked after the cooldown, as the order of the errors goes, and before the cooldown is spent
            $ability === Ability::FLYERS && !$this->flyersNow() and throw new StoryRefusedException('no flyers');

            $ready[$ability] = $now + ($this->cooldowns[$ability] ?? 0);
            $this->storage->set(self::KEY . $id, $ready, $this->ttl());

            return $ready[$ability];
        });

        $round = $this->ritual->round();
        $this->publisher->power($round, $id, $ability, $now);
        $ability === Ability::FLYERS and $this->flyers->rise($this->flock, self::rounded($walker->walk->positionAt($now)), $id, $now);
        $this->metrics->abilityUsed($ability);

        return ['ability' => $ability, 'readyAt' => $readyAt];
    }

    private static function rounded(Point $point): Point
    {
        return new Point(\round($point->x, 1), \round($point->y, 1));
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
