<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

/**
 * A ritual lock could not be acquired in time.
 */
final class RitualBusyException extends \RuntimeException {}
