<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

use App\Domain\Element\Element;
use App\Domain\Story\Story;
use Psr\SimpleCache\CacheInterface;
use RoadRunner\Lock\LockInterface;

/**
 * Ritual state in a key-value store: the round, the scene, the stage and click counters of every pumpkin and of the
 * centrepieces — the bonfire and the magic circle — and the story the moderator leads beside them.
 *
 * Each pumpkin is written under its own lock `pumpkin:{id}`, a centrepiece into `hellowin:{type}` under the lock named
 * by its type (`bonfire`, `circle`), the scene and the story under `scene`. A reset takes `scene` and then every element lock, so no click or transition interleaves with it:
 * a click reads the round and the scene under the element lock and therefore never sees a half-reset ritual.
 */
final readonly class RitualStore
{
    private const KEY_ROUND = 'hellowin:round';
    private const KEY_SCENE = 'hellowin:scene';
    private const KEY_STORY = 'hellowin:story';
    private const KEY_PUMPKIN = 'hellowin:pumpkin:';
    private const KEY_CENTREPIECE = 'hellowin:';
    private const LOCK_SCENE = 'scene';
    private const LOCK_PUMPKIN = 'pumpkin:';

    /** @var list<non-empty-string> */
    private array $pumpkins;

    /** @var array<non-empty-string, Element::TYPE_BONFIRE|Element::TYPE_CIRCLE> Centrepiece types by element id. */
    private array $centrepieces;

    /**
     * @param iterable<non-empty-string> $pumpkins Ids of every pumpkin of the ritual.
     * @param float $lockTtl Seconds a lock outlives a crashed worker.
     * @param float $lockWait Seconds to wait for a busy lock before giving up.
     * @param non-empty-string|null $bonfire Element id of the bonfire, null when the patch has none.
     * @param non-empty-string|null $circle Element id of the magic circle, null when the patch has none.
     */
    public function __construct(
        private CacheInterface $storage,
        private LockInterface $lock,
        iterable $pumpkins,
        private float $lockTtl = 5.0,
        private float $lockWait = 5.0,
        ?string $bonfire = null,
        ?string $circle = null,
    ) {
        $ids = [];
        foreach ($pumpkins as $id) {
            $ids[] = $id;
        }
        $this->pumpkins = $ids;

        $centrepieces = [];
        $bonfire === null or $centrepieces[$bonfire] = Element::TYPE_BONFIRE;
        $circle === null or $centrepieces[$circle] = Element::TYPE_CIRCLE;
        $this->centrepieces = $centrepieces;
    }

    /**
     * Counts a click on a pumpkin or a centrepiece; on a pause of the progress the element keeps its clicks.
     *
     * @param \Closure(RitualSnapshot): bool|null $accepts Decides on the whole ritual, read under the element lock,
     *        whether the click counts at all.
     * @param \Closure(int<0, max>): bool|null $acceptsIn Decides by the scene alone, read under the element lock: a
     *        pumpkin click pays one read for it rather than the whole ritual.
     * @return ClickOutcome|null Null when `$accepts` or `$acceptsIn` refused the click: nothing is written.
     * @throws RitualBusyException
     */
    public function click(string $id, ?\Closure $accepts = null, ?\Closure $acceptsIn = null): ?ClickOutcome
    {
        $id = $this->known($id);

        return $this->locked([$this->lockOf($id)], function () use ($id, $accepts, $acceptsIn): ?ClickOutcome {
            if ($accepts !== null && !$accepts($this->snapshot())) {
                return null;
            }
            if ($acceptsIn !== null && !$acceptsIn($this->readScene())) {
                return null;
            }

            // On a pause the click is still accepted, so the reveal counts it, but it brings no transition nearer
            $story = $this->readStory();
            $current = $this->readPumpkin($id);
            $clicks = $story->paused ? $current->clicks : $current->clicks + 1;
            $pumpkin = new Pumpkin($id, $current->stage, $clicks, $current->accepted + 1, $current->spent);
            $this->writePumpkin($pumpkin);

            return new ClickOutcome($this->readRound(), $this->readScene(), $pumpkin, $story->k, $story->players, !$story->paused);
        });
    }

    /**
     * Moves a pumpkin or a centrepiece to the stage if it is still one stage behind it in the same round; clicks start
     * over.
     *
     * @param int<0, max> $cost The threshold in force: the transition takes up to as many clicks, the rest are burned.
     * @return Pumpkin|null The moved element, null when the transition is stale or already done.
     * @throws RitualBusyException
     */
    public function advancePumpkin(int $round, string $id, int $toStage, int $cost): ?Pumpkin
    {
        $id = $this->known($id);

        return $this->locked([$this->lockOf($id)], function () use ($round, $id, $toStage, $cost): ?Pumpkin {
            $current = $this->readPumpkin($id);
            if ($toStage < 1 || $this->readRound() !== $round || $current->stage !== $toStage - 1) {
                return null;
            }

            $pumpkin = new Pumpkin($id, $toStage, 0, $current->accepted, $current->spent + \min($current->clicks, $cost));
            $this->writePumpkin($pumpkin);

            return $pumpkin;
        });
    }

    /**
     * Marks that every pumpkin of the scene is done and the bonfire is open, once per scene of a round.
     *
     * @return bool False when the scene is stale, already open or already ready.
     * @throws RitualBusyException
     */
    public function markBonfireOpen(int $round, int $scene): bool
    {
        return $this->locked([self::LOCK_SCENE], function () use ($round, $scene): bool {
            $story = $this->readStory();
            if ($scene < 0 || $this->readRound() !== $round || $this->readScene() !== $scene
                || $story->bonfire === $scene || $story->ready === $scene) {
                return false;
            }

            $this->storage->set(self::KEY_STORY, $story->bonfireOpenIn($scene)->toArray());

            return true;
        });
    }

    /**
     * Marks the scene ready for the moderator, once per scene of a round. On a pause the room's progress makes nothing
     * ready: the pause is lifted under this same lock before the catch-up looks, so a goal reached meanwhile is not lost.
     *
     * @param non-empty-string|null $player Who finished the last element of the scene.
     * @param int|null $step The finale step the scene must still be at; null — any.
     * @param bool $evenPaused The moderator's own call — the wave in the bar, the owl's throw — which a pause does not hold.
     * @return bool False when the scene is stale, already marked or paused.
     * @throws RitualBusyException
     */
    public function markReady(int $round, int $scene, ?string $player, ?int $step = null, bool $evenPaused = false): bool
    {
        return $this->locked([self::LOCK_SCENE], function () use ($round, $scene, $player, $step, $evenPaused): bool {
            $story = $this->readStory();
            if ($scene < 0 || $this->readRound() !== $round || $this->readScene() !== $scene || $story->ready === $scene
                || $step !== null && $story->finale !== $step || $story->paused && !$evenPaused) {
                return false;
            }

            $this->storage->set(self::KEY_STORY, $story->readyIn($scene, $player)->toArray());

            return true;
        });
    }

    /**
     * Moves the scene forward if it is still one step behind it in the same round.
     *
     * @param bool $final The new scene is the final one: the finale starts at step 0.
     * @return bool False when the transition is stale or already done.
     * @throws RitualBusyException
     */
    public function advanceScene(int $round, int $toScene, bool $final = false): bool
    {
        return $this->locked([self::LOCK_SCENE], function () use ($round, $toScene, $final): bool {
            if ($toScene < 1 || $this->readRound() !== $round || $this->readScene() !== $toScene - 1) {
                return false;
            }

            $this->storage->set(self::KEY_SCENE, $toScene);
            $this->storage->set(self::KEY_STORY, $this->readStory()->nextScene($final)->toArray());

            return true;
        });
    }

    /**
     * Changes the story under the scene lock.
     *
     * @param \Closure(Story): Story $change
     * @param int|null $round The round the change belongs to: in another one the story stays as it is.
     * @return array{Story, Story} The story before and after the change.
     * @throws RitualBusyException
     */
    public function updateStory(\Closure $change, ?int $round = null): array
    {
        return $this->locked([self::LOCK_SCENE], function () use ($change, $round): array {
            $before = $this->readStory();
            $after = $round === null || $round === $this->readRound() ? $change($before) : $before;
            $after == $before or $this->storage->set(self::KEY_STORY, $after->toArray());

            return [$before, $after];
        });
    }

    /**
     * Starts the next round from scene 0 with every pumpkin and centrepiece at stage 0 and the story over. Runs once
     * per round: a call for a round that is already over changes nothing.
     *
     * @param bool $again A new cycle of the evening from the dawn, not the moderator's reset.
     * @return positive-int|null The new round, null when `$fromRound` is not the current one.
     * @throws RitualBusyException
     */
    public function reset(int $fromRound, bool $again = false): ?int
    {
        $elements = $this->elements();
        $locks = [self::LOCK_SCENE, ...\array_map($this->lockOf(...), $elements)];

        return $this->locked($locks, function () use ($fromRound, $elements, $again): ?int {
            $round = $this->readRound();
            if ($round !== $fromRound) {
                return null;
            }

            foreach ($elements as $id) {
                $this->writePumpkin(new Pumpkin($id));
            }
            $this->storage->set(self::KEY_SCENE, 0);
            $this->storage->set(self::KEY_STORY, $this->readStory()->restarted($again)->toArray());
            $this->storage->set(self::KEY_ROUND, ++$round);

            return $round;
        });
    }

    /**
     * Current round without taking locks, for events outside the ritual.
     *
     * @return positive-int
     */
    public function round(): int
    {
        return $this->readRound();
    }

    /**
     * Current scene without taking locks: one read, for those who only need to know where the room is.
     *
     * @return int<0, max>
     */
    public function scene(): int
    {
        return $this->readScene();
    }

    /**
     * Where the room is, without taking locks: one read of the scene and of the finale step (null outside the final
     * scene) — what picks the variant of the owl's line.
     *
     * @return array{int<0, max>, int|null}
     */
    public function place(): array
    {
        $values = [...$this->storage->getMultiple([self::KEY_SCENE, self::KEY_STORY])];

        return [self::toCount($values[self::KEY_SCENE] ?? null), Story::fromArray($values[self::KEY_STORY] ?? null)->finale];
    }

    /**
     * Current story without taking locks, for the console switches outside the ritual.
     */
    public function story(): Story
    {
        return $this->readStory();
    }

    /**
     * @return Element::TYPE_* The type of a known element: a pumpkin unless it is a centrepiece.
     */
    public function typeOf(string $id): string
    {
        return $this->centrepieces[$id] ?? Element::TYPE_PUMPKIN;
    }

    /**
     * Current state without taking locks: it may lag a concurrent click or transition.
     */
    public function snapshot(): RitualSnapshot
    {
        $keys = [self::KEY_ROUND, self::KEY_SCENE, self::KEY_STORY];
        foreach ($this->elements() as $id) {
            $keys[] = $this->keyOf($id);
        }

        $values = [...$this->storage->getMultiple($keys)];

        return new RitualSnapshot(
            round: self::toRound($values[self::KEY_ROUND] ?? null),
            scene: self::toCount($values[self::KEY_SCENE] ?? null),
            pumpkins: \array_map(
                static fn(string $id): Pumpkin => self::toPumpkin($id, $values[self::KEY_PUMPKIN . $id] ?? null),
                $this->pumpkins,
            ),
            story: Story::fromArray($values[self::KEY_STORY] ?? null),
            bonfire: $this->centrepiece(Element::TYPE_BONFIRE, $values),
            circle: $this->centrepiece(Element::TYPE_CIRCLE, $values),
        );
    }

    /**
     * @return int<0, max>
     */
    private static function toCount(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * The first round is 1, so a store that was never written to is in round 1.
     *
     * @return positive-int
     */
    private static function toRound(mixed $value): int
    {
        return \is_int($value) && $value > 0 ? $value : 1;
    }

    /**
     * @param non-empty-string $id
     */
    private static function toPumpkin(string $id, mixed $value): Pumpkin
    {
        return \is_array($value)
            ? new Pumpkin(
                $id,
                self::toCount($value['stage'] ?? null),
                self::toCount($value['clicks'] ?? null),
                self::toCount($value['accepted'] ?? null),
                self::toCount($value['spent'] ?? null),
            )
            : new Pumpkin($id);
    }

    /**
     * @param Element::TYPE_BONFIRE|Element::TYPE_CIRCLE $type
     * @param array<array-key, mixed> $values
     */
    private function centrepiece(string $type, array $values): ?Pumpkin
    {
        $id = \array_search($type, $this->centrepieces, true);

        return $id === false ? null : self::toPumpkin($id, $values[$this->keyOf($id)] ?? null);
    }

    /**
     * @return list<non-empty-string> Pumpkins and centrepieces.
     */
    private function elements(): array
    {
        return [...$this->pumpkins, ...\array_keys($this->centrepieces)];
    }

    /**
     * @return non-empty-string
     */
    private function known(string $id): string
    {
        return \in_array($id, $this->elements(), true)
            ? $id
            : throw new \InvalidArgumentException(\sprintf('Unknown element "%s".', $id));
    }

    /**
     * @return non-empty-string
     */
    private function keyOf(string $id): string
    {
        return isset($this->centrepieces[$id]) ? self::KEY_CENTREPIECE . $this->centrepieces[$id] : self::KEY_PUMPKIN . $id;
    }

    /**
     * @return non-empty-string
     */
    private function lockOf(string $id): string
    {
        return $this->centrepieces[$id] ?? self::LOCK_PUMPKIN . $id;
    }

    /**
     * @return positive-int
     */
    private function readRound(): int
    {
        return self::toRound($this->storage->get(self::KEY_ROUND));
    }

    /**
     * @return int<0, max>
     */
    private function readScene(): int
    {
        return self::toCount($this->storage->get(self::KEY_SCENE));
    }

    private function readStory(): Story
    {
        return Story::fromArray($this->storage->get(self::KEY_STORY));
    }

    /**
     * @param non-empty-string $id
     */
    private function readPumpkin(string $id): Pumpkin
    {
        return self::toPumpkin($id, $this->storage->get($this->keyOf($id)));
    }

    private function writePumpkin(Pumpkin $pumpkin): void
    {
        $this->storage->set(
            $this->keyOf($pumpkin->id),
            ['stage' => $pumpkin->stage, 'clicks' => $pumpkin->clicks, 'accepted' => $pumpkin->accepted, 'spent' => $pumpkin->spent],
        );
    }

    /**
     * Runs the operation holding every lock, taken in the given order and released in reverse.
     *
     * @template T
     * @param list<non-empty-string> $resources
     * @param \Closure(): T $operation
     * @return T
     * @throws RitualBusyException
     */
    private function locked(array $resources, \Closure $operation): mixed
    {
        /** @var array<non-empty-string, non-empty-string> $held */
        $held = [];

        try {
            foreach ($resources as $resource) {
                $id = $this->lock->lock($resource, ttl: $this->lockTtl, waitTTL: $this->lockWait);
                $id === false and throw new RitualBusyException(\sprintf('"%s" is locked by another worker.', $resource));
                $held[$resource] = $id;
            }

            return $operation();
        } finally {
            foreach (\array_reverse($held, true) as $resource => $id) {
                $this->lock->release($resource, $id);
            }
        }
    }
}
