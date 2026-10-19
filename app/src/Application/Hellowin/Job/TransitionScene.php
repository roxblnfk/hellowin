<?php

declare(strict_types=1);

namespace App\Application\Hellowin\Job;

use App\Application\Hellowin\TransitionHandler;
use App\Application\Hellowin\TransitionQueue;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Queue\JobHandler;

/**
 * Payload: {@see TransitionQueue::scene()}. Pumpkins finishing at the same time may each push it;
 * the idempotency storage drops the duplicates by `payload.key`.
 */
#[Idempotent(storage: 'transitions', key: 'payload.key')]
final class TransitionScene extends JobHandler
{
    public function invoke(int $round, int $toScene, TransitionHandler $transitions): void
    {
        $transitions->scene($round, $toScene);
    }
}
