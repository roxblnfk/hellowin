<?php

declare(strict_types=1);

namespace App\Domain\Story;

/**
 * Three d6 drawn from HMAC-SHA256 of the attempt's key (docs/spec/protocol.md, «Кости»): the same key always gives the
 * same throw, so a repeated request answers what the first one did and nothing need be kept for it, while without the
 * secret nobody can tell which attempt rolls 6-6-6.
 *
 * A byte becomes a face only below 252, the largest multiple of six that fits: `byte % 6` over all 256 values would
 * make 1–4 a little likelier than 5 and 6.
 */
final readonly class DiceRoller
{
    public const SIX = [6, 6, 6];

    /** Bytes at and above it are dropped: 252 = 6 × 42. */
    private const LIMIT = 252;

    /**
     * @param \Closure(): non-empty-string $secret Asked on every throw: it may be read from the store.
     */
    public function __construct(
        private \Closure $secret,
    ) {}

    /**
     * @param non-empty-string $secret
     */
    public static function withSecret(string $secret): self
    {
        return new self(static fn(): string => $secret);
    }

    /**
     * @param non-empty-string $player
     * @param positive-int $attempt
     * @return array{int<1, 6>, int<1, 6>, int<1, 6>}
     */
    public function roll(int $round, string $player, int $attempt): array
    {
        foreach ($this->faces(\sprintf('%d:%s:%d', $round, $player, $attempt)) as $dice) {
            return $dice;
        }

        throw new \LogicException('The stream of dice never ends.');
    }

    /**
     * The owl's throw: the first two are anything but 6-6-6 — the next three faces of the same stream when it comes —
     * and the third is 6-6-6 for sure.
     *
     * @param positive-int $attempt
     * @return array{int<1, 6>, int<1, 6>, int<1, 6>}
     */
    public function owl(int $round, int $attempt): array
    {
        if ($attempt >= 3) {
            return self::SIX;
        }

        foreach ($this->faces(\sprintf('%d:owl:%d', $round, $attempt)) as $dice) {
            if ($dice !== self::SIX) {
                return $dice;
            }
        }

        throw new \LogicException('The stream of dice never ends.');
    }

    /**
     * Throws of three faces from the key: the first HMAC block, then blocks of the key with a counter, as long as asked.
     *
     * @return \Generator<int, array{int<1, 6>, int<1, 6>, int<1, 6>}, mixed, void>
     */
    private function faces(string $key): \Generator
    {
        $secret = ($this->secret)();
        $dice = [];
        for ($block = 0; ; ++$block) {
            $bytes = \hash_hmac('sha256', $block === 0 ? $key : $key . "\0" . $block, $secret, true);
            for ($i = 0, $length = \strlen($bytes); $i < $length; ++$i) {
                $byte = \ord($bytes[$i]);
                if ($byte >= self::LIMIT) {
                    continue;
                }
                $dice[] = $byte % 6 + 1;
                if (\count($dice) === 3) {
                    yield $dice;
                    $dice = [];
                }
            }
        }
    }
}
