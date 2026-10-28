<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Flyers in the sky, in a key-value store: `hellowin:flyer:{id}` while the flyer is up, and an index
 * `hellowin:flyers` (id → when it leaves the screen, null for a wandering zombie) for the ticker.
 *
 * A flyer ends exactly once: shot ({@see shoot()}), escaped ({@see sweep()}) or, a wandering zombie, retired
 * ({@see retire()}). Each deletes its key under the lock `flyer:{id}`, and only the one that still finds the key counts
 * it — so of two shots one hits, and a flyer shot at the last moment is never also counted as escaped. The ticker and
 * the moderator's special launches both write the index, so it changes under the lock `flyers`.
 *
 * The key expires on its own a little after the flight, in case the ticker is down; a flyer that expires that way
 * is counted neither as shot nor as escaped. A wandering zombie has no flight: its key outlives any evening and the
 * sweep passes it by, it ends shot or retired.
 *
 * An elephant is never shot down, it ends escaped: its key keeps `lastFartAt` instead, read and written under its lock
 * by every shot ({@see fart()}), so of any number of shots at once one farts, and the next fart waits out the cooldown.
 * The last elephant launched stays under `hellowin:elephant` with no expiry: the ticker counts the pause to the next one
 * from it, wherever it came from.
 */
final readonly class FlyerStore
{
    private const KEY_FLYER = 'hellowin:flyer:';
    private const KEY_INDEX = 'hellowin:flyers';
    private const KEY_ELEPHANT = 'hellowin:elephant';
    private const LAST_FART_AT = 'lastFartAt';
    private const LOCK_FLYER = 'flyer:';
    private const LOCK_INDEX = 'flyers';
    private const LOCK_WANDERERS = 'wanderers';

    /** Milliseconds a shot may come after the flight: the client starts drawing the flyer only when the event arrives. */
    private const LATENCY = 500;

    /** Seconds the key of a wandering zombie lives if nothing retires it. */
    private const WANDER_TTL = 12 * 3600;

    /**
     * @param positive-int $grace Milliseconds the key outlives the flight, for the ticker to sweep it.
     * @param positive-int $fartCooldown Milliseconds from an elephant's fart to the next one it may make.
     */
    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
        private int $grace = 10_000,
        private int $fartCooldown = 1000,
    ) {}

    /**
     * @throws LockBusyException
     */
    public function add(Flyer $flyer): void
    {
        $this->storage->set(
            self::KEY_FLYER . $flyer->id,
            $flyer->toArray(),
            $flyer->wander ? self::WANDER_TTL : (int) \ceil(($flyer->duration + $this->grace) / 1000),
        );

        $this->locks->run([self::LOCK_INDEX], function () use ($flyer): void {
            $index = $this->index();
            $index[$flyer->id] = $flyer->wander ? null : $flyer->expiresAt();
            $this->storage->set(self::KEY_INDEX, $index);
            $flyer->kind === Flyer::KIND_ELEPHANT and $this->storage->set(self::KEY_ELEPHANT, $flyer->toArray());
        });
    }

    /**
     * The last elephant launched, flying or long gone.
     */
    public function lastElephant(): ?Flyer
    {
        return Flyer::fromArray($this->storage->get(self::KEY_ELEPHANT));
    }

    /**
     * Makes the elephant fart if it is still in the sky and its last fart is at least the cooldown behind.
     *
     * @param int $now Unix time in milliseconds.
     * @return Fart|null Null for any other flyer, a late shot or one within the cooldown.
     * @throws LockBusyException
     */
    public function fart(string $id, int $now): ?Fart
    {
        if (!Flyer::isId($id)) {
            return null;
        }

        return $this->locks->run([self::LOCK_FLYER . $id], function () use ($id, $now): ?Fart {
            /** @var mixed $data */
            $data = $this->storage->get(self::KEY_FLYER . $id);
            $flyer = Flyer::fromArray($data);
            if (!\is_array($data) || $flyer === null || $flyer->downable() || !$flyer->flyingAt($now - self::LATENCY)) {
                return null;
            }
            $last = isset($data[self::LAST_FART_AT]) && \is_int($data[self::LAST_FART_AT]) ? $data[self::LAST_FART_AT] : null;
            if ($last !== null && $now - $last < $this->fartCooldown) {
                return null;
            }

            $data[self::LAST_FART_AT] = $now;
            // The key keeps its expiry: the sweep still finds it, and it still goes away if the ticker is down
            $ttl = (int) \ceil(($flyer->expiresAt() + $this->grace - $now) / 1000);
            $this->storage->set(self::KEY_FLYER . $id, $data, \max(1, $ttl));

            return new Fart($flyer, $last === null);
        });
    }

    /**
     * Shoots the flyer down if it is still in the sky and a shot can bring it down.
     *
     * @param int $now Unix time in milliseconds.
     * @return Flyer|null The flyer that was hit, null for a miss: already shot, gone, unknown or an elephant.
     * @throws LockBusyException
     */
    public function shoot(string $id, int $now): ?Flyer
    {
        if (!Flyer::isId($id)) {
            return null;
        }

        $flyer = $this->locks->run([self::LOCK_FLYER . $id], function () use ($id, $now): ?Flyer {
            $flyer = Flyer::fromArray($this->storage->get(self::KEY_FLYER . $id));
            if ($flyer === null || !$flyer->downable() || !$flyer->flyingAt($now - self::LATENCY)) {
                return null;
            }

            $this->storage->delete(self::KEY_FLYER . $id);

            return $flyer;
        });

        // The sweep never takes a wanderer off the index, so the shot does
        $flyer !== null && $flyer->wander and $this->unindex([$flyer->id]);

        return $flyer;
    }

    /**
     * Takes every flyer that has left the screen off the index.
     *
     * @param int $now Unix time in milliseconds.
     * @return list<Flyer> Flyers that escaped: nobody shot them.
     * @throws LockBusyException
     */
    public function sweep(int $now): array
    {
        $escaped = [];
        $swept = [];

        foreach ($this->index() as $id => $expiresAt) {
            if ($expiresAt === null || $expiresAt + self::LATENCY > $now) {
                continue;
            }
            $swept[] = $id;

            $flyer = $this->take($id);
            $flyer === null or $escaped[] = $flyer;
        }

        $this->unindex($swept);

        return $escaped;
    }

    /**
     * Wandering zombies still on the lawn.
     *
     * @return list<Flyer>
     */
    public function wanderers(): array
    {
        $wanderers = [];
        foreach ($this->index() as $id => $expiresAt) {
            $flyer = $expiresAt === null ? Flyer::fromArray($this->storage->get(self::KEY_FLYER . $id)) : null;
            $flyer !== null && $flyer->wander and $wanderers[] = $flyer;
        }

        return $wanderers;
    }

    /**
     * Runs `$operation` over the wandering zombies under the lock `wanderers`: the crowd it tops up or retires is not
     * changed by another top-up meanwhile.
     *
     * @template T
     * @param \Closure(list<Flyer>): T $operation
     * @return T
     * @throws LockBusyException
     */
    public function herd(\Closure $operation): mixed
    {
        return $this->locks->run([self::LOCK_WANDERERS], fn(): mixed => $operation($this->wanderers()));
    }

    /**
     * Takes every wandering zombie off the lawn unhurt.
     *
     * @return list<Flyer> The ones still there: not shot meanwhile.
     * @throws LockBusyException
     */
    public function retire(): array
    {
        $ids = \array_keys(\array_filter($this->index(), static fn(?int $expiresAt): bool => $expiresAt === null));
        $retired = [];
        foreach ($ids as $id) {
            $flyer = $this->take($id);
            $flyer === null or $retired[] = $flyer;
        }
        $this->unindex($ids);

        return $retired;
    }

    /**
     * Deletes the flyer's key under its lock.
     *
     * @param non-empty-string $id
     * @return Flyer|null The flyer if the key was still there.
     * @throws LockBusyException
     */
    private function take(string $id): ?Flyer
    {
        return $this->locks->run([self::LOCK_FLYER . $id], function () use ($id): ?Flyer {
            $flyer = Flyer::fromArray($this->storage->get(self::KEY_FLYER . $id));
            $flyer === null or $this->storage->delete(self::KEY_FLYER . $id);

            return $flyer;
        });
    }

    /**
     * Re-reads the index under its lock: a flyer launched meanwhile must stay in it.
     *
     * @param list<non-empty-string> $ids
     * @throws LockBusyException
     */
    private function unindex(array $ids): void
    {
        $ids === [] or $this->locks->run([self::LOCK_INDEX], function () use ($ids): void {
            $this->storage->set(self::KEY_INDEX, \array_diff_key($this->index(), \array_flip($ids)));
        });
    }

    /**
     * @return array<non-empty-string, int|null> Flyer id → Unix time in milliseconds when it leaves the screen, null
     *         for a wandering zombie.
     */
    private function index(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY_INDEX);
        if (!\is_array($value)) {
            return [];
        }

        $index = [];
        /** @var mixed $expiresAt */
        foreach ($value as $id => $expiresAt) {
            Flyer::isId($id) && ($expiresAt === null || \is_int($expiresAt)) and $index[$id] = $expiresAt;
        }

        return $index;
    }
}
