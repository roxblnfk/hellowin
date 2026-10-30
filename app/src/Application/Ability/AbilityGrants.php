<?php

declare(strict_types=1);

namespace App\Application\Ability;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\PlayMetrics;
use App\Application\Story\OwlLine;
use App\Application\Story\StoryRefusedException;
use App\Domain\Clock\Clock;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Ability;
use App\Domain\Player\Player;
use App\Domain\Player\PlayerId;
use App\Domain\Player\PlayerStore;
use App\Domain\RateLimit\Cooldown;
use App\Domain\Ritual\RitualStore;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Who gets an ability (docs/spec/scenario.md, «Способности»): a triple of a player's new throw, the flyer that brings
 * the player's `flyers` to the threshold, or the moderator. The record changes under the lock `player:{id}`, then
 * everyone hears of it: the event `ability`, the character's copy and, at most once per gap, the owl's line.
 *
 * A grant by the dice or a flyer is a show beside the throw or the hit: a failure is logged and the action stands.
 */
final readonly class AbilityGrants
{
    public const SOURCE_DICE = 'dice';
    public const SOURCE_FLYERS = 'flyers';
    public const SOURCE_MODERATOR = 'moderator';

    /** The line's number stays exact in a JavaScript number. */
    private const MAX_N = 0x7FFFFFFF;

    /**
     * @param Cooldown $owlGap One line of the owl about abilities per its window.
     * @param positive-int $fartFlyers The count of shot flyers that brings `fart` to a player without an ability.
     */
    public function __construct(
        private PlayerStore $players,
        private WalkerStore $walkers,
        private RitualStore $ritual,
        private ChannelPublisher $publisher,
        private PlayMetrics $metrics,
        private Clock $clock,
        private Cooldown $owlGap,
        private int $fartFlyers,
        private LoggerInterface $logger,
        private Randomizer $random = new Randomizer(),
    ) {}

    /**
     * A new throw of the player: three equal faces bring any ability but the one the player has.
     *
     * @param non-empty-string $id
     * @param array{int, int, int} $dice
     * @return Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN|null The ability granted; null — none.
     */
    public function rolled(int $round, string $id, array $dice): ?string
    {
        if ($dice[0] !== $dice[1] || $dice[1] !== $dice[2]) {
            return null;
        }

        try {
            $player = $this->players->update($id, function (Player $player): Player {
                $others = \array_values(\array_diff(Ability::ALL, [$player->ability]));

                return $player->withAbility($others[$this->random->getInt(0, \count($others) - 1)]);
            });
        } catch (\Throwable $e) {
            $this->failed($id, $e);

            return null;
        }
        $this->granted($round, $player, self::SOURCE_DICE);

        return $player->ability;
    }

    /**
     * A flyer shot down: the one on which the player's `flyers` became the threshold brings `fart`, unless the player
     * has an ability already. The equality, not «at least», makes it one flyer of the player's whole life.
     */
    public function shot(int $round, Player $shooter): void
    {
        if ($shooter->flyers !== $this->fartFlyers || $shooter->ability !== null) {
            return;
        }

        $changed = false;
        try {
            $player = $this->players->amend($shooter->id, static function (Player $player) use (&$changed): Player {
                $changed = $player->ability === null;

                return $changed ? $player->withAbility(Ability::FART) : $player;
            });
        } catch (\Throwable $e) {
            $this->failed($shooter->id, $e);

            return;
        }
        $player !== null && $changed and $this->granted($round, $player, self::SOURCE_FLYERS);
    }

    /**
     * The moderator gives an ability or takes it away; the ability the player has already changes nothing.
     *
     * @return Ability::FLYERS|Ability::FART|Ability::MEEP|Ability::LIGHTNING|Ability::PUMPKIN|null
     * @throws StoryRefusedException `invalid ability`, `no target` — the player has no record.
     * @throws LockBusyException
     */
    public function grant(mixed $id, mixed $ability): ?string
    {
        $ability === null || Ability::isValid($ability) or throw new StoryRefusedException('invalid ability');
        PlayerId::isValid($id) or throw new StoryRefusedException('no target');

        $before = null;
        $player = $this->players->amend($id, static function (Player $player) use ($ability, &$before): Player {
            $before = $player->ability;

            return $player->withAbility($ability);
        }) ?? throw new StoryRefusedException('no target');
        $before === $ability or $this->granted($this->ritual->round(), $player, self::SOURCE_MODERATOR);

        return $ability;
    }

    /**
     * @param self::SOURCE_* $source
     */
    private function granted(int $round, Player $player, string $source): void
    {
        $this->publisher->ability($round, $player, $player->ability, $source, $this->clock->now());
        $this->dress($player);
        if ($player->ability === null) {
            return;
        }

        $this->metrics->abilityGranted($player->ability, $source);
        $this->owlGap->take() and $this->publisher->owl($round, OwlLine::ABILITY, [
            'player' => $player->ref(),
            'ability' => $player->ability,
            'n' => $this->random->getInt(0, self::MAX_N),
        ]);
    }

    /**
     * The character's copy of the ability, for the snapshot: a failure leaves the copy stale till the player enters
     * again, the event has told everyone already.
     */
    private function dress(Player $player): void
    {
        try {
            $this->walkers->update(
                /** @param array<non-empty-string, Walker> $walkers */
                static function (array $walkers) use ($player): array {
                    $walker = $walkers[$player->id] ?? null;
                    $walker === null or $walkers[$player->id] = $walker->withAbility($player->ability);

                    return [$walkers, null];
                },
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Character of {player} keeps its old ability: {message}', [
                'player' => $player->id,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    private function failed(string $id, \Throwable $e): void
    {
        $this->logger->warning('Ability of {player} is not granted: {message}', [
            'player' => $id,
            'message' => $e->getMessage(),
            'exception' => $e,
        ]);
    }
}
