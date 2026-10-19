<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use Spiral\Bootloader\I18nBootloader;
use Spiral\Testing\Http\FakeHttp;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Tests\TestCase;

#[Test]
final class HomeControllerTest extends TestCase
{
    private FakeHttp $http;

    public function defaultActionWorks(): void
    {
        $response = $this->http->get('/')->assertOk();

        Assert::string(\strip_tags((string) $response->getOriginalResponse()->getBody()))
            ->contains('The PHP Framework for future Innovators');
    }

    public function defaultActionWithRuLocale(): void
    {
        if (!\in_array(I18nBootloader::class, $this->getRegisteredBootloaders(), true)) {
            throw new SkipTest('Component `spiral/translator` is not installed.');
        }

        $response = $this->http->withHeader('accept-language', 'ru')->get('/')->assertOk();

        Assert::string(\strip_tags((string) $response->getOriginalResponse()->getBody()))
            ->contains('PHP Framework для будущих инноваторов');
    }

    public function interactWithConsole(): void
    {
        $output = $this->runCommand('views:reset');

        Assert::string($output)->contains('cache');
    }

    #[BeforeTest]
    protected function createHttpClient(): void
    {
        $this->http = $this->fakeHttp();
    }
}
