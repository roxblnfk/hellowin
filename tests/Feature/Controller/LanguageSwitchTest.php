<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use RoadRunner\Lock\LockInterface;
use Spiral\Testing\Attribute\Config;
use Testo\Assert;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\Fake\FakeLock;
use Tests\TestCase;

/**
 * The real templates and catalogues of the frontend, not the fixtures of {@see LocaleTest}.
 */
#[Test]
#[Config('cache.storages.hellowin', ['type' => 'array'])]
#[Config('cache.storages.process', ['type' => 'array'])]
final class LanguageSwitchTest extends TestCase
{
    #[BeforeTest]
    public function fakeLock(): void
    {
        $this->getContainer()->bindSingleton(LockInterface::class, new FakeLock());
    }

    public function homePageOffersTheOtherLanguage(): void
    {
        foreach (['ru' => 'en', 'en' => 'ru'] as $lang => $other) {
            $body = $this->body('/', $lang);

            Assert::same(\preg_match_all('/<a [^>]*\bhref="\?lang=(ru|en)"[^>]*\bdata-lang-switch\b/', $body, $links), 1);
            Assert::same($links[1], [$other]);
        }
    }

    public function homePageSpeaksTheChosenLanguage(): void
    {
        $english = $this->body('/', 'en');
        $russian = $this->body('/', 'ru');

        Assert::string($english)->contains('<html lang="en">');
        Assert::string($english)->contains('Sound');
        Assert::string($russian)->contains('<html lang="ru">');
        Assert::string($russian)->contains('Звук');
    }

    public function howItWorksSwitchesToTheOtherLanguage(): void
    {
        $english = $this->body('/how-it-works', 'en');
        $russian = $this->body('/how-it-works', 'ru');

        Assert::string($english)->contains('<h1>How it works</h1>');
        Assert::string($russian)->contains('<h1>Как это работает</h1>');
        Assert::same($this->nav($english), ['/', 'http://localhost:3000/', '?lang=ru']);
        Assert::same($this->nav($russian), ['/', 'http://localhost:3000/', '?lang=en']);
    }

    /**
     * @return list<string> Links of the page header: the game, Grafana, the other language.
     */
    private function nav(string $body): array
    {
        Assert::same(\preg_match('/<div class="how__nav">(.*?)<\/div>/s', $body, $nav), 1);
        \preg_match_all('/<a href="([^"]*)"/', $nav[1], $links);

        return $links[1];
    }

    private function body(string $path, string $lang): string
    {
        return (string) $this->fakeHttp()->get($path, ['lang' => $lang])
            ->assertOk()
            ->getOriginalResponse()
            ->getBody();
    }
}
