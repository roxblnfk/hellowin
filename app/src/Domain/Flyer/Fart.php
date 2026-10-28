<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * An elephant's fart at a shot, {@see FlyerStore::fart()}.
 */
final readonly class Fart
{
    /**
     * @param bool $first The elephant's first fart of its flight.
     */
    public function __construct(
        public Flyer $elephant,
        public bool $first,
    ) {}
}
