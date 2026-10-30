<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Story;

use App\Application\Story\OwlLine;
use App\Domain\Story\SpeechTable;
use App\Domain\Story\Story;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * public/autopilot.json, which the frontend keeps and the server reads at start (docs/spec/protocol.md, «Автопилот»,
 * «Таблица речи совы»). The made-up table talks 1000 ms + 100 per character within 2000…9000 and holds 3000 at most;
 * a missing player stands in at 7 characters.
 */
#[Test]
#[Covers(SpeechTable::class)]
final class SpeechTableTest
{
    private const FILE = __DIR__ . '/../../../../public/autopilot.json';
    private const SHOW = ['base' => 1000, 'perChar' => 100, 'min' => 2000, 'max' => 9000];

    public function everyLineTheServerSaysIsInTheTable(): void
    {
        $table = SpeechTable::fromFile(self::FILE);
        $ids = [
            ...\array_values((new \ReflectionClass(OwlLine::class))->getConstants()),
            ...\array_map(OwlLine::scene(...), \range(1, 4)),
            ...\array_map(OwlLine::finale(...), \range(1, Story::LAST_STEP)),
        ];

        $missing = \array_values(\array_filter($ids, static fn(string $id): bool => !$table->has($id)));

        Assert::same($missing, []);
    }

    public function tableNamesTheStagingOfTheFinaleSteps(): void
    {
        $table = SpeechTable::fromFile(self::FILE);

        Assert::true($table->step(Story::DICE_STEP) > 0);
        Assert::true($table->step(Story::DAWN_STEP) > 0);
        Assert::same($table->step(42), 0);
    }

    public function tableLeadsToTheInstructionsThatOpenTheInput(): void
    {
        $table = SpeechTable::fromFile(self::FILE);

        Assert::true($table->line(OwlLine::finale(Story::DICE_STEP))['lead'] > 0);
        Assert::true($table->line(OwlLine::finale(Story::RAPIER_STEP))['lead'] > 0);
        Assert::true($table->line(OwlLine::finale(Story::RAPIER_STEP))['delay'] > 0);
    }

    public function tableMarksTheLinesAside(): void
    {
        $table = SpeechTable::fromFile(self::FILE);

        $aside = \array_filter(
            [OwlLine::ABILITY, OwlLine::FART_TIME, OwlLine::WITCH_DOWN, OwlLine::SCENE_READY, OwlLine::finale(1)],
            static fn(string $id): bool => $table->line($id)['aside'],
        );

        Assert::same(\array_values($aside), [OwlLine::ABILITY, OwlLine::FART_TIME, OwlLine::WITCH_DOWN]);
    }

    /**
     * The numbers `speechOf` of public/dev/autopilot.js gives for the same events.
     */
    public function realTableCountsTheVariantTheClientSays(): void
    {
        $table = SpeechTable::fromFile(self::FILE);

        Assert::same($table->line('scene-ready', [], 3)['ms'], 9398);
        Assert::same($table->line('hurry', [], 4, 5)['ms'], 16350);
        Assert::same($table->line('ability', ['player' => ['name' => 'Jack'], 'ability' => 'fart', 'n' => 11])['ms'], 14138);
        Assert::same($table->line('dice-win', ['player' => ['name' => ''], 'attempt' => 12])['ms'], 13980);
        Assert::same($table->line('bonfire-done', ['player' => ['name' => '  Jill  ']])['ms'], 11610);
    }

    public function lineCarriesItsDelayAndLead(): void
    {
        $table = SpeechTable::fromArray(['lines' => ['finale-1' => ['ms' => 5000, 'hold' => 2000, 'delay' => 3000, 'lead' => 1500], 'scene-1' => ['ms' => 100, 'hold' => 50]]]);

        Assert::same($table->line('finale-1'), ['ms' => 5000, 'hold' => 2000, 'delay' => 3000, 'lead' => 1500, 'aside' => false]);
        Assert::same($table->line('scene-1'), ['ms' => 100, 'hold' => 50, 'delay' => 0, 'lead' => 0, 'aside' => false]);
    }

    public function finaleStepVariantComesFirst(): void
    {
        $table = self::variants();

        Assert::same($table->line('hurry', [], 4, 5)['ms'], 5000);
        Assert::same($table->line('hurry', [], 4, 6)['ms'], 4000);
        Assert::same($table->line('hurry', [], 4, 6)['lead'], 2500);
    }

    public function sceneVariantOutsideTheFinale(): void
    {
        $table = self::variants();

        Assert::same($table->line('hurry', [], 5)['ms'], 4000);
        Assert::same($table->line('hurry', [], 5)['hold'], 1500);
    }

    public function sceneVariantAtAFinaleStepWithoutOne(): void
    {
        Assert::same(self::variants()->line('hurry', [], 4, 2)['ms'], 8000);
    }

    public function noVariantOfThePlaceTakesTheDefault(): void
    {
        Assert::same(self::variants()->line('hurry', [], 2)['ms'], 3000);
    }

