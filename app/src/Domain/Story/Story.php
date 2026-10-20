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
    /** The finale step where FrankenPHP stands revealed and the characters run to the edges of the lawn. */
    public const SCATTER_STEP = 2;

    /** The finale step that shows the reveal: the real numbers of the evening. */
    public const REVEAL_STEP = 4;

    /** The last finale step: the cursed world, an endless mode with zombies. */
    public const LAST_STEP = 5;

    /**
     * @param int<0, max>|null $ready Scene that is done, its centrepiece included; it waits for the moderator.
     * @param int<0, 5>|null $finale Finale step; null outside the final scene.
     * @param bool $paused Flyers and zombies are not launched.
     * @param bool $zombies Zombies walk out of the patch in the cursed world.
     * @param non-empty-string|null $lastPumpkin Player who finished the last element of a ready scene: the last
     *        pumpkin, or the centrepiece in a scene that has one: the bonfire or the circle.
     * @param int<0, max>|null $bonfire Scene whose pumpkins are all done while its bonfire is not yet: the bonfire is open.
     */
    public function __construct(
        public ?int $ready = null,
        public ?int $finale = null,
        public bool $paused = false,
        public bool $zombies = false,
        public ?string $lastPumpkin = null,
        public ?int $bonfire = null,
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

        return new self(
            \is_int($ready) && $ready >= 0 ? $ready : null,
            \is_int($finale) && $finale >= 0 && $finale <= self::LAST_STEP ? $finale : null,
            ($value['paused'] ?? false) === true,
            ($value['zombies'] ?? false) === true,
            PlayerId::isValid($player) ? $player : null,
            \is_int($bonfire) && $bonfire >= 0 ? $bonfire : null,
        );
    }

    /**
     * @param int<0, max> $scene
     * @param non-empty-string|null $player
     */
    public function readyIn(int $scene, ?string $player): self
    {
        return new self($scene, $this->finale, $this->paused, $this->zombies, $player ?? $this->lastPumpkin, $this->bonfire);
    }

    /**
     * @param int<0, max> $scene
     */
    public function bonfireOpenIn(int $scene): self
    {
        return new self($this->ready, $this->finale, $this->paused, $this->zombies, $this->lastPumpkin, $scene);
    }

    /**
     * The new scene waits for its pumpkins again; the final one starts the finale.
     */
    public function nextScene(bool $final): self
    {
        return new self(null, $final ? 0 : null, $this->paused, $this->zombies, $this->lastPumpkin);
    }

    /**
     * Goes to the finale step if it is the next one; any other step, or no finale yet, leaves the story as it is, so
     * a repeated press does not skip a step.
     */
    public function stepTo(int $step): self
    {
        return $this->finale !== null && $step === $this->finale + 1 && $step <= self::LAST_STEP
            ? new self($this->ready, $step, $this->paused, $this->zombies, $this->lastPumpkin, $this->bonfire)
            : $this;
    }

    /**
     * The first finale step waits for the final scene to be ready: FrankenPHP rises out of a closed circle.
     */
    public function awaitsReady(int $step): bool
    {
        return $this->finale === 0 && $step === 1 && $this->ready === null;
    }

    public function withPaused(bool $paused): self
    {
        return new self($this->ready, $this->finale, $paused, $this->zombies, $this->lastPumpkin, $this->bonfire);
    }

    public function withZombies(bool $zombies): self
    {
        return new self($this->ready, $this->finale, $this->paused, $zombies, $this->lastPumpkin, $this->bonfire);
    }

    /**
     * A new round starts the story over; the pause stays, it is about the room rather than the story.
     */
    public function restarted(): self
    {
        return new self(paused: $this->paused);
    }

    public function cursed(): bool
    {
        return $this->finale === self::LAST_STEP;
    }

    public function revealed(): bool
    {
        return $this->finale !== null && $this->finale >= self::REVEAL_STEP;
    }

    /**
     * @return array{ready: int<0, max>|null, finale: int<0, 5>|null, paused: bool, zombies: bool, lastPumpkin: non-empty-string|null, bonfire: int<0, max>|null}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'finale' => $this->finale,
            'paused' => $this->paused,
            'zombies' => $this->zombies,
            'lastPumpkin' => $this->lastPumpkin,
            'bonfire' => $this->bonfire,
        ];
    }
}
