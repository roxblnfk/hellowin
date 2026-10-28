<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Walk;

use App\Application\Bar\BarRefusedException;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Walk\ActionStop;
use App\Domain\Flyer\Point;
use App\Domain\Walk\Clink;
use App\Domain\Walk\Walk;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\RitualHarness;

/**
 * An accepted action stops the walking character where it is, before the action's own event (docs/spec/protocol.md,
 * «Действие останавливает ход»). Characters walk 10 % of the screen a second in the harness.
 */
#[Test]
#[Covers(ActionStop::class)]
final class ActionStopTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function missedShotStopsTheCharacterWhereItIsOnItsPath(): void
    {
        $ritual = new RitualHarness();
        self::place($ritual, self::A, 20, 90);
        $walk = $ritual->walks->move(self::A, 80, 90);
        $ritual->clock->now += 1500;
        $ritual->broadcast->published = [];

        Assert::null($ritual->flyers->shoot('f-missing', self::A));

        $at = $walk->positionAt($ritual->clock->now);
        $stand = ['path' => [['x' => \round($at->x, 2), 'y' => \round($at->y, 2)]], 'startedAt' => $ritual->clock->now, 'arrivesAt' => $ritual->clock->now];
        Assert::numeric($stand['path'][0]['x'])->between(21, 79);
        Assert::equals($ritual->broadcast->ofType('move'), [['type' => 'move', 'round' => 1, 'player' => ['id' => self::A], 'walk' => $stand, 'stop' => 'shoot']]);
        Assert::same($ritual->walkerStore->all()[self::A]->walk->toArray(), $stand);
    }

    public function hitStopsBeforeTheShot(): void
    {
        $ritual = self::walking(new RitualHarness());
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now);
        $ritual->broadcast->published = [];

        Assert::notNull($ritual->flyers->shoot($flyer?->id ?? '', self::A));

        Assert::same(\array_slice(self::types($ritual), 0, 2), ['move', 'shot']);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'], 'shoot');
    }

    public function standingCharacterIsNotMoved(): void
    {
        $ritual = new RitualHarness();
        self::place($ritual, self::A, 20, 90);
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot('f-missing', self::A);
        $ritual->clicks->click('pumpkin-1', self::A);

        Assert::blank($ritual->broadcast->ofType('move'));
    }

    public function clickStopsBeforeTheClick(): void
    {
        $ritual = self::walking(new RitualHarness());
        $ritual->broadcast->published = [];

        $ritual->clicks->click('pumpkin-1', self::A);

        Assert::same(\array_slice(self::types($ritual), 0, 2), ['move', 'click']);
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'], 'click');
    }

    public function refusedClickDoesNotStop(): void
    {
        $ritual = self::walking(self::bar());
        $walk = $ritual->walkerStore->all()[self::A]->walk;
        $ritual->broadcast->published = [];

        Assert::same(self::refusal(static fn() => $ritual->clicks->click('pumpkin-1', self::A)), ElementLockedException::class);

        Assert::blank($ritual->broadcast->published);
        Assert::equals($ritual->walkerStore->all()[self::A]->walk, $walk);
    }

    public function drinkStopsBeforeTheDrink(): void
    {
        $ritual = self::walking(self::bar());
        self::give($ritual, self::A);
        $ritual->broadcast->published = [];

        $ritual->bar->drink(self::A);

        Assert::same(self::types($ritual), ['move', 'drink', 'drinks']);
        $stopped = $ritual->walkerStore->all()[self::A];
        Assert::equals($ritual->broadcast->ofType('move')[0]['walk'], $stopped->walk->toArray());
        Assert::same($ritual->broadcast->ofType('move')[0]['stop'], 'drink');
        Assert::same($stopped->walk->startedAt, $stopped->walk->arrivesAt);
        Assert::same($stopped->item, Walker::MUG);
    }

    public function refusedDrinkDoesNotStop(): void
    {
        $ritual = self::walking(self::bar());
        $walk = $ritual->walkerStore->all()[self::A]->walk;
        $ritual->broadcast->published = [];

        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::A)), 'no beer');

        Assert::blank($ritual->broadcast->published);
        Assert::equals($ritual->walkerStore->all()[self::A]->walk, $walk);
    }

    public function stopCallsTheMugAtTheCounterOff(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        $walk = $ritual->walks->toCounter(self::A);
        $ritual->clock->now += 500;

        $ritual->flyers->shoot('f-missing', self::A);
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->bar->takes();

        Assert::null($ritual->walkerStore->all()[self::A]->takeAt);
        Assert::blank($ritual->broadcast->ofType('take'));
    }

    public function stopCallsTheClinkOff(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::B, 70, 92);
        self::place($ritual, self::A, 20, 92);
        self::give($ritual, self::A);
        self::give($ritual, self::B);
        $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->clock->now += 500;
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot('f-missing', self::A);

        Assert::null($ritual->walkerStore->all()[self::A]->clink);
        Assert::false(isset($ritual->broadcast->ofType('move')[0]['clink']));
    }

    public function zombieTheServerWalksIsNotStopped(): void
    {
        $ritual = new RitualHarness();
        self::place($ritual, self::A, 20, 90);
        $now = $ritual->clock->now;
        $ritual->walkerStore->update(static function (array $walkers) use ($now): array {
            $walk = Walk::along([new Point(20, 90), new Point(80, 90)], $now, 10.0);
            $walkers[self::A] = $walkers[self::A]->zombified($now, $now)->shambling($walk, $walk->arrivesAt);

            return [$walkers, null];
        });
        $ritual->clock->now += 1000;
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot('f-missing', self::A);

        Assert::blank($ritual->broadcast->ofType('move'));
        Assert::true($ritual->walkerStore->all()[self::A]->walk->arrivesAt > $ritual->clock->now);
    }

    public function busyLawnLeavesTheCharacterWalkingAndTheShotStands(): void
    {
        $lock = new FakeLock();
        $ritual = self::walking(new RitualHarness(lock: $lock));
        $flyer = $ritual->flyers->launchSpecial('witch', $ritual->clock->now);
        $ritual->broadcast->published = [];
        $lock->held['walkers'] = 'other-worker';

        Assert::notNull($ritual->flyers->shoot($flyer?->id ?? '', self::A));

        Assert::blank($ritual->broadcast->ofType('move'));
        Assert::count($ritual->broadcast->ofType('shot'), 1);
    }

    private static function bar(): RitualHarness
    {
        return new RitualHarness(stageMax: [0, 1, 2]);
    }

    /**
     * Player A stands at (20, 90) and sets off to (80, 90); half a second later it is on the way.
     */
    private static function walking(RitualHarness $ritual): RitualHarness
    {
        self::place($ritual, self::A, 20, 90);
        $ritual->walks->move(self::A, 80, 90);
        $ritual->clock->now += 500;

        return $ritual;
    }

    /**
     * @param non-empty-string $id
     */
    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * @param non-empty-string $id
     */
    private static function give(RitualHarness $ritual, string $id): void
    {
        $ritual->walkerStore->update(static function (array $walkers) use ($id): array {
            $walkers[$id] = $walkers[$id]->tookMug(true)->clinkedMug(0);

            return [$walkers, null];
        });
    }

    /**
     * @return list<mixed>
     */
    private static function types(RitualHarness $ritual): array
    {
        return \array_column(\array_column($ritual->broadcast->published, 'message'), 'type');
    }

    /**
     * @param \Closure(): mixed $call
     * @return string|null The refusal, or the class of the exception that is no refusal.
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (BarRefusedException $e) {
            return $e->error;
        } catch (\Throwable $e) {
            return $e::class;
        }

        return null;
    }
}
