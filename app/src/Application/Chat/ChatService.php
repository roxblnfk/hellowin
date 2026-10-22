<?php

declare(strict_types=1);

namespace App\Application\Chat;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Player\PlayerService;
use App\Application\Walk\NoCharacterException;
use App\Domain\Chat\ChatStore;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\PlayerId;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\WalkerStore;

/**
 * The players' chat (docs/spec/protocol.md, «Чат»): a message is a bubble over the speaker's character for everyone,
 * and the history and the mute stay with the moderator. A zombie's words are kept for the moderator only: the event
 * carries no text, so the page cannot leak it.
 *
 * @psalm-import-type Message from ChatStore
 */
final readonly class ChatService
{
    /**
     * @param positive-int $max Characters of a message at most, after the cleaning.
     * @param positive-int $history Messages the history keeps.
     */
    public function __construct(
        private ChatStore $store,
        private WalkerStore $walkers,
        private PlayerService $players,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private Clock $clock,
        private int $max,
        private int $history,
    ) {}

    /**
     * Trims the text and turns every run of control characters, line breaks and spaces into one space.
     *
     * @return non-empty-string|null Null — not a string, not UTF-8 or nothing left.
     */
    public static function clean(mixed $text): ?string
    {
        if (!\is_string($text) || !\mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $text = \trim((string) \preg_replace('/[\s\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * @param non-empty-string $id
     * @throws NoCharacterException
     * @throws ChatRefusedException `invalid text`, `muted`.
     * @throws LockBusyException
     */
    public function say(string $id, mixed $text): void
    {
        $walker = $this->walkers->all()[$id] ?? throw new NoCharacterException('no character');
        $text = self::clean($text);
        $text !== null && \mb_strlen($text) <= $this->max or throw new ChatRefusedException('invalid text');
        $this->store->isMuted($id) and throw new ChatRefusedException('muted');

        $message = ['player' => ['id' => $id, 'name' => $walker->name], 'text' => $text];
        $walker->zombie() and $message['zombie'] = true;
        $message['at'] = $this->clock->now();

        $this->store->append($message, $this->history);
        $this->publisher->say($this->ritual->round(), $message['player'], $walker->zombie() ? null : $text, $message['at']);
    }

    /**
     * @return array{messages: list<Message>, muted: list<array{id: non-empty-string, name: non-empty-string|null}>}
     */
    public function history(): array
    {
        return ['messages' => $this->store->messages(), 'muted' => $this->mutedRefs($this->store->muted())];
    }

    /**
     * A player who has left the lawn may be muted too: only a record of the player is needed.
     *
     * @return list<array{id: non-empty-string, name: non-empty-string|null}> The muted players after the change.
     * @throws ChatRefusedException `no target`, `invalid on`.
     * @throws LockBusyException
     */
    public function mute(mixed $player, mixed $on): array
    {
        PlayerId::isValid($player) && $this->players->find($player) !== null or throw new ChatRefusedException('no target');
        \is_bool($on) or throw new ChatRefusedException('invalid on');

        return $this->mutedRefs($this->store->mute($player, $on));
    }

    /**
     * @param list<non-empty-string> $ids
     * @return list<array{id: non-empty-string, name: non-empty-string|null}>
     */
    private function mutedRefs(array $ids): array
    {
        return \array_map(fn(string $id): array => ['id' => $id, 'name' => $this->players->find($id)?->name], $ids);
    }
}
