<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Story\RevealBuilder;
use App\Domain\Clock\Clock;
use App\Domain\Clock\StartTime;
use App\Domain\Clock\SystemClock;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Bush;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\Items;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;

/**
 * The state snapshot of docs/spec/protocol.md: the initial render of the page and the RPC `state` share it.
 *
 * @psalm-type Snapshot = array{
 *     round: positive-int,
 *     scene: int<0, max>,
 *     sceneStages: positive-int,
 *     stageMax: int<0, max>,
 *     threshold: positive-int,
 *     bushes: list<array{id: non-empty-string, x: float, y: float, depth: int<0, max>}>,
 *     elements: list<array{id: non-empty-string, type: non-empty-string, x: float, y: float, size?: 's'|'m'|'l', reachable?: bool, stage: int<0, max>, clicks: int<0, max>, threshold?: positive-int, stageMax?: int<0, max>}>,
 *     walkers: list<array>,
 *     ready: bool,
 *     finale: array{step: int, reveal?: array}|null,
 *     paused: bool,
 *     k: float,
 *     online: int<0, max>,
 *     zombies: bool,
 *     startedAt: int,
 *     now: int
 * }
 */
final readonly class SnapshotFactory
{
    /**
     * @param list<Bush> $bushes
     */
    public function __construct(
        private ElementRegistry $elements,
        private RitualStore $store,
        private RitualPolicy $policy,
        private array $bushes = [],
        private Clock $clock = new SystemClock(),
        private ?WalkerStore $walkers = null,
        private Items $items = new Items(),
        private ?RevealBuilder $reveal = null,
        private ?StartTime $startTime = null,
    ) {}

    /**
     * @return Snapshot
     */
    public function create(): array
    {
        $ritual = $this->store->snapshot();
        $k = $ritual->story->k;
        $walkers = $this->walkers?->all() ?? [];

        $item = $this->items->held($ritual->scene, $ritual->story->finale);
        $elements = [];
        foreach ($this->elements->all() as $element) {
            $pumpkin = $ritual->pumpkin($element->id);
            $state = $element->toArray() + [
                'stage' => $pumpkin?->stage ?? 0,
                'clicks' => $pumpkin?->clicks ?? 0,
            ];
            $element->centrepiece() and $state += [
                'threshold' => $this->policy->threshold($element->type, $k),
                'stageMax' => $this->policy->stageMax($ritual->scene, $element->type),
            ];
            $elements[] = $state;
        }

        return [
            'round' => $ritual->round,
            'scene' => $ritual->scene,
            'sceneStages' => $this->policy->finalScene(),
            'stageMax' => $this->policy->stageMax($ritual->scene),
            'threshold' => $this->policy->threshold(k: $k),
            'bushes' => \array_map(static fn(Bush $bush): array => $bush->toArray(), $this->bushes),
            'elements' => $elements,
            'walkers' => \array_values(\array_map(
                static fn(Walker $walker): array => $walker->toArray($item),
                $walkers,
            )),
            'ready' => $ritual->ready(),
            'finale' => $this->reveal?->finale($ritual),
            'paused' => $ritual->story->paused,
            'k' => $k,
            'online' => \count($walkers),
            'zombies' => $ritual->story->zombies,
            // Server time in ms: the client derives its clock offset from it to judge flyer `expiresAt`
            // When the server started, ms: the uptime of the finale; without the record — now
            'startedAt' => $this->startTime?->at() ?? $this->clock->now(),
            'now' => $this->clock->now(),
        ];
    }
}
