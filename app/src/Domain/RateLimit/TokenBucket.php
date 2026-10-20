<?php

declare(strict_types=1);

namespace App\Domain\RateLimit;

/**
 * A token bucket: it holds up to `burst` tokens, refills at `rate` tokens per second, and every call takes one.
 * Short bursts pass at once, a sustained pace above `rate` runs dry; unlike a fixed window, nothing resets at a
 * boundary, so twice the limit cannot slip through around one.
 */
final readonly class TokenBucket
{
    /**
     * @param float $rate Tokens per second, above zero.
     * @param int $burst Above zero.
     */
    public function __construct(
        public float $rate,
        public int $burst,
    ) {
        $rate > 0 or throw new \InvalidArgumentException('Token bucket rate must be above zero.');
        $burst > 0 or throw new \InvalidArgumentException('Token bucket burst must be positive.');
    }

    /**
     * @param array{tokens: float, at: int}|null $state The bucket after the last call taken from it; null is a full one.
     * @param int $now Unix time in milliseconds.
     * @return array{tokens: float, at: int}|null The state to store, or null when no token is left: the stored state
     *         stays as it was, since the refill counts from its time.
     */
    public function take(?array $state, int $now): ?array
    {
        $tokens = $state === null
            ? (float) $this->burst
            : \min((float) $this->burst, $state['tokens'] + (float) \max(0, $now - $state['at']) * $this->rate / 1000.0);

        return $tokens >= 1.0 ? ['tokens' => $tokens - 1.0, 'at' => $now] : null;
    }

    /**
     * @return positive-int Seconds an emptied bucket takes to fill up: after them a stored state is a full bucket and
     *         may expire.
     */
    public function refillSeconds(): int
    {
        return \max(1, (int) \ceil((float) $this->burst / $this->rate));
    }
}
