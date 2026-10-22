<?php

declare(strict_types=1);

namespace App\Application\Walk;

use App\Application\Hellowin\ChannelPublisher;
use App\Domain\Clock\Clock;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * An accepted action of a player stops the player's walking character where it is (docs/spec/protocol.md, «Действие
 * останавливает ход»). The services call {@see stop()} once the action is accepted and before they publish its event,
 * so every client gets the stopping `move` first.
 */
final readonly class ActionStop
{
    public const SHOOT = 'shoot';
    public const CLICK = 'click';
    public const STRIKE = 'strike';
    public const DRINK = 'drink';
    public const DICE = 'dice';

    public function __construct(
        private WalkerStore $walkers,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private Clock $clock,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * The stop is a show beside the action: a failure leaves the character walking and the action stands.
     *
     * @param non-empty-string|null $player Null for an anonymous connection: it has no character.
     * @param self::* $action
     */
    public function stop(?string $player, string $action): void
    {
        if ($player === null) {
            return;
        }

        try {
            $now = $this->clock->now();
            // Most actions come from a character that stands: those take no lock
            if (($this->walkers->all()[$player] ?? null)?->stoppedAt($now) === null) {
                return;
            }

            $stopped = $this->walkers->update(
                /** @param array<non-empty-string, Walker> $walkers */
                static function (array $walkers) use ($player, $now): array {
                    $walker = ($walkers[$player] ?? null)?->stoppedAt($now);
                    $walker === null or $walkers[$player] = $walker;

                    return [$walkers, $walker];
                },
            );
            $stopped === null or $this->publisher->move($this->ritual->round(), $stopped, $action);
        } catch (\Throwable $e) {
            $this->logger->warning('Walk of {player} is not stopped by {action}: {message}', [
                'player' => $player,
                'action' => $action,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
