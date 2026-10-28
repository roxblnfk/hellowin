<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Walk;

use App\Application\Walk\ClinkRefusedException;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\WalkService;
use App\Domain\Flyer\Point;
use App\Domain\Player\Look;
use App\Domain\Walk\Clink;
use App\Domain\Walk\Walk;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Tests\Fake\RitualHarness;

#[Test]
#[Covers(WalkService::class)]
#[Covers(Clink::class)]
#[Covers(Walker::class)]
final class ClinkTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function clinkWithACharacterWalksToItsSideAndClinksOnArrival(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $ritual->broadcast->published = [];

        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);

        // From the left, so to the left of the target, a gap away
        Assert::equals($walk->path[\count($walk->path) - 1], new Point(56, 90));
        Assert::equals($ritual->broadcast->ofType('move'), [[
            'type' => 'move',
            'round' => 1,
            'player' => ['id' => self::A],
            'walk' => $walk->toArray(),
            'clink' => ['walker' => ['id' => self::B]],
        ]]);

        $ritual->clock->now = $walk->arrivesAt - 1;
        $ritual->walks->clinks();
        Assert::blank($ritual->broadcast->ofType('clink'));
        // A late viewer learns the character is on its way to a clink from the snapshot
        Assert::same(\array_column($ritual->snapshot->create()['walkers'], 'clink', 'id'), [self::A => ['walker' => ['id' => self::B]]]);

        $ritual->clock->now = $walk->arrivesAt + 150;
        $ritual->walks->clinks();
        $ritual->walks->clinks();

        $clinks = $ritual->broadcast->ofType('clink');
        Assert::count($clinks, 1);
        Assert::blank(\array_column($ritual->snapshot->create()['walkers'], 'clink'));
        Assert::int($clinks[0]['toast'])->greaterThanOrEqual(0);
        unset($clinks[0]['toast']);
        Assert::equals($clinks[0], [
            'type' => 'clink',
            'round' => 1,
            'player' => ['id' => self::A],
            'walker' => ['id' => self::B],
            'at' => $walk->arrivesAt + 150,
        ]);
    }

    public function characterComesFromTheRightToTheRightSide(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 80, 90);
        self::place($ritual, self::B, 60, 90);

        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);

        Assert::equals($walk->path[\count($walk->path) - 1], new Point(64, 90));
    }

    public function clinkWaitsForAWalkingTargetToStop(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 80, 90);
        self::place($ritual, self::B, 60, 90);
        $target = $ritual->walks->move(self::B, 90, 90);

        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);

        // Beside where the target is heading, and only once both are there
        Assert::equals($walk->path[\count($walk->path) - 1], new Point(86, 90));
        Assert::true($target->arrivesAt > $walk->arrivesAt);
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();
        Assert::blank($ritual->broadcast->ofType('clink'));
        $ritual->clock->now = $target->arrivesAt;
        $ritual->walks->clinks();
        Assert::count($ritual->broadcast->ofType('clink'), 1);
    }

    public function targetThatWalkedOffIsNotClinkedWith(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->clock->now += 100;
        $ritual->walks->move(self::B, 90, 60);

        $ritual->clock->now = $walk->arrivesAt + 10_000;
        $ritual->walks->clinks();

        Assert::blank($ritual->broadcast->ofType('clink'));
        Assert::null($ritual->walkerStore->all()[self::A]->clink);
    }

    public function newMoveOfTheComerCancelsTheClink(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->clock->now += 100;
        $ritual->walks->move(self::A, 20, 60);

        $ritual->clock->now += 60_000;
        $ritual->walks->clinks();

        Assert::blank($ritual->broadcast->ofType('clink'));
    }

    public function newClinkOfTheComerReplacesTheOneOnTheWay(): void
    {
        $ritual = self::cursed(['pumpkin-2']);
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->clock->now += 100;
        $ritual->walks->clink(self::A, Clink::PUMPKIN, 'pumpkin-2');

        $ritual->clock->now += 60_000;
        $ritual->walks->clinks();

        Assert::same(\array_column($ritual->broadcast->ofType('clink'), 'pumpkin'), [['id' => 'pumpkin-2']]);
        Assert::blank(\array_column($ritual->broadcast->ofType('clink'), 'walker'));
    }

    /**
     * The frontend turns a pumpkin's mug outwards from the root of the patch, to the lawn; the harness pumpkins are of
     * size `m`.
     */
    #[DataSet(['pumpkin-3', 66.2, 48.3, new Point(78.12, 45.65)], 'right, above')]
    #[DataSet(['pumpkin-5', 71.2, 58.4, new Point(84.02, 57.57)], 'right')]
    #[DataSet(['pumpkin-11', 40, 83.7, new Point(29.2, 87.43)], 'left, below')]
    #[DataSet(['pumpkin-15', 27.7, 60.2, new Point(14.69, 59.7)], 'left')]
    #[DataSet(['pumpkin-17', 35.8, 51.5, new Point(24.05, 49.33)], 'left, onto the edge of the patch')]
    public function clinkWithAPumpkinStandsOnTheSideOfItsMug(string $id, float $x, float $y, Point $meet): void
    {
        $ritual = self::cursed([$id], [$id], [$id => [$x, $y]]);
        self::place($ritual, self::A, 50, 95);

        $walk = $ritual->walks->clink(self::A, Clink::PUMPKIN, $id);

        $end = $walk->path[\count($walk->path) - 1];
        Assert::equals($end, $meet);
        Assert::same($end->x > $x, $x > 50);
    }

    public function pumpkinRightAboveTheRootKeepsItsMugByItsId(): void
    {
        // crc32('pumpkin-11') is odd: the mug on the left
        $ritual = self::cursed(['pumpkin-11'], ['pumpkin-11'], ['pumpkin-11' => [50, 85]]);
        self::place($ritual, self::A, 70, 95);

        $walk = $ritual->walks->clink(self::A, Clink::PUMPKIN, 'pumpkin-11');

        Assert::same(\crc32('pumpkin-11') & 1, 1);
        Assert::true($walk->path[\count($walk->path) - 1]->x <= 46.0);
    }

    public function pumpkinBeyondTheLawnIsMetOnItsEdge(): void
    {
        // Right of the patch with its mug to the right, but the lawn ends before the jumped pumpkin
        $ritual = self::cursed(['pumpkin-1'], ['pumpkin-1'], ['pumpkin-1' => [96, 90]]);
        self::place($ritual, self::A, 60, 92);

        $walk = $ritual->walks->clink(self::A, Clink::PUMPKIN, 'pumpkin-1');

        Assert::equals($walk->path[\count($walk->path) - 1], new Point(97, 94.86));
    }

    public function clinkWithAPumpkinAboveTheLawnWalksToItsEdge(): void
    {
        $ritual = self::cursed(['pumpkin-2']);
        self::place($ritual, self::A, 20, 90);

        $walk = $ritual->walks->clink(self::A, Clink::PUMPKIN, 'pumpkin-2');
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();

        // The harness pumpkins stand at (10, 20), above the lawn
        Assert::equals($walk->path[\count($walk->path) - 1], new Point(3, 44));
        Assert::same($ritual->broadcast->ofType('clink')[0]['pumpkin'], ['id' => 'pumpkin-2']);
        Assert::false(isset($ritual->broadcast->ofType('clink')[0]['walker']));
    }

    public function clinkDoesNotHappenOnceTheWorldIsNoLongerCursed(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->reset->reset();

        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();

        Assert::blank($ritual->broadcast->ofType('clink'));
        Assert::null($ritual->walkerStore->all()[self::A]->clink);
    }

    public function newClinkIsRefusedWhileOneIsPlaying(): void
    {
        $ritual = self::cursed();
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();

        $ritual->clock->now += 3199;
        $refused = self::refusal(fn() => $ritual->walks->clink(self::A, Clink::WALKER, self::B));
        $ritual->clock->now += 1;
        $ritual->walks->clink(self::A, Clink::WALKER, self::B);

        Assert::same($refused, 'busy');
        // Moving is never refused
        $ritual->walks->move(self::A, 30, 90);
    }

    public function clinkBeforeTheCursedWorldIsRefused(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        self::place($ritual, self::A, 20, 90);
        self::place($ritual, self::B, 60, 90);
        $ritual->transitions->scene(1, 1);
        foreach ([1, 2, 3, 4] as $step) {
            $ritual->story->finaleStep($step, force: true);
        }

        Assert::same(self::refusal(fn() => $ritual->walks->clink(self::A, Clink::WALKER, self::B)), 'not cursed');
    }

    #[DataSet([Clink::WALKER, self::A], 'self')]
    #[DataSet([Clink::WALKER, 'cccccccccccccccc'], 'character off the lawn')]
    #[DataSet([Clink::WALKER, ''], 'empty')]
    #[DataSet([Clink::PUMPKIN, 'pumpkin-1'], 'pumpkin out of reach')]
    #[DataSet([Clink::PUMPKIN, 'pumpkin-9'], 'unknown pumpkin')]
    #[DataSet([Clink::PUMPKIN, RitualHarness::CIRCLE], 'circle')]
    public function clinkWithoutATargetIsRefused(string $kind, string $target): void
    {
        $ritual = self::cursed(['pumpkin-2']);
        self::place($ritual, self::A, 20, 90);
        $ritual->broadcast->published = [];

        /** @psalm-suppress ArgumentTypeCoercion */
        Assert::same(self::refusal(fn() => $ritual->walks->clink(self::A, $kind, $target)), 'no target');
        Assert::blank($ritual->broadcast->published);
    }

    public function playerWithoutACharacterOnTheLawnCannotClink(): never
    {
        $ritual = self::cursed();
        self::place($ritual, self::B, 60, 90);
        $ritual->players->register(self::A, 'Jack', self::LOOK);

        Expect::exception(NoCharacterException::class);

        $ritual->walks->clink(self::A, Clink::WALKER, self::B);
    }

    public function pendingClinkSurvivesInTheRecord(): void
    {
        $clink = new Clink(Clink::WALKER, self::B, 1500, 1000);
        $look = Look::fromArray(self::LOOK);
        \assert($look !== null);
        $record = (new Walker(self::A, 'Jack', $look, Walk::standing(new Point(1, 2), 1000), 1000, $clink, 900))->toRecord();

        $restored = Walker::fromArray(self::A, $record);

        Assert::equals($restored?->clink, $clink);
        Assert::same($restored?->clinkedAt, 900);
        Assert::false(isset((new Walker(self::A, null, $look, Walk::standing(new Point(1, 2), 1000), 1000))->toRecord()['clink']));
    }

    /**
     * The final scene at the cursed world's step.
     *
     * @param list<non-empty-string> $reachable
     * @param list<non-empty-string> $pumpkins
     * @param array<non-empty-string, array{float, float}> $positions
     */
    private static function cursed(array $reachable = [], array $pumpkins = ['pumpkin-1', 'pumpkin-2'], array $positions = []): RitualHarness
    {
        $ritual = new RitualHarness(stageMax: [1], pumpkins: $pumpkins, circle: 1, reachable: $reachable, positions: $positions);
        $ritual->transitions->scene(1, 1);
        foreach ([1, 2, 3, 4, 5] as $step) {
            $ritual->story->finaleStep($step, force: true);
        }

        return $ritual;
    }

    /**
     * Puts the player's character on the lawn and walks it to the point, arrived, with a mug in hand: a newcomer of the
     * cursed world comes empty-handed.
     *
     * @param non-empty-string $id
     */
    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
        $ritual->walkerStore->update(static fn(array $walkers): array => [[$id => $walkers[$id]->withBeer()] + $walkers, null]);
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (ClinkRefusedException $e) {
            return $e->error;
        }

        return null;
    }
}
