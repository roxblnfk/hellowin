<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Walk\WalkService;
use App\Domain\Element\Element;
use App\Domain\Lock\LockBusyException;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Ritual\RitualPolicy;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Story;

/**
 * The moderator's console (docs/spec/protocol.md, «Модератор»). Every call is safe to repeat: it names the state the
 * moderator sees or wants, and a call that finds the story already there changes and publishes nothing.
 */
final readonly class StoryService
{
    /** Longest free line of the owl, in characters. */
    private const TEXT_MAX = 200;

    public function __construct(
        private RitualStore $store,
        private RitualPolicy $policy,
        private TransitionQueue $queue,
        private ChannelPublisher $publisher,
        private RevealBuilder $reveal,
        private ?WalkService $walks = null,
        private ?ResetService $reset = null,
    ) {}

    /**
     * Queues the move from the scene the moderator sees to the next one. The job runs at most once per scene of
     * a round, so two presses — or two moderators — move it once.
     *
     * At dawn the next scene is the bar again: a new cycle of the evening is the reset of the round, which compares
     * the round under its locks — so a repeated press finds the round over and changes nothing.
     *
     * @param bool $force Move on even if not every pumpkin is done: the room got stuck.
     * @return array{scene: int<0, max>, round?: positive-int} The scene the moderator will see: the next one, or the
     *         current one when it already moved; with the round when the call named the final scene, which the dawn
     *         may have left for a new cycle.
     * @throws StoryRefusedException
     * @throws RitualBusyException
     */
    public function nextScene(int $scene, bool $force): array
    {
        $ritual = $this->store->snapshot();
        $final = $this->policy->finalScene();
        if ($scene !== $ritual->scene) {
            return $scene === $final ? ['scene' => $ritual->scene, 'round' => $ritual->round] : ['scene' => $ritual->scene];
        }
        if ($ritual->scene === $final && $ritual->story->dawn() && $this->reset !== null) {
            $this->reset->reset($ritual->round, again: true);
            $now = $this->store->snapshot();

            return ['scene' => $now->scene, 'round' => $now->round];
        }
        $ritual->scene < $final or throw new StoryRefusedException('final scene');
        $force || $ritual->ready() or throw new StoryRefusedException('not ready');

        $this->queue->scene($ritual->round, $ritual->scene + 1);

        return ['scene' => $ritual->scene + 1];
    }

    /**
     * @param bool $force Take the first step even if the circle is not closed: the room got stuck.
     * @return int<0, 7> The current step: the requested one if it was next, the unchanged one otherwise.
     * @throws StoryRefusedException Outside the final scene, or the first step before the scene is ready.
     * @throws RitualBusyException
     */
    public function finaleStep(int $step, bool $force = false): int
    {
        [$before, $after] = $this->store->updateStory(
            static fn(Story $story): Story => !$force && $story->awaitsReady($step) ? $story : $story->stepTo($step),
        );
        $after->finale === null and throw new StoryRefusedException('not final');
        !$force && $before->awaitsReady($step) and throw new StoryRefusedException('not ready');

        if ($after->finale !== $before->finale) {
            $ritual = $this->store->snapshot();
            $this->publisher->finale($ritual->round, $ritual->scene, $after->finale, $this->reveal->finale($ritual)['reveal'] ?? null);
            $this->publisher->owl($ritual->round, OwlLine::finale($after->finale));
            $after->finale === Story::SCATTER_STEP and $this->scatter();
        }

        return $after->finale;
    }

    /**
     * @throws StoryRefusedException
     */
    public function say(mixed $line, mixed $params): void
    {
        OwlLine::isId($line) or throw new StoryRefusedException('invalid line');
        $params === null || \is_array($params) && ($params === [] || !\array_is_list($params))
            or throw new StoryRefusedException('invalid params');

        /** @var array<non-empty-string, mixed>|null $params */
        $this->publisher->owl($this->store->round(), $line, $params ?? []);
    }

    /**
     * @throws StoryRefusedException
     */
    public function sayText(mixed $text): void
    {
        $text = \is_string($text) ? \trim($text) : '';
        $text !== '' && \mb_check_encoding($text, 'UTF-8') && \mb_strlen($text) <= self::TEXT_MAX
            or throw new StoryRefusedException('invalid text');

        $this->publisher->owlText($this->store->round(), $text);
    }

    /**
     * Flyers and zombies stop launching; the ones already out finish their way.
     *
     * @throws RitualBusyException
     */
    public function pause(bool $on): void
    {
        [$before] = $this->store->updateStory(static fn(Story $story): Story => $story->withPaused($on));
        $before->paused === $on or $this->publisher->pause($this->store->round(), $on);
    }

    /**
     * Scales every click threshold to the room. An element that already has the clicks of its new threshold moves
     * with its next click: the policy compares the clicks with the threshold in force on every click.
     *
     * @return array{k: float, thresholds: array<Element::TYPE_*, positive-int>} The multiplier and the thresholds in
     *         force after the call.
     * @throws StoryRefusedException
     * @throws RitualBusyException
     */
    public function thresholds(mixed $k): array
    {
        Story::isK($k) or throw new StoryRefusedException('invalid k');
        $k = (float) $k;

        [$before] = $this->store->updateStory(static fn(Story $story): Story => $story->withK($k));
        $thresholds = [];
        foreach ([Element::TYPE_PUMPKIN, ...Element::CENTREPIECES] as $type) {
            $thresholds[$type] = $this->policy->threshold($type, $k);
        }
        $before->k === $k or $this->publisher->thresholds($this->store->round(), $k, $thresholds);

        return ['k' => $k, 'thresholds' => $thresholds];
    }

    /**
     * Zombies walk only in the cursed world, but the switch may be turned on beforehand.
     *
     * @throws RitualBusyException
     */
    public function zombies(bool $on): void
    {
        [$before] = $this->store->updateStory(static fn(Story $story): Story => $story->withZombies($on));
        if ($before->zombies === $on) {
            return;
        }

        $round = $this->store->round();
        $this->publisher->zombies($round, $on);
        $on and $this->publisher->owl($round, OwlLine::ZOMBIES);
    }

    /**
     * The characters run from FrankenPHP. The step has already moved, so a busy lawn only costs the run, not the step.
     */
    private function scatter(): void
    {
        try {
            $this->walks?->scatter();
        } catch (LockBusyException) {
        }
    }
}
