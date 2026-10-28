<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Story\EveningTally;
use App\Domain\Balance\Limit;
use App\Domain\Flyer\Flyer;
use App\Domain\Lock\Locks;
use App\Domain\RateLimit\RateLimiter;
use App\Domain\RateLimit\TokenBucket;
use App\Domain\Story\Story;
use App\Endpoint\Centrifugo\ConnectService;
use App\Endpoint\Centrifugo\RPCService;
use Psr\Log\NullLogger;
use RoadRunner\Centrifugal\Proxy\DTO\V1\RPCResponse;
use RoadRunner\Centrifugo\Request\RPC;
use Spiral\Cache\Storage\ArrayStorage;
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
        Assert::blank($this->gameMetrics($ritual));
    }

    public function closedBonfireIsLocked(): void
    {
        $ritual = new RitualHarness(bonfire: [1]);

        $response = $this->call($ritual, 'click', ['element' => RitualHarness::BONFIRE]);

        Assert::same($response, ['ok' => false, 'error' => 'locked']);
        Assert::blank($ritual->broadcast->published);
        Assert::blank($this->gameMetrics($ritual));
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

    public function timeIsTheServerClockForAnyone(): void
    {
        $ritual = new RitualHarness(lock: new FakeLock(available: false));
        $ritual->clock->now = 1_760_900_001_500;

        Assert::same($this->call($ritual, 'time', []), ['ok' => true, 'now' => 1_760_900_001_500]);
        Assert::same($this->call($ritual, 'time', [], self::PLAYER), ['ok' => true, 'now' => 1_760_900_001_500]);
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
                ['id' => 'pumpkin-1', 'type' => 'pumpkin', 'x' => 10.0, 'y' => 20.0, 'size' => 'm', 'reachable' => false, 'stage' => 0, 'clicks' => 0],
                ['id' => 'pumpkin-2', 'type' => 'pumpkin', 'x' => 10.0, 'y' => 20.0, 'size' => 'm', 'reachable' => false, 'stage' => 0, 'clicks' => 2],
            ],
            'walkers' => [],
            'bar' => ['mugs' => 0, 'max' => 1, 'pouring' => null, 'drunk' => 0, 'target' => 4, 'roadrunner' => false],
            'ready' => false,
            'finale' => null,
            'paused' => false,
            'k' => 1.0,
            'online' => 0,
            'presence' => ['players' => 0, 'connections' => 0],
            'limits' => ['players' => 1, 'k' => 1, 'burst' => true, 'limits' => ['pumpkin' => 4]],
            'cursed' => null,
            'boss' => null,
            'dice' => null,
            'top' => ['board' => []],
            'flyers' => [],
            'emotes' => RitualHarness::EMOTES,
            'startedAt' => $ritual->clock->now,
            'now' => $ritual->clock->now,
            'auto' => ['on' => false, 'next' => null],
        ]]);
    }

    public function autoIsForTheModeratorOnly(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'auto', ['on' => true], self::PLAYER);

        Assert::same($response, ['ok' => false, 'error' => 'forbidden']);
        Assert::false($ritual->autopilot->state()['on']);
        Assert::blank($ritual->broadcast->ofType('auto'));
    }

    public function autoWantsABoolean(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'auto', ['on' => 1], moderator: true);

        Assert::same($response, ['ok' => false, 'error' => 'invalid on']);
        Assert::false($ritual->autopilot->state()['on']);
    }

    public function autoTellsEveryoneOnlyOfAChange(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2]);

        $on = $this->call($ritual, 'auto', ['on' => true], moderator: true);
        $again = $this->call($ritual, 'auto', ['on' => true], moderator: true);
        $off = $this->call($ritual, 'auto', ['on' => false], moderator: true);

        Assert::same([$on, $again, $off], [['ok' => true, 'on' => true], ['ok' => true, 'on' => true], ['ok' => true, 'on' => false]]);
        Assert::same($ritual->broadcast->ofType('auto'), [
            ['type' => 'auto', 'round' => 1, 'on' => true, 'next' => ['action' => 'bar.ready', 'wait' => 'room']],
            ['type' => 'auto', 'round' => 1, 'on' => false, 'next' => null],
        ]);
        Assert::same(\array_slice($this->rpcCalls($ritual), -1), [['auto', 'ok']]);
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
            'drinks' => 0,
            'damage' => 0,
            'rolls' => 0,
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

        $response = $this->call($ritual, 'me', [], self::PLAYER);

        Assert::same($response['player']['clicks'] ?? null, 3);
        Assert::false(\array_key_exists('top', $response));
    }

    public function meIsNullForAnUnknownOrAnonymousPlayer(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'me', [], self::PLAYER), ['ok' => true, 'player' => null, 'moderator' => false]);
        Assert::same($this->call($ritual, 'me', []), ['ok' => true, 'player' => null, 'moderator' => false]);
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

    public function clinkSendsTheCharacterToTheTarget(): void
    {
        $ritual = new RitualHarness(stageMax: [1], reachable: ['pumpkin-2']);
        $other = 'bbbbbbbbbbbbbbbb';
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);
        $this->call($ritual, 'register', ['name' => 'Jill', 'look' => self::LOOK], $other);

        Assert::same($this->call($ritual, 'clink', ['walker' => $other], self::PLAYER), ['ok' => false, 'error' => 'not cursed']);

        $ritual->transitions->scene(1, 1);
        foreach ([1, 2, 3, 4, 5] as $step) {
            $ritual->story->finaleStep($step, force: true);
        }
        $ritual->broadcast->published = [];

        $toWalker = $this->call($ritual, 'clink', ['walker' => $other], self::PLAYER);
        $toPumpkin = $this->call($ritual, 'clink', ['pumpkin' => 'pumpkin-2'], self::PLAYER);

        Assert::true($toWalker['ok']);
        Assert::true($toPumpkin['ok']);
        Assert::equals(\array_column($ritual->broadcast->ofType('move'), 'walk'), [$toWalker['walk'], $toPumpkin['walk']]);
        Assert::same($this->call($ritual, 'clink', ['pumpkin' => 'pumpkin-1'], self::PLAYER), ['ok' => false, 'error' => 'no target']);
        Assert::same($this->call($ritual, 'clink', ['walker' => 7], self::PLAYER), ['ok' => false, 'error' => 'no target']);
        Assert::same($this->call($ritual, 'clink', [], self::PLAYER), ['ok' => false, 'error' => 'no target']);
    }

    public function clinkNeedsACharacter(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack'], self::PLAYER);

        Assert::same($this->call($ritual, 'clink', ['walker' => self::PLAYER], self::PLAYER), ['ok' => false, 'error' => 'no character']);
        Assert::same($this->call($ritual, 'clink', ['walker' => self::PLAYER]), ['ok' => false, 'error' => 'anonymous']);
    }

    #[DataSet(['reset'])]
    #[DataSet(['scene.next'])]
    #[DataSet(['finale.step'])]
    #[DataSet(['owl.say'])]
    #[DataSet(['flyer.special'])]
    #[DataSet(['pause'])]
    #[DataSet(['flyers.burst'])]
    #[DataSet(['bar.target'])]
    #[DataSet(['thresholds'])]
    #[DataSet(['cursed.target'])]
    #[DataSet(['boss.hp'])]
    #[DataSet(['dice.owl'])]
    #[DataSet(['meep'])]
    #[DataSet(['stats.reset'])]
    public function consoleIsForbiddenToEveryoneButTheModerator(string $method): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, $method, ['on' => true, 'scene' => 0, 'step' => 1, 'text' => 'Hi', 'k' => 2], self::PLAYER), ['ok' => false, 'error' => 'forbidden']);
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

    public function nextSceneAtDawnAnswersTheNewRound(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->transitions->scene(1, 1);
        $ritual->store->updateStory(static fn(Story $story): Story => new Story(finale: Story::DAWN_STEP));

        Assert::same($this->call($ritual, 'scene.next', ['scene' => 1], moderator: true), ['ok' => true, 'scene' => 0, 'round' => 2]);
        Assert::same($this->call($ritual, 'scene.next', ['scene' => 1], moderator: true), ['ok' => true, 'scene' => 0, 'round' => 2]);
        Assert::count($ritual->broadcast->ofType('reset'), 1);
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

        // The step that reveals is counted once it has answered: five calls before it, six after it
        $reveal = static fn(int $calls): array => ['clicks' => 5, 'calls' => $calls, 'jobs' => 3, 'burned' => 1, 'shot' => 1, 'drinks' => 0, 'slain' => 0, 'damage' => 0, 'rolls' => 0, 'lastPumpkin' => ['id' => self::PLAYER, 'name' => null]];
        Assert::same($ritual->broadcast->ofType('finale')[4], ['type' => 'finale', 'round' => 1, 'step' => 4, 'item' => 'torch', 'reveal' => $reveal(5)]);
        Assert::same($this->call($ritual, 'state', [])['state']['finale'], ['step' => 4, 'reveal' => $reveal(6)]);
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

    public function pausedProgressAcceptsActionsUncountedAfterTheEarlierChecks(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'pause', ['on' => true], moderator: true);

        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER), ['ok' => true, 'counted' => false]);
        Assert::same($this->call($ritual, 'click', ['element' => 'ghost-42'], self::PLAYER), ['ok' => false, 'error' => 'unknown element']);
        Assert::same($this->call($ritual, 'strike', []), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'drink', []), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'roll', ['attempt' => 1], self::PLAYER), ['ok' => false, 'error' => 'no character']);

        $this->call($ritual, 'pause', ['on' => false], moderator: true);

        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER), ['ok' => true]);
    }

    public function thresholdsAnswerTheLimitsInForce(): void
    {
        $ritual = new RitualHarness(threshold: 4);

        $response = $this->call($ritual, 'thresholds', ['k' => 2], moderator: true);
        $this->call($ritual, 'thresholds', ['k' => 2.0], moderator: true);

        $limits = ['pumpkin' => 8];
        // JSON drops the zero fraction: the client gets the number 2
        Assert::same($response, ['ok' => true, 'k' => 2, 'players' => 1, 'limits' => $limits]);
        Assert::same($ritual->broadcast->ofType('limits'), [['type' => 'limits', 'round' => 1, 'players' => 1, 'k' => 2, 'burst' => true, 'limits' => $limits]]);
        Assert::blank($ritual->broadcast->ofType('thresholds'));
        Assert::same($this->call($ritual, 'state', [])['state']['k'], 2);
    }

    #[DataSet([[]], 'missing')]
    #[DataSet([['k' => '2']], 'a string')]
    #[DataSet([['k' => true]], 'a bool')]
    #[DataSet([['k' => 0.09]], 'below the range')]
    #[DataSet([['k' => 10.5]], 'above the range')]
    public function thresholdsNeedAMultiplierInRange(array $params): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'thresholds', $params, moderator: true), ['ok' => false, 'error' => 'invalid k']);
        Assert::blank($ritual->broadcast->published);
    }

    public function onlineCountsTheCharactersOnTheLawn(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);
        $this->call($ritual, 'register', ['name' => 'Ghost'], '0123456789abcdee');

        Assert::same($this->call($ritual, 'state', [])['state']['online'], 1);
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

    public function zombieSwitchIsGone(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'zombies', ['on' => true], moderator: true), ['ok' => false, 'error' => 'unknown method']);
        Assert::false(\array_key_exists('zombies', $this->call($ritual, 'state', [])['state']));
        Assert::blank($ritual->broadcast->published);
    }

    public function burstLaunchesFlyersOfTheSetOnEveryPress(): void
    {
        $ritual = new RitualHarness();
        $ritual->story->pause(true);
        $ritual->broadcast->published = [];

        // One figure on the floor: the base of 2; the pause does not hold a burst, nor does the burst just launched
        Assert::same($this->call($ritual, 'flyers.burst', [], moderator: true), ['ok' => true, 'count' => 2]);
        Assert::same($this->call($ritual, 'flyers.burst', [], moderator: true), ['ok' => true, 'count' => 2]);

        $flyers = $ritual->broadcast->ofType('flyer');
        Assert::same(\array_column($flyers, 'kind'), ['duck', 'duck', 'duck', 'duck']);
        Assert::same(\array_unique(\array_column($flyers, 'launchedAt')), [$ritual->clock->now]);
    }

    public function burstGrowsWithTheRoomWithoutACap(): void
    {
        $ritual = new RitualHarness();

        // Every other figure adds half a flyer: nine figures, 2 + 0.5 × 8
        foreach (\range(1, 9) as $i) {
            $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], \sprintf('%016x', $i));
        }
        Assert::same($this->call($ritual, 'flyers.burst', [], moderator: true), ['ok' => true, 'count' => 6]);
    }

    public function burstNeedsFlyersInTheSet(): void
    {
        $ritual = new RitualHarness(flyerKinds: [[Flyer::KIND_DUCK], []]);
        $ritual->transitions->scene(1, 1);
        $ritual->broadcast->published = [];

        Assert::same($this->call($ritual, 'flyers.burst', [], moderator: true), ['ok' => false, 'error' => 'no flyers']);
        Assert::false($this->call($ritual, 'state', [])['state']['limits']['burst']);
        Assert::blank($ritual->broadcast->ofType('flyer'));
    }

    public function barTargetIsForTheBarOnly(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'bar.target', ['delta' => '5'], moderator: true), ['ok' => false, 'error' => 'invalid delta']);
        Assert::same($this->call($ritual, 'bar.target', ['delta' => 5], moderator: true), ['ok' => false, 'error' => 'not bar']);
    }

    public function barTargetIsTheLimitWithTheBonusAndTellsTheConsole(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1, 2], barDrinks: new Limit(5, 1.5));
        foreach (['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc'] as $id) {
            $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], $id);
        }
        $ritual->countPlayers();
        $ritual->limits->settle();
        $ritual->broadcast->published = [];

        Assert::same($this->call($ritual, 'bar.target', ['delta' => 5], moderator: true), ['ok' => true, 'target' => 13]);

        Assert::same($ritual->broadcast->ofType('drinks'), [['type' => 'drinks', 'round' => 1, 'drunk' => 0, 'target' => 13]]);
        Assert::same($ritual->broadcast->ofType('limits'), [['type' => 'limits', 'round' => 1, 'players' => 3, 'k' => 1, 'burst' => true, 'limits' => ['bar' => 13]]]);
        Assert::same($this->call($ritual, 'state', [])['state']['limits']['limits'], ['bar' => 13]);
    }

    public function playerOverItsClickBurstIsRateLimited(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 2)]);

        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter);
        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter);
        $response = $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter);

        Assert::same($response, ['ok' => false, 'error' => 'rate limited']);
        Assert::count($ritual->broadcast->ofType('click'), 2);
        Assert::same($ritual->playerStore->find(self::PLAYER)?->clicks, 2);
        Assert::true(\in_array(['add', PlayMetrics::RPC_LIMITED, 1.0, ['click']], $ritual->metrics->calls, true));
    }

    public function playerKeepsItsBucketAcrossConnections(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 1)]);

        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter, client: 'tab-1');
        $response = $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter, client: 'tab-2');

        Assert::same($response, ['ok' => false, 'error' => 'rate limited']);
    }

    public function connectionWithoutPlayerHasItsOwnBucket(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 1)]);

        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], limiter: $limiter, client: 'tab-1');

        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], limiter: $limiter, client: 'tab-2'), ['ok' => true]);
        Assert::same(
            $this->call($ritual, 'click', ['element' => 'pumpkin-1'], limiter: $limiter, client: 'tab-1'),
            ['ok' => false, 'error' => 'rate limited'],
        );
    }

    public function methodsWithoutALimitOfTheirOwnShareOther(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 1), 'other' => new TokenBucket(1.0, 1)]);

        Assert::same($this->call($ritual, 'time', [], self::PLAYER, limiter: $limiter)['ok'], true);
        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter), ['ok' => true]);
        Assert::same($this->call($ritual, 'state', [], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
        Assert::true(\in_array(['add', PlayMetrics::RPC_LIMITED, 1.0, ['other']], $ritual->metrics->calls, true));
    }

    public function strikeTakesAClickTokenAndTheRapierMethodsShareOther(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 1), 'other' => new TokenBucket(1.0, 2)]);

        $this->call($ritual, 'strike', [], self::PLAYER, limiter: $limiter);
        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
        $this->call($ritual, 'beer', ['pumpkin' => 'pumpkin-1'], self::PLAYER, limiter: $limiter);
        $this->call($ritual, 'word', ['text' => 'rapira'], self::PLAYER, limiter: $limiter);
        Assert::same($this->call($ritual, 'cure', ['walker' => 'bbbbbbbbbbbbbbbb'], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
    }

    public function rapierMethodsAnswerTheirErrors(): void
    {
        $ritual = new RitualHarness(stageMax: [1], reachable: ['pumpkin-2']);
        $ritual->transitions->scene(1, 1);

        foreach (['beer' => ['pumpkin' => 'pumpkin-2'], 'word' => ['text' => 'rapira'], 'cure' => ['walker' => self::PLAYER], 'strike' => []] as $method => $data) {
            Assert::same($this->call($ritual, $method, $data), ['ok' => false, 'error' => 'anonymous'], $method);
            Assert::same($this->call($ritual, $method, $data, self::PLAYER), ['ok' => false, 'error' => 'no character'], $method);
        }
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']], self::PLAYER);
        Assert::same($this->call($ritual, 'beer', ['pumpkin' => 'pumpkin-2'], self::PLAYER), ['ok' => false, 'error' => 'not cursed']);
        Assert::same($this->call($ritual, 'word', ['text' => 'rapira'], self::PLAYER), ['ok' => false, 'error' => 'not now']);
        Assert::same($this->call($ritual, 'cursed.target', ['goal' => 'drinks', 'delta' => 5], moderator: true), ['ok' => false, 'error' => 'not cursed']);
        Assert::same($this->call($ritual, 'cursed.target', ['goal' => 'mugs', 'delta' => 5], moderator: true), ['ok' => false, 'error' => 'invalid goal']);
        Assert::same($this->call($ritual, 'boss.hp', ['delta' => 10], moderator: true), ['ok' => false, 'error' => 'not now']);

        for ($step = 1; $step <= 5; ++$step) {
            $this->call($ritual, 'finale.step', ['step' => $step, 'force' => true], moderator: true);
        }
        Assert::same($this->call($ritual, 'cursed.target', ['goal' => 'drinks', 'delta' => 5], moderator: true), ['ok' => true, 'target' => 7]);
        Assert::same($this->call($ritual, 'finale.step', ['step' => 6], moderator: true), ['ok' => false, 'error' => 'not ready']);
        Assert::same($this->call($ritual, 'finale.step', ['step' => 6, 'force' => true], moderator: true), ['ok' => true, 'step' => 6]);
        Assert::same($this->call($ritual, 'word', ['text' => 'шпага'], self::PLAYER), ['ok' => true, 'correct' => false]);
        Assert::same($this->call($ritual, 'move', ['x' => 30, 'y' => 90], self::PLAYER), ['ok' => false, 'error' => 'zombified']);
        Assert::same($this->call($ritual, 'word', ['text' => 'rapira'], self::PLAYER), ['ok' => false, 'error' => 'zombified']);
    }

    public function rapierRoundTrip(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $ritual->transitions->scene(1, 1);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']], self::PLAYER);
        for ($step = 1; $step <= 6; ++$step) {
            $this->call($ritual, 'finale.step', ['step' => $step, 'force' => true], moderator: true);
        }

        Assert::same($this->call($ritual, 'word', ['text' => 'Рапира'], self::PLAYER), ['ok' => true, 'correct' => true]);
        Assert::same($this->call($ritual, 'boss.hp', ['delta' => -44], moderator: true), ['ok' => true, 'hp' => 1, 'max' => 1]);
        Assert::same($this->call($ritual, 'strike', [], self::PLAYER), ['ok' => true, 'hp' => 0]);
        Assert::same($this->call($ritual, 'strike', [], self::PLAYER), ['ok' => false, 'error' => 'not now']);
        $ritual->runJobs();

        Assert::same($this->call($ritual, 'state', [])['state']['finale']['step'], 7);
        Assert::same($this->call($ritual, 'me', [], self::PLAYER)['player']['damage'], 1);
    }

    public function clinkWithRoadrunnerNeedsHimBehindTheCounter(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']], self::PLAYER);

        Assert::same($this->call($ritual, 'clink', ['roadrunner' => true]), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'clink', ['roadrunner' => true], self::PLAYER), ['ok' => false, 'error' => 'no target']);
    }

    public function barMethodsShareOther(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        $limiter = $this->limiter($ritual, ['other' => new TokenBucket(1.0, 2)]);

        $this->call($ritual, 'bar.take', [], self::PLAYER, limiter: $limiter);
        $this->call($ritual, 'drink', [], self::PLAYER, limiter: $limiter);

        Assert::same($this->call($ritual, 'clink.auto', ['walker' => 'bbbbbbbbbbbbbbbb'], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
    }

    public function barTakeWalksToTheCounter(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);

        Assert::same($this->call($ritual, 'bar.take', []), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'bar.take', [], self::PLAYER), ['ok' => false, 'error' => 'no character']);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        $response = $this->call($ritual, 'bar.take', [], self::PLAYER);

        Assert::true($response['ok']);
        Assert::same(\end($response['walk']['path']), ['x' => 66, 'y' => 62]);
    }

    public function drinkNeedsAClinkedBeer(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        Assert::same($this->call($ritual, 'drink', []), ['ok' => false, 'error' => 'anonymous']);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($this->call($ritual, 'drink', [], self::PLAYER), ['ok' => false, 'error' => 'no beer']);
    }

    public function autoClinkNeedsAPlayerWithBeer(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);
        Assert::same($this->call($ritual, 'clink.auto', ['walker' => 'bbbbbbbbbbbbbbbb']), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'clink.auto', ['walker' => 'bbbbbbbbbbbbbbbb'], self::PLAYER), ['ok' => false, 'error' => 'no character']);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($this->call($ritual, 'clink.auto', ['walker' => 'bbbbbbbbbbbbbbbb'], self::PLAYER), ['ok' => false, 'error' => 'no beer']);
        Assert::same($this->call($ritual, 'clink', ['walker' => 'bbbbbbbbbbbbbbbb'], self::PLAYER), ['ok' => false, 'error' => 'no beer']);
    }

    public function barConsoleIsTheModeratorsOnly(): void
    {
        $ritual = new RitualHarness(stageMax: [0, 1]);

        Assert::same($this->call($ritual, 'bar.target', ['delta' => 5]), ['ok' => false, 'error' => 'forbidden']);
        Assert::same($this->call($ritual, 'bar.ready', []), ['ok' => false, 'error' => 'forbidden']);
        Assert::same($this->call($ritual, 'bar.target', ['delta' => 5], moderator: true), ['ok' => true, 'target' => 9]);
        Assert::same($this->call($ritual, 'bar.target', ['delta' => 'x'], moderator: true), ['ok' => false, 'error' => 'invalid delta']);
        Assert::same($this->call($ritual, 'bar.ready', [], moderator: true), ['ok' => true]);
        Assert::same($this->call($ritual, 'scene.next', ['scene' => 0], moderator: true), ['ok' => true, 'scene' => 1]);
        $ritual->runJobs();
        Assert::same($this->call($ritual, 'bar.ready', [], moderator: true), ['ok' => false, 'error' => 'not bar']);
    }

    public function rollAnswersTheDiceAndTheLastAttemptOnABadOne(): void
    {
        $ritual = new RitualHarness(stageMax: [1], circle: 1);
        $ritual->transitions->scene(1, 1);
        $ritual->players->register(self::PLAYER, 'Jack', ['body' => 'skeleton', 'cloak' => 'cape', 'face' => 'grin', 'eyes' => 'green', 'hat' => 'pumpkin']);
        $ritual->walks->move(self::PLAYER, 20, 90);
        Assert::same($this->call($ritual, 'roll', ['attempt' => 1], self::PLAYER), ['ok' => false, 'error' => 'not now']);
        $this->call($ritual, 'finale.step', ['step' => 1, 'force' => true], moderator: true);

        $response = $this->call($ritual, 'roll', ['attempt' => 1], self::PLAYER);

        Assert::same($response['ok'] ?? null, true);
        Assert::same($response['attempt'] ?? null, 1);
        Assert::count($response['dice'] ?? [], 3);
        Assert::same($this->call($ritual, 'roll', ['attempt' => 1], self::PLAYER), $response);
        Assert::same($this->call($ritual, 'roll', ['attempt' => 5], self::PLAYER), ['ok' => false, 'error' => 'bad attempt', 'last' => 1]);
        Assert::same($this->call($ritual, 'roll', ['attempt' => 2], self::PLAYER), ['ok' => false, 'error' => 'busy']);
        Assert::same($this->call($ritual, 'roll', ['attempt' => 1]), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'dice.owl', [], moderator: true)['attempt'] ?? null, 1);
    }

    public function moderatorMakesRoadrunnerMeepForEveryone(): void
    {
        $ritual = new RitualHarness();
        $ritual->clock->now = 1_760_900_001_500;

        Assert::same($this->call($ritual, 'meep', ['flyer' => 'f-0a1b2c3d'], moderator: true), ['ok' => true]);
        Assert::same($this->call($ritual, 'meep', [], moderator: true), ['ok' => true]);
        Assert::same($this->call($ritual, 'meep', ['flyer' => ['not', 'an', 'id']], moderator: true), ['ok' => true]);

        Assert::same($ritual->broadcast->ofType('meep'), [
            ['type' => 'meep', 'round' => 1, 'flyer' => 'f-0a1b2c3d', 'at' => 1_760_900_001_500],
            ['type' => 'meep', 'round' => 1, 'at' => 1_760_900_001_500],
            ['type' => 'meep', 'round' => 1, 'at' => 1_760_900_001_500],
        ]);
    }

    public function moderatorIsNeverRateLimited(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 1)]);

        for ($i = 0; $i < 5; ++$i) {
            Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, true, $limiter), ['ok' => true]);
        }
    }

    public function sayAnswersItsErrorsInOrder(): void
    {
        $ritual = new RitualHarness();
        $long = \str_repeat('ы', RitualHarness::CHAT_MAX + 1);

        Assert::same($this->call($ritual, 'say', ['text' => $long]), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'say', ['text' => $long], self::PLAYER), ['ok' => false, 'error' => 'no character']);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);
        $this->call($ritual, 'chat.mute', ['player' => self::PLAYER, 'on' => true], moderator: true);
        Assert::same($this->call($ritual, 'say', ['text' => $long], self::PLAYER), ['ok' => false, 'error' => 'invalid text']);
        Assert::same($this->call($ritual, 'say', ['text' => ['Boo']], self::PLAYER), ['ok' => false, 'error' => 'invalid text']);
        Assert::same($this->call($ritual, 'say', ['text' => 'Boo'], self::PLAYER), ['ok' => false, 'error' => 'muted']);
        Assert::blank($ritual->broadcast->ofType('say'));
        Assert::blank($this->call($ritual, 'chat.history', [], moderator: true)['messages']);
    }

    public function playerSaysAndTheModeratorReadsAndMutes(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($this->call($ritual, 'say', ['text' => "Boo\n!"], self::PLAYER), ['ok' => true]);
        Assert::same($this->call($ritual, 'chat.history', [], moderator: true), ['ok' => true, 'messages' => [
            ['id' => 1, 'player' => ['id' => self::PLAYER, 'name' => 'Jack'], 'text' => 'Boo !', 'at' => $ritual->clock->now],
        ], 'muted' => []]);
        Assert::same(
            $this->call($ritual, 'chat.mute', ['player' => self::PLAYER, 'on' => true], moderator: true),
            ['ok' => true, 'muted' => [['id' => self::PLAYER, 'name' => 'Jack']]],
        );
        Assert::same(
            $this->call($ritual, 'chat.mute', ['player' => 'bbbbbbbbbbbbbbbb', 'on' => true], moderator: true),
            ['ok' => false, 'error' => 'no target'],
        );
        Assert::same(
            $this->call($ritual, 'chat.mute', ['player' => self::PLAYER, 'on' => 'yes'], moderator: true),
            ['ok' => false, 'error' => 'invalid on'],
        );
        Assert::count($ritual->broadcast->ofType('say'), 1);
    }

    #[DataSet(['chat.history'])]
    #[DataSet(['chat.mute'])]
    public function chatConsoleIsTheModeratorsOnly(string $method): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($this->call($ritual, $method, ['player' => self::PLAYER, 'on' => true], self::PLAYER), ['ok' => false, 'error' => 'forbidden']);
        Assert::same($this->call($ritual, $method, ['player' => self::PLAYER, 'on' => true]), ['ok' => false, 'error' => 'forbidden']);
        Assert::blank($ritual->chatStore->muted());
    }

    public function sayTakesATokenOfItsOwnBucket(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['say' => new TokenBucket(1.0, 1), 'other' => new TokenBucket(1.0, 5)]);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($this->call($ritual, 'say', ['text' => 'Boo'], self::PLAYER, limiter: $limiter), ['ok' => true]);
        Assert::same($this->call($ritual, 'say', ['text' => 'Boo'], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
        Assert::same($this->call($ritual, 'time', [], self::PLAYER, limiter: $limiter)['ok'], true);
        Assert::true(\in_array(['add', PlayMetrics::RPC_LIMITED, 1.0, ['say']], $ritual->metrics->calls, true));
    }

    public function emoteAnswersItsErrorsInOrder(): void
    {
        $ritual = new RitualHarness();

        Assert::same($this->call($ritual, 'emote', ['emoji' => '🦄']), ['ok' => false, 'error' => 'anonymous']);
        Assert::same($this->call($ritual, 'emote', ['emoji' => '🦄'], self::PLAYER), ['ok' => false, 'error' => 'no character']);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);
        Assert::same($this->call($ritual, 'emote', ['emoji' => '🦄'], self::PLAYER), ['ok' => false, 'error' => 'unknown emoji']);
        Assert::same($this->call($ritual, 'emote', [], self::PLAYER), ['ok' => false, 'error' => 'unknown emoji']);
        Assert::same($this->call($ritual, 'emote', ['emoji' => ['🎃']], self::PLAYER), ['ok' => false, 'error' => 'unknown emoji']);
        Assert::blank($ritual->broadcast->ofType('emote'));
    }

    public function emoteGoesToEveryoneAndCountsAsAnAction(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);

        Assert::same($this->call($ritual, 'emote', ['emoji' => '👻'], self::PLAYER), ['ok' => true]);

        Assert::same($ritual->broadcast->ofType('emote'), [[
            'type' => 'emote',
            'round' => 1,
            'player' => ['id' => self::PLAYER],
            'emoji' => '👻',
            'at' => $ritual->clock->now,
        ]]);
        Assert::same(\array_slice($this->rpcCalls($ritual), -1), [['emote', 'ok']]);
        Assert::same($ritual->tally->count(EveningTally::CALLS), 2);
    }

    public function emoteTakesATokenOfTheOtherBucket(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['other' => new TokenBucket(1.0, 1)]);

        Assert::same($this->call($ritual, 'emote', ['emoji' => '👻'], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'no character']);
        Assert::same($this->call($ritual, 'emote', ['emoji' => '👻'], self::PLAYER, limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
        Assert::true(\in_array(['add', PlayMetrics::RPC_LIMITED, 1.0, ['other']], $ritual->metrics->calls, true));
    }

    public function unavailableLimiterLetsTheCallThrough(): void
    {
        $ritual = new RitualHarness();
        $limiter = new RateLimiter(
            new ArrayStorage(),
            new Locks(new FakeLock(available: false)),
            $ritual->clock,
            ['click' => new TokenBucket(1.0, 1)],
        );

        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter), ['ok' => true]);
    }

    public function successfulCallIsCountedAsOkAndTimed(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        Assert::same($this->rpcCalls($ritual), [['click', 'ok']]);
        $durations = $this->rpcDurations($ritual);
        Assert::count($durations, 1);
        Assert::same($durations[0][1], ['click']);
        Assert::true($durations[0][0] >= 0.0 && $durations[0][0] < 1.0);
    }

    public function failedCallIsCountedByItsErrorCode(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'click', ['element' => 'ghost-42']);
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK]);

        Assert::same($this->rpcCalls($ritual), [['click', 'unknown element'], ['register', 'anonymous']]);
    }

    public function revealAnswersTheEveningNumbersOfAFreshRitualToAnyone(): void
    {
        $ritual = new RitualHarness();

        $response = $this->call($ritual, 'reveal', []);

        Assert::same($response, ['ok' => true, 'reveal' => [
            'clicks' => 0,
            'calls' => 0,
            'jobs' => 0,
            'burned' => 0,
            'shot' => 0,
            'drinks' => 0,
            'slain' => 0,
            'damage' => 0,
            'rolls' => 0,
            'lastPumpkin' => null,
        ]]);
        Assert::blank($ritual->broadcast->published);
        Assert::same($this->rpcCalls($ritual), [['reveal', 'ok']]);
    }

    public function revealCountsNowOutsideTheFinaleWithoutEvents(): void
    {
        $ritual = new RitualHarness();
        $ritual->click('pumpkin-1', 3, self::PLAYER);
        $ritual->runJobs();
        $ritual->broadcast->published = [];

        $reveal = $this->call($ritual, 'reveal', [], self::PLAYER)['reveal'] ?? [];

        Assert::same([$reveal['clicks'] ?? null, $reveal['jobs'] ?? null], [3, 1]);
        Assert::same($reveal, $ritual->reveal->reveal($ritual->store->snapshot())->toArray());
        Assert::null($this->call($ritual, 'state', [])['state']['finale']);
        Assert::blank($ritual->broadcast->published);
    }

    public function everyActionCountsTowardTheCallsWhateverTheAnswer(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'click', ['element' => 'pumpkin-1']);
        $this->call($ritual, 'click', ['element' => 'ghost-42']);
        $this->call($ritual, 'move', ['x' => 10, 'y' => 90]);
        $this->call($ritual, 'shoot', ['flyer' => 'nobody']);
        $this->call($ritual, 'reset', ['round' => 1]);
        $this->call($ritual, 'flyers.burst', [], moderator: true);
        foreach (['time', 'state', 'me', 'reveal'] as $read) {
            $this->call($ritual, $read, [], self::PLAYER);
        }
        $this->call($ritual, 'chat.history', [], moderator: true);
        $this->call($ritual, 'explode', []);

        Assert::same($ritual->tally->count(EveningTally::CALLS), 6);
        Assert::same($this->call($ritual, 'reveal', [])['reveal']['calls'] ?? null, 6);
    }

    public function statsResetAnswersTheRecordsZeroedAndCountsInTheReveal(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'register', ['name' => 'Jack', 'look' => self::LOOK], self::PLAYER);
        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER);
        $ritual->broadcast->published = [];
        $calls = $ritual->tally->count(EveningTally::CALLS);

        Assert::same($this->call($ritual, 'stats.reset', [], moderator: true), ['ok' => true, 'players' => 1]);
        Assert::same($this->call($ritual, 'stats.reset', [], moderator: true), ['ok' => true, 'players' => 1]);

        Assert::same($ritual->players->find(self::PLAYER)?->clicks, 0);
        Assert::same($ritual->players->find(self::PLAYER)?->name, 'Jack');
        Assert::same($ritual->broadcast->ofType('top'), [
            ['type' => 'top', 'round' => 1, 'board' => []],
            ['type' => 'top', 'round' => 1, 'board' => []],
        ]);
        Assert::same($ritual->tally->count(EveningTally::CALLS), $calls + 2);
        Assert::same(\array_slice($this->rpcCalls($ritual), -1), [['stats.reset', 'ok']]);
    }

    public function statsResetWithTheTopLockBusyAnswersBusy(): void
    {
        $ritual = new RitualHarness();
        $ritual->lock->held['top'] = 'someone-else';

        Assert::same($this->call($ritual, 'stats.reset', [], moderator: true), ['ok' => false, 'error' => 'busy']);
    }

    public function rateLimitedCallCountsToo(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['move' => new TokenBucket(1.0, 1)]);

        $this->call($ritual, 'move', ['x' => 10, 'y' => 90], self::PLAYER, limiter: $limiter);
        $limited = $this->call($ritual, 'move', ['x' => 10, 'y' => 90], self::PLAYER, limiter: $limiter);

        Assert::same($limited, ['ok' => false, 'error' => 'rate limited']);
        Assert::same($ritual->tally->count(EveningTally::CALLS), 2);
    }

    public function unavailableValkeyFailsNoCall(): void
    {
        $ritual = new RitualHarness();
        $ritual->valkey->down = true;

        Assert::same($this->call($ritual, 'click', ['element' => 'pumpkin-1']), ['ok' => true]);
        Assert::same($this->call($ritual, 'reset', ['round' => 1], moderator: true), ['ok' => true, 'round' => 2]);
        Assert::same($this->call($ritual, 'reveal', [])['reveal']['calls'] ?? null, 0);
    }

    public function resetStartsTheCallsOverAndBurnsItsRepeat(): void
    {
        $ritual = new RitualHarness();
        $this->call($ritual, 'click', ['element' => 'pumpkin-1']);

        $this->call($ritual, 'reset', ['round' => 1], moderator: true);
        $this->call($ritual, 'reset', ['round' => 1], moderator: true);

        $reveal = $this->call($ritual, 'reveal', [])['reveal'] ?? [];
        Assert::same([$reveal['calls'] ?? null, $reveal['burned'] ?? null], [2, 1]);
    }

    public function pressOfTheModeratorFindingItsStateReachedIsBurned(): void
    {
        $ritual = new RitualHarness(stageMax: [1]);
        $this->call($ritual, 'scene.next', ['scene' => 0, 'force' => true], moderator: true);
        $ritual->runJobs();
        $this->call($ritual, 'finale.step', ['step' => 1, 'force' => true], moderator: true);
        Assert::same($ritual->tally->count(EveningTally::DROPPED), 0);

        $this->call($ritual, 'scene.next', ['scene' => 0, 'force' => true], moderator: true);
        $this->call($ritual, 'finale.step', ['step' => 1, 'force' => true], moderator: true);
        $this->call($ritual, 'finale.step', ['step' => 0], moderator: true);

        Assert::same($ritual->tally->count(EveningTally::DROPPED), 3);
    }

    public function revealTakesATokenOfOther(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['other' => new TokenBucket(1.0, 1)]);

        Assert::true($this->call($ritual, 'reveal', [], limiter: $limiter)['ok']);
        Assert::same($this->call($ritual, 'reveal', [], limiter: $limiter), ['ok' => false, 'error' => 'rate limited']);
    }

    public function unknownMethodIsCountedUnderOneLabel(): void
    {
        $ritual = new RitualHarness();

        $this->call($ritual, 'explode', []);
        $this->call($ritual, 'implode', []);

        Assert::same($this->rpcCalls($ritual), [['unknown', 'unknown method'], ['unknown', 'unknown method']]);
        Assert::same(\array_column($this->rpcDurations($ritual), 1), [['unknown'], ['unknown']]);
    }

    public function refusedCallsAreCounted(): void
    {
        $ritual = new RitualHarness();
        $limiter = $this->limiter($ritual, ['click' => new TokenBucket(1.0, 1), 'other' => new TokenBucket(1.0, 1)]);

        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter);
        $this->call($ritual, 'click', ['element' => 'pumpkin-1'], self::PLAYER, limiter: $limiter);
        $this->call($ritual, 'reset', ['round' => 1], self::PLAYER, limiter: $limiter);
        $this->call($ritual, 'whatever', [], self::PLAYER, limiter: $limiter);

        Assert::same($this->rpcCalls($ritual), [
            ['click', 'ok'],
            ['click', 'rate limited'],
            ['reset', 'forbidden'],
            ['unknown', 'rate limited'],
        ]);
    }

    public function callThatCouldNotAnswerIsCountedAsException(): void
    {
        $ritual = new RitualHarness();
        $this->worker->failing = true;

        try {
            $this->call($ritual, 'time', []);
        } catch (\RuntimeException) {
        }

        Assert::same($this->rpcCalls($ritual), [['time', 'exception']]);
        Assert::count($this->rpcDurations($ritual), 1);
    }

    public function everyLabelledMethodIsHandled(): void
    {
        $constants = (new \ReflectionClass(RPCService::class))->getConstants();
        $methods = [...$constants['PUBLIC'], ...$constants['CONSOLE']];
        $ritual = new RitualHarness();

        foreach ($methods as $method) {
            $answer = $this->call($ritual, $method, [], self::PLAYER, moderator: true);
            Assert::true(($answer['error'] ?? null) !== 'unknown method', $method);
        }

        Assert::same(\array_column($this->rpcCalls($ritual), 0), $methods);
    }

    #[BeforeTest]
    public function createWorker(): void
    {
        $this->worker = new SpyWorker();
    }

    /**
     * Metrics the call changed besides the RPC's own count and time.
     */
    private function gameMetrics(RitualHarness $ritual): array
    {
        return \array_filter(
            $ritual->metrics->calls,
            static fn(array $call): bool => !\in_array($call[1], [PlayMetrics::RPC_CALLS, PlayMetrics::RPC_DURATION], true),
        );
    }

    /**
     * @return list<list<string>> method and result of each counted call
     */
    private function rpcCalls(RitualHarness $ritual): array
    {
        $calls = \array_filter($ritual->metrics->calls, static fn(array $call): bool => $call[1] === PlayMetrics::RPC_CALLS);

        return \array_values(\array_map(static fn(array $call): array => $call[3], $calls));
    }

    /**
     * @return list<array{float, list<string>}> observed seconds and labels
     */
    private function rpcDurations(RitualHarness $ritual): array
    {
        $calls = \array_filter($ritual->metrics->calls, static fn(array $call): bool => $call[1] === PlayMetrics::RPC_DURATION);

        return \array_values(\array_map(static fn(array $call): array => [$call[2], $call[3]], $calls));
    }

    /**
     * @param array<non-empty-string, TokenBucket> $buckets
     */
    private function limiter(RitualHarness $ritual, array $buckets): RateLimiter
    {
        return new RateLimiter(new ArrayStorage(), new Locks(new FakeLock()), $ritual->clock, $buckets);
    }

    private function call(
        RitualHarness $ritual,
        string $method,
        array $params,
        string $user = '',
        bool $moderator = false,
        ?RateLimiter $limiter = null,
        string $client = 'client-1',
    ): array {
        $service = new RPCService(
            $ritual->clicks,
            $ritual->snapshot,
            $ritual->players,
            $ritual->flyers,
            new NullLogger(),
            $ritual->reset,
            $ritual->walks,
            $ritual->story,
            $ritual->clock,
            $limiter ?? $this->limiter($ritual, []),
            new PlayMetrics($ritual->metrics, new NullLogger()),
            $ritual->bar,
            $ritual->rapier,
            $ritual->cursed,
            $ritual->dice,
            $ritual->chat,
            $ritual->limits,
            $ritual->tally,
            $ritual->emotes,
            $ritual->stats,
            $ritual->autopilot,
        );
        $service->handle(new RPC(
            worker: $this->worker,
            client: $client,
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
