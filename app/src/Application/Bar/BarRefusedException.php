<?php

declare(strict_types=1);

namespace App\Application\Bar;

/**
 * The bar cannot do it now; the error is the code for the client: `not bar`, `has beer`, `no beer`, `not clinked`,
 * `no target` or `invalid delta`.
 */
final class BarRefusedException extends \RuntimeException
{
    /**
     * @param non-empty-string $error
     */
    public function __construct(
        public readonly string $error,
    ) {
        parent::__construct($error);
    }
}
