<?php

declare(strict_types=1);

namespace App\Application\Story;

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
    public const WITCH_DOWN = 'witch-down';
    public const ZOMBIES = 'zombies';

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
