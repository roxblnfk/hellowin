<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

use App\Domain\Element\Element;
use App\Domain\Story\Story;

final readonly class RitualSnapshot
{
    /**
     * @param positive-int $round
     * @param int<0, max> $scene
     * @param list<Pumpkin> $pumpkins
     * @param Pumpkin|null $bonfire Null when the patch has no bonfire.
     * @param Pumpkin|null $circle Null when the patch has no magic circle.
     */
    public function __construct(
        public int $round,
        public int $scene,
        public array $pumpkins,
        public Story $story = new Story(),
        public ?Pumpkin $bonfire = null,
        public ?Pumpkin $circle = null,
    ) {}

    /**
     * A pumpkin or a centrepiece by element id.
     */
    public function pumpkin(string $id): ?Pumpkin
    {
        foreach ([...$this->pumpkins, $this->bonfire, $this->circle] as $pumpkin) {
            if ($pumpkin?->id === $id) {
                return $pumpkin;
            }
        }

        return null;
    }

    /**
     * @param Element::TYPE_BONFIRE|Element::TYPE_CIRCLE $type
     */
    public function centrepiece(string $type): ?Pumpkin
    {
        return $type === Element::TYPE_BONFIRE ? $this->bonfire : $this->circle;
    }

    /**
     * @return Element::TYPE_* The type of the element with the id; a pumpkin unless it is a centrepiece.
     */
    public function typeOf(string $id): string
    {
        return match ($id) {
            $this->bonfire?->id => Element::TYPE_BONFIRE,
            $this->circle?->id => Element::TYPE_CIRCLE,
            default => Element::TYPE_PUMPKIN,
        };
    }

    /**
     * The scene waits for the moderator to move on.
     */
    public function ready(): bool
    {
        return $this->story->ready === $this->scene;
    }
}
