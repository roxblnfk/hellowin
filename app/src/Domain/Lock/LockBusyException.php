<?php

declare(strict_types=1);

namespace App\Domain\Lock;

/**
 * A lock could not be acquired in time.
 */
final class LockBusyException extends \RuntimeException {}
