<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Hellowin\SnapshotFactory;
use App\Endpoint\Centrifugo\ConnectService;
use App\Endpoint\Centrifugo\RPCService;
use Psr\Log\NullLogger;
use RoadRunner\Centrifugal\Proxy\DTO\V1\RPCResponse;
use RoadRunner\Centrifugo\Request\RPC;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\Fake\RitualHarness;
use Tests\Fake\SpyWorker;

#[Test]
#[Covers(RPCService::class)]
#[Covers(SnapshotFactory::class)]
final class RPCServiceTest
{
    private const PLAYER = '0123456789abcdef';
    private const LOOK = ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin'];

    private SpyWorker $worker;

    public function clickIsAccepted(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => true]);
        Assert::count($ritual->broadcast->ofType('click'), 1);
    }

    public function unknownElementIsRejected(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'click', ['element' => 'ghost-42']);

        Assert::same($response, ['ok' => false, 'error' => 'unknown element']);
        Assert::blank($ritual->broadcast->published);
        Assert::blank($ritual->metrics->calls);
    }

    public function closedBonfireIsLocked(): void
    {
        $ritual = new RitualHarness(bonfire: [1]);

        $response = $this->call($ritual, 'click', ['element' => RitualHarness::BONFIRE]);

        Assert::same($response, ['ok' => false, 'error' => 'locked']);
        Assert::blank($ritual->broadcast->published);
        Assert::blank($ritual->metrics->calls);
    }

    public function missingElementIsRejected(): void
    {
        $response = $this->call(new RitualHarness(), 'click', []);

        Assert::same($response, ['ok' => false, 'error' => 'unknown element']);
    }

    public function unknownMethodIsRejected(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'explode', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => false, 'error' => 'unknown method']);
        Assert::blank($ritual->broadcast->published);
    }

    public function busyRitualIsReported(): void
    {
        $ritual = new RitualHarness(lock: new FakeLock(available: false));

        $response = $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::same($response, ['ok' => false, 'error' => 'busy']);
        Assert::blank($ritual->broadcast->published);
    }

    public function stateReturnsTheSnapshot(): void
    {
        $ritual = new RitualHarness(threshold: 4, stageMax: [4, 6, 8]);
        $ritual->click('pumpkin-2', 2);

        $response = $this->call($ritual, 'state', []);

        Assert::equals($response, ['ok' => true, 'state' => [
            'round' => 1,
            'scene' => 0,
            'sceneStages' => 3,
            'stageMax' => 4,
            'threshold' => 4,
            'bushes' => [['id' => 'bush-1', 'x' => 4.0, 'y' => 86.0, 'depth' => 2]],
            'elements' => [
                ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 10.0, 'y' => 20.0, 'size' => 'm', 'stage' => 0, 'clicks' => 0],
                ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 10.0, 'y' => 20.0, 'size' => 'm', 'stage' => 0, 'clicks' => 2],
            ],
            'walkers' => [],
            'ready' => false,
            'finale' => null,
            'paused' => false,
            'zombies' => false,
            'now' => $ritual->clock->now,
        ]]);
    }

    public function clickOfAPlayerIsAttributed(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER);

        Assert::same($ritual->broadcast->ofType('click')[0]['player'], ['id' => self::PLAYER, 'name' => null]);
        Assert::same($ritual->playerStore->find(self::PLAYER)?->clicks, 1);
    }

    public function anonymousClickCarriesNoPlayer(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::false(\array_key_exists('player', $ritual->broadcast->ofType('click')[0]));
        Assert::blank($ritual->playerStore->ids());
    }

    public function registerNamesThePlayer(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 2, self::PLAYER);

        $response = $this->call($ritual, 'register', ['name' => '  Jack O. Lantern '], self::PLAYER);

        Assert::same($response, ['ok' => true, 'player' => [
            'id' => self::PLAYER,
            'name' => 'Jack O. Lantern',
            'look' => null,
            'clicks' => 2,
            'finished' => 0,
            'flyers' => 0,
            'points' => 0,
        ]]);
    }

    #[DataSet([''], 'empty')]
    #[DataSet(['   '], 'spaces')]
    #[DataSet(['---'], 'punctuation only')]
    #[DataSet(['<script>'], 'markup')]
    #[DataSet(['a  b'], 'double space')]
    #[DataSet(['abcdefghijklmnopqrstuvwxy'], 'too long')]
    #[DataSet([42], 'number')]
    #[DataSet([null], 'missing')]
    public function registerRejectsAnInvalidName(mixed $name): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'register', ['name' => $name], self::PLAYER);

        Assert::same($response, ['ok' => false, 'error' => 'invalid name']);
        Assert::null($ritual->playerStore->find(self::PLAYER));
    }

    public function anonymousConnectionCannotRegister(): void
    {
        $response = $this->call(new RitualHarness(), 'register', ['name' => 'Jack']);

        Assert::same($response, ['ok' => false, 'error' => 'anonymous']);
    }

    public function meReturnsTheStatistics(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-2', 3, self::PLAYER);

        $ritual->leaderboard->build();

        $response = $this->call($ritual, 'me', [], self::PLAYER);

        Assert::same($response['player']['clicks'] ?? null, 3);
        Assert::same(\array_column($response['top'] ?? [], 'id'), [self::PLAYER]);
    }

    public function meIsNullForAnUnknownOrAnonymousPlayer(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'me', [], self::PLAYER), ['ok' => true, 'player' => null, 'top' => [], 'moderator' => false]);
        Assert::same($this->call($ritual, 'me', []), ['ok' => true, 'player' => null, 'top' => [], 'moderator' => false]);
    }

    public function firstShotHitsAndTheSecondMisses(): void
    {
        $ritual = new RitualHarness();
        $flyer = $ritual->flyers->launch($ritual->clock->now);

        $first = $this->call($ritual, 'shoot', ['flyer' => $flyer->id], self::PLAYER);
        $second = $this->call($ritual, 'shoot', ['flyer' => $flyer->id], 'bbbbbbbbbbbbbbbb');

        Assert::same($first, ['ok' => true, 'hit' => true, 'points' => $flyer->points]);
        Assert::same($second, ['ok' => true, 'hit' => false]);
        Assert::same($ritual->broadcast->ofType('shot'), [[
            'type' => 'shot',
            'round' => 1,
            'flyer' => $flyer->id,
            'points' => $flyer->points,
            'player' => ['id' => self::PLAYER, 'name' => null],
        ]]);
        Assert::same($ritual->playerStore->find(self::PLAYER)?->points, $flyer->points);
        Assert::same($ritual->playerStore->find(self::PLAYER)?->flyers, 1);
        Assert::null($ritual->playerStore->find('bbbbbbbbbbbbbbbb'));
    }

    public function anonymousHitCarriesNoPlayer(): void
    {
        $ritual = new RitualHarness();
        $flyer = $ritual->flyers->launch($ritual->clock->now);

        $response = $this->call($ritual, 'shoot', ['flyer' => $flyer->id]);

        Assert::true($response['hit']);
        Assert::false(\array_key_exists('player', $ritual->broadcast->ofType('shot')[0]));
    }

    public function shotAfterTheFlightMisses(): void
    {
        $ritual = new RitualHarness();
        $flyer = $ritual->flyers->launch($ritual->clock->now);
        $ritual->clock->now = $flyer->expiresAt() + 1_000;

        $response = $this->call($ritual, 'shoot', ['flyer' => $flyer->id], self::PLAYER);

        Assert::same($response, ['ok' => true, 'hit' => false]);
        Assert::blank($ritual->broadcast->ofType('shot'));
    }

    #[DataSet([['flyer' => 'f-00000000']], 'unknown')]
    #[DataSet([['flyer' => '../etc']], 'malformed')]
    #[DataSet([['flyer' => 42]], 'number')]
    #[DataSet([[]], 'missing')]
    public function shotAtNothingMisses(array $params): void
    {
        Assert::same($this->call(new RitualHarness(), 'shoot', $params, self::PLAYER), ['ok' => true, 'hit' => false]);
    }

    public function resetStartsTheNextRoundOnce(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 2);

        Assert::same($this->call($ritual, 'reset', ['round' => 1], moderator: true), ['ok' => true, 'round' => 2]);
        Assert::same($this->call($ritual, 'reset', ['round' => 1], moderator: true), ['ok' => true, 'round' => 2]);
        Assert::count($ritual->broadcast->ofType('reset'), 1);
        Assert::same($this->call($ritual, 'state', [])['state']['elements'][0]['clicks'], 0);
    }

    #[DataSet([[]], 'no round')]
    #[DataSet([['round' => '1']], 'string')]
    #[DataSet([['round' => 0]], 'zero')]
    public function resetNeedsTheRound(array $params): void
    {
        Assert::same($this->call(new RitualHarness(), 'reset', $params, moderator: true), ['ok' => false, 'error' => 'invalid round']);
    }

    public function onlyTheModeratorResets(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'reset', ['round' => 1], self::PLAYER), ['ok' => false, 'error' => 'forbidden']);
        Assert::blank($ritual->broadcast->ofType('reset'));
    }

    public function meTellsTheModeratorItsRole(): void
    {
        Assert::true($this->call(new RitualHarness(), 'me', [], moderator: true)['moderator']);
    }

    public function registerWithALookPutsTheCharacterOnTheLawn(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($response['player']['look'] ?? null, self::LOOK);
        Assert::same($ritual->playerStore->find(self::PLAYER)?->look?->toArray(), self::LOOK);
        Assert::same($ritual->broadcast->ofType('walker')[0]['walker']['id'] ?? null, self::PLAYER);
        Assert::same(\array_column($this->call($ritual, 'state', [])['state']['walkers'], 'id'), [self::PLAYER]);
    }

    public function registerWithoutALookKeepsTheOneChosen(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        $response = $this->call($ritual, 'register', ['name' => 'Jill'], self::PLAYER);

        Assert::same($response['player']['look'] ?? null, self::LOOK);
        Assert::same($ritual->broadcast->ofType('walker')[1]['walker']['name'] ?? null, 'Jill');
    }

    public function registerRejectsAnInvalidLook(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'register', ['name' => 'Jack', 'look' => ['hat' => 'Top Hat']], self::PLAYER);

        Assert::same($response, ['ok' => false, 'error' => 'invalid look']);
        Assert::null($ritual->playerStore->find(self::PLAYER));
        Assert::blank($ritual->broadcast->published);
    }

    public function moveSendsTheCharacter(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        $response = $this->call($ritual, 'move', ['x' => 10, 'y' => 90.5], self::PLAYER);

        Assert::true($response['ok']);
        Assert::equals($response['walk']['path'][\count($response['walk']['path']) - 1], ['x' => 10, 'y' => 90.5]);
        Assert::equals($ritual->broadcast->ofType('move')[0]['walk'], $response['walk']);
    }

    #[DataSet([['x' => '10', 'y' => 90]], 'string')]
    #[DataSet([['x' => 10]], 'no y')]
    #[DataSet([[]], 'nothing')]
    public function moveNeedsAPoint(array $params): void
    {
        Assert::same($this->call(new RitualHarness(), 'move', $params, self::PLAYER), ['ok' => false, 'error' => 'invalid point']);
    }

    public function moveNeedsACharacter(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack'], self::PLAYER);

        Assert::same($this->call($ritual, 'move', ['x' => 10, 'y' => 90], self::PLAYER), ['ok' => false, 'error' => 'no character']);
        Assert::same($this->call($ritual, 'move', ['x' => 10, 'y' => 90]), ['ok' => false, 'error' => 'anonymous']);
        Assert::blank($ritual->broadcast->published);
    }

    #[DataSet(['reset'])]
    #[DataSet(['scene.next'])]
    #[DataSet(['finale.step'])]
    #[DataSet(['owl.say'])]
    #[DataSet(['flyer.special'])]
    #[DataSet(['pause'])]
    #[DataSet(['zombies'])]
    public function consoleIsForbiddenToEveryoneButTheModerator(string $method): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, $method, ['on' => true, 'scene' => 0, 'step' => 1, 'text' => 'Hi'], self::PLAYER), ['ok' => false, 'error' => 'forbidden']);
        Assert::blank($ritual->broadcast->published);
    }

    public function nextSceneMovesAReadySceneOnce(): void
    {
        $ritual = new RitualHarness(threshold: 1);
        $ritual->click('pumpkin-1');
        $ritual->click('pumpkin-2');
        $ritual->runJobs();
        Assert::true($this->call($ritual, 'state', [])['state']['ready']);

        Assert::same($this->call($ritual, 'scene.next', ['scene' => 0], moderator: true), ['ok' => true, 'scene' => 1]);
        Assert::same($this->call($ritual, 'scene.next', ['scene' => 0], moderator: true), ['ok' => true, 'scene' => 1]);
        $ritual->runJobs();

        Assert::same($this->call($ritual, 'scene.next', ['scene' => 0], moderator: true), ['ok' => true, 'scene' => 1]);
        Assert::count($ritual->broadcast->ofType('scene'), 1);
        Assert::false($this->call($ritual, 'state', [])['state']['ready']);
    }

    public function nextSceneWaitsForTheScenePumpkinsUnlessForced(): void
    {
        $ritual = new RitualHarness(threshold: 1);

        Assert::same($this->call($ritual, 'scene.next', ['scene' => 0], moderator: true), ['ok' => false, 'error' => 'not ready']);
        Assert::same($this->call($ritual, 'scene.next', ['scene' => 0, 'force' => true], moderator: true), ['ok' => true, 'scene' => 1]);
        $ritual->runJobs();

        Assert::same($ritual->store->snapshot()->scene, 1);
    }

    public function finalSceneHasNoNextOne(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->transitions->scene(1, 1);

        Assert::same($this->call($ritual, 'scene.next', ['scene' => 1, 'force' => true], moderator: true), ['ok' => false, 'error' => 'final scene']);
        Assert::same($this->call($ritual, 'scene.next', ['scene' => '1'], moderator: true), ['ok' => false, 'error' => 'invalid scene']);
    }

    public function finaleGoesStepByStep(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        Assert::same($this->call($ritual, 'finale.step', ['step' => 1], moderator: true), ['ok' => false, 'error' => 'not final']);
        $ritual->transitions->scene(1, 1);

        // No circle drawn: the first step waits for the ready scene unless forced
        Assert::same($this->call($ritual, 'finale.step', ['step' => 1], moderator: true), ['ok' => false, 'error' => 'not ready']);
        Assert::same($this->call($ritual, 'finale.step', ['step' => 1, 'force' => true], moderator: true), ['ok' => true, 'step' => 1]);
        Assert::same($this->call($ritual, 'finale.step', ['step' => 1], moderator: true), ['ok' => true, 'step' => 1]);
        Assert::same($this->call($ritual, 'finale.step', ['step' => 3], moderator: true), ['ok' => true, 'step' => 1]);

        Assert::same(\array_column($ritual->broadcast->ofType('finale'), 'step'), [0, 1]);
        Assert::same(\array_column($ritual->broadcast->ofType('owl'), 'line'), ['scene-1', 'finale-1']);
        Assert::same($this->call($ritual, 'state', [])['state']['finale'], ['step' => 1]);
    }

    public function revealShowsTheNumbersOfTheRound(): void
    {
        $ritual = new RitualHarness(threshold: 2, stageMax: [1]);
        $ritual->click('pumpkin-1', 3);
        $ritual->click('pumpkin-2', 2, self::PLAYER);
        $ritual->runJobs();
        $this->call($ritual, 'scene.next', ['scene' => 0], moderator: true);
        $ritual->runJobs();
        $flyer = $ritual->flyers->launch($ritual->clock->now);
        $this->call($ritual, 'shoot', ['flyer' => $flyer->id]);

        for ($step = 1; $step <= 4; ++$step) {
            $this->call($ritual, 'finale.step', ['step' => $step, 'force' => true], moderator: true);
        }

        $reveal = ['clicks' => 5, 'jobs' => 3, 'burned' => 1, 'shot' => 1, 'lastPumpkin' => ['id' => self::PLAYER, 'name' => null]];
        Assert::same($ritual->broadcast->ofType('finale')[4], ['type' => 'finale', 'round' => 1, 'step' => 4, 'reveal' => $reveal]);
        Assert::same($this->call($ritual, 'state', [])['state']['finale'], ['step' => 4, 'reveal' => $reveal]);
    }

    public function resetEndsTheFinale(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->transitions->scene(1, 1);

        $this->call($ritual, 'reset', ['round' => 1], moderator: true);

        Assert::null($this->call($ritual, 'state', [])['state']['finale']);
    }

    public function owlSaysACatalogueLineOrTheModeratorsText(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'owl.say', ['line' => 'intro'], moderator: true), ['ok' => true]);
        $this->call($ritual, 'owl.say', ['line' => 'clues', 'params' => ['player' => ['id' => self::PLAYER, 'name' => 'Jack']]], moderator: true);
        $this->call($ritual, 'owl.say', ['text' => '  Tonight the pumpkins wake  '], moderator: true);

        Assert::same($ritual->broadcast->ofType('owl'), [
            ['type' => 'owl', 'round' => 1, 'line' => 'intro'],
            ['type' => 'owl', 'round' => 1, 'line' => 'clues', 'params' => ['player' => ['id' => self::PLAYER, 'name' => 'Jack']]],
            ['type' => 'owl', 'round' => 1, 'text' => 'Tonight the pumpkins wake'],
        ]);
    }

    #[DataSet([['text' => '   '], 'invalid text'], 'blank text')]
    #[DataSet([['text' => 42], 'invalid text'], 'number text')]
    #[DataSet([['text' => null], 'invalid text'], 'null text')]
    #[DataSet([['line' => 'Intro!'], 'invalid line'], 'line not a slug')]
    #[DataSet([[], 'invalid line'], 'nothing')]
    #[DataSet([['line' => 'intro', 'params' => ['a', 'b']], 'invalid params'], 'list params')]
    public function owlRejectsWhatItCannotSay(array $params, string $error): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'owl.say', $params, moderator: true), ['ok' => false, 'error' => $error]);
        Assert::blank($ritual->broadcast->published);
    }

    public function owlTextHasALimit(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'owl.say', ['text' => \str_repeat('ы', 200)], moderator: true), ['ok' => true]);
        Assert::same($this->call($ritual, 'owl.say', ['text' => \str_repeat('ы', 201)], moderator: true), ['ok' => false, 'error' => 'invalid text']);
    }

    public function pauseIsPublishedOnChangeOnly(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'pause', ['on' => true], moderator: true), ['ok' => true, 'on' => true]);
        $this->call($ritual, 'pause', ['on' => true], moderator: true);

        Assert::same($ritual->broadcast->ofType('pause'), [['type' => 'pause', 'round' => 1, 'on' => true]]);
        Assert::true($this->call($ritual, 'state', [])['state']['paused']);
        Assert::same($this->call($ritual, 'pause', ['on' => 1], moderator: true), ['ok' => false, 'error' => 'invalid on']);
    }

    public function moderatorLaunchesAWitchEvenOnAPause(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'pause', ['on' => true], moderator: true);

        $response = $this->call($ritual, 'flyer.special', ['kind' => 'witch'], moderator: true);

        $flyer = $ritual->broadcast->ofType('flyer')[0];
        Assert::same($response, ['ok' => true, 'flyer' => $flyer['flyer']]);
        Assert::same($flyer['kind'], 'witch');
    }

    #[DataSet([['kind' => 'duck']], 'ordinary kind')]
    #[DataSet([['kind' => 42]], 'number')]
    #[DataSet([[]], 'nothing')]
    public function onlySpecialFlyersLaunchByHand(array $params): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'flyer.special', $params, moderator: true), ['ok' => false, 'error' => 'unknown kind']);
        Assert::blank($ritual->broadcast->ofType('flyer'));
    }

    public function roadrunnerIsNeverHitAndTheOwlKeepsQuiet(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'flyer.special', ['kind' => 'roadrunner'], moderator: true);
        $runner = $ritual->broadcast->ofType('flyer')[0];

        $response = $this->call($ritual, 'shoot', ['flyer' => $runner['flyer']], self::PLAYER);

        Assert::same($response, ['ok' => true, 'hit' => false]);
        Assert::same($runner['points'], 0);
        Assert::blank($ritual->broadcast->ofType('shot'));
        Assert::blank($ritual->broadcast->ofType('owl'));
        Assert::null($ritual->playerStore->find(self::PLAYER));
    }

    public function downedWitchMakesTheOwlNameTheShooter(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'flyer.special', ['kind' => 'witch'], moderator: true);
        $witch = $ritual->broadcast->ofType('flyer')[0];

        $response = $this->call($ritual, 'shoot', ['flyer' => $witch['flyer']], self::PLAYER);

        Assert::same($response, ['ok' => true, 'hit' => true, 'points' => $witch['points']]);
        Assert::same($ritual->broadcast->ofType('owl'), [
            ['type' => 'owl', 'round' => 1, 'line' => 'witch-down', 'params' => ['player' => ['id' => self::PLAYER, 'name' => null]]],
        ]);
    }

    public function zombiesSwitchMakesTheOwlWarn(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'zombies', ['on' => true], moderator: true);
        $this->call($ritual, 'zombies', ['on' => true], moderator: true);
        $this->call($ritual, 'zombies', ['on' => false], moderator: true);

        Assert::same(\array_column($ritual->broadcast->ofType('zombies'), 'on'), [true, false]);
        Assert::same($ritual->broadcast->ofType('owl'), [['type' => 'owl', 'round' => 1, 'line' => 'zombies']]);
        Assert::false($this->call($ritual, 'state', [])['state']['zombies']);
    }

    #[BeforeTest]
    public function createWorker(): void
    {
        $this->worker = new SpyWorker();
    }

    private function call(RitualHarness $ritual, string $method, array $params, string $user = '', bool $moderator = false): array
    {
        $service = new RPCService(
            $ritual->clicks,
            $ritual->snapshot,
            $ritual->players,
            $ritual->leaderboard,
            $ritual->flyers,
            new NullLogger(),
            $ritual->reset,
            $ritual->walks,
            $ritual->story,
            $ritual->clock,
        );
        $service->handle(new RPC(
            worker: $this->worker,
            client: 'client-1',
            transport: 'websocket',
            protocol: 'json',
            encoding: 'json',
            user: $user,
            method: $method,
            meta: $moderator ? [ConnectService::META_MODERATOR => true] : [],
            data: $params,
            headers: [],
        ));

        $data = $this->worker->lastResponse(RPCResponse::class)->getResult()?->getData() ?? '';

        return (array) \json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
    }
}
