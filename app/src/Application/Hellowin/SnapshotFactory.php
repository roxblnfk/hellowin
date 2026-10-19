<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Domain\Element\ElementRegistry;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;

/**
 * The state snapshot of docs/spec/protocol.md: the initial render of the page and the RPC `state` share it.
 *
 * @psalm-type Snapshot = array{
 *     round: positive-int,
 *     scene: int<0, max>,
 *     sceneStages: positive-int,
 *     stageMax: int<0, max>,
 *     threshold: positive-int,
 *     elements: list<array{id: non-empty-string, type: non-empty-string, x: float, y: float, size: 's'|'m'|'l', stage: int<0, max>, clicks: int<0, max>}>
 * }
 */
final readonly class SnapshotFactory
{
    /**
     * @param positive-int $threshold
     */
    public function __construct(
        private ElementRegistry $elements,
        private RitualStore $store,
        private RitualPolicy $policy,
        private int $threshold,
    ) {}

    /**
     * @return Snapshot
     */
    public function create(): array
    {
        $ritual = $this->store->snapshot();

        $elements = [];
        foreach ($this->elements->all() as $element) {
            $pumpkin = $ritual->pumpkin($element->id);
            $elements[] = $element->toArray() + [
                'stage' => $pumpkin?->stage ?? 0,
                'clicks' => $pumpkin?->clicks ?? 0,
            ];
        }

        return [
            'round' => $ritual->round,
            'scene' => $ritual->scene,
            'sceneStages' => $this->policy->finalScene(),
            'stageMax' => $this->policy->stageMax($ritual->scene),
            'threshold' => $this->threshold,
            'elements' => $elements,
        ];
    }
}
