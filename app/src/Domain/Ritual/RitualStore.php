<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

use App\Domain\Story\Story;
use Psr\SimpleCache\CacheInterface;
use RoadRunner\Lock\LockInterface;

/**
 * Ritual state in a key-value store: the round, the scene, the stage and click counters of every pumpkin and of the
 * bonfire, and the story the moderator leads beside them.
 *
 * Each pumpkin is written under its own lock `pumpkin:{id}`, the bonfire under `bonfire`, the scene and the story
 * under `scene`. A reset takes `scene` and then every element lock, so no click or transition interleaves with it:
 * a click reads the round and the scene under the element lock and therefore never sees a half-reset ritual.
 */
final readonly class RitualStore
{
    private const KEY_ROUND = 'hellowin:round';
    private const KEY_SCENE = 'hellowin:scene';
    private const KEY_STORY = 'hellowin:story';
    private const KEY_PUMPKIN = 'hellowin:pumpkin:';
    private const KEY_BONFIRE = 'hellowin:bonfire';
    private const LOCK_SCENE = 'scene';
    private const LOCK_PUMPKIN = 'pumpkin:';
    private const LOCK_BONFIRE = 'bonfire';

    /** @var list<non-empty-string> */
    private array $pumpkins;

    /**
     * @param iterable<non-empty-string> $pumpkins Ids of every pumpkin of the ritual.
     * @param float $lockTtl Seconds a lock outlives a crashed worker.
     * @param float $lockWait Seconds to wait for a busy lock before giving up.
     * @param non-empty-string|null $bonfire Element id of the bonfire, null when the patch has none.
     */
    public function __construct(
        private CacheInterface $storage,
        private LockInterface $lock,
        iterable $pumpkins,
        private float $lockTtl = 5.0,
        private float $lockWait = 5.0,
        private ?string $bonfire = null,
    ) {
        $ids = [];
        foreach ($pumpkins as $id) {
            $ids[] = $id;
        }
        $this->pumpkins = $ids;
    }

    /**
     * Counts a click on a pumpkin or the bonfire.
     *
     * @param \Closure(RitualSnapshot): bool|null $accepts Decides on the whole ritual, read under the element lock,
     *        whether the click counts at all.
     * @return ClickOutcome|null Null when `$accepts` refused the click: nothing is written.
     * @throws RitualBusyException
     */
    public function click(string $id, ?\Closure $accepts = null): ?ClickOutcome
    {
        $id = $this->known($id);

        return $this->locked([$this->lockOf($id)], function () use ($id, $accepts): ?ClickOutcome {
            if ($accepts !== null && !$accepts($this->snapshot())) {
                return null;
            }

            $current = $this->readPumpkin($id);
            $pumpkin = new Pumpkin($id, $current->stage, $current->clicks + 1, $current->accepted + 1);
            $this->writePumpkin($pumpkin);

            return new ClickOutcome($this->readRound(), $this->readScene(), $pumpkin);
        });
    }

    /**
     * Moves a pumpkin or the bonfire to the stage if it is still one stage behind it in the same round; clicks start
     * over.
     *
     * @return Pumpkin|null The moved element, null when the transition is stale or already done.
     * @throws RitualBusyException
     */
    public function advancePumpkin(int $round, string $id, int $toStage): ?Pumpkin
    {
        $id = $this->known($id);

        return $this->locked([$this->lockOf($id)], function () use ($round, $id, $toStage): ?Pumpkin {
            $current = $this->readPumpkin($id);
            if ($toStage < 1 || $this->readRound() !== $round || $current->stage !== $toStage - 1) {
                return null;
            }

            $pumpkin = new Pumpkin($id, $toStage, 0, $current->accepted);
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
     * Marks the scene ready for the moderator, once per scene of a round.
     *
     * @param non-empty-string|null $player Who finished the last element of the scene.
     * @return bool False when the scene is stale or already marked.
     * @throws RitualBusyException
     */
    public function markReady(int $round, int $scene, ?string $player): bool
    {
        return $this->locked([self::LOCK_SCENE], function () use ($round, $scene, $player): bool {
            $story = $this->readStory();
            if ($scene < 0 || $this->readRound() !== $round || $this->readScene() !== $scene || $story->ready === $scene) {
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
     * @return array{Story, Story} The story before and after the change.
     * @throws RitualBusyException
     */
    public function updateStory(\Closure $change): array
    {
        return $this->locked([self::LOCK_SCENE], function () use ($change): array {
            $before = $this->readStory();
            $after = $change($before);
            $after == $before or $this->storage->set(self::KEY_STORY, $after->toArray());

            return [$before, $after];
        });
    }

    /**
     * Starts the next round from scene 0 with every pumpkin and the bonfire at stage 0 and the story over. Runs once
     * per round: a call for a round that is already over changes nothing.
     *
     * @return positive-int|null The new round, null when `$fromRound` is not the current one.
     * @throws RitualBusyException
     */
    public function reset(int $fromRound): ?int
    {
        $elements = $this->elements();
        $locks = [self::LOCK_SCENE, ...\array_map($this->lockOf(...), $elements)];

        return $this->locked($locks, function () use ($fromRound, $elements): ?int {
            $round = $this->readRound();
            if ($round !== $fromRound) {
                return null;
            }

            foreach ($elements as $id) {
                $this->writePumpkin(new Pumpkin($id));
            }
            $this->storage->set(self::KEY_SCENE, 0);
            $this->storage->set(self::KEY_STORY, $this->readStory()->restarted()->toArray());
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
            bonfire: $this->bonfire === null ? null : self::toPumpkin($this->bonfire, $values[self::KEY_BONFIRE] ?? null),
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
            )
            : new Pumpkin($id);
    }

    /**
     * @return list<non-empty-string> Pumpkins and the bonfire.
     */
    private function elements(): array
    {
        return $this->bonfire === null ? $this->pumpkins : [...$this->pumpkins, $this->bonfire];
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
        return $id === $this->bonfire ? self::KEY_BONFIRE : self::KEY_PUMPKIN . $id;
    }

    /**
     * @return non-empty-string
     */
    private function lockOf(string $id): string
    {
        return $id === $this->bonfire ? self::LOCK_BONFIRE : self::LOCK_PUMPKIN . $id;
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
            ['stage' => $pumpkin->stage, 'clicks' => $pumpkin->clicks, 'accepted' => $pumpkin->accepted],
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
