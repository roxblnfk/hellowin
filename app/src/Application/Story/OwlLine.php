<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Domain\Element\Element;

/**
 * Lines of the owl's catalogue the server says on its own; texts are on the frontend (docs/spec/scenario.md,
 * «Модератор и сова»). The moderator may send any line id of the same shape.
 */
final class OwlLine
{
    public const SCENE_READY = 'scene-ready';
    public const LAST_PUMPKIN = 'last-pumpkin';
    public const BONFIRE_OPEN = 'bonfire-open';
    public const BONFIRE_DONE = 'bonfire-done';
    public const CIRCLE_DONE = 'circle-done';
    public const WITCH_DOWN = 'witch-down';
    public const BAR_EMPTY = 'bar-empty';
    public const BAR_INTRO = 'bar-intro';
    public const BAR_AGAIN = 'bar-again';
    public const CURSED_READY = 'cursed-ready';
    public const BOSS_DOWN = 'boss-down';
    public const DICE_WIN = 'dice-win';
    public const DICE_OWL_WIN = 'dice-owl-win';
    public const FART_TIME = 'fart-time';

    /**
     * What the owl says, naming the player, when the element of the type made the scene ready.
     *
     * @param Element::TYPE_* $type
     * @return non-empty-string
     */
    public static function finished(string $type): string
    {
        return match ($type) {
            Element::TYPE_PUMPKIN => self::LAST_PUMPKIN,
            Element::TYPE_BONFIRE => self::BONFIRE_DONE,
            Element::TYPE_CIRCLE => self::CIRCLE_DONE,
        };
    }

    /**
     * @return non-empty-string
     */
    public static function scene(int $scene): string
    {
        return 'scene-' . $scene;
    }

    /**
     * @return non-empty-string
     */
    public static function finale(int $step): string
    {
        return 'finale-' . $step;
    }

    /**
     * @psalm-assert-if-true non-empty-string $line
     */
    public static function isId(mixed $line): bool
    {
        return \is_string($line) && \preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $line) === 1 && \strlen($line) <= 32;
    }
}
