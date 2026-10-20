<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Ritual\RitualSnapshot;

/**
 * The real numbers of the round for the finale reveal: the twist of the story is the explanation of the engine.
 *
 * Everything but the shots follows from the ritual state, so clicks pay no extra write: each pumpkin counts its
 * accepted clicks under its own lock, every stage it reached is one transition job that ran, every scene one more,
 * and a transition consumes exactly `threshold` clicks — the rest burned on the idempotency key while the
 * transition was in flight, or after the pumpkin reached the last stage of its scene.
 */
final readonly class Reveal
{
    /**
     * @param int<0, max> $clicks Accepted clicks.
     * @param int<0, max> $jobs Transition jobs that ran.
     * @param int<0, max> $burned Clicks that moved nothing.
     * @param int<0, max> $shot Flyers shot down.
     * @param array{id: non-empty-string, name: non-empty-string|null}|null $lastPumpkin Who finished the last pumpkin.
     */
    public function __construct(
        public int $clicks,
        public int $jobs,
        public int $burned,
        public int $shot,
        public ?array $lastPumpkin,
    ) {}

    /**
     * @param positive-int $threshold Clicks a pumpkin transition takes.
     * @param int<0, max> $shot
     * @param array{id: non-empty-string, name: non-empty-string|null}|null $lastPumpkin
     */
    public static function of(RitualSnapshot $ritual, int $threshold, int $shot, ?array $lastPumpkin): self
    {
        $clicks = 0;
        $stages = 0;
        $pending = 0;
        foreach ($ritual->pumpkins as $pumpkin) {
            $clicks += $pumpkin->accepted;
            $stages += $pumpkin->stage;
            $pending += \min($pumpkin->clicks, $threshold);
        }

        return new self($clicks, $stages + $ritual->scene, \max(0, $clicks - $stages * $threshold - $pending), $shot, $lastPumpkin);
    }

    /**
     * @return array{clicks: int<0, max>, jobs: int<0, max>, burned: int<0, max>, shot: int<0, max>, lastPumpkin: array{id: non-empty-string, name: non-empty-string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'clicks' => $this->clicks,
            'jobs' => $this->jobs,
            'burned' => $this->burned,
            'shot' => $this->shot,
            'lastPumpkin' => $this->lastPumpkin,
        ];
    }
}
