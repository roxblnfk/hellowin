<?php

declare(strict_types=1);

namespace App\Domain\Game;

/**
 * The state lock could not be acquired in time.
 */
final class GameBusyException extends \RuntimeException {}
