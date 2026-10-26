<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use Psr\SimpleCache\CacheInterface;

/**
 * The counts of the channel presence the clients were told last, in `hellowin:presence` — `{players, connections}`.
 * Only the ticker writes it, so no lock; the snapshot reads it.
 *
 * @psalm-type Counts = array{players: int<0, max>, connections: int<0, max>}
 */
final readonly class PresenceStore
{
    private const KEY = 'hellowin:presence';

    public function __construct(
        private CacheInterface $storage,
    ) {}

    /**
     * @return Counts|null Null — not counted yet.
     */
    public function told(): ?array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        /** @var mixed $players */
        $players = \is_array($value) ? $value['players'] ?? null : null;
        /** @var mixed $connections */
        $connections = \is_array($value) ? $value['connections'] ?? null : null;

        return \is_int($players) && $players >= 0 && \is_int($connections) && $connections >= 0
            ? ['players' => $players, 'connections' => $connections]
            : null;
    }

    /**
     * @param Counts $counts
     */
    public function tell(array $counts): void
    {
        $this->storage->set(self::KEY, $counts);
    }
}
