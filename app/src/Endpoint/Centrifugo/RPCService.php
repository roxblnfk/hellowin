<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Balance\LimitsService;
use App\Application\Bar\BarRefusedException;
use App\Application\Bar\BarService;
use App\Application\Chat\ChatRefusedException;
use App\Application\Chat\ChatService;
use App\Application\Chat\EmoteService;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\UnknownElementException;
use App\Application\Player\InvalidLookException;
use App\Application\Player\InvalidNameException;
use App\Application\Player\PlayerService;
use App\Application\Story\BadAttemptException;
use App\Application\Story\CursedService;
use App\Application\Story\DiceService;
use App\Application\Story\EveningTally;
use App\Application\Story\RapierService;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Application\Walk\ClinkRefusedException;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\WalkService;
use App\Application\Walk\ZombifiedException;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\PlayerId;
use App\Domain\RateLimit\RateLimiter;
use App\Domain\Ritual\RitualBusyException;
use App\Domain\Walk\Clink;
use Psr\Log\LoggerInterface;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Request\RequestInterface;
use RoadRunner\Centrifugo\Request\RPC;
use Spiral\RoadRunnerBridge\Centrifugo\ServiceInterface;

/**
 * Client RPC calls. Always answers with a payload — `{ok: true, …}` or `{ok: false, error: string}` —
 * so the client handles failures the same way as results.
 *
 * The player is the connection's user, set by {@see ConnectService}: the client never sends its id. Console methods
 * answer `forbidden` to every connection but the moderator's, whose role {@see ConnectService} put into the meta.
 *
 * Every call but the moderator's first takes a token of its player's bucket — of the connection's, when it has no
 * player — for its group of methods ({@see RateLimiter}); an empty bucket answers `rate limited`.
 *
 * Every call, refused ones included, is counted by method and result and timed ({@see PlayMetrics::rpc()}); every
 * player action of them, and the moderator's, also goes into the round's tally for the reveal ({@see EveningTally}).
 */
