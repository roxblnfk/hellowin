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
 * while the transition was in flight, or after the pumpkin reached the last stage of its scene. The moderator may
 * change the thresholds mid-round, so each element keeps the clicks its transitions took, and only the clicks
 * gathered toward the next stage are measured by the threshold in force now.
 */
final readonly class Reveal
{
    /**
     * @param int<0, max> $clicks Accepted clicks.
     * @param int<0, max> $jobs Transition jobs that ran.
     * @param int<0, max> $burned Clicks that moved nothing.
     * @param int<0, max> $shot Flyers shot down.
     * @param int<0, max> $drinks Mugs drunk in the evening: in the bar and in the cursed world.
     * @param array{id: non-empty-string, name: non-empty-string|null}|null $lastPumpkin Who finished the last element of
     *        the last ready scene.
     * @param int<0, max> $slain Zombies slain in the cursed world.
     * @param int<0, max> $damage Strikes FrankenPHP took.
     */
    public function __construct(
        public int $clicks,
        public int $jobs,
        public int $burned,
        public int $shot,
        public ?array $lastPumpkin,
        public int $drinks = 0,
        public int $slain = 0,
        public int $damage = 0,
    ) {}

    /**
     * @param RitualPolicy $policy Clicks a transition of each element takes.
     * @param int<0, max> $shot
     * @param int<0, max> $drinks
     * @param array{id: non-empty-string, name: non-empty-string|null}|null $lastPumpkin
     * @param int<0, max> $slain
     * @param int<0, max> $damage
     */
    public static function of(RitualSnapshot $ritual, RitualPolicy $policy, int $shot, ?array $lastPumpkin, int $drinks = 0, int $slain = 0, int $damage = 0): self
    {
        $k = $ritual->story->k;
        $elements = \array_map(
            static fn(Pumpkin $pumpkin): array => [$pumpkin, $policy->threshold(Element::TYPE_PUMPKIN, $k)],
            $ritual->pumpkins,
        );
        foreach (Element::CENTREPIECES as $type) {
            $centrepiece = $ritual->centrepiece($type);
            $centrepiece === null or $elements[] = [$centrepiece, $policy->threshold($type, $k)];
        }

        $clicks = 0;
        $stages = 0;
        $spent = 0;
        foreach ($elements as [$element, $cost]) {
            $clicks += $element->accepted;
            $stages += $element->stage;
            $spent += $element->spent + \min($element->clicks, $cost);
        }

        return new self($clicks, $stages + $ritual->scene, \max(0, $clicks - $spent), $shot, $lastPumpkin, $drinks, $slain, $damage);
    }

    /**
     * @return array{clicks: int<0, max>, jobs: int<0, max>, burned: int<0, max>, shot: int<0, max>, drinks: int<0, max>, slain: int<0, max>, damage: int<0, max>, lastPumpkin: array{id: non-empty-string, name: non-empty-string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'clicks' => $this->clicks,
            'jobs' => $this->jobs,
            'burned' => $this->burned,
            'shot' => $this->shot,
            'drinks' => $this->drinks,
            'slain' => $this->slain,
            'damage' => $this->damage,
            'lastPumpkin' => $this->lastPumpkin,
        ];
    }
}
