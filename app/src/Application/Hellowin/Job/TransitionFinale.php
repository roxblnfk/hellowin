<?php

declare(strict_types=1);

namespace App\Application\Hellowin\Job;

use App\Application\Hellowin\TransitionQueue;
use App\Application\Story\StoryService;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Queue\JobHandler;

/**
 * Payload: {@see TransitionQueue::finale()}. The boss's last strike pushes it once, but a redelivered job is dropped by
 * `payload.key` like any transition.
 */
#[Idempotent(storage: 'transitions', key: 'payload.key')]
final class TransitionFinale extends JobHandler
{
    public function invoke(int $round, int $toStep, StoryService $story): void
    {
        $story->finaleOf($round, $toStep);
    }
}
