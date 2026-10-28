<?php

declare(strict_types=1);

namespace App\Domain\Walk;

use App\Domain\Element\Element;
use App\Domain\Flyer\Point;

/**
 * Where a character stands at a reachable pumpkin for a beer or a clink (docs/spec/protocol.md, «Точка у кружки»): on
 * the side of the pumpkin's mug, `$gap[size]` past the pumpkin as the frontend draws it after the jump out of the
 * sunset — every offset from the root of the patch grown `$out` times — level with it, brought onto the lawn.
 *
 * The numbers are `PUMPKIN_STAND` of public/js/layout.js: the frontend tells a character standing at a pumpkin by the
 * end of its walk near this point.
 */
final readonly class PumpkinStand
{
    /**
     * @param float $out How many times farther from the root the frontend draws a jumped pumpkin.
     * @param array{s: float, m: float, l: float} $gap Percents of the screen from the drawn pumpkin to the point, by
     *        its size.
     */
    public function __construct(
        public float $out,
        public array $gap,
    ) {}

    /**
     * The side of the mug follows the frontend: outwards from the root, by the pumpkin's x against the patch centre;
     * on a pumpkin right above the centre by `crc32(id)` — odd on the left (public/js/pixel/scene.js).
     */
    public function at(Element $pumpkin, WalkZone $lawn): Point
    {
        $root = $lawn->patch();
        $side = match (true) {
            $pumpkin->x > $root->cx => 1.0,
            $pumpkin->x < $root->cx => -1.0,
            default => (\crc32($pumpkin->id) & 1) === 1 ? -1.0 : 1.0,
        };
        $gap = $this->gap[$pumpkin->size ?? 'm'];

        return $lawn->clamp(new Point(
            $root->cx + ($pumpkin->x - $root->cx) * $this->out + $side * $gap,
            $root->cy + ($pumpkin->y - $root->cy) * $this->out,
        ));
    }
}
