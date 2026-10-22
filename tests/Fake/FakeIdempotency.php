<?php

declare(strict_types=1);

namespace Tests\Fake;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\FailurePolicy;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\IdempotencyContext;

/**
 * An AtLeastOnce lease in an array: a completed key replays its result, a failure under
 * {@see FailurePolicy::Release} leaves the key free. TTLs are recorded, not enforced.
 */
final class FakeIdempotency implements Idempotency
{
    /** @var array<non-empty-string, mixed> Kept results by key. */
    public array $kept = [];

    /** @var list<array{non-empty-string, ExecuteOptions|null}> Every call: the key and its options. */
    public array $calls = [];

    #[\Override]
    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
    {
        $this->calls[] = [$key, $options];
        if (\array_key_exists($key, $this->kept)) {
            return $this->kept[$key];
        }

        $context = new class($key) implements IdempotencyContext {
            /**
             * @param non-empty-string $key
             */
            public function __construct(
                private readonly string $key,
            ) {}

            #[\Override]
            public function getKey(): string
            {
                return $this->key;
            }

            #[\Override]
            public function renew(bool $force = false): void {}
        };

        /** @var mixed $result */
        $result = $operation($context);
        $this->kept[$key] = $result;

        return $result;
    }
}
