<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chat;

use App\Application\Chat\ChatRefusedException;
use App\Application\Chat\ChatService;
use App\Application\Walk\NoCharacterException;
use App\Domain\Chat\ChatStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(ChatService::class)]
#[Covers(ChatStore::class)]
final class ChatServiceTest
{
    private const A = '0123456789abcdef';
    private const B = 'fedcba9876543210';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    #[DataSet(["  Boo!  ", 'Boo!'], 'trimmed')]
    #[DataSet(["a\nb\r\nc", 'a b c'], 'line breaks')]
    #[DataSet(["a\t\x00\x07b", 'a b'], 'control characters')]
    #[DataSet(["a    b", 'a b'], 'spaces collapsed')]
    #[DataSet(["a\u{2028}b", 'a b'], 'line separator')]
    #[DataSet(["👨‍👩‍👧", "👨‍👩‍👧"], 'joined emoji kept')]
    public function cleaningTurnsControlsAndBreaksIntoOneSpace(string $text, string $clean): void
    {
        Assert::same(ChatService::clean($text), $clean);
    }

    #[DataSet([''], 'empty')]
    #[DataSet([" \n\t "], 'only blanks')]
    #[DataSet([42], 'number')]
    #[DataSet([null], 'missing')]
    #[DataSet(["\xff\xfe"], 'not UTF-8')]
    public function nothingLeftIsNoText(mixed $text): void
    {
        Assert::null(ChatService::clean($text));
    }

    public function messageGoesToEveryoneOverTheSpeakersCharacter(): void
    {
        $ritual = self::lawn();

        $ritual->chat->say(self::A, "  кто\n тут?! ");

        Assert::same($ritual->broadcast->ofType('say'), [[
            'type' => 'say',
            'round' => 1,
            'player' => ['id' => self::A, 'name' => 'Jack'],
            'text' => 'кто тут?!',
            'at' => $ritual->clock->now,
        ]]);
    }

    public function lengthCountsCharactersAfterTheCleaning(): void
    {
        $ritual = self::lawn();

        $ritual->chat->say(self::A, '  ' . \str_repeat('ы', RitualHarness::CHAT_MAX) . "\n");

        Assert::count($ritual->broadcast->ofType('say'), 1);
    }

    public function tooLongIsInvalidText(): never
    {
        $ritual = self::lawn();

        Expect::exception(ChatRefusedException::class)->withMessage('invalid text');

        $ritual->chat->say(self::A, \str_repeat('ы', RitualHarness::CHAT_MAX + 1));
    }

    public function playerWithoutACharacterHasNoCharacter(): never
    {
        $ritual = new RitualHarness();
        $ritual->players->register(self::A, 'Jack');

        Expect::exception(NoCharacterException::class);

        $ritual->chat->say(self::A, 'Boo');
    }

    public function noCharacterComesBeforeInvalidText(): never
    {
        $ritual = new RitualHarness();

        Expect::exception(NoCharacterException::class);

        $ritual->chat->say(self::A, '');
    }

    public function invalidTextComesBeforeMuted(): never
    {
        $ritual = self::lawn();
        $ritual->chat->mute(self::A, true);

        Expect::exception(ChatRefusedException::class)->withMessage('invalid text');

        $ritual->chat->say(self::A, '   ');
    }

    public function mutedPlayerIsRefused(): never
    {
        $ritual = self::lawn();
        $ritual->chat->mute(self::A, true);

        Expect::exception(ChatRefusedException::class)->withMessage('muted');

        $ritual->chat->say(self::A, 'Boo');
    }

    public function unmutedPlayerSpeaksAgain(): void
    {
        $ritual = self::lawn();
        $ritual->chat->mute(self::A, true);

        Assert::same($ritual->chat->mute(self::A, false), []);
        $ritual->chat->say(self::A, 'Boo');

        Assert::count($ritual->broadcast->ofType('say'), 1);
    }

    public function zombieMoansWithoutTextAndTheHistoryKeepsWhatItMeant(): void
    {
        $ritual = self::lawn();
        $now = $ritual->clock->now;
        $ritual->walkerStore->update(static function (array $walkers) use ($now): array {
            $walkers[self::A] = $walkers[self::A]->zombified($now, $now);

            return [$walkers, null];
        });

        $ritual->chat->say(self::A, 'я не зомби');

        Assert::same($ritual->broadcast->ofType('say'), [[
            'type' => 'say',
            'round' => 1,
            'player' => ['id' => self::A, 'name' => 'Jack'],
            'zombie' => true,
            'at' => $now,
        ]]);
        Assert::same($ritual->chat->history()['messages'], [[
            'id' => 1,
            'player' => ['id' => self::A, 'name' => 'Jack'],
            'text' => 'я не зомби',
            'zombie' => true,
            'at' => $now,
        ]]);
    }

    public function historyKeepsTheLastMessagesFromOldToNew(): void
    {
        $ritual = self::lawn();

        foreach (['one', 'two', 'three', 'four', 'five'] as $text) {
            $ritual->chat->say(self::A, $text);
            ++$ritual->clock->now;
        }

        $messages = $ritual->chat->history()['messages'];
        Assert::count($messages, RitualHarness::CHAT_HISTORY);
        Assert::same(\array_column($messages, 'text'), ['three', 'four', 'five']);
        Assert::same(\array_column($messages, 'id'), [3, 4, 5]);
    }

    public function historyAndMutedOutliveAResetAndANewCycle(): void
    {
        $ritual = self::lawn();
        $ritual->chat->say(self::A, 'Boo');
        $ritual->chat->mute(self::B, true);

        $ritual->reset->reset(1);
        $ritual->reset->reset(2, again: true);

        Assert::same(\array_column($ritual->chat->history()['messages'], 'text'), ['Boo']);
        Assert::same($ritual->chat->history()['muted'], [['id' => self::B, 'name' => 'Ann']]);
    }

    public function goneOrNamelessPlayerMayBeMuted(): void
    {
        $ritual = self::lawn();
        $ritual->clicks->click('pumpkin-1', 'aaaaaaaaaaaaaaaa');

        Assert::same($ritual->chat->mute(self::B, true), [['id' => self::B, 'name' => 'Ann']]);
        Assert::same($ritual->chat->mute('aaaaaaaaaaaaaaaa', true), [
            ['id' => self::B, 'name' => 'Ann'],
            ['id' => 'aaaaaaaaaaaaaaaa', 'name' => null],
        ]);
    }

    #[DataSet(['bbbbbbbbbbbbbbbb'], 'no record')]
    #[DataSet(['Jack'], 'not an id')]
    #[DataSet([null], 'missing')]
    public function unknownPlayerIsNoTarget(mixed $player): never
    {
        $ritual = self::lawn();

        Expect::exception(ChatRefusedException::class)->withMessage('no target');

        $ritual->chat->mute($player, true);
    }

    public function onMustBeABool(): never
    {
        $ritual = self::lawn();

        Expect::exception(ChatRefusedException::class)->withMessage('invalid on');

        $ritual->chat->mute(self::A, 1);
    }

    /**
     * Jack stands on the lawn; Ann has a record and left.
     */
    private static function lawn(): RitualHarness
    {
        $ritual = new RitualHarness();
        $ritual->walks->enter($ritual->players->register(self::A, 'Jack', self::LOOK));
        $ritual->players->register(self::B, 'Ann', self::LOOK);

        return $ritual;
    }
}
