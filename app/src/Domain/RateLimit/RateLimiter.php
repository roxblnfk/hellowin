<?php

declare(strict_types=1);

namespace App\Domain\RateLimit;

use App\Domain\Clock\Clock;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * Token buckets per group of calls and caller. The buckets live in a storage of the RoadRunner process: a limit needs
 * no persistence, and the in-memory KV keeps them across worker restarts. The KV has no atomic update, so the read
 * and the write of a bucket happen under a RoadRunner lock: two workers serving one caller cannot both take its last
 * token.
 */
final readonly class RateLimiter
{
    /**
     * @param array<non-empty-string, TokenBucket> $buckets By group; a group left out is not limited.
     */
    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
        private Clock $clock,
        private array $buckets,
    ) {}

    /**
     * Takes a token of the caller's bucket of the group.
     *
     * @param non-empty-string $group
     * @param non-empty-string $key The caller.
     * @throws \App\Domain\Lock\LockBusyException
     */
    public function allow(string $group, string $key): bool
    {
        $bucket = $this->buckets[$group] ?? null;
        if ($bucket === null) {
            return true;
        }

        $id = \sprintf('hellowin:rate:%s:%s', $group, $key);

        return $this->locks->run([\sprintf('rate:%s:%s', $group, $key)], function () use ($bucket, $id): bool {
            $next = $bucket->take(self::state($this->storage->get($id)), $this->clock->now());
            if ($next === null) {
                return false;
            }

            // A second on top: the KV keeps expiry to whole seconds, and a bucket gone before it is full grants a burst
            $this->storage->set($id, $next, $bucket->refillSeconds() + 1);

            return true;
        });
    }

    /**
     * @return array{tokens: float, at: int}|null
     */
    private static function state(mixed $stored): ?array
    {
        /** @var mixed $tokens */
        $tokens = \is_array($stored) ? $stored['tokens'] ?? null : null;
        /** @var mixed $at */
        $at = \is_array($stored) ? $stored['at'] ?? null : null;

        return \is_float($tokens) && \is_int($at) ? ['tokens' => $tokens, 'at' => $at] : null;
    }
}
