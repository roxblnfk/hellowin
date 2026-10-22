<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Balance\Burst;
use App\Domain\Flyer\FlyerDepth;
use Spiral\Core\InjectableConfig;

/**
 * @see app/config/flyers.php
 */
final class FlyerConfig extends InjectableConfig
{
    use EnvValues;

    public const CONFIG = 'flyers';

    protected array $config = [
        'interval' => '3000-8000',
        'duration' => '16000,6500,4000',
        'points' => '3,2,1',
        'bush_chance' => 0.4,
        'arc_chance' => 0.5,
        'kinds' => 'fly;duck,chicken;;bat;worm',
        'kinds_cursed' => 'worm,bat,firefly',
        'firefly_points' => 1,
        'fly_stops' => '2-3',
        'fly_sit' => '900-1600',
        'fly_leg' => 900,
        'fly_points' => 1,
        'rare' => ';roadrunner;;;',
        'rare_chance' => 0.08,
        'rare_points' => 10,
        'kinds_dawn' => 'duck,chicken',
        'interval_dawn' => '1000-2500',
        'zombie_interval' => '2000-5000',
        'zombie_min' => 5,
        'zombie_per_player' => 5,
        'zombie_points' => 2,
        'burst_base' => 5,
        'burst_per_player' => 0.2,
    ];

    /**
     * Milliseconds between launches, picked at random in the range; `"5000"` is a fixed interval.
     *
     * @return array{positive-int, positive-int}
     */
    public function getInterval(): array
    {
        return self::range($this->config['interval'] ?? null, 'interval', 'HELLOWIN_FLYER_INTERVAL');
    }

    /**
     * Milliseconds between launches at dawn, like {@see getInterval()}: many birds, often.
     *
     * @return array{positive-int, positive-int}
     */
    public function getDawnInterval(): array
    {
        return self::range($this->config['interval_dawn'] ?? null, 'interval_dawn', 'HELLOWIN_FLYER_INTERVAL_DAWN');
    }

    /**
     * Milliseconds between zombies in the cursed world, like {@see getInterval()}.
     *
     * @return array{positive-int, positive-int}
     */
    public function getZombieInterval(): array
    {
        return self::range($this->config['zombie_interval'] ?? null, 'zombie_interval', 'HELLOWIN_ZOMBIE_INTERVAL');
    }

    /**
     * @return positive-int Wandering zombies the cursed world always keeps alive.
     */
    public function getZombieMin(): int
    {
        return self::positiveInt($this->config['zombie_min'] ?? null, 'zombie_min', 'HELLOWIN_ZOMBIE_MIN');
    }

    /**
     * @return positive-int Wandering zombies per character on the floor the zombie interval tops the crowd up to.
     */
    public function getZombiePerPlayer(): int
    {
        return self::positiveInt($this->config['zombie_per_player'] ?? null, 'zombie_per_player', 'HELLOWIN_ZOMBIE_PER_PLAYER');
    }

    /**
     * @return positive-int
     */
    public function getZombiePoints(): int
    {
        return self::positiveInt($this->config['zombie_points'] ?? null, 'zombie_points', 'HELLOWIN_ZOMBIE_POINTS');
    }

    /**
     * Flight duration and points per depth, from the farthest (0) to the nearest.
     *
     * @return non-empty-list<FlyerDepth>
     */
    public function getDepths(): array
    {
        $durations = self::intList($this->config['duration'] ?? null, 'duration', 'HELLOWIN_FLYER_DURATION');
        $points = self::intList($this->config['points'] ?? null, 'points', 'HELLOWIN_FLYER_POINTS');

        \count($durations) === \count($points) or throw new \InvalidArgumentException(\sprintf(
            'Config "flyers.duration" and "flyers.points" (env HELLOWIN_FLYER_DURATION, HELLOWIN_FLYER_POINTS) must list '
            . 'the same number of depths, got %d and %d.',
            \count($durations),
            \count($points),
        ));

        return \array_map(
            static fn(int $duration, int $points): FlyerDepth => new FlyerDepth($duration, $points),
            $durations,
            $points,
        );
    }

