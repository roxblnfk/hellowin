<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\ResetService;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\UnknownElementException;
use App\Application\Player\InvalidLookException;
use App\Application\Player\InvalidNameException;
use App\Application\Player\LeaderboardService;
use App\Application\Player\PlayerService;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\WalkService;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\PlayerId;
use App\Domain\Ritual\RitualBusyException;
use Psr\Log\LoggerInterface;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Request\RequestInterface;
use RoadRunner\Centrifugo\Request\RPC;
use Spiral\RoadRunnerBridge\Centrifugo\ServiceInterface;

/**
 * Client RPC calls. Always answers with a payload — `{ok: true, …}` or `{ok: false, error: string}` —
 * so the client handles failures the same way as results.
 *
 * The player is the connection's user, set by {@see ConnectService}: the client never sends its id.
 */
final readonly class RPCService implements ServiceInterface
{
    public function __construct(
        private ClickHandler $clicks,
        private SnapshotFactory $snapshot,
        private PlayerService $players,
        private LeaderboardService $leaderboard,
        private FlyerService $flyers,
        private LoggerInterface $logger,
        private ResetService $reset,
        private WalkService $walks,
    ) {}

    #[\Override]
    public function handle(RequestInterface $request): void
    {
        \assert($request instanceof RPC);

        $player = PlayerId::isValid($request->user) ? $request->user : null;
        $moderator = ($request->meta[ConnectService::META_MODERATOR] ?? null) === true;

        $request->respond(new RPCResponse($this->guarded((string) $request->method, fn(): array => match ($request->method) {
            'click' => $this->click($request->getData(), $player),
            'state' => ['ok' => true, 'state' => $this->snapshot->create()],
            'register' => $this->register($request->getData(), $player),
            'move' => $this->move($request->getData(), $player),
            'shoot' => $this->shoot($request->getData(), $player),
            'reset' => $moderator ? $this->reset($request->getData()) : self::fail('forbidden'),
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
     * @param \Closure(): array $call
     */
    private function guarded(string $method, \Closure $call): array
    {
        try {
            return $call();
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
}
