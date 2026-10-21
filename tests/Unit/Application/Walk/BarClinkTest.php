<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Walk;

use App\Application\Bar\BarRefusedException;
use App\Application\Walk\ClinkRefusedException;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\WalkService;
use App\Domain\Walk\Clink;
use App\Domain\Walk\Walker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * Clinking in the bar: only characters with beer clink, and a clink is what lets a mug be drunk
 * (docs/spec/scenario.md, «Чоканье»).
 */
#[Test]
#[Covers(WalkService::class)]
#[Covers(Walker::class)]
final class BarClinkTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccc';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function clinkByClickInTheBarCountsForBothMugs(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 40, 92);
        self::give($ritual, self::A);
        self::give($ritual, self::B);

        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();

        Assert::count($ritual->broadcast->ofType('clink'), 1);
        Assert::true($ritual->walkerStore->all()[self::A]->clinked);
        Assert::true($ritual->walkerStore->all()[self::B]->clinked);
        // Not before the clink has played, for either mug
        $ritual->clock->now = $walk->arrivesAt + 3199;
        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::B)), 'busy');
        $ritual->clock->now = $walk->arrivesAt + 3200;
        $ritual->bar->drink(self::B);
        Assert::count($ritual->broadcast->ofType('drink'), 1);
    }

    public function targetThatDrankUpOnTheWayDoesNotClink(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 40, 92);
        self::give($ritual, self::A);
        self::give($ritual, self::B, clinked: true);
        $walk = $ritual->walks->clink(self::A, Clink::WALKER, self::B);
        $ritual->bar->drink(self::B);

        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walks->clinks();

        Assert::blank($ritual->broadcast->ofType('clink'));
        Assert::false($ritual->walkerStore->all()[self::A]->clinked);
    }

    public function barClinkNeedsBeerOnBothSides(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 40, 92);

        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::A, Clink::WALKER, self::B)), 'no beer');
        self::give($ritual, self::A);
        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::A, Clink::WALKER, self::B)), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::A, Clink::PUMPKIN, 'pumpkin-1')), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::A, Clink::WALKER, self::A)), 'no target');
    }

    public function dayIsNeitherTheBarNorTheCursedWorld(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 40, 92);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        Assert::same(self::refusal(static fn() => $ritual->walks->clink(self::A, Clink::WALKER, self::B)), 'not cursed');
    }

    public function autoClinkCountsForTheCallerOnlyAndTellsNobody(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 26, 92);
        self::give($ritual, self::A);
        self::give($ritual, self::B);
        $ritual->broadcast->published = [];

        $ritual->walks->clinkAuto(self::A, self::B);

        Assert::true($ritual->walkerStore->all()[self::A]->clinked);
        Assert::false($ritual->walkerStore->all()[self::B]->clinked);
        Assert::blank($ritual->broadcast->published);
    }

    public function autoClinkIsRefusedWhenThePartnerIsFarOrDry(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 26, 92);
        self::place($ritual, self::C, 60, 92);

        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto(self::A, self::B)), 'no beer');
        self::give($ritual, self::A);
        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto(self::A, self::B)), 'no target');
        self::give($ritual, self::C);
        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto(self::A, self::C)), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto(self::A, self::A)), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto(self::A, 'dddddddddddddddd')), 'no target');
        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto('dddddddddddddddd', self::A)), 'no character');
        Assert::false($ritual->walkerStore->all()[self::A]->clinked);
    }

    public function autoClinkIsOnlyForTheBar(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        $ritual->story->nextScene(0, true);
        $ritual->runJobs();

        Assert::same(self::refusal(static fn() => $ritual->walks->clinkAuto(self::A, self::B)), 'not bar');
    }

    public function newMugStartsUnclinked(): void
    {
        $ritual = self::bar();
        self::place($ritual, self::A, 20, 92);
        self::place($ritual, self::B, 70, 92);
        self::give($ritual, self::A, clinked: true);
        $ritual->bar->drink(self::A);
        self::give($ritual, self::A);
        $ritual->clock->now += 1200;

        Assert::same(self::refusal(static fn() => $ritual->bar->drink(self::A)), 'not clinked');
    }

    private static function bar(): RitualHarness
    {
        return new RitualHarness(stageMax: [0, 1, 2]);
    }

    private static function place(RitualHarness $ritual, string $id, float $x, float $y): void
    {
        $ritual->players->register($id, 'Jack', self::LOOK);
        $walk = $ritual->walks->move($id, $x, $y);
        $ritual->clock->now = \max($ritual->clock->now, $walk->arrivesAt);
    }

    /**
     * @param non-empty-string $id
     */
    private static function give(RitualHarness $ritual, string $id, bool $clinked = false): void
    {
        $ritual->walkerStore->update(static function (array $walkers) use ($id, $clinked): array {
            $walker = $walkers[$id]->tookMug(true);
            $walkers[$id] = $clinked ? $walker->clinkedMug(0) : $walker;

            return [$walkers, null];
        });
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function refusal(\Closure $call): ?string
    {
        try {
            $call();
        } catch (BarRefusedException|ClinkRefusedException $e) {
            return $e->error;
        } catch (NoCharacterException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
