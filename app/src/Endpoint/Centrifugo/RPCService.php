<?php

declare(strict_types=1);

namespace App\Endpoint\Centrifugo;

use App\Application\Hellowin\ClickHandler;
use App\Application\Hellowin\SnapshotFactory;
use App\Application\Hellowin\UnknownElementException;
use App\Domain\Ritual\RitualBusyException;
use Psr\Log\LoggerInterface;
use RoadRunner\Centrifugo\Payload\RPCResponse;
use RoadRunner\Centrifugo\Request\RequestInterface;
use RoadRunner\Centrifugo\Request\RPC;
use Spiral\RoadRunnerBridge\Centrifugo\ServiceInterface;

/**
 * Client RPC calls. Always answers with a payload — `{ok: true, …}` or `{ok: false, error: string}` —
 * so the client handles failures the same way as results.
 */
final readonly class RPCService implements ServiceInterface
{
    public function __construct(
        private ClickHandler $clicks,
        private SnapshotFactory $snapshot,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function handle(RequestInterface $request): void
    {
        \assert($request instanceof RPC);

        $request->respond(new RPCResponse(match ($request->method) {
            'click' => $this->click($request->getData()),
            'state' => $this->state(),
            default => self::fail('unknown method'),
        }));
    }

    /**
     * @param non-empty-string $error
     * @return array{ok: false, error: non-empty-string}
     */
    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }

    private function click(array $params): array
    {
        $element = $params['element'] ?? null;
        if (!\is_string($element)) {
            return self::fail('unknown element');
        }

        try {
            $this->clicks->click($element);
            return ['ok' => true];
        } catch (UnknownElementException) {
            return self::fail('unknown element');
        } catch (RitualBusyException) {
            return self::fail('busy');
        } catch (\Throwable $e) {
            $this->logger->error('Click failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            return self::fail('internal error');
        }
    }

    /**
     * Resynchronizes a client after a reconnect or a missed reset.
     */
    private function state(): array
    {
        try {
            return ['ok' => true, 'state' => $this->snapshot->create()];
        } catch (\Throwable $e) {
            $this->logger->error('State failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            return self::fail('internal error');
        }
    }
}
