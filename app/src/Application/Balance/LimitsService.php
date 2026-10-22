<?php

declare(strict_types=1);

namespace App\Application\Balance;

use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Story\CursedService;
use App\Application\Story\OwlLine;
use App\Application\Story\RapierService;
use App\Domain\Balance\LimitsStore;
use App\Domain\Clock\Clock;
use App\Domain\Element\Element;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualSnapshot;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Story;
use App\Domain\Walk\WalkerStore;

/**
 * The limits of the game follow the room (docs/spec/protocol.md, «Лимиты и баланс»): every limit is its formula for
 * the figures on the floor under the moderator's multiplier, plus a bonus where the moderator has one.
 *
 * The ticker counts the figures once a second and moves `Story::$players` only once a new count has held for
 * {@see DEBOUNCE} ms, so a reloaded page does not shake the goals. Any change — the count, `k`, a bonus, a new scene or
 * step — is settled in one place ({@see settle()}): progress a lower limit has already reached moves on by itself, and
 * the console gets one `limits` event.
 *
 * @psalm-type LimitsState = array{players: positive-int, k: float, burst: bool, limits: array<non-empty-string, positive-int>}
 */
final readonly class LimitsService
{
    /** Milliseconds a new count of figures must hold before the limits follow it. */
    public const DEBOUNCE = 3000;

    /**
     * @param int<0, max> $debounce Milliseconds a new count of figures must hold.
     */
    public function __construct(
        private RitualStore $ritual,
        private RitualPolicy $policy,
        private LimitsStore $store,
        private WalkerStore $walkers,
        private ChannelPublisher $publisher,
        private TransitionQueue $queue,
        private Clock $clock,
        private ?FlyerService $flyers = null,
        private ?BarService $bar = null,
        private ?CursedService $cursed = null,
        private ?RapierService $rapier = null,
        private int $debounce = self::DEBOUNCE,
    ) {}

    /**
     * The `limits` as the clients see them: an empty object, not a list, when the context has none.
     *
     * @param LimitsState $state
     * @return array{players: positive-int, k: float, burst: bool, limits: array<non-empty-string, positive-int>|\stdClass}
     */
    public static function toClient(array $state): array
    {
        $state['limits'] === [] and $state['limits'] = new \stdClass();

        return $state;
    }

    /**
     * The ticker's work, once a second: counts the figures on the floor, moves the count the limits follow once it has
     * held long enough, and settles the limits.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function follow(): void
    {
        $now = $this->clock->now();
        $players = \count($this->walkers->all());
        $pending = $this->store->pending();
        if ($players === $this->ritual->story()->players) {
            $pending === null or $this->store->settled();
        } elseif ($pending === null || $pending['players'] !== $players) {
            $this->store->pend($players, $now);
        } elseif ($now - $pending['since'] >= $this->debounce) {
            $this->ritual->updateStory(static fn(Story $story): Story => $story->withPlayers($players));
            $this->store->settled();
        }

        $this->settle();
    }

    /**
     * Compares the limits in force with those the clients were told in this round; on a change, progress that has
     * reached a lower limit moves on, and the `limits` event goes out.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function settle(): void
    {
        $ritual = $this->ritual->snapshot();
        $state = $this->state($ritual);
        $told = $this->store->told($ritual->round);
        if ($told === $state) {
            return;
        }

        /** @var mixed $toldLimits */
        $toldLimits = $told['limits'] ?? null;
        $this->apply($ritual, \is_array($toldLimits) ? $toldLimits : [], $state['limits']);
        $this->publisher->limits($ritual->round, self::toClient($state));
        $this->store->tell($ritual->round, $state);
    }

    /**
     * {@see settle()} right after the console or the story changed something; a busy lock leaves it to the ticker,
     * which settles within a second anyway.
     */
    public function nudge(): void
    {
        try {
            $this->settle();
        } catch (LockBusyException|RitualBusyException) {
        }
    }

    /**
     * The pause is lifted: the progress the pause held catches up as after a lower limit — elements with the clicks of
     * their threshold move, the bar and the cursed world check their goals — and a scene whose last element moved
     * meanwhile is ready now. Safe to repeat: the jobs keep their keys, and a scene is marked ready once.
     *
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    public function resume(): void
    {
        $ritual = $this->ritual->snapshot();
        $this->catchUp($ritual);
        $this->bar?->follow($ritual->round);
        $this->cursed?->resume();
        if ($this->policy->sceneReady($ritual) && $this->ritual->markReady($ritual->round, $ritual->scene, null)) {
            $this->publisher->ready($ritual->round, $ritual->scene);
            $this->publisher->owl($ritual->round, OwlLine::SCENE_READY);
        }
    }

    /**
     * The limits of the current context: the pumpkins in scenes before the final one and the bonfire in those that raise it, the circle while
     * it is drawn, the bar's goal in the bar, the cursed world's goals on its goal step, FrankenPHP's health while it
     * stands.
     *
     * @return LimitsState
     */
    public function state(?RitualSnapshot $ritual = null): array
    {
        $ritual ??= $this->ritual->snapshot();
        $story = $ritual->story;
        $limits = [];
        if ($this->policy->bar($ritual->scene)) {
            $this->bar === null or $limits['bar'] = $this->bar->target($story);
        } elseif ($ritual->scene < $this->policy->finalScene()) {
            $limits[Element::TYPE_PUMPKIN] = $this->policy->threshold(Element::TYPE_PUMPKIN, $story->k, $story->players);
            $this->policy->stageMax($ritual->scene, Element::TYPE_BONFIRE) > 0
                and $limits[Element::TYPE_BONFIRE] = $this->policy->threshold(Element::TYPE_BONFIRE, $story->k, $story->players);
        } elseif ($story->finale === 0) {
            $limits[Element::TYPE_CIRCLE] = $this->policy->threshold(Element::TYPE_CIRCLE, $story->k, $story->players);
        } elseif ($story->finale === Story::CURSED_STEP && $this->cursed !== null) {
            $limits += $this->cursed->targets($ritual->round, $story);
        } elseif ($story->rapier() && $this->rapier !== null && ($this->rapier->state($ritual->round)['hp'] ?? null) !== 0) {
            $limits['boss'] = $this->rapier->hpMax($ritual->round, $story);
        }

        return [
            'players' => \max(1, $story->players),
            'k' => $story->k,
            'burst' => $this->flyers?->burstable($ritual->scene, $story) ?? false,
            'limits' => $limits,
        ];
    }

    /**
     * Progress a lower limit has reached moves on by itself: an element with the clicks of its new threshold gets the
     * same transition job a click would queue — same key, so it runs once — the bar and the cursed world check their
     * goals, and FrankenPHP's health follows its new maximum with the damage kept.
     *
     * @param array $told The limits the clients were told.
     * @param array<non-empty-string, positive-int> $limits The limits in force.
     * @throws LockBusyException
     * @throws RitualBusyException
     */
    private function apply(RitualSnapshot $ritual, array $told, array $limits): void
    {
        // Only a limit that was in force and moved: a new round, scene or step starts its limits from nothing to catch up
        // with, and the events that bring it in tell the clients the rest
        $changed = static fn(string $limit): bool => isset($limits[$limit], $told[$limit]) && $told[$limit] !== $limits[$limit];

        // A pause holds the stages too; lifting it catches up with whatever limit is in force then
        if (!$ritual->story->paused
            && ($changed(Element::TYPE_PUMPKIN) || $changed(Element::TYPE_BONFIRE) || $changed(Element::TYPE_CIRCLE))) {
            $this->catchUp($ritual);
        }
        $changed('bar') and $this->bar?->follow($ritual->round);
        $changed('drinks') || $changed('zombies') and $this->cursed?->follow();
        $changed('boss') and $this->rapier?->rescale($ritual->round);
    }

    private function catchUp(RitualSnapshot $ritual): void
    {
        $story = $ritual->story;
        foreach ($ritual->pumpkins as $pumpkin) {
            $toStage = $this->policy->pumpkinTransition($pumpkin, $ritual->scene, $story->k, $story->players);
            $toStage === null or $this->queue->pumpkin($ritual->round, $pumpkin->id, $toStage);
        }

        foreach (Element::CENTREPIECES as $type) {
            $centrepiece = $ritual->centrepiece($type);
            if ($centrepiece === null || !$this->policy->centrepieceOpen($type, $ritual)) {
                continue;
            }
            $toStage = $this->policy->centrepieceTransition($type, $centrepiece, $ritual->scene, $story->k, $story->players);
            $toStage === null or $this->queue->pumpkin($ritual->round, $centrepiece->id, $toStage, type: $type);
        }
    }
}
