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

    /**
     * The bonfire goes through the same job and key shape as a pumpkin, `{round}:bonfire:{toStage}`; only the metric
     * label tells it apart.
     *
     * @param non-empty-string|null $player Player of the click that queues the job; not part of the key, so
     *        of the duplicates the one that runs decides who finished the element.
     * @param bool $bonfire The element is the bonfire.
     */
    public function pumpkin(int $round, string $pumpkin, int $toStage, ?string $player = null, bool $bonfire = false): void
    {
        $this->queue->push(TransitionPumpkin::class, [
            'key' => self::pumpkinKey($round, $pumpkin, $toStage),
            'round' => $round,
            'pumpkin' => $pumpkin,
            'toStage' => $toStage,
            'player' => $player,
        ]);
        $this->metrics->jobPushed($bonfire ? RitualMetrics::KIND_BONFIRE : RitualMetrics::KIND_PUMPKIN);
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
