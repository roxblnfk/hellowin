<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Walk;

use App\Application\Hellowin\PlayMetrics;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\WalkService;
use App\Domain\Flyer\Point;
use App\Domain\Player\Player;
use App\Domain\Walk\WalkerStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(WalkService::class)]
#[Covers(WalkerStore::class)]
final class WalkServiceTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function characterEntersAtTheBottomOfTheLawn(): void
    {
        $ritual = new RitualHarness();

        $walker = $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));

        Assert::numeric($walker?->walk->path[0]->y)->between(86, 90);
        Assert::same($walker->walk->startedAt, $walker->walk->arrivesAt);
        Assert::equals($ritual->broadcast->ofType('walker'), [[
            'type' => 'walker',
            'round' => 1,
            'walker' => ['id' => self::A, 'name' => 'Jack', 'look' => self::LOOK, 'item' => null, 'walk' => $walker->walk->toArray()],
        ]]);
    }

    public function playerWithoutALookStaysOff(): void
    {
        $ritual = new RitualHarness();

        Assert::null($ritual->walks->enter(new Player(self::A, 'Jack')));
        Assert::blank($ritual->walkerStore->all());
        Assert::blank($ritual->broadcast->published);
    }

    public function registeringAgainRedressesInPlace(): void
    {
        $ritual = new RitualHarness();
        $first = $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));

        $second = $ritual->walks->enter(self::register($ritual, self::A, 'Jill', ['hat' => 'horns'] + self::LOOK));

        Assert::equals($second?->walk, $first?->walk);
        Assert::same($ritual->broadcast->ofType('walker')[1]['walker']['name'], 'Jill');
        Assert::same($ritual->broadcast->ofType('walker')[1]['walker']['look']['hat'], 'horns');
        Assert::count($ritual->walkerStore->all(), 1);
    }

    public function moveWalksFromWhereTheCharacterIsNow(): void
    {
        $ritual = new RitualHarness();
        $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));
        $start = $ritual->walkerStore->all()[self::A]->walk->path[0];
        $first = $ritual->walks->move(self::A, $start->x, 96);
        $ritual->clock->now += 250;

        $second = $ritual->walks->move(self::A, 90, 90);

        Assert::equals($second->path[0], new Point($start->x, \round($start->y + 2.5, 2)));
        Assert::equals($second->path[\count($second->path) - 1], new Point(90, 90));
        Assert::same($second->startedAt, $ritual->clock->now);
        Assert::true($first->arrivesAt > $first->startedAt);
        Assert::equals($ritual->broadcast->ofType('move')[1], [
            'type' => 'move',
            'round' => 1,
            'player' => ['id' => self::A],
            'walk' => $second->toArray(),
        ]);
        Assert::equals($ritual->walkerStore->all()[self::A]->walk, $second);
    }

    public function moveIntoThePatchStopsAtItsEdge(): void
    {
        $ritual = new RitualHarness();
        $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));

        $walk = $ritual->walks->move(self::A, 50, 70);

        Assert::equals($walk->path[\count($walk->path) - 1], new Point(50, 88));
    }

    public function playerWithoutACharacterCannotMove(): never
    {
        $ritual = new RitualHarness();
        $ritual->players->register(self::A, 'Jack');

        Expect::exception(NoCharacterException::class);

        $ritual->walks->move(self::A, 10, 90);
    }

    public function moveBeforeTheCharacterEntersPutsItOnTheLawnFirst(): void
    {
        $ritual = new RitualHarness();
        self::register($ritual, self::A, 'Jack');

        $ritual->walks->move(self::A, 10, 90);

        Assert::same(\array_column($ritual->broadcast->published, 'message')[0]['type'], 'walker');
        Assert::count($ritual->broadcast->ofType('move'), 1);
    }

    public function connectedPlayerWithALookEntersOnce(): void
    {
        $ritual = new RitualHarness();
        self::register($ritual, self::A, 'Jack');
        $ritual->players->register(self::B, 'Watcher');
        $ritual->presence->online = [self::A, self::B];

        $ritual->walks->sync();
        $ritual->walks->sync();

        Assert::same(\array_column(\array_column($ritual->broadcast->ofType('walker'), 'walker'), 'id'), [self::A]);
        Assert::same(\array_keys($ritual->walkerStore->all()), [self::A]);
        Assert::equals($ritual->counters()[PlayMetrics::WALKERS . '{}'] ?? null, 1);
    }

    public function characterLeavesOnceItsPlayerIsGoneLongEnough(): void
    {
        $ritual = new RitualHarness();
        self::register($ritual, self::A, 'Jack');
        $ritual->presence->online = [self::A];
        $ritual->walks->sync();
        $ritual->presence->online = [];

        $ritual->clock->now += 4_999;
        $ritual->walks->sync();
        $stayed = $ritual->broadcast->ofType('leave');
        $ritual->clock->now += 1;
        $ritual->walks->sync();
        $ritual->walks->sync();

        Assert::blank($stayed);
        Assert::same($ritual->broadcast->ofType('leave'), [['type' => 'leave', 'round' => 1, 'player' => ['id' => self::A]]]);
        Assert::blank($ritual->walkerStore->all());
    }

    public function reconnectingInTimeKeepsTheCharacter(): void
    {
        $ritual = new RitualHarness();
        self::register($ritual, self::A, 'Jack');
        $ritual->presence->online = [self::A];
        $ritual->walks->sync();

        $ritual->presence->online = [];
        $ritual->clock->now += 3_000;
        $ritual->walks->sync();
        $ritual->presence->online = [self::A];
        $ritual->walks->sync();
        $ritual->presence->online = [];
        $ritual->clock->now += 3_000;
        $ritual->walks->sync();

        Assert::blank($ritual->broadcast->ofType('leave'));
        Assert::count($ritual->broadcast->ofType('walker'), 1);
    }

    public function charactersHoldTheItemOfTheScene(): void
    {
        $ritual = new RitualHarness();
        $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));

        $ritual->store->advanceScene(1, 1);
        $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));

        Assert::same(\array_column(\array_column($ritual->broadcast->ofType('walker'), 'walker'), 'item'), [null, 'torch']);
        Assert::same(\array_column($ritual->snapshot->create()['walkers'], 'item'), ['torch']);
    }

    public function charactersOutliveTheReset(): void
    {
        $ritual = new RitualHarness();
        $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));

        $ritual->reset->reset();

        Assert::same(\array_column($ritual->snapshot->create()['walkers'], 'id'), [self::A]);
    }

    public function charactersRunToTheEdgesWhenFrankenphpStandsRevealed(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->walks->enter(self::register($ritual, self::A, 'Jack'));
        $ritual->walks->enter(self::register($ritual, self::B, 'Jill'));
        $ritual->walks->move(self::B, 20, 55);
        $ritual->transitions->scene(1, 1);
        $ritual->story->finaleStep(1, force: true);
        $ritual->broadcast->published = [];

        $ritual->story->finaleStep(2);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['finale', 'owl', 'move', 'move']);
        $zone = RitualHarness::walkZone();
        foreach ($ritual->broadcast->ofType('move') as $move) {
            $path = $move['walk']['path'];
            $from = Point::fromArray($path[0]);
            $to = Point::fromArray($path[\count($path) - 1]);
            Assert::notNull($from);
            Assert::notNull($to);
            // Out to a side of the lawn, further from the patch than it was
            Assert::true($to->x <= $zone->x1 || $to->x >= $zone->x2 || $to->y <= $zone->y1 || $to->y >= $zone->y2);
            Assert::true(\hypot($to->x - 50, $to->y - 63) > \hypot($from->x - 50, $from->y - 63));
        }

        // Other steps leave the characters be
        $ritual->broadcast->published = [];
        $ritual->story->finaleStep(3);
        Assert::blank($ritual->broadcast->ofType('move'));
    }

    private static function register(RitualHarness $ritual, string $id, string $name, array $look = self::LOOK): Player
    {
        return $ritual->players->register($id, $name, $look);
    }
}
