<?php

declare(strict_types=1);

namespace App\Application\Story;

use App\Application\Hellowin\ChannelPublisher;
use App\Application\Hellowin\TransitionQueue;
use App\Application\Player\PlayerService;
use App\Application\Walk\ActionStop;
use App\Application\Walk\NoCharacterException;
use App\Application\Walk\ZombifiedException;
use App\Domain\Clock\Clock;
use App\Domain\Flyer\Point;
use App\Domain\Lock\LockBusyException;
use App\Domain\Player\Player;
use App\Domain\Ritual\RitualStore;
use App\Domain\Story\Boss;
use App\Domain\Story\BossStore;
use App\Domain\Story\MagicWord;
use App\Domain\Story\Story;
use App\Domain\Walk\Walk;
use App\Domain\Walk\Walker;
use App\Domain\Walk\WalkerStore;
use App\Domain\Walk\WalkZone;
use Random\Randomizer;

/**
 * The rapier step (docs/spec/scenario.md, «Рапира»): the magic word, zombie players and their cure, and FrankenPHP as
 * a boss the whole room strikes.
 *
 * Three mechanics are here to be seen. Only the server checks the word: the client never learns it. A zombie is cured
 * by the first player whose click gets the lawn lock, like the first hit of a flyer. Every strike takes the one lock
 * `boss`, like every click on the bonfire, and the strike that takes the last point queues the dawn — a transition job
 * with the idempotency key `{round}:finale:7`.
 */