    /**
     * Probability that a flyer takes off from a bush rather than comes from behind the screen edge.
     */
    public function getBushChance(): float
    {
        return self::chance($this->config['bush_chance'] ?? null, 'bush_chance', 'HELLOWIN_FLYER_BUSH_CHANCE');
    }

    /**
     * Probability of a curved flight.
     */
    public function getArcChance(): float
    {
        return self::chance($this->config['arc_chance'] ?? null, 'arc_chance', 'HELLOWIN_FLYER_ARC_CHANCE');
    }

    /**
     * Flyer kinds by scene, picked at random for every launch: `"duck,chicken;crow,bat"` — sets separated by `;`, kinds
     * within a set by `,`. Scenes past the list take its last set.
     *
     * @return non-empty-list<list<non-empty-string>> An empty set is a scene without flyers.
     */
    public function getKinds(): array
    {
        /** @var mixed $value */
        $value = $this->config['kinds'] ?? null;
        $error = self::invalid('kinds', 'HELLOWIN_FLYER_KINDS', 'comma-separated lowercase words, sets by scene separated by ";"', $value);
        \is_string($value) or throw $error;

        return \array_map(static fn(string $set): array => self::set($set, $error), \explode(';', $value));
    }

    /**
     * Flyer kinds of the cursed world in place of its scene's set, one set: `"worm,bat,firefly"`.
     *
     * @return list<non-empty-string> Empty is a cursed world without flyers.
     */
    public function getCursedKinds(): array
    {
        /** @var mixed $value */
        $value = $this->config['kinds_cursed'] ?? null;
        $error = self::invalid('kinds_cursed', 'HELLOWIN_FLYER_KINDS_CURSED', 'comma-separated lowercase words', $value);
        \is_string($value) or throw $error;

        return self::set($value, $error);
    }

    /**
     * Flyer kinds of the dawn in place of its scene's set, one set like {@see getCursedKinds()}: `"duck,chicken"`.
     *
     * @return list<non-empty-string>
     */
    public function getDawnKinds(): array
    {
        /** @var mixed $value */
        $value = $this->config['kinds_dawn'] ?? null;
        $error = self::invalid('kinds_dawn', 'HELLOWIN_FLYER_KINDS_DAWN', 'comma-separated lowercase words', $value);
        is_string($value) or throw $error;

        return self::set($value, $error);
    }

    /**
     * @return positive-int Points for a firefly at any depth.
     */
    public function getFireflyPoints(): int
    {
        return self::positiveInt($this->config['firefly_points'] ?? null, 'firefly_points', 'HELLOWIN_FLYER_FIREFLY_POINTS');
    }

    /**
     * How many times a fly lands, picked at random in the range; `"2"` is a fixed number.
     *
     * @return array{positive-int, positive-int}
     */
    public function getFlyStops(): array
    {
        return self::range($this->config['fly_stops'] ?? null, 'fly_stops', 'HELLOWIN_FLYER_FLY_STOPS', 'landings like "2-3" or "2"');
    }

    /**
     * Milliseconds a fly sits at each landing, picked at random in the range like {@see getInterval()}.
     *
     * @return array{positive-int, positive-int}
     */
    public function getFlySit(): array
    {
        return self::range($this->config['fly_sit'] ?? null, 'fly_sit', 'HELLOWIN_FLYER_FLY_SIT');
    }

    /**
     * @return positive-int Milliseconds of a fly's flight from one point to the next.
     */
    public function getFlyLeg(): int
    {
        return self::positiveInt($this->config['fly_leg'] ?? null, 'fly_leg', 'HELLOWIN_FLYER_FLY_LEG');
    }

    /**
     * @return positive-int Points for a fly.
     */
    public function getFlyPoints(): int
    {
        return self::positiveInt($this->config['fly_points'] ?? null, 'fly_points', 'HELLOWIN_FLYER_FLY_POINTS');
    }

