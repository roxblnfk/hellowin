<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The key of the dice HMAC. A configured one wins; without it the first worker that throws draws a random key and
 * keeps it in `hellowin:dice:secret` under the lock `dice:secret`, so every worker, and a restarted RoadRunner, throws
 * the same dice for the same attempt.
 */
final readonly class DiceSecret
{
    private const KEY = 'hellowin:dice:secret';
    private const LOCK = 'dice:secret';

    /**
     * @param non-empty-string|null $configured
     */
    public function __construct(
        private ?string $configured,
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @return non-empty-string
     * @throws LockBusyException
     */
    public function get(): string
    {
        return $this->configured ?? $this->stored() ?? $this->locks->run(
            [self::LOCK],
            function (): string {
                $secret = $this->stored();
                if ($secret === null) {
                    $secret = \base64_encode(\random_bytes(32));
                    $this->storage->set(self::KEY, $secret);
                }

                return $secret;
            },
        );
    }

    /**
     * @return non-empty-string|null
     */
    private function stored(): ?string
    {
        /** @var mixed $secret */
        $secret = $this->storage->get(self::KEY);

        return \is_string($secret) && $secret !== '' ? $secret : null;
    }
}
