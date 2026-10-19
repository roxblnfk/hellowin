<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Flyer\FlyerService;
use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\UnknownElementException;
use App\Application\Player\InvalidNameException;
use App\Application\Player\LeaderboardService;
use App\Application\Player\PlayerService;
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
    ) {}

    #[\Override]
    public function handle(RequestInterface $request): void
    {
        \assert($request instanceof RPC);

        $player = PlayerId::isValid($request->user) ? $request->user : null;

        $request->respond(new RPCResponse($this->guarded((string) $request->method, fn(): array => match ($request->method) {
            'click' => $this->click($request->getData(), $player),
            'state' => ['ok' => true, 'state' => $this->snapshot->create()],
            'register' => $this->register($request->getData(), $player),
            'shoot' => $this->shoot($request->getData(), $player),
            'me' => [
                'ok' => true,
                'player' => $player === null ? null : $this->players->find($player)?->toArray(),
                'top' => $this->leaderboard->last(),
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
     * @param non-empty-string|null $player
     */
    private function register(array $params, ?string $player): array
    {
        if ($player === null) {
            return self::fail('anonymous');
        }

        try {
            return ['ok' => true, 'player' => $this->players->register($player, $params['name'] ?? null)->toArray()];
        } catch (InvalidNameException) {
            return self::fail('invalid name');
        }
    }
}
