<?php

declare(strict_types=1);

namespace App\Application\Hellowin;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Story\AutopilotService;
use App\Application\Story\CursedService;
use App\Application\Story\DiceService;
use App\Application\Story\RapierService;
use App\Application\Story\RevealBuilder;
use App\Application\Story\TopService;
use App\Domain\Clock\Clock;
use App\Domain\Clock\StartTime;
use App\Domain\Clock\SystemClock;
use App\Domain\Element\ElementRegistry;
use App\Domain\Flyer\Bush;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Autopilot;
use App\Domain\Story\Story;
use App\Domain\Walk\Items;
use App\Domain\Walk\PresenceStore;
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
 *     bar: array{mugs: int<0, max>, max: positive-int, pouring: array|null, drunk: int<0, max>, target: positive-int, roadrunner: bool}|null,
 *     ready: bool,
 *     finale: array{step: int, reveal?: array, wordAt?: int}|null,
 *     paused: bool,
 *     k: float,
 *     online: int<0, max>,
 *     presence: array{players: int<0, max>, connections: int<0, max>},
 *     limits: array{players: positive-int, k: float, burst: bool, limits: array|\stdClass}|null,
 *     cursed: array{drunk: int<0, max>, slain: int<0, max>, drinksTarget: positive-int, zombiesTarget: positive-int}|null,
 *     boss: array{hp: int<0, max>, max: positive-int, burnMs: int<0, max>, awakeAt: int}|null,
 *     dice: array{on: bool, winner?: array, owl: int<0, max>, openAt?: int}|null,
 *     top: array{board: list<array{player: array{id: non-empty-string, name: non-empty-string|null}, points: positive-int}>},
 *     flyers: list<array>,
 *     runner: array{at: int, ltr: bool}|null,
 *     emotes: list<non-empty-string>,
 *     startedAt: int,
 *     now: int,
 *     auto?: array{on: bool, next: Next|null}
 * }
 * @psalm-import-type Next from Autopilot
 */
final readonly class SnapshotFactory
{
    /**
     * @param list<Bush> $bushes
     * @param list<non-empty-string> $emotes The emoji of the emote panel, in the order of its buttons.
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
        private ?BarService $bar = null,
        private ?CursedService $cursed = null,
        private ?RapierService $rapier = null,
        private ?DiceService $dice = null,
        private ?TopService $top = null,
        private ?FlyerService $flyers = null,
        private ?LimitsService $limits = null,
        private ?PresenceStore $presence = null,
        private array $emotes = [],
        private ?AutopilotService $autopilot = null,
    ) {}

    /**
     * The evening's numbers counted now, in any scene: the same `reveal` the snapshot and the finale carry from the
     * reveal step on.
     *
     * @return array|null Null without the reveal builder.
     */
    public function reveal(): ?array
    {
        return $this->reveal?->reveal($this->store->snapshot())->toArray();
    }

    /**
     * @return Snapshot
     */
    public function create(): array
    {
        $ritual = $this->store->snapshot();
        $k = $ritual->story->k;
        $walkers = $this->walkers?->all() ?? [];

        $item = $this->items->held($ritual->scene, $ritual->story->finale);
        // In the bar and in the cursed world every character holds its own
        $own = $this->policy->bar($ritual->scene) || $ritual->story->cursed();
        $elements = [];
        foreach ($this->elements->all() as $element) {
            $pumpkin = $ritual->pumpkin($element->id);
            $state = $element->toArray() + [
                'stage' => $pumpkin?->stage ?? 0,
                'clicks' => $pumpkin?->clicks ?? 0,
            ];
            $element->centrepiece() and $state += [
                'threshold' => $this->policy->threshold($element->type, $k, $ritual->story->players),
                'stageMax' => $this->policy->stageMax($ritual->scene, $element->type),
            ];
            $elements[] = $state;
        }

        $snapshot = [
            'round' => $ritual->round,
            'scene' => $ritual->scene,
            'sceneStages' => $this->policy->finalScene(),
            'stageMax' => $this->policy->stageMax($ritual->scene),
            'threshold' => $this->policy->threshold(k: $k, players: $ritual->story->players),
            'bushes' => \array_map(static fn(Bush $bush): array => $bush->toArray(), $this->bushes),
            'elements' => $elements,
            'walkers' => \array_values(\array_map(
                static fn(Walker $walker): array => $walker->toArray($own ? $walker->item : $item),
                $walkers,
            )),
            'bar' => $this->bar?->state($ritual->round, $ritual->scene, $ritual->story, \count($walkers)),
            'ready' => $ritual->ready(),
            'finale' => $this->finale($ritual),
            'paused' => $ritual->story->paused,
            'k' => $k,
            'online' => \count($walkers),
            // Who is connected, as the ticker counted it last; zeros before its first count
            'presence' => $this->presence?->told() ?? ['players' => 0, 'connections' => 0],
            'limits' => $this->limits === null ? null : LimitsService::toClient($this->limits->state($ritual)),
            'cursed' => $this->cursed?->state($ritual->round, $ritual->story),
            'boss' => ($ritual->story->finale ?? 0) >= Story::RAPIER_STEP ? $this->rapier?->state($ritual->round) : null,
            'dice' => $this->dice?->state($ritual->round, $ritual->story),
            'top' => $this->top?->state() ?? ['board' => []],
            'flyers' => $this->flyers?->wanderers($ritual->story) ?? [],
            // The last run, finished or not: the client draws only what is left of it
            'runner' => $ritual->story->runner?->toArray(),
            'emotes' => $this->emotes,
            // Server time in ms: the client derives its clock offset from it to judge flyer `expiresAt`
            // When the server started, ms: the uptime of the finale; without the record — now
            'startedAt' => $this->startTime?->at() ?? $this->clock->now(),
            'now' => $this->clock->now(),
        ];
        // A server without the autopilot sends no field: the console hides the switch then
        $this->autopilot === null or $snapshot['auto'] = $this->autopilot->state();

        return $snapshot;
    }

    /**
     * @return array{step: int, reveal?: array, wordAt?: int}|null
     */
    private function finale(RitualSnapshot $ritual): ?array
    {
        $finale = $this->reveal?->finale($ritual);
        $wordAt = $finale !== null && $ritual->story->rapier() ? $this->rapier?->wordAt($ritual->round) : null;
        $wordAt === null or $finale['wordAt'] = $wordAt;

        return $finale;
    }
}
