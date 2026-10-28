<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Story;

use App\Application\Story\RapierService;
use App\Application\Story\StoryService;
use App\Application\Story\WaitException;
use App\Application\Ticker\Ticker;
use App\Domain\Story\OwlClock;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The owl explains before the room acts (docs/spec/protocol.md, «Сова объясняет: ввод, подсказка,
 * авто-расколдовывание»): the word opens at the instruction of `finale-6`, the owl hints at it once, and a zombie
 * player turns human by itself. The harness's `finale-6` starts 2650 ms after the step and leads 4000 ms to the
 * instruction; any other line holds the bubble 3000 ms.
 */
#[Test]
#[Covers(RapierService::class)]
#[Covers(StoryService::class)]
#[Covers(OwlClock::class)]
final class OwlExplainsTest
{
    private const JACK = 'aaaaaaaaaaaaaaaa';
    private const JILL = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function wordOpensAtTheInstructionOfTheOwlsLine(): void
    {
        $ritual = self::cursed();
        $ritual->broadcast->published = [];

        $ritual->story->finaleStep(6, force: true);

        $wordAt = $ritual->clock->now + 2650 + 4000;
        Assert::same($ritual->wordStore->wordAt(1), $wordAt);
        $types = \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
        Assert::true(\array_search('finale', $types, true) < \array_search('owl', $types, true));
        Assert::same($ritual->broadcast->ofType('finale')[0]['wordAt'] ?? null, $wordAt);
        Assert::same($ritual->snapshot->create()['finale']['wordAt'] ?? null, $wordAt);
    }

    public function wordOpensAfterTheLineTheOwlIsStillHolding(): void
    {
        $ritual = self::cursed();
        $ritual->story->say('scene-ready', null);

        $ritual->story->finaleStep(6, force: true);

        Assert::same($ritual->wordStore->wordAt(1), $ritual->clock->now + 3000 + 4000);
    }

    public function laterLinesDoNotMoveTheWord(): void
    {
        $ritual = self::rapier();
        $wordAt = $ritual->wordStore->wordAt(1);

        $ritual->story->say('scene-ready', null);
        $ritual->story->sayText('Ещё немного терпения');

        Assert::same($ritual->wordStore->wordAt(1), $wordAt);
        Assert::same($ritual->rapier->openWord(1), $wordAt);
    }

    public function wordBeforeTheInstructionWaitsAndSpendsNothing(): void
    {
        $ritual = self::rapier();
        $wordAt = $ritual->wordStore->wordAt(1);
        Assert::notNull($wordAt);
        $ritual->clock->now = $wordAt - 1;
        $ritual->broadcast->published = [];

        Assert::same(self::wait(static fn() => $ritual->rapier->word(self::JILL, 'пиво')), $wordAt);

        Assert::blank($ritual->broadcast->published);
        Assert::false($ritual->walkerStore->all()[self::JILL]->zombie());
        $ritual->clock->now = $wordAt;
        Assert::true($ritual->rapier->word(self::JACK, 'rapira'));
    }

    public function owlHintsOnceWhileNobodyHasTheRapier(): void
    {
        $ritual = self::rapier();
        $hintAt = (int) $ritual->wordStore->wordAt(1) + RitualHarness::WORD_HINT_MS;
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        Assert::false($ritual->rapier->hint($hintAt - 1));
        Assert::true($ritual->rapier->hint($hintAt));
        Assert::false($ritual->rapier->hint($hintAt + 60_000));

        Assert::same($ritual->broadcast->ofType('owl'), [['type' => 'owl', 'round' => 1, 'line' => 'word-hint']]);
    }

    public function owlDoesNotHintOnceTheBossIsAwake(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JACK, 'rapira');
        $ritual->broadcast->published = [];

        Assert::false($ritual->rapier->hint((int) $ritual->wordStore->wordAt(1) + RitualHarness::WORD_HINT_MS));