    public function abilityPhraseByNModuloTheList(): void
    {
        $table = self::variants();

        Assert::same($table->line('ability', ['ability' => 'fart', 'n' => 5])['ms'], 7000);
        Assert::same($table->line('ability', ['ability' => 'fart', 'n' => 1.0])['ms'], 6500);
        Assert::same($table->line('ability', ['ability' => 'meep', 'n' => 1])['ms'], 2500);
        Assert::true($table->line('ability', ['ability' => 'fart'])['aside']);
    }

    public function abilityPhraseAtZeroForABrokenN(): void
    {
        $table = self::variants();

        Assert::same($table->line('ability', ['ability' => 'fart', 'n' => -1])['ms'], 6000);
        Assert::same($table->line('ability', ['ability' => 'fart', 'n' => 'x'])['ms'], 6000);
        Assert::same($table->line('ability', ['ability' => 'fart'])['ms'], 6000);
    }

    public function unknownAbilityTakesTheDefault(): void
    {
        $table = self::variants();

        Assert::same($table->line('ability', ['ability' => 'nope', 'n' => 1])['ms'], 2000);
        Assert::same($table->line('ability')['ms'], 2000);
    }

    public function placeholdersCountTheTrimmedName(): void
    {
        $table = self::variants();

        $line = $table->line('bonfire-done', ['player' => ['id' => 'a', 'name' => '  Jill  ']]);

        Assert::same($line, ['ms' => 1000 + (20 + 4) * 100, 'hold' => 3000, 'delay' => 0, 'lead' => 0, 'aside' => false]);
    }

    public function missingNameCountsTheStandIn(): void
    {
        $table = self::variants();

        Assert::same($table->line('bonfire-done', ['player' => ['name' => '   ']])['ms'], 1000 + (20 + 7) * 100);
        Assert::same($table->line('bonfire-done', ['player' => ['name' => '']])['ms'], 1000 + (20 + 7) * 100);
        Assert::same($table->line('bonfire-done')['ms'], 1000 + (20 + 7) * 100);
    }

    public function nameCountsInUtf16Units(): void
    {
        Assert::same(self::variants()->line('bonfire-done', ['player' => ['name' => 'Жук🎃']])['ms'], 1000 + (20 + 5) * 100);
    }

    public function attemptCountsItsDigits(): void
    {
        $table = self::variants();

        Assert::same($table->line('dice-win', ['player' => ['name' => 'Al'], 'attempt' => 123])['ms'], 1000 + (10 + 2 + 3) * 100);
        Assert::same($table->line('dice-win', ['player' => ['name' => 'Al']])['ms'], 1000 + (10 + 2 + 1) * 100);
    }

    public function placeholdersStayWithinTheShowRange(): void
    {
        $line = self::variants()->line('bonfire-done', ['player' => ['name' => \str_repeat('W', 80)]]);

        Assert::same([$line['ms'], $line['hold']], [9000, 3000]);
    }

    public function unknownLineTalksAsLongAsAnyText(): void
    {
        $table = SpeechTable::fromArray(['showMs' => self::SHOW, 'holdMs' => 3000]);

        Assert::same($table->line('never-written'), ['ms' => 9000, 'hold' => 3000, 'delay' => 0, 'lead' => 0, 'aside' => false]);
    }

    public function ownTextHoldsNoLongerThanItTalks(): void
    {
        $table = SpeechTable::fromArray(['showMs' => self::SHOW, 'holdMs' => 3000]);

        Assert::same($table->text(' Hi '), ['ms' => 2000, 'hold' => 2000, 'delay' => 0, 'lead' => 0, 'aside' => false]);
        Assert::same($table->text(\str_repeat('ы', 40)), ['ms' => 5000, 'hold' => 3000, 'delay' => 0, 'lead' => 0, 'aside' => false]);
    }

    public function missingFileIsAnError(): never
    {
        Expect::exception(\RuntimeException::class);

        SpeechTable::fromFile(__DIR__ . '/no-such-table.json');
    }

    private static function variants(): SpeechTable
    {
        return SpeechTable::fromArray([
            'showMs' => self::SHOW,
            'holdMs' => 3000,
            'someone' => 7,
            'lines' => [
                'hurry' => [
                    'ms' => 3000,
                    'hold' => 3000,
                    'scenes' => ['4' => ['ms' => 8000, 'hold' => 3000], '5' => ['ms' => 4000, 'hold' => 1500]],
                    'steps' => ['5' => ['ms' => 5000, 'hold' => 3000], '6' => ['ms' => 4000, 'hold' => 3500, 'lead' => 2500]],
                ],
                'ability' => [
                    'ms' => 2000,
                    'hold' => 2000,
                    'aside' => true,
                    'abilities' => [
                        'fart' => [['ms' => 6000, 'hold' => 3000], ['ms' => 6500, 'hold' => 3000], ['ms' => 7000, 'hold' => 3000]],
                        'meep' => [['ms' => 2500, 'hold' => 2500]],
                    ],
                ],
                'bonfire-done' => ['ms' => 3000, 'hold' => 3000, 'chars' => 20, 'fill' => ['player']],
                'dice-win' => ['ms' => 3000, 'hold' => 3000, 'chars' => 10, 'fill' => ['player', 'attempt']],
            ],
        ]);
    }
}
