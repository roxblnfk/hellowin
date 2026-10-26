<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Endpoint\Web\Middleware\LocaleMiddleware;
use Spiral\Testing\Attribute\Config;
use Spiral\Testing\Http\TestResponse;
use Spiral\Translator\Translator;
use Spiral\Translator\TranslatorInterface;
use Spiral\Views\ViewsInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Tests\TestCase;

/**
 * The English catalogue and views are fixtures: the real ones are the frontend's text and change with it.
 */
#[Test]
#[Covers(LocaleMiddleware::class)]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
#[Config('translator.directory', __DIR__ . '/../../Fixture/locale')]
#[Config('views.namespaces.default', [__DIR__ . '/../../Fixture/views/', __DIR__ . '/../../../app/views/'])]
// Fixture views share names with the real ones: compiled files on disk would mix them up between tests
#[Config('views.cache.enabled', false)]
final class LocaleTest extends TestCase
{
    public function withoutAnyHintThePageIsRussian(): void
    {
        $this->page()->assertBodyContains('<html lang="ru">');
    }

    public function queryPicksTheLanguageAndRemembersItForAYear(): void
    {
        $response = $this->page(['lang' => 'en'], cookies: [LocaleMiddleware::COOKIE => 'ru'], headers: ['Accept-Language' => 'ru'])
            ->assertBodyContains('<html lang="en">');

        $cookie = $response->getOriginalResponse()->getHeaderLine('Set-Cookie');
        Assert::string($cookie)->contains('hellowin_lang=en');
        Assert::string($cookie)->contains('Max-Age=31536000');
        Assert::string($cookie)->contains('Path=/');
    }

    public function unknownQueryFallsThroughAndSetsNoCookie(): void
    {
        $response = $this->page(['lang' => 'de'], cookies: [LocaleMiddleware::COOKIE => 'en'])
            ->assertBodyContains('<html lang="en">');

        Assert::same($response->getOriginalResponse()->getHeaderLine('Set-Cookie'), '');
    }

    public function cookieWinsOverAcceptLanguage(): void
    {
        $this->page(cookies: [LocaleMiddleware::COOKIE => 'en'], headers: ['Accept-Language' => 'ru'])
            ->assertBodyContains('<html lang="en">');
        $this->page(cookies: [LocaleMiddleware::COOKIE => 'ru'], headers: ['Accept-Language' => 'en'])
            ->assertBodyContains('<html lang="ru">');
    }

    public function acceptLanguageGoesByWeight(): void
    {
        $this->page(headers: ['Accept-Language' => 'de-DE,ru;q=0.5,en-US;q=0.8'])
            ->assertBodyContains('<html lang="en">');
        $this->page(headers: ['Accept-Language' => 'en;q=0.4,ru;q=0.9'])
            ->assertBodyContains('<html lang="ru">');
    }

    /**
     * A directory without locale folders: no catalogue at all, as before the English one is written.
     */
    #[Config('translator.directory', __DIR__ . '/../../Fixture/views-without-english')]
    public function languageWithoutACatalogueFallsBackToRussian(): void
    {
        $this->page(['lang' => 'en'])->assertBodyContains('<html lang="ru">');
    }

    public function pagesVaryByCookieAndAcceptLanguage(): void
    {
        $this->page()->assertHasHeader('Vary', 'Cookie, Accept-Language');
    }

    public function localeDoesNotOutliveTheRequest(): void
    {
        $this->page(['lang' => 'en'])->assertBodyContains('<html lang="en">');

        Assert::same($this->getContainer()->get(TranslatorInterface::class)->getLocale(), 'ru');
        $this->page()->assertBodyContains('<html lang="ru">');
    }

    public function templateKeysAreTranslatedPerLocale(): void
    {
        $views = $this->getContainer()->get(ViewsInterface::class);
        $translator = $this->getContainer()->get(Translator::class);

        $translator->setLocale('en');
        $english = $views->render('i18n-probe');
        $translator->setLocale('ru');
        $russian = $views->render('i18n-probe');

        Assert::string($english)->contains('<p lang="en">Translator check · Ключ без перевода</p>');
        Assert::string($russian)->contains('<p lang="ru">Проверка переводчика · Ключ без перевода</p>');
    }

    public function englishHowItWorksIsItsOwnView(): void
    {
        $this->page(['lang' => 'en'])->assertBodyContains('The English how-it-works fixture');
        $this->page(['lang' => 'ru'])->assertBodyContains('Как это работает · Hellowin');
    }

    #[Config('views.namespaces.default', [__DIR__ . '/../../Fixture/views-without-english/'])]
    public function howItWorksWithoutAnEnglishViewFallsBackToRussian(): void
    {
        $this->page(['lang' => 'en'])->assertBodyContains('The only how-it-works fixture');
    }

    private function page(array $query = [], array $headers = [], array $cookies = []): TestResponse
    {
        return $this->fakeHttp()->get('/how-it-works', $query, $headers, $cookies)->assertOk();
    }
}
