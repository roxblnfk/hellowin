<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Chat;

use App\Application\Chat\ChatRefusedException;
use App\Application\Chat\EmoteService;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Walk\NoCharacterException;
use App\Domain\Lock\LockBusyException;
use App\Domain\Lock\Locks;
use App\Domain\RateLimit\RateLimiter;
use App\Domain\RateLimit\TokenBucket;
use Spiral\Cache\Storage\ArrayStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(EmoteService::class)]
final class EmoteServiceTest
{
    private const A = '0123456789abcdef';
    private const B = 'fedcba9876543210';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function emoteGoesToEveryoneOverTheCharacter(): void
    {
        $ritual = self::lawn();

        $ritual->emotes->emote(self::A, '🍺');

        Assert::same($ritual->broadcast->ofType('emote'), [[
            'type' => 'emote',
            'round' => 1,
            'player' => ['id' => self::A],
            'emoji' => '🍺',
            'at' => $ritual->clock->now,
        ]]);
        Assert::same($ritual->counters()[PlayMetrics::EMOTES . '{🍺}'] ?? null, 1.0);
    }

    public function playerWithoutACharacterHasNoEmote(): void
    {
        $ritual = self::lawn();

        Expect::exception(NoCharacterException::class);

        $ritual->emotes->emote(self::B, '🎃');
    }

    public function emojiOutOfTheSetIsRefused(): void
    {
        $ritual = self::lawn();

        Expect::exception(ChatRefusedException::class)->withMessage('unknown emoji');

        $ritual->emotes->emote(self::A, '🦄');
    }

    public function secondEmoteWithinTheCooldownIsBusyAndSilent(): void
    {
        $ritual = self::lawn();
        $ritual->emotes->emote(self::A, '🎃');
        $ritual->clock->now += RitualHarness::EMOTE_COOLDOWN - 1;

        try {
            $ritual->emotes->emote(self::A, '👻');
            Assert::fail('A second emote within the cooldown must be refused.');
        } catch (ChatRefusedException $e) {
            Assert::same($e->error, 'busy');
        }

        Assert::count($ritual->broadcast->ofType('emote'), 1);
        Assert::null($ritual->counters()[PlayMetrics::EMOTES . '{👻}'] ?? null);
    }

    public function emoteComesBackAfterTheCooldown(): void
    {
        $ritual = self::lawn();
        $ritual->emotes->emote(self::A, '🎃');
        $ritual->clock->now += RitualHarness::EMOTE_COOLDOWN;

        $ritual->emotes->emote(self::A, '👻');

        Assert::count($ritual->broadcast->ofType('emote'), 2);
    }

    public function cooldownIsPerPlayer(): void
    {
        $ritual = self::lawn();
        $ritual->walks->enter($ritual->players->register(self::B, 'Ann', self::LOOK));

        $ritual->emotes->emote(self::A, '🎃');
        $ritual->emotes->emote(self::B, '🎃');

        Assert::count($ritual->broadcast->ofType('emote'), 2);
    }

    public function zombieEmotesOnPause(): void
    {
        $ritual = self::lawn();
        $now = $ritual->clock->now;
        $ritual->walkerStore->update(static function (array $walkers) use ($now): array {
            $walkers[self::A] = $walkers[self::A]->zombified($now, $now);

            return [$walkers, null];
        });
        $ritual->story->pause(true);

        $ritual->emotes->emote(self::A, '🎃');

        Assert::count($ritual->broadcast->ofType('emote'), 1);
    }

    public function emoteDoesNotStopTheWalk(): void
    {
        $ritual = self::lawn();
        $ritual->walks->move(self::A, 50.0, 90.0);
        $walking = $ritual->walkerStore->all()[self::A];
        $moves = \count($ritual->broadcast->ofType('move'));

        $ritual->emotes->emote(self::A, '🎃');

        Assert::equals($ritual->walkerStore->all()[self::A], $walking);
        Assert::count($ritual->broadcast->ofType('move'), $moves);
    }

    public function busyLockOfTheCooldownIsBusy(): void
    {
        $ritual = self::lawn();
        $emotes = new EmoteService(
            RitualHarness::EMOTES,
            $ritual->walkerStore,
            $ritual->store,
            $ritual->publisher,
            $ritual->clock,
            new RateLimiter(new ArrayStorage(), new Locks(new FakeLock(available: false)), $ritual->clock, EmoteService::bucket(1000)),
            $ritual->playMetrics,
        );

        Expect::exception(LockBusyException::class);

        $emotes->emote(self::A, '🎃');
    }

    public function zeroCooldownLeavesNoBucket(): void
    {
        Assert::same(EmoteService::bucket(0), []);
        Assert::equals(EmoteService::bucket(500), [EmoteService::GROUP => new TokenBucket(2.0, 1)]);
    }

    private static function lawn(): RitualHarness
    {
        $ritual = new RitualHarness();
        $ritual->walks->enter($ritual->players->register(self::A, 'Jack', self::LOOK));
        $ritual->players->register(self::B, 'Ann', self::LOOK);

        return $ritual;
    }
}
