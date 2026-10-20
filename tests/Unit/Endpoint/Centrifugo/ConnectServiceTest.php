<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Centrifugo;

use App\Application\Config\HellowinConfig;
use App\Domain\Player\PlayerId;
use App\Endpoint\Centrifugo\ConnectService;
use RoadRunner\Centrifugal\Proxy\DTO\V1\ConnectResponse;
use RoadRunner\Centrifugal\Proxy\DTO\V1\ConnectResult;
use RoadRunner\Centrifugo\Request\Connect;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;
use Tests\Fake\SpyWorker;

#[Test]
#[Covers(ConnectService::class)]
#[Covers(PlayerId::class)]
final class ConnectServiceTest
{
    private const SECRET = '6f1c2a54-93b7-4d0e-8a1f-2c3d4e5f6a7b';

    public function subscribesAnonymousClientToTheGameChannel(): void
    {
        $result = self::connect([]);

        Assert::same($result->getUser(), '');
        Assert::same(\iterator_to_array($result->getChannels()), ['spooky']);
    }

    public function playerSecretBecomesItsPublicId(): void
    {
        $result = self::connect(['playerSecret' => self::SECRET]);

        Assert::same($result->getUser(), PlayerId::fromSecret(self::SECRET));
        Assert::true(PlayerId::isValid($result->getUser()));
        Assert::same(\iterator_to_array($result->getChannels()), ['spooky']);
    }

    public function publicIdDoesNotContainTheSecret(): void
    {
        $id = (string) PlayerId::fromSecret(self::SECRET);

        Assert::same(\strlen($id), PlayerId::LENGTH);
        Assert::false(\str_contains(self::SECRET, $id));
        Assert::notSame($id, PlayerId::fromSecret(self::SECRET . 'x'));
    }

    #[DataSet([''], 'empty')]
    #[DataSet(['short'], 'too short')]
    #[DataSet(['not a uuid at all, has spaces'], 'spaces')]
    #[DataSet([12345678901234567890], 'number')]
    #[DataSet([['nested']], 'array')]
    public function malformedSecretConnectsAnonymously(mixed $secret): void
    {
        Assert::same(self::connect(['playerSecret' => $secret])->getUser(), '');
    }

    public function moderatorSecretPutsTheRoleIntoTheMeta(): void
    {
        $result = self::connect(['playerSecret' => self::SECRET, 'moderatorSecret' => 'owl-secret'], 'owl-secret');

        Assert::same(\json_decode($result->getMeta(), true), [ConnectService::META_MODERATOR => true]);
        Assert::same($result->getUser(), PlayerId::fromSecret(self::SECRET));
    }

    #[DataSet(['owl-secret', 'wrong'], 'wrong secret')]
    #[DataSet(['owl-secret', null], 'no secret')]
    #[DataSet(['owl-secret', ['owl-secret']], 'array')]
    #[DataSet(['', ''], 'console off')]
    public function otherConnectionsHaveNoRole(string $expected, mixed $secret): void
    {
        Assert::same(self::connect(['moderatorSecret' => $secret], $expected)->getMeta(), '');
    }

    private static function connect(array $data, string $moderatorSecret = ''): ConnectResult
    {
        $worker = new SpyWorker();
        $service = new ConnectService(new HellowinConfig(['channel' => 'spooky', 'moderator_secret' => $moderatorSecret]));

        $service->handle(new Connect(
            worker: $worker,
            client: 'client-1',
            transport: 'websocket',
            protocol: 'json',
            encoding: 'json',
            data: $data,
            name: null,
            version: null,
            channels: [],
            headers: [],
        ));

        $result = $worker->lastResponse(ConnectResponse::class)->getResult();
        Assert::notNull($result);

        return $result;
    }
}
