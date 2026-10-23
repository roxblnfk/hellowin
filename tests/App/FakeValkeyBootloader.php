<?php

declare(strict_types=1);

namespace Tests\App;

use Predis\ClientInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Tests\Fake\FakeValkey;

/**
 * Valkey in memory for the app under test: loaded after ValkeyBootloader, so its binding wins.
 */
final class FakeValkeyBootloader extends Bootloader
{
    #[\Override]
    public function defineSingletons(): array
    {
        return [
            ClientInterface::class => static fn(): ClientInterface => new FakeValkey(),
        ];
    }
}
