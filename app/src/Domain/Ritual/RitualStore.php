<?php

declare(strict_types=1);

namespace App\Domain\Ritual;

use App\Domain\Story\Story;
use Psr\SimpleCache\CacheInterface;
use RoadRunner\Lock\LockInterface;

/**
 * Ritual state in a key-value store: the round, the scene, every pumpkin's stage and click counters, and the story
 * the moderator leads beside them.
 *
 * Each pumpkin is written under its own lock `pumpkin:{id}`, the scene and the story under `scene`. A reset takes
 * `scene` and then every pumpkin lock, so no click or transition interleaves with it: a click reads the round and
 * the scene under the pumpkin lock and therefore never sees a half-reset ritual.
 */
final readonly class RitualStore
{
    private const KEY_ROUND = 'hellowin:round';
    private const KEY_SCENE = 'hellowin:scene';
    private const KEY_STORY = 'hellowin:story';
    private const KEY_PUMPKIN = 'hellowin:pumpkin:';
    private const LOCK_SCENE = 'scene';
    private const LOCK_PUMPKIN = 'pumpkin:';

    /** @var list<non-empty-string> */
    private array $pumpkins;

    /**
     * @param iterable<non-empty-string> $pumpkins Ids of every pumpkin of the ritual.
     * @param float $lockTtl Seconds a lock outlives a crashed worker.
     * @param float $lockWait Seconds to wait for a busy lock before giving up.
     */
    public function __construct(
        private CacheInterface $storage,
        private LockInterface $lock,
        iterable $pumpkins,
        private float $lockTtl = 5.0,
        private float $lockWait = 5.0,
    ) {
        $ids = [];
        foreach ($pumpkins as $id) {
            $ids[] = $id;
        }
        $this->pumpkins = $ids;
    }

    /**
     * Counts a click on the pumpkin.
     *
     * @throws RitualBusyException
     */
    public function click(string $id): ClickOutcome
    {
        $id = $this->known($id);

        return $this->locked([self::LOCK_PUMPKIN . $id], function () use ($id): ClickOutcome {
            $current = $this->readPumpkin($id);
            $pumpkin = new Pumpkin($id, $current->stage, $current->clicks + 1, $current->accepted + 1);
            $this->writePumpkin($pumpkin);

            return new ClickOutcome($this->readRound(), $this->readScene(), $pumpkin);
        });
    }

    /**
     * Moves the pumpkin to the stage if it is still one stage behind it in the same round; clicks start over.
     *
     * @return Pumpkin|null The moved pumpkin, null when the transition is stale or already done.
     * @throws RitualBusyException
     */
    public function advancePumpkin(int $round, string $id, int $toStage): ?Pumpkin
    {
        $id = $this->known($id);

        return $this->locked([self::LOCK_PUMPKIN . $id], function () use ($round, $id, $toStage): ?Pumpkin {
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
     * Marks the scene ready for the moderator, once per scene of a round.
     *
     * @param non-empty-string|null $player Who finished the last pumpkin.
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
     * Starts the next round from scene 0 with every pumpkin at stage 0 and the story over. Runs once per round:
     * a call for a round that is already over changes nothing.
     *
     * @return positive-int|null The new round, null when `$fromRound` is not the current one.
     * @throws RitualBusyException
     */
    public function reset(int $fromRound): ?int
    {
        $locks = [self::LOCK_SCENE, ...\array_map(static fn(string $id): string => self::LOCK_PUMPKIN . $id, $this->pumpkins)];

        return $this->locked($locks, function () use ($fromRound): ?int {
            $round = $this->readRound();
            if ($round !== $fromRound) {
                return null;
            }

            foreach ($this->pumpkins as $id) {
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
        foreach ($this->pumpkins as $id) {
            $keys[] = self::KEY_PUMPKIN . $id;
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
     * @return non-empty-string
     */
    private function known(string $id): string
    {
        return \in_array($id, $this->pumpkins, true)
            ? $id
            : throw new \InvalidArgumentException(\sprintf('Unknown pumpkin "%s".', $id));
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
        return self::toPumpkin($id, $this->storage->get(self::KEY_PUMPKIN . $id));
    }

    private function writePumpkin(Pumpkin $pumpkin): void
    {
        $this->storage->set(
            self::KEY_PUMPKIN . $pumpkin->id,
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
