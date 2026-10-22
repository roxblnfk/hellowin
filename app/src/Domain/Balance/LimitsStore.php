<?php

declare(strict_types=1);

namespace App\Domain\Balance;

use Psr\SimpleCache\CacheInterface;

/**
 * What the limits were last told to the clients, in `hellowin:limits` — `{round, told}` — and the count of figures the
 * ticker waits to hold, in `hellowin:limits:pending` — `{players, since}`.
 *
 * No lock: the ticker settles the limits every second and RPC calls of the console now and then, and two of them
 * racing at most publish the same event twice or leave a stale one for the next tick to put right.
 */
final readonly class LimitsStore
{
    private const KEY = 'hellowin:limits';
    private const KEY_PENDING = 'hellowin:limits:pending';

    public function __construct(
        private CacheInterface $storage,
    ) {}

    /**
     * @return array|null The last `limits` event of the round without `type` and `round`; null — none in this round.
     */
    public function told(int $round): ?array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $told */
        $told = \is_array($value) && ($value['round'] ?? null) === $round ? $value['told'] ?? null : null;

        return \is_array($told) ? $told : null;
    }

    public function tell(int $round, array $event): void
    {
        $this->storage->set(self::KEY, ['round' => $round, 'told' => $event]);
    }

    /**
     * @return array{players: int<0, max>, since: int}|null The count the ticker has seen since `since`, in ms.
     */
    public function pending(): ?array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY_PENDING);
        /** @var mixed $players */
        $players = \is_array($value) ? $value['players'] ?? null : null;
        /** @var mixed $since */
        $since = \is_array($value) ? $value['since'] ?? null : null;

        return \is_int($players) && $players >= 0 && \is_int($since) ? ['players' => $players, 'since' => $since] : null;
    }

    /**
     * @param int<0, max> $players
     * @param int $since Unix time in milliseconds.
     */
    public function pend(int $players, int $since): void
    {
        $this->storage->set(self::KEY_PENDING, ['players' => $players, 'since' => $since]);
    }

    public function settled(): void
    {
        $this->storage->delete(self::KEY_PENDING);
    }
}
