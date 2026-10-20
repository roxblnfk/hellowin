<?php

declare(strict_types=1);

namespace App\Application\Walk;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Domain\Clock\Clock;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Point;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Look;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\Clink;
use App\Domain\Walk\Items;
use App\Domain\Walk\Walk;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use App\Domain\Walk\WalkZone;
use Random\Randomizer;

/**
 * Players' characters on the lawn (docs/spec/players.md, «На поле»).
 *
 * A character enters when its player registers with a look or, connected with a look, shows up in the channel
 * presence; it leaves when the presence has not listed the player for `$leaveAfter` milliseconds. The ticker
 * compares the presence with the lawn once a second ({@see sync()}); the delay lets a reloaded page keep its
 * character where it stood.
 */
final readonly class WalkService
{
    /**
     * @param float $speed Percents of the screen per second along the path.
     * @param positive-int $leaveAfter Milliseconds without a connection before the character leaves.
     * @param array{float, float} $spawnY Top and bottom of the band new characters appear in.
     * @param float $clinkGap Percents of the screen between two characters that clink.
     * @param positive-int $clinkDuration Milliseconds a clink plays; a new clink of the same player is refused meanwhile.
     */
    public function __construct(
        private WalkerStore $store,
        private WalkZone $zone,
        private PlayerStore $players,
        private Presence $presence,
        private ChannelPublisher $publisher,
        private RitualStore $ritual,
        private PlayMetrics $metrics,
        private Clock $clock,
        private float $speed,
        private int $leaveAfter,
        private array $spawnY,
        private Items $items,
        private ElementRegistry $elements,
        private float $clinkGap,
        private int $clinkDuration,
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * Puts the player's character on the lawn, or dresses the one already there in the player's name and look.
     *
     * @return Walker|null Null for a player without a look: such a player stays off the lawn.
     * @throws LockBusyException
     */
    public function enter(Player $player): ?Walker
    {
        $look = $player->look;
        if ($look === null) {
            return null;
        }

        $now = $this->clock->now();
        $walker = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($player, $look, $now): array {
                $walker = isset($walkers[$player->id])
                    ? $walkers[$player->id]->dressed($player->name, $look)->seen($now)
                    : $this->spawn($player->id, $player->name, $look, $now);
                $walkers[$player->id] = $walker;

                return [$walkers, $walker];
            },
        );

        $ritual = $this->ritual->snapshot();
        $this->publisher->walker($ritual->round, $walker, $this->items->held($ritual->scene, $ritual->story->finale));

        return $walker;
    }

    /**
     * Sends the character from where it is now to the lawn point nearest to the given one.
     *
     * @param non-empty-string $id
     * @throws NoCharacterException The player has not picked a look.
     * @throws LockBusyException
     */
    public function move(string $id, float $x, float $y): Walk
    {
        $player = $this->players->find($id);
        $look = $player?->look;
        if ($player === null || $look === null) {
            throw new NoCharacterException('no character');
        }

        $now = $this->clock->now();
        $target = self::rounded($this->zone->clamp(new Point($x, $y)));

        $result = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($player, $look, $now, $target): array {
                $entered = isset($walkers[$player->id]) ? null : $this->spawn($player->id, $player->name, $look, $now);
                $walker = $entered ?? $walkers[$player->id];

                $from = self::rounded($walker->walk->positionAt($now));
                $walker = $walker->walking(Walk::along($this->zone->route($from, $target), $now, $this->speed))->seen($now);
                $walkers[$player->id] = $walker;

                return [$walkers, [$entered, $walker]];
            },
        );
        [$entered, $walker] = $result;

        $ritual = $this->ritual->snapshot();
        $entered === null or $this->publisher->walker($ritual->round, $entered, $this->items->held($ritual->scene, $ritual->story->finale));
        $this->publisher->move($ritual->round, $walker);

        return $walker->walk;
    }

    /**
     * Sends the character to clink with another character or a reachable pumpkin (docs/spec/scenario.md, «Чоканье»):
     * to the side of the character where it stands or is heading, or to the lawn point nearest to the pumpkin. The
     * clink itself happens on arrival, in {@see clinks()}.
     *
     * @param non-empty-string $id
     * @param Clink::WALKER|Clink::PUMPKIN $kind
     * @throws NoCharacterException The player has no character on the lawn.
     * @throws ClinkRefusedException
     * @throws LockBusyException
     */
    public function clink(string $id, string $kind, string $target): Walk
    {
        if (!isset($this->store->all()[$id])) {
            throw new NoCharacterException('no character');
        }
        if (!$this->ritual->snapshot()->story->cursed()) {
            throw new ClinkRefusedException('not cursed');
        }
        if ($target === '' || $target === $id) {
            throw new ClinkRefusedException('no target');
        }

        $pumpkin = null;
        if ($kind === Clink::PUMPKIN) {
            $element = $this->elements->find($target);
            $element !== null && $element->type === Element::TYPE_PUMPKIN && $element->reachable
                or throw new ClinkRefusedException('no target');
            $pumpkin = self::rounded($this->zone->clamp(new Point($element->x, $element->y)));
        }

        $now = $this->clock->now();
        $walker = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $kind, $target, $pumpkin, $now): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $from = self::rounded($walker->walk->positionAt($now));

                if ($pumpkin !== null) {
                    $walk = Walk::along($this->zone->route($from, $pumpkin), $now, $this->speed);
                    $clink = new Clink($kind, $target, $walk->arrivesAt);
                } else {
                    $other = $walkers[$target] ?? throw new ClinkRefusedException('no target');
                    $stand = $other->walk->path[\count($other->walk->path) - 1];
                    $meet = self::rounded($this->zone->beside($stand, $from, $this->clinkGap));
                    $walk = Walk::along($this->zone->route($from, $meet), $now, $this->speed);
                    $clink = new Clink($kind, $target, \max($walk->arrivesAt, $other->walk->arrivesAt), $other->walk->startedAt);
                }

                $walker->clinkPlaying($now, $this->clinkDuration) and throw new ClinkRefusedException('busy');
                $walker = $walker->clinking($walk, $clink)->seen($now);
                $walkers[$id] = $walker;

                return [$walkers, $walker];
            },
        );

        $this->publisher->move($this->ritual->round(), $walker);

        return $walker->walk;
    }

    /**
     * Clinks whose characters have arrived: the ticker calls it every tick. A clink happens when the target is in
     * place — the pumpkin always, a character when it has not set off since the clink was asked for — and the world
     * is still cursed; either way the clink is done with, under the lawn lock, so it is published once.
     *
     * @throws LockBusyException
     */
    public function clinks(): void
    {
        $now = $this->clock->now();
        $due = \array_filter($this->store->all(), static fn(Walker $walker): bool => $walker->clink?->due($now) ?? false);
        if ($due === []) {
            return;
        }

        $ritual = $this->ritual->snapshot();
        $cursed = $ritual->story->cursed();
        $met = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            static function (array $walkers) use ($now, $cursed): array {
                $met = [];
                foreach ($walkers as $id => $walker) {
                    $clink = $walker->clink;
                    if ($clink === null || !$clink->due($now)) {
                        continue;
                    }

                    $target = $clink->kind === Clink::WALKER ? $walkers[$clink->target] ?? null : null;
                    $inPlace = $clink->kind === Clink::PUMPKIN || $target?->walk->startedAt === $clink->since;
                    $happens = $cursed && $inPlace;
                    $walkers[$id] = $walker->clinked($happens ? $now : null);
                    $happens and $met[] = [$id, $clink];
                }

                return [$walkers, $met];
            },
        );

        foreach ($met as [$id, $clink]) {
            $this->publisher->clink(
                $ritual->round,
                $id,
                $clink->toTarget(),
                $this->random->getInt(0, 0x7FFFFFFF),
                $now,
            );
        }
    }

    /**
     * Matches the lawn to the presence: players with a look who connected enter, characters whose players have been
     * gone for `$leaveAfter` leave.
     *
     * @throws LockBusyException
     */
    public function sync(): void
    {
        $players = $this->presence->players();
        $online = \array_fill_keys($players, true);
        $now = $this->clock->now();

        // Players are read before the lock: the lawn lock is held only for the lawn
        $on = $this->store->all();
        $newcomers = [];
        foreach ($players as $id) {
            $player = isset($on[$id]) ? null : $this->players->find($id);
            $look = $player?->look;
            $player === null || $look === null or $newcomers[] = [$player, $look];
        }

        $result = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($online, $newcomers, $now): array {
                $entered = [];
                $left = [];
                foreach ($walkers as $walker) {
                    if (isset($online[$walker->id])) {
                        $walkers[$walker->id] = $walker->seen($now);
                    } elseif ($now - $walker->seenAt >= $this->leaveAfter) {
                        unset($walkers[$walker->id]);
                        $left[] = $walker->id;
                    }
                }
                foreach ($newcomers as [$player, $look]) {
                    if (!isset($walkers[$player->id])) {
                        $walkers[$player->id] = $entered[] = $this->spawn($player->id, $player->name, $look, $now);
                    }
                }

                return [$walkers, [$entered, $left, \count($walkers)]];
            },
        );
        [$entered, $left, $count] = $result;

        if ($entered !== [] || $left !== []) {
            $ritual = $this->ritual->snapshot();
            $item = $this->items->held($ritual->scene, $ritual->story->finale);
            foreach ($entered as $walker) {
                $this->publisher->walker($ritual->round, $walker, $item);
            }
            foreach ($left as $id) {
                $this->publisher->leave($ritual->round, $id);
            }
        }
        $this->metrics->walkers($count);
    }

    /**
     * Every character on the lawn runs from the patch to the edge of the lawn, as a move of its own.
     *
     * @throws LockBusyException
     */
    public function scatter(): void
    {
        $now = $this->clock->now();
        $moved = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($now): array {
                foreach ($walkers as $id => $walker) {
                    $from = self::rounded($walker->walk->positionAt($now));
                    $to = self::rounded($this->zone->outward($from));
                    $walkers[$id] = $walker->walking(Walk::along($this->zone->route($from, $to), $now, $this->speed));
                }

                return [$walkers, \array_values($walkers)];
            },
        );

        $round = $this->ritual->round();
        foreach ($moved as $walker) {
            $this->publisher->move($round, $walker);
        }
    }

    private static function rounded(Point $point): Point
    {
        return new Point(\round($point->x, 2), \round($point->y, 2));
    }

    /**
     * A new character stands at a random point of the spawn band along the bottom of the lawn.
     *
     * @param non-empty-string $id
     * @param non-empty-string|null $name
     */
    private function spawn(string $id, ?string $name, Look $look, int $now): Walker
    {
        $at = $this->zone->clamp(new Point(
            $this->random->getFloat($this->zone->x1, $this->zone->x2),
            $this->random->getFloat($this->spawnY[0], $this->spawnY[1]),
        ));

        return new Walker($id, $name, $look, Walk::standing(self::rounded($at), $now), $now);
    }
}
