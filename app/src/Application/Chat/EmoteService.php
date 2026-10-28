<?php

declare(strict_types=1);

namespace App\Application\Chat;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Walk\NoCharacterException;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\RateLimit\RateLimiter;
use App\Domain\RateLimit\TokenBucket;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\WalkerStore;

/**
 * The emotes (docs/spec/protocol.md, «Эмоции»): an emoji over the player's character for everyone. Nothing is kept,
 * so a latecomer never sees it; the scene, the pause and a zombie character do not matter.
 *
 * The pause between two emotes of a player is a token bucket of one token that refills in the pause: the RPC rate
 * limit's buckets, under its lock, in a group of its own.
 */
final readonly class EmoteService
{
    /** The limiter's group of the pause. */
    public const GROUP = 'emote';

    /**
     * @param non-empty-list<non-empty-string> $emotes
     * @param RateLimiter $cooldown Takes the pause from {@see bucket()} under {@see GROUP}.
     */
    public function __construct(
        private array $emotes,
        private WalkerStore $walkers,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private Clock $clock,
        private RateLimiter $cooldown,
        private PlayMetrics $metrics,
    ) {}

    /**
     * @param int<0, max> $ms Milliseconds between two emotes of a player.
     * @return array<self::GROUP, TokenBucket> No bucket — no pause.
     */
    public static function bucket(int $ms): array
    {
        return $ms === 0 ? [] : [self::GROUP => new TokenBucket(1000.0 / (float) $ms, 1)];
    }

    /**
     * @param non-empty-string $id
     * @throws NoCharacterException
     * @throws ChatRefusedException `unknown emoji`, `busy` — too soon after the player's last emote.
     * @throws LockBusyException
     */
    public function emote(string $id, mixed $emoji): void
    {
        isset($this->walkers->all()[$id]) or throw new NoCharacterException('no character');
        \is_string($emoji) && \in_array($emoji, $this->emotes, true) or throw new ChatRefusedException('unknown emoji');
        $this->cooldown->allow(self::GROUP, $id) or throw new ChatRefusedException('busy');

        $this->publisher->emote($this->ritual->round(), $id, $emoji, $this->clock->now());
        $this->metrics->emote($emoji);
    }
}
