<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Application\Hellowin\Job\TransitionScene;
use Spiral\Queue\QueueInterface;

/**
 * Queues transition jobs. The payload carries the idempotency key the job handler is deduplicated by:
 * it names the round, so the same transition in the next round is a new operation.
 */
final readonly class TransitionQueue
{
    public function __construct(
        private QueueInterface $queue,
        private RitualMetrics $metrics,
    ) {}

    /**
     * @return non-empty-string
     */
    public static function pumpkinKey(int $round, string $pumpkin, int $toStage): string
    {
        return \sprintf('%d:%s:%d', $round, $pumpkin, $toStage);
    }

    /**
     * @return non-empty-string
     */
    public static function sceneKey(int $round, int $toScene): string
    {
        return \sprintf('%d:scene:%d', $round, $toScene);
    }

    public function pumpkin(int $round, string $pumpkin, int $toStage): void
    {
        $this->queue->push(TransitionPumpkin::class, [
            'key' => self::pumpkinKey($round, $pumpkin, $toStage),
            'round' => $round,
            'pumpkin' => $pumpkin,
            'toStage' => $toStage,
        ]);
        $this->metrics->jobPushed(RitualMetrics::KIND_PUMPKIN);
    }

    public function scene(int $round, int $toScene): void
    {
        $this->queue->push(TransitionScene::class, [
            'key' => self::sceneKey($round, $toScene),
            'round' => $round,
            'toScene' => $toScene,
        ]);
        $this->metrics->jobPushed(RitualMetrics::KIND_SCENE);
    }
}
