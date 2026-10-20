<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\TransitionQueue;
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
    ) {}

    /**
     * Queues the move from the scene the moderator sees to the next one. The job runs at most once per scene of
     * a round, so two presses — or two moderators — move it once.
     *
     * @param bool $force Move on even if not every pumpkin is done: the room got stuck.
     * @return int<0, max> The scene the moderator will see: the next one, or the current one when it already moved.
     * @throws StoryRefusedException
     */
    public function nextScene(int $scene, bool $force): int
    {
        $ritual = $this->store->snapshot();
        if ($scene !== $ritual->scene) {
            return $ritual->scene;
        }
        $ritual->scene < $this->policy->finalScene() or throw new StoryRefusedException('final scene');
        $force || $ritual->ready() or throw new StoryRefusedException('not ready');

        $this->queue->scene($ritual->round, $ritual->scene + 1);

        return $ritual->scene + 1;
    }

    /**
     * @return int<0, 5> The current step: the requested one if it was next, the unchanged one otherwise.
     * @throws StoryRefusedException Outside the final scene.
     * @throws RitualBusyException
     */
    public function finaleStep(int $step): int
    {
        [$before, $after] = $this->store->updateStory(static fn(Story $story): Story => $story->stepTo($step));
        $after->finale === null and throw new StoryRefusedException('not final');

        if ($after->finale !== $before->finale) {
            $ritual = $this->store->snapshot();
            $this->publisher->finale($ritual->round, $after->finale, $this->reveal->finale($ritual)['reveal'] ?? null);
            $this->publisher->owl($ritual->round, OwlLine::finale($after->finale));
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
}
