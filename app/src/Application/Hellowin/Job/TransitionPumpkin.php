<?php

declare(strict_types=1);

namespace App\Application\Hellowin\Job;

use App\Application\Hellowin\TransitionHandler;
use App\Application\Hellowin\TransitionQueue;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Queue\JobHandler;

/**
 * Payload: {@see TransitionQueue::pumpkin()}. Clicks keep pushing this job until it runs, so most
 * deliveries are duplicates: the idempotency storage drops them by `payload.key`.
 */
#[Idempotent(storage: 'transitions', key: 'payload.key')]
final class TransitionPumpkin extends JobHandler
{
    public function invoke(int $round, string $pumpkin, int $toStage, TransitionHandler $transitions): void
    {
        $transitions->pumpkin($round, $pumpkin, $toStage);
    }
}