final readonly class RPCService implements ServiceInterface
{
    /** Groups with a limit of their own by method; the rest share `other`. A strike is a click at FrankenPHP. */
    private const RATE_GROUPS = ['click' => 'click', 'strike' => 'click', 'shoot' => 'shoot', 'move' => 'move', 'say' => 'say'];

    private const CONSOLE = ['reset', 'scene.next', 'finale.step', 'owl.say', 'flyer.special', 'flyers.burst', 'pause', 'thresholds', 'bar.target', 'bar.ready', 'cursed.target', 'boss.hp', 'dice.owl', 'meep', 'chat.history', 'chat.mute'];

    /** Methods open to everyone; with {@see CONSOLE} they are the metric's `method` labels, any other name is `unknown`. */
    private const PUBLIC = ['click', 'state', 'time', 'register', 'move', 'clink', 'shoot', 'bar.take', 'drink', 'clink.auto', 'beer', 'word', 'cure', 'strike', 'roll', 'say', 'emote', 'me', 'reveal'];

    /** Reads the page and the console poll for themselves, not actions: the tally of the calls leaves them out. */
    private const READS = ['state', 'time', 'me', 'reveal', 'chat.history'];

    public function __construct(
        private ClickHandler $clicks,
        private SnapshotFactory $snapshot,
        private PlayerService $players,
        private FlyerService $flyers,
        private LoggerInterface $logger,
        private ResetService $reset,
        private WalkService $walks,
        private StoryService $story,
        private Clock $clock,
        private RateLimiter $limiter,
        private PlayMetrics $metrics,
        private ?BarService $bar = null,
        private ?RapierService $rapier = null,
        private ?CursedService $cursed = null,
        private ?DiceService $dice = null,
        private ?ChatService $chat = null,
        private ?LimitsService $limits = null,
        private ?EveningTally $tally = null,
        private ?EmoteService $emotes = null,
    ) {}

    #[\Override]
    public function handle(RequestInterface $request): void
    {
        \assert($request instanceof RPC);

        $started = \hrtime(true);
        $method = (string) $request->method;
        $result = 'exception';
        try {
            $answer = $this->answer($request, $method);
            $request->respond(new RPCResponse($answer));
            $result = self::result($answer);
        } finally {
            $known = \in_array($method, self::PUBLIC, true) || \in_array($method, self::CONSOLE, true);
            $this->metrics->rpc($known ? $method : 'unknown', $result, (float) (\hrtime(true) - $started) / 1e9);
            $known && !\in_array($method, self::READS, true) and $this->tally?->add(EveningTally::CALLS);
        }
    }

    /**
     * Error codes are literals of this class and of the refusal exceptions, so the label stays a short list.
     *
     * @return non-empty-string
     */
    private static function result(array $answer): string
    {
        /** @var mixed $error */
        $error = $answer['error'] ?? null;

        return match (true) {
            ($answer['ok'] ?? null) === true => 'ok',
            \is_string($error) && $error !== '' => $error,
            default => 'exception',
        };
    }

    /**
     * @param non-empty-string $error
     * @return array{ok: false, error: non-empty-string}
     */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }

    private function answer(RPC $request, string $method): array
    {
        $player = PlayerId::isValid($request->user) ? $request->user : null;
        $moderator = ($request->meta[ConnectService::META_MODERATOR] ?? null) === true;

        if (!$moderator && !$this->withinRate($method, $player ?? $request->client)) {
            return self::fail('rate limited');
        }

        if (\in_array($method, self::CONSOLE, true) && !$moderator) {
            return self::fail('forbidden');
        }

        return $this->guarded($method, fn(): array => match ($method) {
            'click' => $this->click($request->getData(), $player),
            'state' => ['ok' => true, 'state' => $this->snapshot->create()],
            'reveal' => ['ok' => true, 'reveal' => $this->snapshot->reveal() ?? throw new StoryRefusedException('not now')],
            // Neither a lock nor the store: the client syncs its clock over several calls and keeps the fastest one
            'time' => ['ok' => true, 'now' => $this->clock->now()],
            'register' => $this->register($request->getData(), $player),
            'move' => $this->move($request->getData(), $player),
            'clink' => $this->clink($request->getData(), $player),
            'shoot' => $this->shoot($request->getData(), $player),
            'reset' => $this->reset($request->getData()),
            'scene.next' => $this->nextScene($request->getData()),
            'finale.step' => $this->finaleStep($request->getData()),
            'owl.say' => $this->say($request->getData()),
            'flyer.special' => $this->special($request->getData()),
            'flyers.burst' => ['ok' => true, 'count' => $this->flyers->burst($this->clock->now())],
            'pause' => $this->toggle($request->getData(), $this->story->pause(...)),
            'thresholds' => ['ok' => true] + $this->story->thresholds($request->getData()['k'] ?? null),
            'bar.take' => $this->take($player),
            'drink' => $this->drink($player),
            'clink.auto' => $this->clinkAuto($request->getData(), $player),
            'bar.target' => $this->settled(['ok' => true, 'target' => $this->barService()->moveTarget($request->getData()['delta'] ?? null)]),
            'bar.ready' => $this->barReady(),
            'beer' => $this->beer($request->getData(), $player),
            'word' => $player === null ? self::fail('anonymous') : ['ok' => true, 'correct' => $this->rapierService()->word($player, $request->getData()['text'] ?? null)],
            'cure' => $this->cure($request->getData(), $player),
            'strike' => $player === null ? self::fail('anonymous') : ['ok' => true] + $this->rapierService()->strike($player),
            'cursed.target' => $this->settled(['ok' => true, 'target' => $this->cursedService()->moveTarget($request->getData()['goal'] ?? null, $request->getData()['delta'] ?? null)]),
            'boss.hp' => $this->settled(['ok' => true] + $this->rapierService()->tune($request->getData()['delta'] ?? null)),
            'roll' => $player === null ? self::fail('anonymous') : ['ok' => true] + $this->diceService()->roll($player, $request->getData()['attempt'] ?? null),
            'dice.owl' => ['ok' => true] + $this->diceService()->owl(),
            'meep' => $this->meep($request->getData()),
            'say' => $this->chatSay($request->getData(), $player),
            'emote' => $this->emote($request->getData(), $player),
            'chat.history' => ['ok' => true] + $this->chatService()->history(),
            'chat.mute' => ['ok' => true, 'muted' => $this->chatService()->mute($request->getData()['player'] ?? null, $request->getData()['on'] ?? null)],
            'me' => [
                'ok' => true,
                'player' => $player === null ? null : $this->players->find($player)?->toArray(),
                'moderator' => $moderator,
            ],
            default => self::fail('unknown method'),
        });
    }

    /**
     * A failing limiter — the lock or the KV plugin in trouble — lets the call through: the limit guards the game
     * from floods and must not take it down with it.
     */
    private function withinRate(string $method, string $caller): bool
    {
        $group = self::RATE_GROUPS[$method] ?? 'other';
        if ($caller === '') {
            return true;
        }

        try {
            $allowed = $this->limiter->allow($group, $caller);
        } catch (\Throwable $e) {
            $this->logger->warning('Rate limit of {group} is unavailable: {message}', [
                'group' => $group,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return true;
        }

        $allowed or $this->metrics->rpcLimited($group);

        return $allowed;
    }

    /**
     * @param \Closure(): array $call
     */
    private function guarded(string $method, \Closure $call): array
    {
        try {
            return $call();
        } catch (StoryRefusedException|BarRefusedException|ClinkRefusedException|ZombifiedException|ChatRefusedException $e) {
            return self::fail($e->error);
        } catch (BadAttemptException $e) {
            return self::fail('bad attempt') + ['last' => $e->last];
        } catch (NoCharacterException) {
            return self::fail('no character');
        } catch (RitualBusyException|LockBusyException) {
            return self::fail('busy');
        } catch (\Throwable $e) {
            $this->logger->error('RPC {method} failed: {message}', [
                'method' => $method,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return self::fail('internal error');
        }
    }

    /**
     * @param non-empty-string|null $player
     */
    private function click(array $params, ?string $player): array
    {
        $element = $params['element'] ?? null;
        if (!\is_string($element)) {
            return self::fail('unknown element');
        }

        try {
            return $this->clicks->click($element, $player)->counted ? ['ok' => true] : ['ok' => true, 'counted' => false];
        } catch (UnknownElementException) {
            return self::fail('unknown element');
        } catch (ElementLockedException) {
            return self::fail('locked');
        }
    }

    /**
     * A miss is not an error: the flyer was already shot down, has flown away or never existed.
     *
     * @param non-empty-string|null $player
     */
    private function shoot(array $params, ?string $player): array
    {
        /** @var mixed $flyer */
        $flyer = $params['flyer'] ?? null;
        $points = \is_string($flyer) ? $this->flyers->shoot($flyer, $player) : null;

        return $points === null ? ['ok' => true, 'hit' => false] : ['ok' => true, 'hit' => true, 'points' => $points];
    }

    /**
     * The client names the round it sees, so a repeated press of "Заново" restarts the ritual only once: the second
     * call finds that round over and gets the current one.
     */
    private function reset(array $params): array
    {
        $round = $params['round'] ?? null;
        if (!\is_int($round) || $round < 1) {
            return self::fail('invalid round');
        }

        return ['ok' => true, 'round' => $this->reset->reset($round) ?? $this->snapshot->create()['round']];
    }

    private function nextScene(array $params): array
    {
        $scene = $params['scene'] ?? null;
        if (!\is_int($scene) || $scene < 0) {
            return self::fail('invalid scene');
        }

        return ['ok' => true] + $this->story->nextScene($scene, ($params['force'] ?? false) === true);
    }

    private function finaleStep(array $params): array
    {
        $step = $params['step'] ?? null;
        if (!\is_int($step)) {
            return self::fail('invalid step');
        }

        return ['ok' => true, 'step' => $this->story->finaleStep($step, ($params['force'] ?? false) === true)];
    }

    /**
     * A free text wins over a catalogue line when both come.
     */
    private function say(array $params): array
    {
        \array_key_exists('text', $params)
            ? $this->story->sayText($params['text'])
            : $this->story->say($params['line'] ?? null, $params['params'] ?? null);

        return ['ok' => true];
    }

    private function meep(array $params): array
    {
        $this->story->meep($params['flyer'] ?? null);

        return ['ok' => true];
    }

    private function special(array $params): array
    {
        /** @var mixed $kind */
        $kind = $params['kind'] ?? null;
        $flyer = \is_string($kind) ? $this->flyers->launchSpecial($kind, $this->clock->now()) : null;

        return $flyer === null ? self::fail('unknown kind') : ['ok' => true, 'flyer' => $flyer->id];
    }

    /**
     * A bonus of the moderator moved a limit: the clients learn it from the limits, the bar's goal among them.
     *
     * @template T of array
     * @param T $answer
     * @return T
     */
    private function settled(array $answer): array
    {
        $this->limits?->nudge();

        return $answer;
    }

    /**
     * @param \Closure(bool): void $switch
     */
    private function toggle(array $params, \Closure $switch): array
    {
        $on = $params['on'] ?? null;
        if (!\is_bool($on)) {
            return self::fail('invalid on');
        }

        $switch($on);

        return ['ok' => true, 'on' => $on];
    }

    /**
     * @param non-empty-string|null $player
     */
    private function register(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        try {
            $registered = $this->players->register($player, $params['name'] ?? null, $params['look'] ?? null);
        } catch (InvalidNameException) {
            return self::fail('invalid name');
        } catch (InvalidLookException) {
            return self::fail('invalid look');
        }

        $this->walks->enter($registered);

        return ['ok' => true, 'player' => $registered->toArray()];
    }

    /**
     * @param non-empty-string|null $player
     */
    private function move(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        $x = $params['x'] ?? null;
        $y = $params['y'] ?? null;
        if (!(\is_int($x) || \is_float($x)) || !(\is_int($y) || \is_float($y))) {
            return self::fail('invalid point');
        }

        try {
            return ['ok' => true, 'walk' => $this->walks->move($player, (float) $x, (float) $y)->toArray()];
        } catch (NoCharacterException) {
            return self::fail('no character');
        }
    }

    /**
     * @param non-empty-string|null $player
     */
    private function take(?string $player): array
    {
        return $player === null
            ? self::fail('anonymous')
            : ['ok' => true, 'walk' => $this->walks->toCounter($player)->toArray()];
    }

    /**
     * @param non-empty-string|null $player
     */
    private function drink(?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        return $this->barService()->drink($player) ? ['ok' => true] : ['ok' => true, 'counted' => false];
    }

    /**
     * @param non-empty-string|null $player
     */
    private function clinkAuto(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        /** @var mixed $walker */
        $walker = $params['walker'] ?? null;
        $this->walks->clinkAuto($player, \is_string($walker) ? $walker : '');

        return ['ok' => true];
    }

    private function barReady(): array
    {
        $this->barService()->wave();

        return ['ok' => true];
    }

    /**
     * @param non-empty-string|null $player
     */
    private function beer(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        /** @var mixed $pumpkin */
        $pumpkin = $params['pumpkin'] ?? null;

        return ['ok' => true, 'walk' => $this->walks->toPumpkin($player, \is_string($pumpkin) ? $pumpkin : '')->toArray()];
    }

    /**
     * @param non-empty-string|null $player
     */
    private function cure(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        /** @var mixed $walker */
        $walker = $params['walker'] ?? null;

        return ['ok' => true, 'cured' => $this->rapierService()->cure($player, \is_string($walker) ? $walker : '')];
    }

    /**
     * Without the rapier service the night never reaches the rapier.
     */
    private function rapierService(): RapierService
    {
        return $this->rapier ?? throw new StoryRefusedException('not now');
    }

    /**
     * @param non-empty-string|null $player
     */
    private function chatSay(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        $this->chatService()->say($player, $params['text'] ?? null);

        return ['ok' => true];
    }

    /**
     * Without the emote service the panel is closed: no emoji is in the set.
     *
     * @param non-empty-string|null $player
     */
    private function emote(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        ($this->emotes ?? throw new ChatRefusedException('unknown emoji'))->emote($player, $params['emoji'] ?? null);

        return ['ok' => true];
    }

    /**
     * Without the chat service the chat is closed.
     */
    private function chatService(): ChatService
    {
        return $this->chat ?? throw new ChatRefusedException('not now');
    }

    private function diceService(): DiceService
    {
        return $this->dice ?? throw new StoryRefusedException('not now');
    }

    private function cursedService(): CursedService
    {
        return $this->cursed ?? throw new StoryRefusedException('not cursed');
    }

    /**
     * Without the bar service the bar is closed: no scene is the bar then.
     */
    private function barService(): BarService
    {
        return $this->bar ?? throw new BarRefusedException('not bar');
    }

    /**
     * A target that is not a string is no target at all.
     *
     * @param non-empty-string|null $player
     */
    private function clink(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        /** @var mixed $walker */
        $walker = $params['walker'] ?? null;
        /** @var mixed $pumpkin */
        $pumpkin = $params['pumpkin'] ?? null;
        [$kind, $target] = match (true) {
            ($params['roadrunner'] ?? null) === true => [Clink::ROADRUNNER, Clink::ROADRUNNER],
            \is_string($walker) => [Clink::WALKER, $walker],
            default => [Clink::PUMPKIN, \is_string($pumpkin) ? $pumpkin : ''],
        };

        try {
            return ['ok' => true, 'walk' => $this->walks->clink($player, $kind, $target)->toArray()];
        } catch (NoCharacterException) {
            return self::fail('no character');
        } catch (ClinkRefusedException $e) {
            return self::fail($e->error);
        }
    }
}
