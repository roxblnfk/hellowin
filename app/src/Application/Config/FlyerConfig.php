<?php

declare(strict_types=1);

namespace App\Application\Config;

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
        'kinds' => 'duck,chicken;crow,bat;ghost,bat;ghost,bat',
        'rare' => 'roadrunner;;witch;witch',
        'rare_chance' => 0.08,
        'rare_points' => 10,
    ];

    /**
     * Milliseconds between launches, picked at random in the range; `"5000"` is a fixed interval.
     *
     * @return array{positive-int, positive-int}
     */
    public function getInterval(): array
    {
        $value = $this->config['interval'] ?? null;
        \is_int($value) and $value = (string) $value;
        $error = self::invalid('interval', 'HELLOWIN_FLYER_INTERVAL', 'milliseconds like "3000-8000" or "5000"', $value);

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
     * @return non-empty-list<non-empty-list<non-empty-string>>
     */
    public function getKinds(): array
    {
        /** @var mixed $value */
        $value = $this->config['kinds'] ?? null;
        $error = self::invalid('kinds', 'HELLOWIN_FLYER_KINDS', 'comma-separated lowercase words, sets by scene separated by ";"', $value);
        \is_string($value) or throw $error;

        $scenes = [];
        foreach (\explode(';', $value) as $set) {
            $kinds = [];
            foreach (\explode(',', $set) as $item) {
                $kind = self::kind($item) ?? throw $error;
                $kinds[$kind] = $kind;
            }
            $scenes[] = \array_values($kinds);
        }

        return $scenes;
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

    private static function chance(mixed $value, string $key, string $env): float
    {
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && $value >= 0.0 && $value <= 1.0
            ? $value
            : throw self::invalid($key, $env, 'a probability from 0 to 1', $value);
    }
}
