<?php

declare(strict_types=1);

namespace App\Domain\Flyer;

/**
 * A bolt that found its flyer in the sky ({@see FlyerStore::strike()}).
 */
final readonly class Strike
{
    /**
     * @param Flyer|null $hit The flyer brought down; null — shot by someone else first, or an elephant, which only
     *        farts.
     */
    public function __construct(
        public ?Flyer $hit,
    ) {}
}
