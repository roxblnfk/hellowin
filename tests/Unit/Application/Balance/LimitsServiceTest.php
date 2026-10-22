<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Balance;

use App\Application\Balance\LimitsService;
use App\Application\Hellowin\Job\TransitionPumpkin;
use App\Domain\Balance\Limit;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The limits follow the room: the ticker's count of figures, held for a while, and one `limits` event per change.
 */
#[Test]
#[Covers(LimitsService::class)]
final class LimitsServiceTest
{
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function newCountHoldsBeforeTheLimitsFollowIt(): void
    {
        $ritual = new RitualHarness();
        $ritual->limits->follow();
        self::enter($ritual, 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc');

        $ritual->limits->follow();
        $ritual->clock->now += LimitsService::DEBOUNCE - 1;
        $ritual->limits->follow();
        Assert::same($ritual->store->story()->players, 0);

        $ritual->clock->now += 1;
        $ritual->limits->follow();
        $ritual->limits->follow();

        Assert::same($ritual->store->story()->players, 3);
        Assert::same(\array_column($ritual->broadcast->ofType('limits'), 'players'), [1, 3]);
    }

    public function flickeringCountStartsTheWaitOver(): void
    {
        $ritual = new RitualHarness();
        self::enter($ritual, 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb');
        $ritual->limits->follow();

        $ritual->clock->now += 2000;
        self::enter($ritual, 'cccccccccccccccc');
        $ritual->limits->follow();
        $ritual->clock->now += 2000;
        $ritual->limits->follow();
        Assert::same($ritual->store->story()->players, 0);

        $ritual->clock->now += 1000;
        $ritual->limits->follow();
        Assert::same($ritual->store->story()->players, 3);
    }

    public function eventGoesOutOnChangeOnly(): void
    {
        $ritual = new RitualHarness(threshold: 4);

        $ritual->limits->settle();
        $ritual->limits->settle();
        $ritual->story->thresholds(1);
        $ritual->limits->follow();

        Assert::same($ritual->broadcast->ofType('limits'), [
            ['type' => 'limits', 'round' => 1, 'players' => 1, 'k' => 1, 'burst' => true, 'limits' => ['pumpkin' => 4, 'bonfire' => 3]],
        ]);
    }

    public function fewerFiguresMoveAnElementThatHasTheClicksOnce(): void
    {
        $ritual = new RitualHarness(stageMax: [3, 6], pumpkin: new Limit(2, 2));
        $ritual->store->updateStory(static fn(Story $story): Story => $story->withPlayers(3));
        $ritual->click('pumpkin-1', 4);
        $ritual->limits->settle();
        Assert::blank($ritual->queue->pushed);

        // The room thins out to one: the threshold drops to 2, and the 4 clicks gathered move the pumpkin
        $ritual->store->updateStory(static fn(Story $story): Story => $story->withPlayers(1));
        $ritual->limits->settle();
        $ritual->limits->settle();

        Assert::same(\array_column($ritual->queue->payloads(TransitionPumpkin::class), 'key'), ['1:pumpkin-1:1']);
        $ritual->runJobs();
        Assert::same(\array_column($ritual->broadcast->ofType('pumpkin'), 'stage'), [1]);
    }

    public function eachContextHasItsOwnLimits(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1], circle: 2);
        Assert::same($ritual->limits->state()['limits'], ['bar' => 4]);

        $ritual->story->nextScene(0, true);
        $ritual->runJobs();
        Assert::same($ritual->limits->state()['limits'], ['pumpkin' => 2, 'bonfire' => 3]);

        $ritual->story->nextScene(1, true);
        $ritual->runJobs();
        Assert::same($ritual->limits->state()['limits'], ['circle' => 3]);

        $ritual->story->finaleStep(1, force: true);
        Assert::same($ritual->limits->state()['limits'], []);
        Assert::equals($ritual->snapshot->create()['limits']['limits'], new \stdClass());
        Assert::string((string) \json_encode(LimitsService::toClient($ritual->limits->state())))->contains('"limits":{}');
    }

    private static function enter(RitualHarness $ritual, string ...$ids): void
    {
        foreach ($ids as $id) {
            $ritual->players->register($id, 'Jack', self::LOOK);
            $ritual->walks->move($id, 40, 90);
        }
    }
}
