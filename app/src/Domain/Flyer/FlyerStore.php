<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Flyers in the sky, in a key-value store: `hellowin:flyer:{id}` while the flyer is up, and an index
 * `hellowin:flyers` (id → when it leaves the screen) for the ticker.
 *
 * A flyer ends exactly once: shot ({@see shoot()}) or escaped ({@see sweep()}). Both delete its key under the lock
 * `flyer:{id}`, and only the one that still finds the key counts it — so of two shots one hits, and a flyer shot at
 * the last moment is never also counted as escaped. The ticker and the moderator's special launches both write the
 * index, so it changes under the lock `flyers`.
 *
 * The key expires on its own a little after the flight, in case the ticker is down; a flyer that expires that way
 * is counted neither as shot nor as escaped.
 */
final readonly class FlyerStore
{
    private const KEY_FLYER = 'hellowin:flyer:';
    private const KEY_INDEX = 'hellowin:flyers';
    private const LOCK_FLYER = 'flyer:';
    private const LOCK_INDEX = 'flyers';

    /** Milliseconds a shot may come after the flight: the client starts drawing the flyer only when the event arrives. */
    private const LATENCY = 500;

    /**
     * @param positive-int $grace Milliseconds the key outlives the flight, for the ticker to sweep it.
     */
    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
        private int $grace = 10_000,
    ) {}

    /**
     * @throws LockBusyException
     */
    public function add(Flyer $flyer): void
    {
        $this->storage->set(
            self::KEY_FLYER . $flyer->id,
            $flyer->toArray(),
            (int) \ceil(($flyer->duration + $this->grace) / 1000),
        );

        $this->locks->run([self::LOCK_INDEX], function () use ($flyer): void {
            $index = $this->index();
            $index[$flyer->id] = $flyer->expiresAt();
            $this->storage->set(self::KEY_INDEX, $index);
        });
    }

    /**
     * Shoots the flyer down if it is still in the sky.
     *
     * @param int $now Unix time in milliseconds.
     * @return Flyer|null The flyer that was hit, null for a miss: already shot, gone, unknown or one that cannot be hit.
     * @throws LockBusyException
     */
    public function shoot(string $id, int $now): ?Flyer
    {
        if (!Flyer::isId($id)) {
            return null;
        }

        return $this->locks->run([self::LOCK_FLYER . $id], function () use ($id, $now): ?Flyer {
            $flyer = Flyer::fromArray($this->storage->get(self::KEY_FLYER . $id));
            if ($flyer === null || !$flyer->shootable() || !$flyer->flyingAt($now - self::LATENCY)) {
                return null;
            }

            $this->storage->delete(self::KEY_FLYER . $id);

            return $flyer;
        });
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
            if ($expiresAt + self::LATENCY > $now) {
                continue;
            }
            $swept[] = $id;

            $flyer = $this->locks->run([self::LOCK_FLYER . $id], function () use ($id): ?Flyer {
                $flyer = Flyer::fromArray($this->storage->get(self::KEY_FLYER . $id));
                $flyer === null or $this->storage->delete(self::KEY_FLYER . $id);

                return $flyer;
            });

            $flyer === null or $escaped[] = $flyer;
        }

        // Re-read under the lock: a flyer launched meanwhile must stay in the index
        $swept === [] or $this->locks->run([self::LOCK_INDEX], function () use ($swept): void {
            $this->storage->set(self::KEY_INDEX, \array_diff_key($this->index(), \array_flip($swept)));
        });

        return $escaped;
    }

    /**
     * @return array<non-empty-string, int> Flyer id → Unix time in milliseconds when it leaves the screen.
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
            Flyer::isId($id) && \is_int($expiresAt) and $index[$id] = $expiresAt;
        }

        return $index;
    }
}
