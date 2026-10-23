<?php

declare(strict_types=1);

namespace App\Application\Story;

use Predis\ClientInterface;

/**
 * Counters of the round for the finale reveal that grow at the rate of the moves, so they skip the lock and the KV
 * plugin: one Valkey INCR each, `hellowin:tally:{what}`. The handler of a call does not know the round without
 * reading it, so the keys hold the current round only and the reset of the round deletes them; a call already in
 * flight across the reset counts toward the new round.
 *
 * Valkey in trouble never fails the action: a lost count only lowers the number in the reveal.
 */
final readonly class EveningTally
{
    /** Player actions that reached the RPC handler, whatever the answer. */
    public const CALLS = 'calls';

    /** Repeats the idempotency dropped outside the element clicks: those the reveal counts as burned clicks already. */
    public const DROPPED = 'dropped';

    private const PREFIX = 'hellowin:tally:';

    /** A safety net for a stack left running: a round lasts an evening, the reset deletes the keys anyway. */
    private const TTL = 172800;

    public function __construct(
        private ClientInterface $valkey,
    ) {}

    /**
     * @param self::CALLS|self::DROPPED $what
     */
    public function add(string $what): void
    {
        $key = self::PREFIX . $what;
        try {
            // Only the first count of a round pays the second round trip
            $this->valkey->incr($key) === 1 and $this->valkey->expire($key, self::TTL);
        } catch (\Throwable) {
        }
    }

    /**
     * @param self::CALLS|self::DROPPED $what
     * @return int<0, max>
     */
    public function count(string $what): int
    {
        try {
            $value = (int) $this->valkey->get(self::PREFIX . $what);
        } catch (\Throwable) {
            return 0;
        }

        return \max(0, $value);
    }

    public function clear(): void
    {
        try {
            $this->valkey->del(self::PREFIX . self::CALLS, self::PREFIX . self::DROPPED);
        } catch (\Throwable) {
        }
    }
}