        Assert::blank($ritual->broadcast->ofType('owl'));
    }

    public function noHintAtZero(): void
    {
        $ritual = self::rapier(wordHintMs: 0);

        Assert::false($ritual->rapier->hint($ritual->clock->now + 3_600_000));
    }

    public function tickerHintsAndCures(): void
    {
        $ritual = self::rapier();
        $ticker = new Ticker($ritual->top, 500, $ritual->flyers, [999_999, 999_999], $ritual->walks, new NullLogger(), rapier: $ritual->rapier);
        $ritual->rapier->word(self::JILL, 'пиво');
        $ritual->broadcast->published = [];

        $ritual->clock->now = (int) $ritual->wordStore->wordAt(1) + RitualHarness::WORD_HINT_MS;
        $ticker->tick($ritual->clock->now);

        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['word-hint']);
        Assert::same(\array_column($ritual->broadcast->ofType('cure'), 'auto'), [true]);
    }

    public function wrongWordSetsTheZombiesOwnTime(): void
    {
        $ritual = self::rapier();
        $ritual->broadcast->published = [];

        $ritual->rapier->word(self::JILL, 'пиво');

        $until = $ritual->clock->now + RitualHarness::ZOMBIE_PLAYER_MS;
        Assert::same($ritual->broadcast->ofType('word')[0]['until'] ?? null, $until);
        Assert::same(\array_column($ritual->snapshot->create()['walkers'], null, 'id')[self::JILL]['zombieUntil'] ?? null, $until);
    }

    public function zombieTurnsHumanByItselfOnce(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JILL, 'пиво');
        $until = $ritual->clock->now + RitualHarness::ZOMBIE_PLAYER_MS;
        $ritual->broadcast->published = [];

        Assert::blank($ritual->rapier->recover($until - 1));
        Assert::same($ritual->rapier->recover($until), [self::JILL]);
        Assert::blank($ritual->rapier->recover($until + 1));

        Assert::same($ritual->broadcast->ofType('cure'), [['type' => 'cure', 'round' => 1, 'player' => ['id' => self::JILL, 'name' => 'Jill'], 'auto' => true]]);
        $jill = \array_column($ritual->snapshot->create()['walkers'], null, 'id')[self::JILL];
        Assert::false(isset($jill['zombie']) || isset($jill['zombieUntil']));
        // The late click finds a human, and the cured player may say the word again
        Assert::false($ritual->rapier->cure(self::JACK, self::JILL));
        Assert::true($ritual->rapier->word(self::JILL, 'rapira'));
    }

    public function cureByAClickLeavesTheTickerNothing(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JILL, 'пиво');
        $until = $ritual->clock->now + RitualHarness::ZOMBIE_PLAYER_MS;

        Assert::true($ritual->rapier->cure(self::JACK, self::JILL));

        Assert::blank($ritual->rapier->recover($until));
        Assert::same(\array_keys($ritual->broadcast->ofType('cure')[0]), ['type', 'round', 'player', 'by']);
        Assert::count($ritual->broadcast->ofType('cure'), 1);
    }

    public function zombieWhoLeftComesBackWithTheSameTime(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JILL, 'пиво');
        $until = $ritual->clock->now + RitualHarness::ZOMBIE_PLAYER_MS;
        self::leave($ritual);

        $ritual->presence->online = [self::JACK, self::JILL];
        $ritual->walks->sync();

        $jill = $ritual->walkerStore->all()[self::JILL];
        Assert::true($jill->zombie());
        Assert::same($jill->zombieUntil, $until);
        Assert::same(\array_slice($ritual->broadcast->ofType('walker'), -1)[0]['walker']['zombieUntil'] ?? null, $until);
    }

    public function zombieWhoLeftComesBackHumanAfterItsTime(): void
    {
        $ritual = self::rapier();
        $ritual->rapier->word(self::JILL, 'пиво');
        self::leave($ritual);
        $ritual->clock->now += RitualHarness::ZOMBIE_PLAYER_MS;
        $ritual->broadcast->published = [];

        $ritual->presence->online = [self::JACK, self::JILL];
        $ritual->walks->sync();

        Assert::false($ritual->walkerStore->all()[self::JILL]->zombie());
        Assert::false(isset($ritual->broadcast->ofType('walker')[0]['walker']['zombie']));
        Assert::blank($ritual->broadcast->ofType('cure'));
    }

    public function zombieStaysOneUntilACureAtZero(): void
    {
        $ritual = self::rapier(zombiePlayerMs: 0);
        $ritual->broadcast->published = [];

        $ritual->rapier->word(self::JILL, 'пиво');

        Assert::false(isset($ritual->broadcast->ofType('word')[0]['until']));
        Assert::false(isset(\array_column($ritual->snapshot->create()['walkers'], null, 'id')[self::JILL]['zombieUntil']));
        Assert::blank($ritual->rapier->recover($ritual->clock->now + 3_600_000));
        Assert::true($ritual->walkerStore->all()[self::JILL]->zombie());
    }

    /**
     * The cursed world with Jack and Jill standing on the lawn, every line of the owl long said.
     *
     * @param int<0, max> $wordHintMs
     * @param int<0, max> $zombiePlayerMs
     */
    private static function cursed(int $wordHintMs = RitualHarness::WORD_HINT_MS, int $zombiePlayerMs = RitualHarness::ZOMBIE_PLAYER_MS): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1, reachable: ['pumpkin-2'], wordHintMs: $wordHintMs, zombiePlayerMs: $zombiePlayerMs);
        $ritual->transitions->scene(1, 1);
        $ritual->players->register(self::JACK, 'Jack', self::LOOK);
        $ritual->walks->move(self::JACK, 20, 90);
        $ritual->players->register(self::JILL, 'Jill', self::LOOK);
        $ritual->walks->move(self::JILL, 60, 90);
        for ($step = 1; $step <= 5; ++$step) {
            $ritual->story->finaleStep($step, force: true);
        }
        $ritual->clock->now += 60_000;

        return $ritual;
    }

    /**
     * The rapier step, the word open.
     *
     * @param int<0, max> $wordHintMs
     * @param int<0, max> $zombiePlayerMs
     */
    private static function rapier(int $wordHintMs = RitualHarness::WORD_HINT_MS, int $zombiePlayerMs = RitualHarness::ZOMBIE_PLAYER_MS): RitualHarness
    {
        $ritual = self::cursed($wordHintMs, $zombiePlayerMs);
        $ritual->story->finaleStep(6, force: true);
        $ritual->owlExplained();

        return $ritual;
    }

    /**
     * Jill's connection is gone long enough for her character to leave the lawn.
     */
    private static function leave(RitualHarness $ritual): void
    {
        $ritual->presence->online = [self::JACK];
        $ritual->clock->now += 10_000;
        $ritual->walks->sync();
        Assert::false(isset($ritual->walkerStore->all()[self::JILL]));
    }

    /**
     * @param \Closure(): mixed $call
     * @return int|null The moment the refusal names.
     */
    private static function wait(\Closure $call): ?int
    {
        try {
            $call();
        } catch (WaitException $e) {
            return $e->openAt;
        }

        return null;
    }
}
