<?php

declare(strict_types=1);

namespace App\Endpoint\Web\Middleware;

use App\Application\Locale\Language;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Cookies\Cookie;
use Spiral\Translator\Translator;

/**
 * Picks the page language: `?lang=` (remembered in a cookie), the cookie, `Accept-Language`, else the default.
 * See docs/spec/frontend.md, «Локализация».
 */
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public const QUERY = 'lang';
    public const COOKIE = 'hellowin_lang';
    public const COOKIE_LIFETIME = 365 * 24 * 3600;

    /**
     * The class, not the interface: only the class can switch the locale. The container keeps one instance for both.
     */
    public function __construct(
        private Translator $translator,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $query = Language::fromInput($request->getQueryParams()[self::QUERY] ?? null);
        $language = $query
            ?? Language::fromInput($request->getCookieParams()[self::COOKIE] ?? null)
            ?? Language::fromAcceptLanguage($request->getHeaderLine('Accept-Language'))
            ?? Language::DEFAULT;

        // A language without a catalogue directory is unknown to the translator, and setLocale() would throw
        if (!$this->translator->getCatalogueManager()->has($language->value)) {
            $language = Language::DEFAULT;
        }

        // The translator is a singleton of a long-lived worker: the locale is set on every request and put back
        // after it, so neither this request nor the code between requests sees a neighbour's language
        $this->translator->setLocale($language->value);
        try {
            $response = $handler->handle($request);
        } finally {
            $this->translator->setLocale(Language::DEFAULT->value);
        }

        if ($query !== null) {
            $cookie = Cookie::create(self::COOKIE, $query->value, self::COOKIE_LIFETIME, '/', sameSite: 'Lax');
            $response = $response->withAddedHeader('Set-Cookie', $cookie->createHeader());
        }

        return $response->withAddedHeader('Vary', 'Cookie, Accept-Language');
    }
}
