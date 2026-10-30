<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Player\Ability;
use App\Domain\Player\Look;
use App\Domain\Player\PlayerId;

/**
 * A player's character on the lawn. Name, look and ability are copied from the player when the character enters or the
 * player registers again, so the snapshot reads one key instead of every player.
 *
 * In the bar and in the cursed world the character holds its own item — a beer, the empty mug, the rapier or
 * nothing — and remembers whether it has clinked with this mug; everywhere else every character holds the item of the
 * scene. A character that said the wrong word is a zombie the server walks.
 */
final readonly class Walker
{
    /** A mug: from the bar's counter or out of a pumpkin of the cursed world. */
    public const BEER = 'beer';

    /** The mug drunk up: it is neither drunk nor clinked, a new beer replaces it, a shot throws it. */
    public const MUG = 'mug';

    /** The rapier of the one who said the word. */
    public const RAPIER = 'rapier';

    /** Milliseconds between the shambles of a zombie the server walks, min and max. */
    public const SHAMBLE_PAUSE = [2000, 4000];

    private const ITEMS = [self::BEER, self::MUG, self::RAPIER];

    /**
     * @param non-empty-string $id Public id of the player.
     * @param non-empty-string|null $name
     * @param int $seenAt Unix time in milliseconds when the player was last seen connected.
     * @param Clink|null $clink The clink the character is walking to; any new walk cancels it.
     * @param int|null $clinkedAt Unix time in milliseconds of the character's last clink.
     * @param self::BEER|self::MUG|self::RAPIER|null $item The character's own item: {@see BEER}, {@see MUG},
     *        {@see RAPIER} or empty hands.
     * @param bool $clinked The mug in hand has been clinked: it may be drunk.
     * @param int|null $takeAt Unix time in milliseconds when the character reaches the counter, or the pumpkin of
     *        `$takeFrom`, for a mug; any new walk cancels it.
     * @param non-empty-string|null $takeFrom The pumpkin the character walks to for a beer; null — the bar's counter.
     * @param int|null $zombieAt Unix time in milliseconds of the next shamble of a zombie; null — not a zombie.
     * @param int|null $clinkedUntil Unix time in milliseconds when the clink of the mug in hand has played: the mug
     *        may not be drunk before it, so a click never skips the animation.
     * @param int|null $busyUntil Unix time in milliseconds until which the hands are busy: a sip plays, or a pumpkin
     *        is still handing a mug over; no mug is taken or drunk before it.
     * @param int|null $zombieUntil Unix time in milliseconds a zombie turns human by itself; null — only a cure does.
     * @param Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN|null $ability The player's, copied like the name and the look.
     * @param int<0, max>|null $pumpkin The pumpkin the player turned the character into, a number the client picks its
     *        look by; any new walk turns it back.
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public Look $look,
        public Walk $walk,
        public int $seenAt,
        public ?Clink $clink = null,
        public ?int $clinkedAt = null,
        public ?string $item = null,
        public bool $clinked = false,
        public ?int $takeAt = null,
        public ?string $takeFrom = null,
        public ?int $zombieAt = null,
        public ?int $clinkedUntil = null,
        public ?int $busyUntil = null,
        public ?int $zombieUntil = null,
        public ?string $ability = null,
        public ?int $pumpkin = null,
    ) {}

    public static function fromArray(mixed $id, mixed $value): ?self
    {
        if (!PlayerId::isValid($id) || !\is_array($value)) {
            return null;
        }

        /** @var mixed $name */
        $name = $value['name'] ?? null;
        $look = Look::fromArray($value['look'] ?? null);
        $walk = Walk::fromArray($value['walk'] ?? null);
        /** @var mixed $seenAt */
        $seenAt = $value['seenAt'] ?? null;
        /** @var mixed $clinkedAt */
        $clinkedAt = $value['clinkedAt'] ?? null;
        /** @var mixed $takeAt */
        $takeAt = $value['takeAt'] ?? null;
        /** @var mixed $takeFrom */
        $takeFrom = $value['takeFrom'] ?? null;
        /** @var mixed $zombieAt */
        $zombieAt = $value['zombieAt'] ?? null;
        /** @var mixed $item */
        $item = $value['item'] ?? null;
        $item = \in_array($item, self::ITEMS, true) ? $item : null;
        $clinked = $item === self::BEER && ($value['clinked'] ?? false) === true;
        /** @var mixed $clinkedUntil */
        $clinkedUntil = $value['clinkedUntil'] ?? null;
        /** @var mixed $busyUntil */
        $busyUntil = $value['busyUntil'] ?? null;
        /** @var mixed $zombieUntil */
        $zombieUntil = $value['zombieUntil'] ?? null;
        /** @var mixed $pumpkin */
        $pumpkin = $value['pumpkin'] ?? null;

        return $look !== null && $walk !== null && \is_int($seenAt)
            ? new self(
                $id,
                \is_string($name) && $name !== '' ? $name : null,
                $look,
                $walk,
                $seenAt,
                Clink::fromArray($value['clink'] ?? null),
                \is_int($clinkedAt) ? $clinkedAt : null,
                $item,
                $clinked,
                \is_int($takeAt) ? $takeAt : null,
                \is_int($takeAt) && \is_string($takeFrom) && $takeFrom !== '' ? $takeFrom : null,
                \is_int($zombieAt) ? $zombieAt : null,
                $clinked && \is_int($clinkedUntil) ? $clinkedUntil : null,
                \is_int($busyUntil) ? $busyUntil : null,
                \is_int($zombieAt) && \is_int($zombieUntil) ? $zombieUntil : null,
                Ability::fromValue($value['ability'] ?? null),
                \is_int($pumpkin) && $pumpkin >= 0 ? $pumpkin : null,
            )
            : null;
    }

    /**
     * Sets off on a new walk, which cancels the clink or the mug the character was walking to.
     */
    public function walking(Walk $walk): self
    {
        return $this->with(walk: $walk, clink: null, takeAt: null);
    }

    /**
     * Stops the character where it is, as its player acts: a stop is a walk of its own, so it cancels the clink or
     * the mug walked to. A zombie the server walks is never stopped.
     *
     * @param int $now Unix time in milliseconds.
     * @return self|null Null when nothing stops: the character stands already, or is a zombie.
     */
    public function stoppedAt(int $now): ?self
    {
        $walk = $this->zombie() ? null : $this->walk->stoppedAt($now);

        return $walk === null ? null : $this->walking($walk);
    }

    public function clinking(Walk $walk, Clink $clink): self
    {
        return $this->with(walk: $walk, clink: $clink, takeAt: null);
    }

    /**
     * Walks to the counter, or to a pumpkin, for a mug: it is taken, or not, on arrival.
     *
     * @param int $at Unix time in milliseconds of the arrival.
     * @param non-empty-string|null $pumpkin The pumpkin that hands the beer out; null — the bar's counter.
     */
    public function taking(Walk $walk, int $at, ?string $pumpkin = null): self
    {
        return $this->with(walk: $walk, clink: null, takeAt: $at, takeFrom: $pumpkin);
    }

    /**
     * The walk for a mug is over: with a fresh mug, not clinked yet, in place of the empty one or empty hands; or
     * with what it held when the counter was empty.
     *
     * @param int|null $handedAt Unix time in milliseconds when the mug is in hand after a hand-over; null — at once.
     */
    public function tookMug(bool $taken, ?int $handedAt = null): self
    {
        return $taken
            ? $this->with(item: self::BEER, clinked: false, takeAt: null, clinkedUntil: null, busyUntil: $handedAt ?? false)
            : $this->with(takeAt: null);
    }

    /**
     * The mug in hand counts a clink: it may be drunk once the clink has played. A mug clinked already keeps its
     * clink, so clinking it again changes nothing.
     *
     * @param int $until Unix time in milliseconds when the clink has played.
     */
    public function clinkedMug(int $until): self
    {
        return $this->item === self::BEER && !$this->clinked ? $this->with(clinked: true, clinkedUntil: $until) : $this;
    }

    /**
     * Empty hands: the room has left the bar.
     */
    public function emptyHanded(): self
    {
        return $this->with(item: null, clinked: false, clinkedUntil: null);
    }

    /**
     * The mug is drunk: the empty mug in hand, busy with the sip until `$until`.
     *
     * @param int $until Unix time in milliseconds.
     */
    public function drank(int $until): self
    {
        return $this->with(item: self::MUG, clinked: false, clinkedUntil: null, busyUntil: $until);
    }

    /**
     * The empty mug is thrown at a flyer: empty hands.
     */
    public function threwMug(): self
    {
        return $this->with(item: null);
    }

    public function hasMug(): bool
    {
        return $this->item === self::MUG;
    }

    /**
     * A fresh mug in hand: the cursed world hands one out to everybody as it begins.
     */
    public function withBeer(): self
    {
        return $this->with(item: self::BEER, clinked: false, takeAt: null, clinkedUntil: null);
    }

    /**
     * The word was right: the rapier in hand instead of whatever was there.
     */
    public function armed(): self
    {
        return $this->with(item: self::RAPIER, clinked: false, clinkedUntil: null);
    }

    /**
     * Whether the clink of the mug in hand is still playing.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function mugClinking(int $now): bool
    {
        return $this->clinkedUntil !== null && $now < $this->clinkedUntil;
    }

    /**
     * Whether a sip or a hand-over is still playing.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function busyAt(int $now): bool
    {
        return $this->busyUntil !== null && $now < $this->busyUntil;
    }

    /**
     * The word was wrong: the character stops where it is and the server walks it from `$next` on.
     *
     * @param int $now Unix time in milliseconds.
     * @param int $next Unix time in milliseconds of its first shamble.
     * @param int|null $until Unix time in milliseconds it turns human by itself; null — only a cure does.
     */
    public function zombified(int $now, int $next, ?int $until = null): self
    {
        return $this->with(walk: Walk::standing($this->walk->positionAt($now), $now), clink: null, takeAt: null, zombieAt: $next, zombieUntil: $until, pumpkin: $this->pumpkin);
    }

    /**
     * Stands where the server puts it with no `move` sent: no client sees a new walk, so a pumpkin stays one.
     */
    public function placed(Walk $walk): self
    {
        return $this->with(walk: $walk, clink: null, takeAt: null, pumpkin: $this->pumpkin);
    }

    /**
     * The player turned the character into a pumpkin: it stays one until it walks again.
     *
     * @param int<0, max> $pumpkin The client picks the pumpkin's look by it.
     */
    public function disguised(int $pumpkin): self
    {
        return $this->with(pumpkin: $pumpkin);
    }

    /**
     * Itself again, where it stands: the round starts over.
     */
    public function unmasked(): self
    {
        return $this->with(pumpkin: null);
    }

    /**
     * Whether the zombie's own time is up: it turns human with no cure.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function zombieDue(int $now): bool
    {
        return $this->zombie() && $this->zombieUntil !== null && $now >= $this->zombieUntil;
    }

    /**
     * The zombie shambles on: a walk of the server's and the time of the next one.
     *
     * @param int $next Unix time in milliseconds.
     */
    public function shambling(Walk $walk, int $next): self
    {
        return $this->with(walk: $walk, zombieAt: $next);
    }

    /**
     * No longer a zombie: the character stands where it is, its item as before.
     */
    public function cured(): self
    {
        return $this->with(zombieAt: null, zombieUntil: null);
    }

    public function zombie(): bool
    {
        return $this->zombieAt !== null;
    }

    public function hasBeer(): bool
    {
        return $this->item === self::BEER;
    }

    public function hasRapier(): bool
    {
        return $this->item === self::RAPIER;
    }

    /**
     * The clink the character walked to has happened or fallen through.
     *
     * @param int|null $at Unix time in milliseconds of the clink; null when it fell through.
     */
    public function clinked(?int $at): self
    {
        return $this->with(clink: null, clinkedAt: $at ?? $this->clinkedAt);
    }

    /**
     * Whether a clink of the character is still playing.
     *
     * @param int $now Unix time in milliseconds.
     * @param positive-int $duration Milliseconds a clink plays.
     */
    public function clinkPlaying(int $now, int $duration): bool
    {
        return $this->clinkedAt !== null && $now < $this->clinkedAt + $duration;
    }

    /**
     * Whether the character has reached the counter of the bar for a mug.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function atCounter(int $now): bool
    {
        return $this->takeAt !== null && $this->takeFrom === null && $now >= $this->takeAt;
    }

    /**
     * Whether the character has reached the pumpkin it walked to for a beer.
     *
     * @param int $now Unix time in milliseconds.
     */
    public function atPumpkin(int $now): bool
    {
        return $this->takeAt !== null && $this->takeFrom !== null && $now >= $this->takeAt;
    }

    /**
     * @param non-empty-string|null $name
     * @param Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN|null $ability
     */
    public function dressed(?string $name, Look $look, ?string $ability): self
    {
        return new self($this->id, $name, $look, $this->walk, $this->seenAt, $this->clink, $this->clinkedAt, $this->item, $this->clinked, $this->takeAt, $this->takeFrom, $this->zombieAt, $this->clinkedUntil, $this->busyUntil, $this->zombieUntil, $ability, $this->pumpkin);
    }

    /**
     * @param Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN|null $ability
     */
    public function withAbility(?string $ability): self
    {
        return $this->dressed($this->name, $this->look, $ability);
    }

    /**
     * @param int $now Unix time in milliseconds.
     */
    public function seen(int $now): self
    {
        return $this->with(seenAt: $now);
    }

    /**
     * The character as the client contract shows it (docs/spec/protocol.md, `Walker`).
     *
     * @param non-empty-string|null $item What the character holds: the item of the scene and finale step, or in the
     *        bar and the cursed world its own ({@see $item}).
     * @return array{id: non-empty-string, name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, item: non-empty-string|null, walk: array, clink?: array, zombie?: true, zombieUntil?: int, ability?: non-empty-string, pumpkin?: int<0, max>}
     */
    public function toArray(?string $item = null): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'look' => $this->look->toArray(),
            'item' => $item,
            'walk' => $this->walk->toArray(),
        ];
        $this->clink === null or $data['clink'] = $this->clink->toTarget();
        $this->zombie() and $data['zombie'] = true;
        $this->zombie() && $this->zombieUntil !== null and $data['zombieUntil'] = $this->zombieUntil;
        $this->ability === null or $data['ability'] = $this->ability;
        $this->pumpkin === null or $data['pumpkin'] = $this->pumpkin;

        return $data;
    }

    /**
     * @return array{name: non-empty-string|null, look: array<non-empty-string, non-empty-string>, walk: array, seenAt: int, clink?: array, clinkedAt?: int, item?: non-empty-string, clinked?: true, takeAt?: int, takeFrom?: non-empty-string, zombieAt?: int, clinkedUntil?: int, busyUntil?: int, zombieUntil?: int, ability?: non-empty-string, pumpkin?: int<0, max>}
     */
    public function toRecord(): array
    {
        $record = [
            'name' => $this->name,
            'look' => $this->look->toArray(),
            'walk' => $this->walk->toArray(),
            'seenAt' => $this->seenAt,
        ];
        $this->clink === null or $record['clink'] = $this->clink->toArray();
        $this->clinkedAt === null or $record['clinkedAt'] = $this->clinkedAt;
        $this->item === null or $record['item'] = $this->item;
        $this->clinked and $record['clinked'] = true;
        $this->takeAt === null or $record['takeAt'] = $this->takeAt;
        $this->takeFrom === null or $record['takeFrom'] = $this->takeFrom;
        $this->zombieAt === null or $record['zombieAt'] = $this->zombieAt;
        $this->clinkedUntil === null or $record['clinkedUntil'] = $this->clinkedUntil;
        $this->busyUntil === null or $record['busyUntil'] = $this->busyUntil;
        $this->zombieUntil === null or $record['zombieUntil'] = $this->zombieUntil;
        $this->ability === null or $record['ability'] = $this->ability;
        $this->pumpkin === null or $record['pumpkin'] = $this->pumpkin;

        return $record;
    }

    /**
     * A copy with the given fields changed; a field left out keeps its value, `takeFrom` goes with `takeAt`, and a new
     * `walk` turns a pumpkin back unless `pumpkin` is given.
     *
     * @param self::BEER|self::MUG|self::RAPIER|null|false $item False keeps the item.
     * @param non-empty-string|null $takeFrom
     * @param int<0, max>|null|false $pumpkin
     */
    private function with(
        ?Walk $walk = null,
        Clink|false|null $clink = false,
        int|false|null $clinkedAt = false,
        string|false|null $item = false,
        ?bool $clinked = null,
        int|false|null $takeAt = false,
        ?string $takeFrom = null,
        int|false|null $zombieAt = false,
        ?int $seenAt = null,
        int|false|null $clinkedUntil = false,
        int|false|null $busyUntil = false,
        int|false|null $zombieUntil = false,
        int|false|null $pumpkin = false,
    ): self {
        return new self(
            $this->id,
            $this->name,
            $this->look,
            $walk ?? $this->walk,
            $seenAt ?? $this->seenAt,
            $clink === false ? $this->clink : $clink,
            $clinkedAt === false ? $this->clinkedAt : $clinkedAt,
            $item === false ? $this->item : $item,
            $clinked ?? $this->clinked,
            $takeAt === false ? $this->takeAt : $takeAt,
            $takeAt === false ? $this->takeFrom : $takeFrom,
            $zombieAt === false ? $this->zombieAt : $zombieAt,
            $clinkedUntil === false ? $this->clinkedUntil : $clinkedUntil,
            $busyUntil === false ? $this->busyUntil : $busyUntil,
            $zombieUntil === false ? $this->zombieUntil : $zombieUntil,
            $this->ability,
            // Every new walk passes here: the client turns the pumpkin back on any `move` of the character
            $pumpkin === false ? ($walk === null ? $this->pumpkin : null) : $pumpkin,
        );
    }
}
