<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Flyer;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Domain\Flyer\Flyer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\Fake\RitualHarness;

/**
 * The elephant with the release banner cannot be shot down; the first shot makes it fart (docs/spec/protocol.md,
 * «Слон»).
 */
#[Test]
#[Covers(FlyerService::class)]
#[Covers(ChannelPublisher::class)]
#[Covers(PlayMetrics::class)]
final class ElephantTest
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    public function firstShotMakesItFartAndTheOwlSayItThenShotsWithinTheCooldownJustMiss(): void
    {
        $ritual = new RitualHarness();
        $elephant = self::launch($ritual);
        $ritual->clock->now += 5_000;
        $ritual->broadcast->published = [];
        $at = $ritual->clock->now;

        $first = $ritual->flyers->shoot($elephant->id, self::A);
        $ritual->clock->now = $at + 100;
        $second = $ritual->flyers->shoot($elephant->id, self::B);
        $ritual->clock->now = $at + 999;
        $third = $ritual->flyers->shoot($elephant->id, null);

        Assert::same([$first, $second, $third], [null, null, null]);
        Assert::same(\array_column($ritual->broadcast->published, 'message'), [
            ['type' => 'fart', 'round' => 1, 'flyer' => $elephant->id, 'player' => ['id' => self::A], 'at' => $at],
            ['type' => 'owl', 'round' => 1, 'line' => 'fart-time'],
        ]);
        Assert::same($ritual->counters()[PlayMetrics::FARTS . '{}'] ?? null, 1.0);
        Assert::false(\array_key_exists(PlayMetrics::FLYERS_SHOT . '{0}', $ritual->counters()));
    }

    public function shotAfterTheCooldownFartsAgainWithoutTheOwl(): void
    {
        $ritual = new RitualHarness();
        $elephant = self::launch($ritual);
        $at = $ritual->clock->now + 2_000;
        $ritual->broadcast->published = [];

        foreach ([0, 1_000, 1_500, 2_500] as $after) {
            $ritual->clock->now = $at + $after;
            $ritual->flyers->shoot($elephant->id, self::A);
        }

        Assert::same(\array_column($ritual->broadcast->ofType('fart'), 'at'), [$at, $at + 1_000, $at + 2_500]);
        Assert::count($ritual->broadcast->ofType('owl'), 1);
        Assert::same($ritual->broadcast->ofType('owl')[0]['line'], 'fart-time');
        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['fart', 'owl', 'fart', 'fart']);
        Assert::same($ritual->counters()[PlayMetrics::FARTS . '{}'] ?? null, 3.0);
    }

    public function owlLineIsAnAsideTheAutopilotDoesNotWaitFor(): void
    {
        $ritual = new RitualHarness();
        $elephant = self::launch($ritual);
        $before = $ritual->owl->until();

        $ritual->flyers->shoot($elephant->id, self::A);

        Assert::same($ritual->owl->until(), $before);
    }

    public function anonymousShotFartsWithoutAPlayer(): void
    {
        $ritual = new RitualHarness();
        $elephant = self::launch($ritual);

        $ritual->flyers->shoot($elephant->id, null);

        Assert::same($ritual->broadcast->ofType('fart'), [
            ['type' => 'fart', 'round' => 1, 'flyer' => $elephant->id, 'at' => $ritual->clock->now],
        ]);
    }

    public function shotAfterTheFlightAndItsLatencyTellsNothing(): void
    {
        $ritual = new RitualHarness();
        $elephant = self::launch($ritual);
        $ritual->clock->now = $elephant->expiresAt() + 500;
        $ritual->broadcast->published = [];

        Assert::null($ritual->flyers->shoot($elephant->id, self::A));

        Assert::blank($ritual->broadcast->published);
        Assert::false(\array_key_exists(PlayMetrics::FARTS . '{}', $ritual->counters()));
    }

    public function emptyMugFliesBeforeTheFart(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);
        $ritual->players->register(self::A, 'Jack', self::LOOK);
        $walk = $ritual->walks->move(self::A, 20, 90);
        $ritual->clock->now = $walk->arrivesAt;
        $ritual->walkerStore->update(static fn(array $walkers): array => [[self::A => $walkers[self::A]->drank(0)] + $walkers, null]);
        $elephant = self::launch($ritual);
        $ritual->broadcast->published = [];

        $ritual->flyers->shoot($elephant->id, self::A);

        Assert::same(\array_column(\array_column($ritual->broadcast->published, 'message'), 'type'), ['throw', 'fart', 'owl']);
    }

    public function launchCountsAsAFlyerAtTheFarthestDepth(): void
    {
        $ritual = new RitualHarness();

        self::launch($ritual);

        Assert::same($ritual->counters()[PlayMetrics::FLYERS_LAUNCHED . '{0}'] ?? null, 1.0);
        Assert::same($ritual->flyers->lastElephantAt(), $ritual->clock->now);
    }

    private static function launch(RitualHarness $ritual): Flyer
    {
        return $ritual->flyers->launchSpecial(Flyer::KIND_ELEPHANT, $ritual->clock->now) ?? Assert::fail('The elephant flies.');
    }
}
