<?php

declare(strict_types=1);

use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Queue\Interceptor\Consume\ErrorHandlerInterceptor;
use Spiral\Queue\Interceptor\Consume\RetryPolicyInterceptor;

/**
 * @link https://spiral.dev/docs/queue-configuration
 */
return [
    'default' => env('QUEUE_CONNECTION', 'roadrunner'),
    'connections' => [
        // `transitions` is declared in .rr.yaml (jobs.pipelines)
        'roadrunner' => [
            'driver' => 'roadrunner',
            'pipeline' => 'transitions',
        ],
        'sync' => [
            'driver' => 'sync',
        ],
    ],
    'defaultSerializer' => 'json',
    'interceptors' => [
        // Outer → inner. RetryPolicyInterceptor must wrap the idempotency interceptor: it turns the
        // RetryableLockException of a job whose key is held by another worker into a delayed requeue.
        'consume' => [
            ErrorHandlerInterceptor::class,
            RetryPolicyInterceptor::class,
            IdempotencyInterceptor::class,
        ],
        'push' => [],
    ],
];
