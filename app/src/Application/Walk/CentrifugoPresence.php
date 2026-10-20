<?php

declare(strict_types=1);

namespace App\Application\Walk;

use App\Domain\Player\PlayerId;
use RoadRunner\Centrifugo\CentrifugoApiInterface;

/**
 * Presence of the game channel in Centrifugo, asked through its API. Every connection is subscribed to the channel
 * server-side, so the channel presence lists every connection: one entry per tab, with the player as the user.
 * Centrifugo drops the entry as soon as the connection closes.
 */
final readonly class CentrifugoPresence implements Presence
{
    /**
     * @param non-empty-string $channel
     */
    public function __construct(
        private CentrifugoApiInterface $api,
        private string $channel,
    ) {}

    #[\Override]
    public function players(): array
    {
        $players = [];
        foreach ($this->api->presence($this->channel) as $client) {
            $user = $client['user'];
            PlayerId::isValid($user) and $players[$user] = $user;
        }

        return \array_values($players);
    }
}