    /**
     * The rare flyer of each scene, by scene like the kinds: `"roadrunner;;witch"` — an empty item is a scene without
     * one. Scenes past the list take its last item.
     *
     * @return non-empty-list<non-empty-string|null>
     */
    public function getRare(): array
    {
        /** @var mixed $value */
        $value = $this->config['rare'] ?? null;
        $error = self::invalid('rare', 'HELLOWIN_FLYER_RARE', 'lowercase words or nothing by scene, separated by ";"', $value);
        \is_string($value) or throw $error;

        return \array_map(
            static fn(string $item): ?string => \trim($item) === '' ? null : self::kind($item) ?? throw $error,
            \explode(';', $value),
        );
    }

    /**
     * Probability that a launch is the rare flyer of the scene, when the scene has one.
     */
    public function getRareChance(): float
    {
        return self::chance($this->config['rare_chance'] ?? null, 'rare_chance', 'HELLOWIN_FLYER_RARE_CHANCE');
    }

    /**
     * @return positive-int Points for a rare flyer; the roadrunner is never hit and brings none.
     */
    public function getRarePoints(): int
    {
        return self::positiveInt($this->config['rare_points'] ?? null, 'rare_points', 'HELLOWIN_FLYER_RARE_POINTS');
    }

    /**
     * The moderator's burst of flyers: how many for the room.
     */
    public function getBurst(): Burst
    {
        return new Burst(
            self::number($this->config['burst_base'] ?? null, 'burst_base', 'HELLOWIN_FLYER_BURST_BASE', true),
            self::number($this->config['burst_per_player'] ?? null, 'burst_per_player', 'HELLOWIN_FLYER_BURST_PER_PLAYER', false),
        );
    }

    /**
     * @return array{positive-int, positive-int}
     */
    private static function range(
        mixed $value,
        string $key,
        string $env,
        string $expected = 'milliseconds like "3000-8000" or "5000"',
    ): array {
        \is_int($value) and $value = (string) $value;
        $error = self::invalid($key, $env, $expected, $value);

        if (!\is_string($value) || \preg_match('/^\s*(\d+)\s*(?:-\s*(\d+)\s*)?$/', $value, $match) !== 1) {
            throw $error;
        }
        $min = (int) $match[1];
        $max = isset($match[2]) ? (int) $match[2] : $min;

        if ($min < 1 || $max < $min) {
            throw $error;
        }

        /** @var positive-int $max */
        return [$min, $max];
    }

    /**
     * @return list<non-empty-string> Kinds of a comma-separated set, each once.
     */
    private static function set(string $set, \InvalidArgumentException $error): array
    {
        $kinds = [];
        foreach (\trim($set) === '' ? [] : \explode(',', $set) as $item) {
            $kind = self::kind($item) ?? throw $error;
            $kinds[$kind] = $kind;
        }

        return \array_values($kinds);
    }

    /**
     * @return non-empty-string|null
     */
    private static function kind(string $item): ?string
    {
        $kind = \trim($item);

        return $kind !== '' && \preg_match('/^[a-z]+$/', $kind) === 1 ? $kind : null;
    }

    /**
     * @return non-empty-list<positive-int>
     */
    private static function intList(mixed $value, string $key, string $env): array
    {
        $list = \is_string($value) ? \explode(',', $value) : $value;
        \is_array($list) && $list !== [] or throw self::invalid($key, $env, 'a comma-separated list of positive integers', $value);

        $ints = [];
        /** @var mixed $item */
        foreach ($list as $item) {
            $ints[] = self::positiveInt($item, $key, $env);
        }

        return $ints;
    }

    private static function number(mixed $value, string $key, string $env, bool $positive): float
    {
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && ($positive ? $value > 0.0 : $value >= 0.0)
            ? $value
            : throw self::invalid($key, $env, $positive ? 'a number above zero' : 'a non-negative number', $value);
    }

    private static function chance(mixed $value, string $key, string $env): float
    {
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && $value >= 0.0 && $value <= 1.0
            ? $value
            : throw self::invalid($key, $env, 'a probability from 0 to 1', $value);
    }
}
