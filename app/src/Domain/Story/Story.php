<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Player\PlayerId;

/**
 * What the moderator leads beside the ritual: whether the scene waits for the next one, the finale step and the
 * console switches. Stored next to the ritual and changed under the same `scene` lock, so a reset clears it together
 * with the round.
 */
final readonly class Story
{
    /** The silhouette rises out of the circle: the room rolls the dice until someone rolls 6-6-6. */
    public const DICE_STEP = 1;

    /** The finale step where FrankenPHP stands revealed and the characters run to the edges of the lawn. */
    public const SCATTER_STEP = 2;

    /** The finale step that shows the reveal: the real numbers of the evening. */
    public const REVEAL_STEP = 4;

    /** The cursed world: the room drinks and slays zombies towards its goal, and it is ready for the rapier. */
    public const CURSED_STEP = 5;

    /** The owl is back with the rapier: players say the word, and the first rapier wakes the boss. */
    public const RAPIER_STEP = 6;

    /** The dawn: the night is over, and the moderator's next scene leads the room back to the bar. */
    public const DAWN_STEP = 7;

    /** The last finale step: the dawn, an endless mode until the room goes back to the bar. */
    public const LAST_STEP = self::DAWN_STEP;

    /** Bounds of the multiplier the moderator may set. */
    public const K_MIN = 0.1;

    public const K_MAX = 10.0;

    /**
     * @param int<0, max>|null $ready Scene that is done, its centrepiece included; it waits for the moderator.
     * @param int<0, 7>|null $finale Finale step; null outside the final scene.
     * @param bool $paused Flyers and zombies are not launched.
     * @param non-empty-string|null $lastPumpkin Player who finished the last element of a ready scene: the last
     *        pumpkin, or the centrepiece in a scene that has one: the bonfire or the circle.
     * @param int<0, max>|null $bonfire Scene whose pumpkins are all done while its bonfire is not yet: the bonfire is open.
     * @param float $k Multiplier of every limit, from {@see K_MIN} to {@see K_MAX}.
     * @param int $barBonus The moderator's addition to the bar's goal; gone with the round.
     * @param bool $roadrunner RoadRunner stands behind the bar's counter: the dawn has led the room back to the bar, and
     *        only the moderator's reset sends him away.
     * @param int<0, max> $players Figures on the floor the limits are counted for: the ticker moves it once a count has
     *        held for a while, so a flickering connection does not shake the goals. Like `$k`, it is about the room.
     */
    public function __construct(
        public ?int $ready = null,
        public ?int $finale = null,
        public bool $paused = false,
        public ?string $lastPumpkin = null,
        public ?int $bonfire = null,
        public float $k = 1.0,
        public int $barBonus = 0,
        public bool $roadrunner = false,
        public int $players = 0,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (!\is_array($value)) {
            return new self();
        }

        /** @var mixed $ready */
        $ready = $value['ready'] ?? null;
        /** @var mixed $finale */
        $finale = $value['finale'] ?? null;
        /** @var mixed $player */
        $player = $value['lastPumpkin'] ?? null;
        /** @var mixed $bonfire */
        $bonfire = $value['bonfire'] ?? null;
        /** @var mixed $k */
        $k = $value['k'] ?? null;
        /** @var mixed $barBonus */
        $barBonus = $value['barBonus'] ?? null;
        /** @var mixed $players */
        $players = $value['players'] ?? null;

        return new self(
            \is_int($ready) && $ready >= 0 ? $ready : null,
            \is_int($finale) && $finale >= 0 && $finale <= self::DAWN_STEP ? $finale : null,
            ($value['paused'] ?? false) === true,
            PlayerId::isValid($player) ? $player : null,
            \is_int($bonfire) && $bonfire >= 0 ? $bonfire : null,
            self::isK($k) ? (float) $k : 1.0,
            \is_int($barBonus) ? $barBonus : 0,
            ($value['roadrunner'] ?? false) === true,
            \is_int($players) && $players > 0 ? $players : 0,
        );
    }

    /**
     * @psalm-assert-if-true int|float $k
     */
    public static function isK(mixed $k): bool
    {
        return (\is_int($k) || \is_float($k)) && $k >= self::K_MIN && $k <= self::K_MAX;
    }

    /**
     * @param int<0, max> $scene
     * @param non-empty-string|null $player
     */
    public function readyIn(int $scene, ?string $player): self
    {
        return new self($scene, $this->finale, $this->paused, $player ?? $this->lastPumpkin, $this->bonfire, $this->k, $this->barBonus, $this->roadrunner, $this->players);
    }

    /**
     * @param int<0, max> $scene
     */
    public function bonfireOpenIn(int $scene): self
    {
        return new self($this->ready, $this->finale, $this->paused, $this->lastPumpkin, $scene, $this->k, $this->barBonus, $this->roadrunner, $this->players);
    }

    /**
     * The new scene waits for its pumpkins again; the final one starts the finale.
     */
    public function nextScene(bool $final): self
    {
        return new self(null, $final ? 0 : null, $this->paused, $this->lastPumpkin, null, $this->k, $this->barBonus, $this->roadrunner, $this->players);
    }

    /**
     * Goes to the finale step if it is the next one; any other step, or no finale yet, leaves the story as it is, so
     * a repeated press does not skip a step.
     */
    public function stepTo(int $step): self
    {
        if ($this->finale === null || $step !== $this->finale + 1 || $step > self::LAST_STEP) {
            return $this;
        }
        /** @var int<1, 7> $step */

        // The dice and the cursed world are ready by their own goal, not by the circle closed long ago
        $ready = $step === self::DICE_STEP || $step === self::CURSED_STEP ? null : $this->ready;

        return new self($ready, $step, $this->paused, $this->lastPumpkin, $this->bonfire, $this->k, $this->barBonus, $this->roadrunner, $this->players);
    }

    /**
     * A step that waits for the room: the first one for the closed circle FrankenPHP rises out of, the second for the
     * first 6-6-6 of the dice, the rapier for the goal of the cursed world, the dawn for the fallen boss — which the moderator never has at hand: the boss's last
     * hit takes the dawn by itself.
     */
    public function awaitsReady(int $step): bool
    {
        return match (true) {
            $this->finale === 0 && $step === self::DICE_STEP,
            $this->finale === self::DICE_STEP && $step === self::SCATTER_STEP,
            $this->finale === self::CURSED_STEP && $step === self::RAPIER_STEP => $this->ready === null,
            $this->finale === self::RAPIER_STEP && $step === self::DAWN_STEP => true,
            default => false,
        };
    }

    public function withPaused(bool $paused): self
    {
        return new self($this->ready, $this->finale, $paused, $this->lastPumpkin, $this->bonfire, $this->k, $this->barBonus, $this->roadrunner, $this->players);
    }

    public function withK(float $k): self
    {
        return new self($this->ready, $this->finale, $this->paused, $this->lastPumpkin, $this->bonfire, $k, $this->barBonus, $this->roadrunner, $this->players);
    }

    public function withBarBonus(int $bonus): self
    {
        return new self($this->ready, $this->finale, $this->paused, $this->lastPumpkin, $this->bonfire, $this->k, $bonus, $this->roadrunner, $this->players);
    }

    /**
     * @param int<0, max> $players
     */
    public function withPlayers(int $players): self
    {
        return new self($this->ready, $this->finale, $this->paused, $this->lastPumpkin, $this->bonfire, $this->k, $this->barBonus, $this->roadrunner, $players);
    }

    /**
     * A new round starts the story over; the pause, the multiplier and the figures the limits are counted for stay,
     * they are about the room rather than the story, while the moderator's bonuses go with the round. RoadRunner comes
     * to the bar with a new cycle from the dawn and leaves with the moderator's reset.
     */
    public function restarted(bool $again = false): self
    {
        return new self(paused: $this->paused, k: $this->k, roadrunner: $again, players: $this->players);
    }

    /**
     * The cursed world: its own flyers and zombies, beer from the pumpkins, clinks and drinks — the goal step and the
     * rapier step after it.
     */
    public function cursed(): bool
    {
        return $this->finale === self::CURSED_STEP || $this->finale === self::RAPIER_STEP;
    }

    public function dice(): bool
    {
        return $this->finale === self::DICE_STEP;
    }

    public function rapier(): bool
    {
        return $this->finale === self::RAPIER_STEP;
    }

    public function dawn(): bool
    {
        return $this->finale === self::DAWN_STEP;
    }

    public function revealed(): bool
    {
        return $this->finale !== null && $this->finale >= self::REVEAL_STEP;
    }

    /**
     * @return array{ready: int<0, max>|null, finale: int<0, 7>|null, paused: bool, lastPumpkin: non-empty-string|null, bonfire: int<0, max>|null, k: float, barBonus: int, roadrunner: bool, players: int<0, max>}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'finale' => $this->finale,
            'paused' => $this->paused,
            'lastPumpkin' => $this->lastPumpkin,
            'bonfire' => $this->bonfire,
            'k' => $this->k,
            'barBonus' => $this->barBonus,
            'roadrunner' => $this->roadrunner,
            'players' => $this->players,
        ];
    }
}
