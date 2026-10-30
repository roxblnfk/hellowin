<?php

declare(strict_types=1);

namespace App\Domain\Story;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The autopilot: `hellowin:auto` `{on, next, ctx, since, readyAt, dawnUntil, owlRollAt, acted, resumedAt, paused}`,
 * changed under the lock `auto`. `next` is the move the clients were told last. Not part of the story: the reset and a
 * new cycle of the evening leave it alone. The empty room ({@see Autopilot::empty()}) lives beside it in
 * `hellowin:auto:empty` `{since, done}`, under the same lock: the record starts over with every scene, the reset among
 * them, and the empty room has to outlive its own reset.
 *
 * @psalm-import-type Next from Autopilot
 * @psalm-import-type EmptyRoom from Autopilot
 * @psalm-type Auto = array{on: bool, record: AutoRecord|null, next: Next|null}
 */
final readonly class AutoStore
{
    private const KEY = 'hellowin:auto';
    private const EMPTY_KEY = 'hellowin:auto:empty';
    private const LOCK = 'auto';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     * @throws LockBusyException
     */
    public function locked(\Closure $operation): mixed
    {
        return $this->locks->run([self::LOCK], $operation);
    }

    /**
     * @return Auto
     */
    public function read(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        if (!\is_array($value) || ($value['on'] ?? null) !== true) {
            return ['on' => false, 'record' => null, 'next' => null];
        }

        return ['on' => true, 'record' => AutoRecord::fromArray($value), 'next' => self::next($value['next'] ?? null)];
    }

    /**
     * @param Next|null $next
     */
    public function write(bool $on, ?AutoRecord $record = null, ?array $next = null): void
    {
        $this->storage->set(self::KEY, ['on' => $on, 'next' => $on ? $next : null] + ($on ? $record?->toArray() ?? [] : []));
    }

    /**
     * @return EmptyRoom|null Null — the room was not empty at the last count.
     */
    public function emptyRoom(): ?array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::EMPTY_KEY);
        /** @var mixed $since */
        $since = \is_array($value) ? $value['since'] ?? null : null;
        $done = \is_array($value) && ($value['done'] ?? null) === true;

        return \is_int($since) ? ['since' => $since, 'done' => $done] : null;
    }

    /**
     * @param EmptyRoom|null $empty
     */
    public function writeEmptyRoom(?array $empty): void
    {
        $empty === null ? $this->storage->delete(self::EMPTY_KEY) : $this->storage->set(self::EMPTY_KEY, $empty);
    }

    /**
     * @return Next|null
     */
    private static function next(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $action = match ($value['action'] ?? null) {
            Autopilot::ACTION_BAR => Autopilot::ACTION_BAR,
            Autopilot::ACTION_SCENE => Autopilot::ACTION_SCENE,
            Autopilot::ACTION_STEP => Autopilot::ACTION_STEP,
            Autopilot::ACTION_DICE => Autopilot::ACTION_DICE,
            Autopilot::ACTION_AGAIN => Autopilot::ACTION_AGAIN,
            default => null,
        };
        if ($action === null) {
            return null;
        }

        /** @var mixed $to */
        $to = $value['to'] ?? null;
        /** @var mixed $at */
        $at = $value['at'] ?? null;
        /** @var mixed $wait */
        $wait = $value['wait'] ?? null;
        $next = ['action' => $action];
        \is_int($to) and $next['to'] = $to;
        \is_int($at) and $next['at'] = $at;
        $wait === Autopilot::WAIT_ROOM || $wait === Autopilot::WAIT_PAUSE and $next['wait'] = $wait;
        ($value['force'] ?? null) === true and $next['force'] = true;

        return $next;
    }
}
