<?php

declare(strict_types=1);

namespace App\Application\Web;

use Psr\Http\Message\UriInterface;

/**
 * A browser link to another service of the stack — Centrifugo, Grafana. RoadRunner serves the pages but proxies
 * nothing, so each service is published on its own port, and a port-only URL follows whatever host the page was
 * opened by: `localhost`, `127.0.0.1` or an address in the LAN.
 */
final class ServiceUrl
{
    /**
     * @param string $url `:<port>[/path]` takes the scheme and the host of the page; a full URL or a path stays as is.
     * @param bool $websocket Whether the scheme taken from the page becomes `ws`/`wss`.
     */
    public static function resolve(string $url, UriInterface $page, bool $websocket = false): string
    {
        if (\preg_match('~^:(\d{1,5})(/.*)?$~', $url, $match) !== 1) {
            return $url;
        }

        $secure = $page->getScheme() === 'https';
        $scheme = match (true) {
            $websocket => $secure ? 'wss' : 'ws',
            default => $secure ? 'https' : 'http',
        };
        $host = $page->getHost() === '' ? 'localhost' : $page->getHost();

        return \sprintf('%s://%s:%s%s', $scheme, $host, $match[1], $match[2] ?? '/');
    }
}
