<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Bar\BarRefusedException;
use App\Application\Bar\BarService;
use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ElementLockedException;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\UnknownElementException;
use App\Application\Player\InvalidLookException;
use App\Application\Player\InvalidNameException;
use App\Application\Player\LeaderboardService;
use App\Application\Player\PlayerService;
use App\Application\Story\StoryRefusedException;
use App\Application\Story\StoryService;
use App\Application\Walk\ClinkRefusedException;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\WalkService;
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
 */
final readonly class RPCService implements ServiceInterface
{
    /** Methods with a limit of their own; the rest share `other`. */
    private const RATE_GROUPS = ['click', 'shoot', 'move'];

    private const CONSOLE = ['reset', 'scene.next', 'finale.step', 'owl.say', 'flyer.special', 'pause', 'zombies', 'thresholds', 'bar.target', 'bar.ready'];

    public function __construct(
        private ClickHandler $clicks,
        private SnapshotFactory $snapshot,
        private PlayerService $players,
        private LeaderboardService $leaderboard,
        private FlyerService $flyers,
        private LoggerInterface $logger,
        private ResetService $reset,
        private WalkService $walks,
        private StoryService $story,
        private Clock $clock,
        private RateLimiter $limiter,
        private PlayMetrics $metrics,
        private ?BarService $bar = null,
    ) {}

    #[\Override]
    public function handle(RequestInterface $request): void
    {
        \assert($request instanceof RPC);

        $player = PlayerId::isValid($request->user) ? $request->user : null;
        $moderator = ($request->meta[ConnectService::META_MODERATOR] ?? null) === true;
        $method = (string) $request->method;

        if (!$moderator && !$this->withinRate($method, $player ?? $request->client)) {
            $request->respond(new RPCResponse(self::fail('rate limited')));
            return;
        }

        if (\in_array($method, self::CONSOLE, true) && !$moderator) {
            $request->respond(new RPCResponse(self::fail('forbidden')));
            return;
        }

        $request->respond(new RPCResponse($this->guarded($method, fn(): array => match ($method) {
            'click' => $this->click($request->getData(), $player),
            'state' => ['ok' => true, 'state' => $this->snapshot->create()],
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
            'pause' => $this->toggle($request->getData(), $this->story->pause(...)),
            'zombies' => $this->toggle($request->getData(), $this->story->zombies(...)),
            'thresholds' => ['ok' => true] + $this->story->thresholds($request->getData()['k'] ?? null),
            'bar.take' => $this->take($player),
            'drink' => $this->drink($player),
            'clink.auto' => $this->clinkAuto($request->getData(), $player),
            'bar.target' => ['ok' => true, 'target' => $this->barService()->moveTarget($request->getData()['delta'] ?? null)],
            'bar.ready' => $this->barReady(),
            'me' => [
                'ok' => true,
                'player' => $player === null ? null : $this->players->find($player)?->toArray(),
                'top' => $this->leaderboard->last(),
                'moderator' => $moderator,
            ],
            default => self::fail('unknown method'),
        })));
    }

    /**
     * @param non-empty-string $error
     * @return array{ok: false, error: non-empty-string}
     */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }

    /**
     * A failing limiter — the lock or the KV plugin in trouble — lets the call through: the limit guards the game
     * from floods and must not take it down with it.
     */
    private function withinRate(string $method, string $caller): bool
    {
        $group = \in_array($method, self::RATE_GROUPS, true) ? $method : 'other';
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
        } catch (StoryRefusedException|BarRefusedException|ClinkRefusedException $e) {
            return self::fail($e->error);
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
            $this->clicks->click($element, $player);
            return ['ok' => true];
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

        return ['ok' => true, 'scene' => $this->story->nextScene($scene, ($params['force'] ?? false) === true)];
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

    private function special(array $params): array
    {
        /** @var mixed $kind */
        $kind = $params['kind'] ?? null;
        $flyer = \is_string($kind) ? $this->flyers->launchSpecial($kind, $this->clock->now()) : null;

        return $flyer === null ? self::fail('unknown kind') : ['ok' => true, 'flyer' => $flyer->id];
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

        $this->barService()->drink($player);

        return ['ok' => true];
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
        [$kind, $target] = \is_string($walker)
            ? [Clink::WALKER, $walker]
            : [Clink::PUMPKIN, \is_string($pumpkin) ? $pumpkin : ''];

        try {
            return ['ok' => true, 'walk' => $this->walks->clink($player, $kind, $target)->toArray()];
        } catch (NoCharacterException) {
            return self::fail('no character');
        } catch (ClinkRefusedException $e) {
            return self::fail($e->error);
        }
    }
}
