<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Element\Element;
use App\Domain\Ritual\Pumpkin;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;

/**
 * The real numbers of the round for the finale reveal: the twist of the story is the explanation of the engine.
 *
 * Everything but the shots follows from the ritual state, so clicks pay no extra write: each pumpkin and centrepiece
 * count their accepted clicks under their own lock, every stage they reached is one transition job that ran, every
 * scene one more, and a transition consumes exactly its threshold of clicks — the rest burned on the idempotency key
 * while the transition was in flight, or after the pumpkin reached the last stage of its scene.
 */
final readonly class Reveal
{
    /**
     * @param int<0, max> $clicks Accepted clicks.
     * @param int<0, max> $jobs Transition jobs that ran.
     * @param int<0, max> $burned Clicks that moved nothing.
     * @param int<0, max> $shot Flyers shot down.
     * @param array{id: non-empty-string, name: non-empty-string|null}|null $lastPumpkin Who finished the last element of
     *        the last ready scene.
     */
    public function __construct(
        public int $clicks,
        public int $jobs,
        public int $burned,
        public int $shot,
        public ?array $lastPumpkin,
    ) {}

    /**
     * @param RitualPolicy $policy Clicks a transition of each element takes.
     * @param int<0, max> $shot
     * @param array{id: non-empty-string, name: non-empty-string|null}|null $lastPumpkin
     */
    public static function of(RitualSnapshot $ritual, RitualPolicy $policy, int $shot, ?array $lastPumpkin): self
    {
        $elements = \array_map(static fn(Pumpkin $pumpkin): array => [$pumpkin, $policy->threshold()], $ritual->pumpkins);
        foreach (Element::CENTREPIECES as $type) {
            $centrepiece = $ritual->centrepiece($type);
            $centrepiece === null or $elements[] = [$centrepiece, $policy->threshold($type)];
        }

        $clicks = 0;
        $stages = 0;
        $spent = 0;
        foreach ($elements as [$element, $cost]) {
            $clicks += $element->accepted;
            $stages += $element->stage;
            $spent += $element->stage * $cost + \min($element->clicks, $cost);
        }

        return new self($clicks, $stages + $ritual->scene, \max(0, $clicks - $spent), $shot, $lastPumpkin);
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
