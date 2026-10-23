<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Story\EveningTally;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\CallContextInterface;

/**
 * Counts the queued transitions the idempotency storage dropped into the round's tally ({@see EveningTally}): a job
 * whose call returns without its handler having run was a duplicate of its key. A job that fails is no drop.
 *
 * Not the element jobs: every one of their duplicates was pushed by a click past the threshold, and the reveal counts
 * that click as burned already. Drops of the scene and finale jobs are repeated presses of the moderator, or
 * redeliveries.
 */
final readonly class DroppedJobMiddleware implements ResolutionMiddleware
{
    public function __construct(
        private EveningTally $tally,
    ) {}

    #[\Override]
    public function process(IdempotencyCall $call, callable $next): mixed
    {
        $context = $call->context;
        if (!$context instanceof CallContextInterface || ($context->getTarget()->getPath()[0] ?? null) === TransitionPumpkin::class) {
            return $next($call);
        }

        $ran = false;
        $operation = $call->operation;
        /** @var mixed $result */
        $result = $next($call->withOperation(static function (mixed ...$arguments) use ($operation, &$ran): mixed {
            $ran = true;

            return $operation(...$arguments);
        }));
        $ran or $this->tally->add(EveningTally::DROPPED);

        return $result;
    }
}
