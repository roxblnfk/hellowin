<?php

declare(strict_types=1);

namespace App\Endpoint\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Names the framework of the PHP pages. RoadRunner puts its own `X-Powered-By` on every response, static files too
 * (`.rr.yaml`, `http.headers`); a page answered by PHP carries both.
 */
final readonly class PoweredByMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Powered-By';
    public const VALUE = 'Spiral 3';

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withAddedHeader(self::HEADER, self::VALUE);
    }
}
