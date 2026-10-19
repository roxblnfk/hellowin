<?php

declare(strict_types=1);

namespace App\Application\Bootloader;

use App\Application\Config\HellowinConfig;
use Predis\Client;
use Predis\ClientInterface;
use Spiral\Boot\Bootloader\Bootloader;

/**
 * Valkey client of the idempotency storage: RedisLeaseConfig picks up the `ClientInterface` binding.
 * Predis connects on the first command, so booting needs no running server.
 */
final class ValkeyBootloader extends Bootloader
{
    #[\Override]
    public function defineSingletons(): array
    {
        return [
            ClientInterface::class => static fn(HellowinConfig $config): ClientInterface
                => new Client($config->getValkeyDsn()),
        ];
    }
}
