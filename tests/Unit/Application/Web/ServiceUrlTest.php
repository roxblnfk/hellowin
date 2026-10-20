<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Web;

use App\Application\Web\ServiceUrl;
use Nyholm\Psr7\Uri;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(ServiceUrl::class)]
final class ServiceUrlTest
{
    #[DataSet([':8000/connection/websocket', 'http://localhost:8080/', true, 'ws://localhost:8000/connection/websocket'])]
    #[DataSet([':8000/connection/websocket', 'https://hellowin.example/', true, 'wss://hellowin.example:8000/connection/websocket'])]
    #[DataSet([':3000/', 'http://127.0.0.1:8080/how-it-works', false, 'http://127.0.0.1:3000/'])]
    #[DataSet([':3000', 'https://hellowin.example/', false, 'https://hellowin.example:3000/'])]
    #[DataSet([':3000/', '/how-it-works', false, 'http://localhost:3000/'])]
    public function portTakesTheHostOfThePage(string $url, string $page, bool $websocket, string $expected): void
    {
        Assert::same(ServiceUrl::resolve($url, new Uri($page), $websocket), $expected);
    }

    #[DataSet(['wss://ws.example.com/connection/websocket'])]
    #[DataSet(['/connection/websocket'])]
    #[DataSet(['https://grafana.example.com/'])]
    public function fullUrlAndPathStayAsIs(string $url): void
    {
        Assert::same(ServiceUrl::resolve($url, new Uri('http://localhost:8080/'), true), $url);
    }
}
