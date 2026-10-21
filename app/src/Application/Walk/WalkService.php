<?php

declare(strict_types=1);

namespace App\Application\Walk;

use App\Application\Bar\BarRefusedException;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Domain\Bar\BarLayout;
use App\Domain\Clock\Clock;
use App\Domain\Element\Element;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Point;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Look;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerStore;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
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
     * @param float $clinkReach Percents of the screen: how far from a pumpkin a character may stand to clink with it.
     * @param BarLayout|null $bar Where characters walk in the bar; without it, or without the policy, they walk the
     *        lawn there too.
     * @param RitualPolicy|null $policy Tells the bar from the other scenes.
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
        private float $clinkReach,
        private Randomizer $random = new Randomizer(),
        private ?BarLayout $bar = null,
        private ?RitualPolicy $policy = null,
    ) {}

    /**
     * @return bool Whether characters walk the bar in the scene.
     */
    public function barIn(int $scene): bool
    {
        return $this->bar !== null && $this->policy?->bar($scene) === true;
    }

    /**
     * Puts every character standing in the scene's location: the room moved there, so nobody walks there. On the lawn
     * each stands at the point nearest to where it was; back in the bar — in its spawn band, as far along as it was,
     * never through the door. Walks to a clink are called off.
     *
     * @return list<Walker>
     * @throws LockBusyException
     */
    public function relocate(int $scene): array
    {
        $now = $this->clock->now();
        $zone = $this->zoneIn($scene);
        $band = $this->bar !== null && $this->barIn($scene) ? $this->bar->spawnY : null;

        return $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($zone, $now, $band): array {
                foreach ($walkers as $id => $walker) {
                    $at = $walker->walk->positionAt($now);
                    $band === null or $at = new Point($at->x, $this->random->getFloat($band[0], $band[1]));
                    $at = self::rounded($zone->clamp($at));
                    $walkers[$id] = $walker->walking(Walk::standing($at, $now))->emptyHanded();
                }

                return [$walkers, \array_values($walkers)];
            },
        );
    }

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
                    : $this->spawn($player->id, $player->name, $look, $now, $this->ritual->scene());
                $walkers[$player->id] = $walker;

                return [$walkers, $walker];
            },
        );

        $ritual = $this->ritual->snapshot();
        $this->publisher->walker($ritual->round, $walker, $this->itemOf($walker, $ritual));

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

        // The scene is read under the lawn lock: a move never lands in the location the room has just left
        $result = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($player, $look, $now, $x, $y): array {
                $scene = $this->ritual->scene();
                $zone = $this->zoneIn($scene);
                $entered = isset($walkers[$player->id]) ? null : $this->spawn($player->id, $player->name, $look, $now, $scene);
                $walker = $entered ?? $walkers[$player->id];

                $target = self::rounded($zone->clamp(new Point($x, $y)));
                $from = self::rounded($walker->walk->positionAt($now));
                $walker = $walker->walking(Walk::along($zone->route($from, $target), $now, $this->speed))->seen($now);
                $walkers[$player->id] = $walker;

                return [$walkers, [$entered, $walker]];
            },
        );
        [$entered, $walker] = $result;

        $ritual = $this->ritual->snapshot();
        $entered === null or $this->publisher->walker($ritual->round, $entered, $this->itemOf($entered, $ritual));
        $this->publisher->move($ritual->round, $walker);

        return $walker->walk;
    }

    /**
     * Sends the character to the counter of the bar for a mug: to the point of the place at the counter nearest to it.
     * Whether a mug is left is settled on arrival ({@see \App\Application\Bar\BarService::takes()}); any new walk calls
     * this one off.
     *
     * @param non-empty-string $id
     * @throws NoCharacterException The player has no character on the lawn.
     * @throws BarRefusedException `not bar` or `has beer`.
     * @throws LockBusyException
     */
    public function toCounter(string $id): Walk
    {
        $now = $this->clock->now();
        $walker = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $now): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $bar = $this->bar;
                $bar !== null && $this->barIn($this->ritual->scene()) or throw new BarRefusedException('not bar');
                $walker->hasBeer() and throw new BarRefusedException('has beer');

                $from = self::rounded($walker->walk->positionAt($now));
                $taken = [];
                foreach ($walkers as $other) {
                    $spot = $other->id === $id ? null : $bar->counterSpot($other->walk->path[\count($other->walk->path) - 1]);
                    $spot === null or $taken[] = $spot;
                }
                $to = self::rounded($bar->atCounter($from, $taken, $this->clinkGap));
                $walk = Walk::along($bar->zone->route($from, $to), $now, $this->speed);
                $walker = $walker->taking($walk, $walk->arrivesAt)->seen($now);
                $walkers[$id] = $walker;

                return [$walkers, $walker];
            },
        );

        $this->publisher->move($this->ritual->round(), $walker);

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
        $walker = $this->store->all()[$id] ?? throw new NoCharacterException('no character');
        $ritual = $this->ritual->snapshot();
        $inBar = $this->barIn($ritual->scene);
        $inBar || $ritual->story->cursed() or throw new ClinkRefusedException('not cursed');
        $inBar && !$walker->hasBeer() and throw new ClinkRefusedException('no beer');
        if ($target === '' || $target === $id || $inBar && $kind === Clink::PUMPKIN) {
            throw new ClinkRefusedException('no target');
        }

        $zone = $this->zoneIn($ritual->scene);
        $pumpkin = null;
        if ($kind === Clink::PUMPKIN) {
            $element = $this->elements->find($target);
            $element !== null && $element->type === Element::TYPE_PUMPKIN && $element->reachable
                or throw new ClinkRefusedException('no target');
            $pumpkin = self::rounded($this->mugSide($element));
        }

        $now = $this->clock->now();
        $walker = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $kind, $target, $pumpkin, $now, $zone, $inBar): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $from = self::rounded($walker->walk->positionAt($now));

                if ($pumpkin !== null) {
                    $walk = Walk::along($zone->route($from, $pumpkin), $now, $this->speed);
                    $clink = new Clink($kind, $target, $walk->arrivesAt);
                } else {
                    // In the bar both clink with their mugs
                    $other = $walkers[$target] ?? null;
                    $other !== null && (!$inBar || $other->hasBeer()) or throw new ClinkRefusedException('no target');
                    $stand = $other->walk->path[\count($other->walk->path) - 1];
                    $meet = self::rounded($zone->beside($stand, $from, $this->clinkGap));
                    $walk = Walk::along($zone->route($from, $meet), $now, $this->speed);
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
        $inBar = $this->barIn($ritual->scene);
        $met = $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            static function (array $walkers) use ($now, $cursed, $inBar): array {
                $met = [];
                foreach (\array_keys($walkers) as $id) {
                    // Read again: a clink earlier in the loop may have clinked this character's mug
                    $walker = $walkers[$id];
                    $clink = $walker->clink;
                    if ($clink === null || !$clink->due($now)) {
                        continue;
                    }

                    $target = $clink->kind === Clink::WALKER ? $walkers[$clink->target] ?? null : null;
                    $inPlace = $clink->kind === Clink::PUMPKIN || $target?->walk->startedAt === $clink->since;
                    // In the bar both still hold their mugs: nobody has drunk up on the way
                    $happens = $inPlace && ($cursed || $inBar && $walker->hasBeer() && $target?->hasBeer() === true);
                    $walkers[$id] = $walker->clinked($happens ? $now : null);
                    if ($happens && $inBar && $target !== null) {
                        $walkers[$id] = $walkers[$id]->clinkedMug();
                        $walkers[$target->id] = $walkers[$target->id]->clinkedMug();
                    }
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
     * A clink in the bar that happened by itself, near enough, as the browser of one of the two saw it: it counts for
     * the caller's mug only, and nobody is told — every viewer plays it anyway (docs/spec/scenario.md, «Чоканье»).
     *
     * @param non-empty-string $id
     * @throws NoCharacterException
     * @throws BarRefusedException `not bar`, `no beer` or `no target`: the partner is gone, is the caller, holds no
     *         beer or stands farther than the bar's clink reach.
     * @throws LockBusyException
     */
    public function clinkAuto(string $id, string $partner): void
    {
        $now = $this->clock->now();
        $this->store->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $partner, $now): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $bar = $this->bar;
                $bar !== null && $this->barIn($this->ritual->scene()) or throw new BarRefusedException('not bar');
                $walker->hasBeer() or throw new BarRefusedException('no beer');

                $other = $partner === $id ? null : $walkers[$partner] ?? null;
                $here = $walker->walk->positionAt($now);
                $there = $other?->walk->positionAt($now);
                $other !== null && $there !== null && $other->hasBeer()
                    && \hypot($there->x - $here->x, $there->y - $here->y) <= $bar->clinkReach
                    or throw new BarRefusedException('no target');

                $walkers[$id] = $walker->clinkedMug();

                return [$walkers, null];
            },
        );
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
                $scene = $newcomers === [] ? 0 : $this->ritual->scene();
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
                        $walkers[$player->id] = $entered[] = $this->spawn($player->id, $player->name, $look, $now, $scene);
                    }
                }

                return [$walkers, [$entered, $left, \count($walkers)]];
            },
        );
        [$entered, $left, $count] = $result;

        if ($entered !== [] || $left !== []) {
            $ritual = $this->ritual->snapshot();
            foreach ($entered as $walker) {
                $this->publisher->walker($ritual->round, $walker, $this->itemOf($walker, $ritual));
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
     * Where a character stands to clink with the pumpkin: on the side of its mug, which the frontend draws outwards
     * from the root of the patch, by the pumpkin's x against the patch centre, and on a pumpkin right above the centre
     * by `crc32(id)` — odd on the left (public/js/pixel/scene.js); the nearest lawn point there, `$clinkGap` aside.
     * Without such a point within `$clinkReach` — the lawn point nearest to the pumpkin.
     */
    private function mugSide(Element $pumpkin): Point
    {
        $at = new Point($pumpkin->x, $pumpkin->y);
        $side = match (true) {
            $pumpkin->x > $this->zone->patch()->cx => 1,
            $pumpkin->x < $this->zone->patch()->cx => -1,
            default => (\crc32($pumpkin->id) & 1) === 1 ? -1 : 1,
        };
        $beside = $this->zone->nearestBeside($at, $side, $this->clinkGap);

        return $beside !== null && \hypot($beside->x - $at->x, $beside->y - $at->y) <= $this->clinkReach
            ? $beside
            : $this->zone->clamp($at);
    }

    /**
     * A new character walks in through the door of the bar, when it has one; elsewhere it stands at a random point of
     * the spawn band along the bottom of the lawn.
     *
     * @param non-empty-string $id
     * @param non-empty-string|null $name
     */
    private function spawn(string $id, ?string $name, Look $look, int $now, int $scene): Walker
    {
        $doorway = $this->barIn($scene) ? $this->bar?->doorway() : null;
        if ($doorway !== null) {
            return new Walker($id, $name, $look, Walk::along([$doorway[0], self::rounded($doorway[1])], $now, $this->speed), $now);
        }

        [$zone, $spawnY] = $this->bar !== null && $this->barIn($scene)
            ? [$this->bar->zone, $this->bar->spawnY]
            : [$this->zone, $this->spawnY];
        $at = $zone->clamp(new Point(
            $this->random->getFloat($zone->x1, $zone->x2),
            $this->random->getFloat($spawnY[0], $spawnY[1]),
        ));

        return new Walker($id, $name, $look, Walk::standing(self::rounded($at), $now), $now);
    }

    /**
     * What the character holds: its own mug or nothing in the bar, the item of the scene and finale step elsewhere.
     *
     * @return non-empty-string|null
     */
    private function itemOf(Walker $walker, RitualSnapshot $ritual): ?string
    {
        return $this->policy?->bar($ritual->scene) === true
            ? $walker->item
            : $this->items->held($ritual->scene, $ritual->story->finale);
    }

    /**
     * The bar's floor in the bar, the lawn elsewhere.
     */
    private function zoneIn(int $scene): WalkZone
    {
        return $this->bar !== null && $this->barIn($scene) ? $this->bar->zone : $this->zone;
    }
}
