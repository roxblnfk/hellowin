<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use Psr\SimpleCache\CacheInterface;

/**
 * The players' chat: the last messages in `hellowin:chat` — `{seq, messages}` — and the muted players in
 * `hellowin:chat:muted`, both changed under the lock `chat`. They are the whole evening's: neither a reset nor a new
 * cycle clears them.
 *
 * @psalm-type Message = array{
 *     id: positive-int,
 *     player: array{id: non-empty-string, name: non-empty-string|null},
 *     text: non-empty-string,
 *     zombie?: true,
 *     at: int,
 * }
 */
final readonly class ChatStore
{
    private const KEY = 'hellowin:chat';
    private const KEY_MUTED = 'hellowin:chat:muted';
    private const LOCK = 'chat';

    public function __construct(
        private CacheInterface $storage,
        private Locks $locks,
    ) {}

    /**
     * @return list<Message> From the oldest to the newest.
     */
    public function messages(): array
    {
        return $this->read()['messages'];
    }

    /**
     * Numbers the message after the last one ever kept and drops the oldest beyond the limit.
     *
     * @param array{player: array{id: non-empty-string, name: non-empty-string|null}, text: non-empty-string, zombie?: true, at: int} $message
     * @param positive-int $limit
     * @return Message
     * @throws LockBusyException
     */
    public function append(array $message, int $limit): array
    {
        return $this->locks->run([self::LOCK], function () use ($message, $limit): array {
            $chat = $this->read();
            $seq = $chat['seq'] + 1;
            $message = ['id' => $seq] + $message;
            $messages = \array_slice([...$chat['messages'], $message], -$limit);
            $this->storage->set(self::KEY, ['seq' => $seq, 'messages' => $messages]);

            return $message;
        });
    }

    /**
     * @return list<non-empty-string> Ids of the muted players, in the order they were muted.
     */
    public function muted(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY_MUTED);
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter(
            $value,
            static fn(mixed $id): bool => \is_string($id) && $id !== '',
        ));
    }

    public function isMuted(string $id): bool
    {
        return \in_array($id, $this->muted(), true);
    }

    /**
     * @param non-empty-string $id
     * @return list<non-empty-string> The muted players after the change.
     * @throws LockBusyException
     */
    public function mute(string $id, bool $on): array
    {
        return $this->locks->run([self::LOCK], function () use ($id, $on): array {
            $muted = \array_values(\array_filter($this->muted(), static fn(string $muted): bool => $muted !== $id));
            $on and $muted[] = $id;
            $this->storage->set(self::KEY_MUTED, $muted);

            return $muted;
        });
    }

    /**
     * @return array{seq: int<0, max>, messages: list<Message>}
     */
    private function read(): array
    {
        /** @var mixed $value */
        $value = $this->storage->get(self::KEY);
        if (!\is_array($value)) {
            return ['seq' => 0, 'messages' => []];
        }

        /** @var mixed $seq */
        $seq = $value['seq'] ?? 0;
        /** @var mixed $messages */
        $messages = $value['messages'] ?? [];

        /** @var list<Message> $messages */
        $messages = \is_array($messages) ? \array_values(\array_filter($messages, \is_array(...))) : [];

        return ['seq' => \is_int($seq) && $seq > 0 ? $seq : 0, 'messages' => $messages];
    }
}
