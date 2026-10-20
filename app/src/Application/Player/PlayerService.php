<?php

declare(strict_types=1);

namespace App\Application\Player;

use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Look;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerName;
use App\Domain\Player\PlayerStore;
use Psr\Log\LoggerInterface;

/**
 * Player statistics. Clicks, transitions and shots count for the game first: a statistics failure is logged and
 * the action goes on without a player, so the player's numbers may lag but the game never stalls on them.
 */
final readonly class PlayerService
{
    public function __construct(
        private PlayerStore $store,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param non-empty-string $id
     * @param mixed $look Null keeps the current look.
     * @throws InvalidNameException
     * @throws InvalidLookException
     * @throws LockBusyException
     */
    public function register(string $id, mixed $name, mixed $look = null): Player
    {
        $name = PlayerName::normalize($name) ?? throw new InvalidNameException('invalid name');
        $look = $look === null ? null : Look::fromArray($look) ?? throw new InvalidLookException('invalid look');

        return $this->store->update($id, static fn(Player $player): Player => $player->named($name, $look));
    }

    /**
     * @param non-empty-string $id
     */
    public function find(string $id): ?Player
    {
        return $this->store->find($id);
    }

    /**
     * @param non-empty-string|null $id Null for an anonymous connection.
     */
    public function clicked(?string $id): ?Player
    {
        return $this->change($id, static fn(Player $player): Player => $player->clicked());
    }

    /**
     * @param non-empty-string|null $id
     */
    public function finishedPumpkin(?string $id): ?Player
    {
        return $this->change($id, static fn(Player $player): Player => $player->finishedPumpkin());
    }

    /**
     * @param non-empty-string|null $id
     * @param int<0, max> $points
     */
    public function shotFlyer(?string $id, int $points): ?Player
    {
        return $this->change($id, static fn(Player $player): Player => $player->shotFlyer($points));
    }

    /**
     * @param non-empty-string|null $id
     * @param \Closure(Player): Player $change
     */
    private function change(?string $id, \Closure $change): ?Player
    {
        if ($id === null) {
            return null;
        }

        try {
            return $this->store->update($id, $change);
        } catch (\Throwable $e) {
            $this->logger->warning('Player {player} statistics are not updated: {message}', [
                'player' => $id,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return null;
        }
    }
}