final readonly class RapierService
{
    /** Longest word a player may say, in characters. */
    private const TEXT_MAX = 40;

    /** How far a zombie shambles at a time, percents of the screen. */
    private const SHAMBLE_REACH = 12.0;

    /**
     * @param WalkZone $lawn Where zombies shamble.
     * @param float $speed Percents of the screen per second along the path.
     * @param positive-int $hpBase FrankenPHP's health before the players and the multiplier.
     * @param int<0, max> $hpPerPlayer FrankenPHP's health per player on the floor.
     */
    public function __construct(
        private WalkerStore $walkers,
        private RitualStore $ritual,
        private BossStore $boss,
        private PlayerService $players,
        private ChannelPublisher $publisher,
        private TransitionQueue $queue,
        private Clock $clock,
        private WalkZone $lawn,
        private float $speed,
        private int $hpBase,
        private int $hpPerPlayer,
        private Randomizer $random = new Randomizer(),
        private ?ActionStop $stops = null,
    ) {}

    /**
     * The player says the word: right — the rapier in hand, and the first rapier wakes the boss; wrong — the character
     * turns into a zombie the server walks.
     *
     * @param non-empty-string $id
     * @return bool Whether the word was right.
     * @throws NoCharacterException
     * @throws ZombifiedException
     * @throws StoryRefusedException `not now` — not the rapier step, `has rapier` or `invalid text`.
     * @throws LockBusyException
     */
    public function word(string $id, mixed $text): bool
    {
        $now = $this->clock->now();
        $text = \is_string($text) ? \trim($text) : '';
        [$walker, $correct, $players] = $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $text, $now): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $this->ritual->story()->rapier() or throw new StoryRefusedException('not now');
                $walker->zombie() and throw new ZombifiedException();
                $walker->hasRapier() and throw new StoryRefusedException('has rapier');
                $text !== '' && \mb_check_encoding($text, 'UTF-8') && \mb_strlen($text) <= self::TEXT_MAX
                    or throw new StoryRefusedException('invalid text');

                $correct = MagicWord::matches($text);
                $walker = $correct ? $walker->armed() : $walker->zombified($now, $now + $this->shamblePause());
                $walkers[$id] = $walker;

                return [$walkers, [$walker, $correct, \count($walkers)]];
            },
        );

        $round = $this->ritual->round();
        $this->publisher->word($round, self::player($walker), $correct);
        $correct and $this->wake($round, $players);

        return $correct;
    }

    /**
     * Cures a zombie player: the first click under the lawn lock does it, a later one finds it cured already.
     *
     * @param non-empty-string $id
     * @param string $target The zombie's id, as the client sent it.
     * @return bool Whether this click cured it.
     * @throws NoCharacterException
     * @throws ZombifiedException
     * @throws StoryRefusedException `not now` — not the rapier step, or `no target` — no such character, or it is
     *         the caller's own.
     * @throws LockBusyException
     */
    public function cure(string $id, string $target): bool
    {
        $cured = $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($id, $target): array {
                $walker = $walkers[$id] ?? throw new NoCharacterException('no character');
                $walker->zombie() and throw new ZombifiedException();
                $this->ritual->story()->rapier() or throw new StoryRefusedException('not now');
                $zombie = $target === $id ? null : $walkers[$target] ?? null;
                $zombie !== null or throw new StoryRefusedException('no target');
                if (!$zombie->zombie()) {
                    return [$walkers, null];
                }
                $zombie = $zombie->cured();
                $walkers[$zombie->id] = $zombie;

                return [$walkers, [$zombie, $walker]];
            },
        );
        if ($cured === null) {
            return false;
        }

        [$zombie, $by] = $cured;
        $this->publisher->cure($this->ritual->round(), self::player($zombie), self::player($by));

        return true;
    }

    /**
     * A strike of the rapier at FrankenPHP: one point of health under the boss's lock. The strike that takes the last
     * point fells it — the owl names the player, and the dawn comes by the transition queue.
     *
     * @param non-empty-string $id
     * @return int<0, max> The health after the strike.
     * @throws NoCharacterException
     * @throws ZombifiedException
     * @throws StoryRefusedException `not now` — no boss or it is down, `no rapier`.
     * @throws LockBusyException
     */
    public function strike(string $id): int
    {
        $walker = $this->walkers->all()[$id] ?? throw new NoCharacterException('no character');
        $walker->zombie() and throw new ZombifiedException();
        $ritual = $this->ritual->snapshot();
        $round = $ritual->round;
        $ritual->story->rapier() && $this->boss->get($round)?->down() === false or throw new StoryRefusedException('not now');
        $walker->hasRapier() or throw new StoryRefusedException('no rapier');

        [$before, $after] = $this->boss->update(
            $round,
            static fn(?Boss $boss): ?Boss => $boss === null || $boss->down() ? $boss : $boss->struck(),
        );
        $before !== null && !$before->down() && $after !== null or throw new StoryRefusedException('not now');

        $this->stops?->stop($id, ActionStop::STRIKE);
        $player = $this->players->struck($id) ?? self::player($walker);
        $this->publisher->strike($round, $player, $after->hp);
        if ($after->down()) {
            $this->publisher->owl($round, OwlLine::BOSS_DOWN, ['player' => $player->ref()]);
            $this->queue->finale($round, Story::DAWN_STEP);
        }

        return $after->hp;
    }

    /**
     * The moderator tunes FrankenPHP's health and its maximum by `$delta`, the health never below one.
     *
     * @return array{hp: int<0, max>, max: positive-int}
     * @throws StoryRefusedException `invalid delta`, or `not now` — no boss or it is down.
     * @throws LockBusyException
     */
    public function tune(mixed $delta): array
    {
        \is_int($delta) or throw new StoryRefusedException('invalid delta');
        $round = $this->ritual->round();
        [$before, $after] = $this->boss->update(
            $round,
            static fn(?Boss $boss): ?Boss => $boss === null || $boss->down() ? $boss : $boss->tuned($delta),
        );
        $before !== null && !$before->down() && $after !== null or throw new StoryRefusedException('not now');

        $after == $before or $this->publisher->boss($round, $after->toEvent());

        return $after->toEvent();
    }

    /**
     * The moderator took the dawn without waiting for the room: FrankenPHP falls as it is, with no last strike to name.
     *
     * @throws LockBusyException
     */
    public function fell(int $round): void
    {
        $this->boss->update($round, static fn(?Boss $boss): ?Boss => $boss?->felled());
    }

    /**
     * The ticker's work: zombie players shamble about the lawn, a short walk every few seconds, as an ordinary move
     * every client plays the same.
     *
     * @throws LockBusyException
     */
    public function shamble(): void
    {
        $now = $this->clock->now();
        $due = static fn(Walker $walker): bool => $walker->zombieAt !== null && $walker->zombieAt <= $now;
        if (\array_filter($this->walkers->all(), $due) === [] || !$this->ritual->story()->rapier()) {
            return;
        }

        $moved = $this->walkers->update(
            /** @param array<non-empty-string, Walker> $walkers */
            function (array $walkers) use ($now, $due): array {
                $moved = [];
                foreach ($walkers as $id => $walker) {
                    if (!$due($walker)) {
                        continue;
                    }
                    $from = $walker->walk->positionAt($now);
                    $from = new Point(\round($from->x, 2), \round($from->y, 2));
                    $angle = $this->random->getFloat(0.0, 2.0 * \M_PI);
                    $reach = $this->random->getFloat(self::SHAMBLE_REACH / 2.0, self::SHAMBLE_REACH);
                    $to = $this->lawn->clamp(new Point($from->x + $reach * \cos($angle), $from->y + $reach * \sin($angle)));
                    $to = new Point(\round($to->x, 2), \round($to->y, 2));
                    $walk = Walk::along($this->lawn->route($from, $to), $now, $this->speed);
                    $moved[] = $walkers[$id] = $walker->shambling($walk, $now + $this->shamblePause());
                }

                return [$walkers, $moved];
            },
        );

        $round = $this->ritual->round();
        foreach ($moved as $walker) {
            $this->publisher->move($round, $walker);
        }
    }

    /**
     * FrankenPHP's health as the snapshot carries it: from its waking on, at dawn down to nothing.
     *
     * @return array{hp: int<0, max>, max: positive-int}|null
     */
    public function state(int $round): ?array
    {
        return $this->boss->get($round)?->toEvent();
    }

    /**
     * The player as events name it, by the character's copy of the name.
     */
    private static function player(Walker $walker): Player
    {
        return new Player($walker->id, $walker->name);
    }

    /**
     * The first rapier wakes FrankenPHP with health for the players on the floor, scaled like every threshold.
     *
     * @param int<0, max> $players
     * @throws LockBusyException
     */
    private function wake(int $round, int $players): void
    {
        $max = \max(1, (int) \ceil((float) ($this->hpBase + $this->hpPerPlayer * $players) * $this->ritual->story()->k));
        [$before, $after] = $this->boss->update($round, static fn(?Boss $boss): Boss => $boss ?? new Boss($max, $max));
        $before === null && $after !== null and $this->publisher->boss($round, $after->toEvent());
    }

    private function shamblePause(): int
    {
        return $this->random->getInt(...Walker::SHAMBLE_PAUSE);
    }
}
