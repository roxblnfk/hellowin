<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Element\Element;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Point;
use App\Domain\RateLimit\TokenBucket;
use App\Domain\Walk\Items;
use App\Domain\Walk\WalkZone;
use Spiral\Core\InjectableConfig;

/**
 * @see app/config/hellowin.php
 */
final class HellowinConfig extends InjectableConfig
{
    public const CONFIG = 'hellowin';

    protected array $config = [
        'channel' => 'hellowin',
        'storage' => 'hellowin',
        'centrifugo_url' => ':8000/connection/websocket',
        'grafana_url' => ':3000/',
        'clicks_per_stage' => 5,
        'moderator_secret' => '',
        'scene_stage_max' => '0,4,8,11',
        'bonfire_clicks_per_stage' => 20,
        'bonfire_stage_max' => '0,0,4,7',
        'circle_clicks_per_stage' => 20,
        'circle_stages' => 4,
        'valkey_dsn' => 'tcp://valkey:6379',
        'rpc_rate' => ['click' => '20,40', 'shoot' => '20,40', 'move' => '10,20', 'other' => '10,30'],
        'elements' => [],
        'bushes' => [],
        'horizon' => 38,
        'items' => [],
        'finale_items' => [],
        'walk' => [
            'area' => ['x1' => 3, 'y1' => 44, 'x2' => 97, 'y2' => 97],
            'patch' => ['cx' => 50, 'cy' => 63, 'rx' => 31, 'ry' => 25],
            'spawn' => ['y1' => 86, 'y2' => 90],
            'speed' => 12,
            'leave_after' => 5000,
            'reach' => 10,
            'clink_gap' => 4,
            'clink_duration' => 3200,
        ],
    ];

    /**
     * @return non-empty-string
     */
    public function getChannel(): string
    {
        return self::nonEmptyString($this->config['channel'] ?? null, 'channel');
    }

    /**
     * Cache storage holding the game state.
     *
     * @return non-empty-string
     */
    public function getStorage(): string
    {
        return self::nonEmptyString($this->config['storage'] ?? null, 'storage');
    }

    /**
     * @return non-empty-string A full URL, a path or `:<port>/path`: see {@see \App\Application\Web\ServiceUrl}.
     */
    public function getCentrifugoUrl(): string
    {
        return self::nonEmptyString($this->config['centrifugo_url'] ?? null, 'centrifugo_url');
    }

    /**
     * @return non-empty-string The Grafana dashboard, in the forms of {@see getCentrifugoUrl()}.
     */
    public function getGrafanaUrl(): string
    {
        return self::nonEmptyString($this->config['grafana_url'] ?? null, 'grafana_url');
    }

    /**
     * Clicks on a pumpkin that move it to its next stage. A digit string is accepted too: env variables
     * arrive as strings.
     *
     * @return positive-int
     */
    public function getClicksPerStage(): int
    {
        return self::positiveInt($this->config['clicks_per_stage'] ?? null, 'clicks_per_stage', 'HELLOWIN_CLICKS_PER_STAGE');
    }

    /**
     * The last pumpkin stage of every scene before the final one, cumulative: `[0, 4, 8, 11]` — the bar (scene 0) has
     * no pumpkins to move, they reach stage 4 in scene 1, 8 in scene 2, 11 in scene 3; scene 4 is the final one. Only
     * the first value may be 0, and only then scene 0 is the bar. A comma-separated string is accepted too: env
     * variables arrive as strings.
     *
     * @return non-empty-list<int<0, max>> Strictly increasing.
     */
    public function getSceneStageMax(): array
    {
        $value = $this->config['scene_stage_max'] ?? null;
        \is_string($value) and $value = \array_map(\trim(...), \explode(',', $value));

        $error = static fn(): \InvalidArgumentException => new \InvalidArgumentException(\sprintf(
            'Config "hellowin.scene_stage_max" (env HELLOWIN_SCENE_STAGE_MAX) must be a strictly increasing list of '
            . 'integers from 0, like "0,4,8,11"; got %s.',
            \is_array($value) ? (string) \json_encode($value, \JSON_UNESCAPED_UNICODE) : \get_debug_type($value),
        ));

        \is_array($value) && $value !== [] or throw $error();

        $list = [];
        foreach ($value as $stage) {
            \is_string($stage) && \preg_match('/^\d+$/', $stage) === 1 and $stage = (int) $stage;
            \is_int($stage) && $stage > ($list === [] ? -1 : $list[\count($list) - 1]) or throw $error();
            $list[] = $stage;
        }

        /** @var non-empty-list<int<0, max>> $list */
        return $list;
    }

    /**
     * Clicks on the bonfire that move it to its next stage: the whole room clicks it, so it takes more than a pumpkin.
     *
     * @return positive-int
     */
    public function getBonfireClicksPerStage(): int
    {
        return self::positiveInt(
            $this->config['bonfire_clicks_per_stage'] ?? null,
            'bonfire_clicks_per_stage',
            'HELLOWIN_BONFIRE_CLICKS_PER_STAGE',
        );
    }

    /**
     * The last bonfire stage of every scene, cumulative like {@see getSceneStageMax()}: `[0, 0, 4, 7]` leaves the
     * bonfire out in the bar and scene 1 and takes it to stage 4 in scene 2, to 7 in scene 3. Scenes past the list keep
     * its last value.
     *
     * @return non-empty-list<int<0, max>> Never decreasing.
     */
    public function getBonfireStageMax(): array
    {
        $value = $this->config['bonfire_stage_max'] ?? null;
        \is_string($value) and $value = \array_map(\trim(...), \explode(',', $value));

        $error = static fn(): \InvalidArgumentException => new \InvalidArgumentException(\sprintf(
            'Config "hellowin.bonfire_stage_max" (env HELLOWIN_BONFIRE_STAGE_MAX) must be a non-decreasing list of '
            . 'integers from 0, like "0,0,4,7"; got %s.',
            \is_array($value) ? (string) \json_encode($value, \JSON_UNESCAPED_UNICODE) : \get_debug_type($value),
        ));

        \is_array($value) && $value !== [] or throw $error();

        $list = [];
        foreach ($value as $stage) {
            \is_string($stage) && \preg_match('/^\d+$/', $stage) === 1 and $stage = (int) $stage;
            \is_int($stage) && $stage >= ($list === [] ? 0 : $list[\count($list) - 1]) or throw $error();
            $list[] = $stage;
        }

        /** @var non-empty-list<int<0, max>> $list */
        return $list;
    }

    /**
     * Clicks on the magic circle that move it to its next stage: the whole room draws it, like the bonfire.
     *
     * @return positive-int
     */
    public function getCircleClicksPerStage(): int
    {
        return self::positiveInt(
            $this->config['circle_clicks_per_stage'] ?? null,
            'circle_clicks_per_stage',
            'HELLOWIN_CIRCLE_CLICKS_PER_STAGE',
        );
    }

    /**
     * @return positive-int The last stage of the magic circle: it is closed there, and the final scene is ready.
     */
    public function getCircleStages(): int
    {
        return self::positiveInt($this->config['circle_stages'] ?? null, 'circle_stages', 'HELLOWIN_CIRCLE_STAGES');
    }

    /**
     * @return non-empty-string
     */
    public function getValkeyDsn(): string
    {
        return self::nonEmptyString($this->config['valkey_dsn'] ?? null, 'valkey_dsn');
    }

    /**
     * RPC limits by group of methods. A value is `"<per second>,<burst>"`; 0 or an empty one turns the group's limit
     * off, and the group is left out.
     *
     * @return array<non-empty-string, TokenBucket>
     */
    public function getRpcRates(): array
    {
        $groups = $this->config['rpc_rate'] ?? [];
        \is_array($groups) or throw new \InvalidArgumentException('Config "hellowin.rpc_rate" must be an array.');

        $buckets = [];
        /** @var mixed $value */
        foreach ($groups as $group => $value) {
            \is_string($group) && $group !== '' or throw new \InvalidArgumentException(
                'Config "hellowin.rpc_rate" must map groups of methods to limits.',
            );
            \is_int($value) and $value = (string) $value;
            \is_string($value) and $value = \trim($value);
            if (\in_array($value, ['', '0', null, false], true)) {
                continue;
            }

            $match = [];
            \is_string($value) && \preg_match('/^(\d+(?:\.\d+)?)\s*,\s*(\d+)$/', $value, $match);
            $rate = (float) ($match[1] ?? 0);
            $burst = (int) ($match[2] ?? 0);
            $rate > 0 && $burst > 0 or throw new \InvalidArgumentException(\sprintf(
                'Config "hellowin.rpc_rate.%1$s" (env HELLOWIN_RPC_RATE_%2$s) must be "<per second>,<burst>" with '
                    . 'both above zero, like "20,40", or 0 to turn the limit off; got %3$s.',
                $group,
                \strtoupper($group),
                \is_string($value) ? \sprintf('"%s"', $value) : \get_debug_type($value),
            ));

            $buckets[$group] = new TokenBucket($rate, $burst);
        }

        return $buckets;
    }

    /**
     * The secret that makes a connection the moderator's.
     *
     * @return non-empty-string|null Null turns the console off: nobody is the moderator.
     */
    public function getModeratorSecret(): ?string
    {
        /** @var mixed $value */
        $value = $this->config['moderator_secret'] ?? null;
        $value === null || \is_string($value) or throw new \InvalidArgumentException(
            'Config "hellowin.moderator_secret" (env HELLOWIN_MODERATOR_SECRET) must be a string.',
        );
        $value = \trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return float The horizon line in percents of the screen from the top.
     */
    public function getHorizon(): float
    {
        return self::float($this->config['horizon'] ?? null, 'horizon');
    }

    /**
     * @return list<Element>
     */
    public function getElements(): array
    {
        $elements = $this->config['elements'] ?? [];
        \is_array($elements) or throw new \InvalidArgumentException('Config "hellowin.elements" must be a list.');

        $zone = $this->getWalkZone();
        $reach = $this->getWalkReach();

        return \array_values(\array_map(static function (mixed $spec) use ($zone, $reach): Element {
            $element = self::element($spec);

            return $element->centrepiece() || $zone->distance(new Point($element->x, $element->y)) > $reach
                ? $element
                : new Element($element->id, $element->type, $element->x, $element->y, $element->emojis, $element->size, true);
        }, $elements));
    }

    /**
     * A bush without `depth` launches the nearest flyers.
     *
     * @param positive-int $depths Number of flyer depths: the length of `HELLOWIN_FLYER_DURATION`.
     *
     * @return list<Bush>
     */
    public function getBushes(int $depths): array
    {
        $bushes = $this->config['bushes'] ?? [];
        \is_array($bushes) or throw new \InvalidArgumentException('Config "hellowin.bushes" must be a list.');

        return \array_values(\array_map(static function (mixed $spec) use ($depths): Bush {
            \is_array($spec) or throw new \InvalidArgumentException('Each bush of "hellowin.bushes" must be an array.');
            $id = self::nonEmptyString($spec['id'] ?? null, 'bushes.*.id');
            /** @var mixed $depth */
            $depth = $spec['depth'] ?? $depths - 1;

            \is_int($depth) && $depth >= 0 && $depth < $depths or throw new \InvalidArgumentException(\sprintf(
                'Config "hellowin.bushes" bush "%s" has depth %s, but flyers have %d depths: use an integer from 0 to %d '
                . 'or list more depths in env HELLOWIN_FLYER_DURATION.',
                $id,
                \is_int($depth) ? (string) $depth : \get_debug_type($depth),
                $depths,
                $depths - 1,
            ));

            return new Bush(
                $id,
                new Point(self::float($spec['x'] ?? null, 'bushes.*.x'), self::float($spec['y'] ?? null, 'bushes.*.y')),
                $depth,
            );
        }, $bushes));
    }

    public function getWalkZone(): WalkZone
    {
        $walk = $this->walk();
        $area = $walk['area'] ?? null;
        $patch = $walk['patch'] ?? null;
        \is_array($area) && \is_array($patch) or throw new \InvalidArgumentException(
            'Config "hellowin.walk" must have "area" {x1, y1, x2, y2} and "patch" {cx, cy, rx, ry}.',
        );

        return new WalkZone(
            self::float($area['x1'] ?? null, 'walk.area.x1'),
            self::float($area['y1'] ?? null, 'walk.area.y1'),
            self::float($area['x2'] ?? null, 'walk.area.x2'),
            self::float($area['y2'] ?? null, 'walk.area.y2'),
            self::float($patch['cx'] ?? null, 'walk.patch.cx'),
            self::float($patch['cy'] ?? null, 'walk.patch.cy'),
            self::float($patch['rx'] ?? null, 'walk.patch.rx'),
            self::float($patch['ry'] ?? null, 'walk.patch.ry'),
        );
    }

    public function getItems(): Items
    {
        $items = $this->config['items'] ?? [];
        \is_array($items) or throw new \InvalidArgumentException('Config "hellowin.items" must be a list.');

        $finale = $this->config['finale_items'] ?? [];
        \is_array($finale) or throw new \InvalidArgumentException('Config "hellowin.finale_items" must be an array.');

        $byStep = [];
        /** @var mixed $item */
        foreach ($finale as $step => $item) {
            \is_int($step) && $step >= 0 && self::isItem($item) or throw new \InvalidArgumentException(
                'Config "hellowin.finale_items" must map finale steps to item ids (slugs `a-z 0-9 -`) or null.',
            );
            /** @var non-empty-string|null $item */
            $byStep[$step] = $item;
        }

        return new Items(\array_values(\array_map(static function (mixed $item): ?string {
            self::isItem($item) or throw new \InvalidArgumentException(
                'Config "hellowin.items" must list item ids (slugs `a-z 0-9 -`) or null by scene.',
            );

            /** @var non-empty-string|null $item */
            return $item;
        }, $items)), $byStep);
    }

    /**
     * @return array{float, float} Top and bottom of the band along the bottom of the lawn new characters appear in.
     */
    public function getWalkSpawn(): array
    {
        $spawn = $this->walk()['spawn'] ?? null;
        \is_array($spawn) or throw new \InvalidArgumentException('Config "hellowin.walk.spawn" must be {y1, y2}.');
        $y1 = self::float($spawn['y1'] ?? null, 'walk.spawn.y1');
        $y2 = self::float($spawn['y2'] ?? null, 'walk.spawn.y2');
        $y1 <= $y2 or throw new \InvalidArgumentException('Config "hellowin.walk.spawn" needs y1 <= y2.');

        return [$y1, $y2];
    }

    /**
     * @return float Percents of the screen per second along the path.
     */
    public function getWalkSpeed(): float
    {
        /** @var mixed $value */
        $value = $this->walk()['speed'] ?? null;
        \is_string($value) && \is_numeric(\trim($value)) and $value = (float) \trim($value);
        \is_int($value) and $value = (float) $value;

        return \is_float($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.walk.speed" (env HELLOWIN_WALK_SPEED) must be a positive number.');
    }

    /**
     * @return positive-int Milliseconds without connections before a character leaves the lawn.
     */
    public function getWalkLeaveAfter(): int
    {
        return self::positiveInt($this->walk()['leave_after'] ?? null, 'walk.leave_after', 'HELLOWIN_WALK_LEAVE_AFTER');
    }

    /**
     * @return float Percents of the screen: a pumpkin at most this far from the lawn is reachable for a clink.
     */
    public function getWalkReach(): float
    {
        return self::nonNegative($this->walk()['reach'] ?? 10, 'walk.reach');
    }

    /**
     * @return float Percents of the screen between two characters that clink.
     */
    public function getClinkGap(): float
    {
        return self::nonNegative($this->walk()['clink_gap'] ?? 4, 'walk.clink_gap');
    }

    /**
     * @return positive-int Milliseconds a clink plays: a new clink of the same player is refused meanwhile.
     */
    public function getClinkDuration(): int
    {
        /** @var mixed $value */
        $value = $this->walk()['clink_duration'] ?? 3200;

        return \is_int($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.walk.clink_duration" must be a positive integer.');
    }

    private static function nonNegative(mixed $value, string $key): float
    {
        $value = self::float($value, $key);

        return $value >= 0.0
            ? $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must not be negative.', $key));
    }

    private static function isItem(mixed $item): bool
    {
        return $item === null || \is_string($item) && \preg_match('/^[a-z0-9-]{1,24}$/', $item) === 1;
    }

    private static function element(mixed $spec): Element
    {
        \is_array($spec) or throw new \InvalidArgumentException('Each element of "hellowin.elements" must be an array.');

        $emojis = $spec['emojis'] ?? null;
        \is_array($emojis) && $emojis !== [] or throw new \InvalidArgumentException('Element emojis must be a non-empty list.');

        $type = $spec['type'] ?? null;
        \in_array($type, [Element::TYPE_PUMPKIN, ...Element::CENTREPIECES], true) or throw new \InvalidArgumentException(
            'Config "hellowin.elements.*.type" must be "pumpkin", "bonfire" or "circle".',
        );

        return new Element(
            id: self::nonEmptyString($spec['id'] ?? null, 'elements.*.id'),
            type: $type,
            x: self::float($spec['x'] ?? null, 'elements.*.x'),
            y: self::float($spec['y'] ?? null, 'elements.*.y'),
            size: $type === Element::TYPE_PUMPKIN ? self::size($spec['size'] ?? null) : null,
            emojis: \array_values(\array_map(
                static fn(mixed $emoji): string => self::nonEmptyString($emoji, 'elements.*.emojis'),
                $emojis,
            )),
        );
    }

    /**
     * @return non-empty-string
     */
    private static function nonEmptyString(mixed $value, string $key): string
    {
        return \is_string($value) && $value !== ''
            ? $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must be a non-empty string.', $key));
    }

    /**
     * @return positive-int
     */
    private static function positiveInt(mixed $value, string $key, string $env): int
    {
        \is_string($value) && \preg_match('/^\s*\d+\s*$/', $value) === 1 and $value = (int) $value;

        return \is_int($value) && $value > 0
            ? $value
            : throw new \InvalidArgumentException(\sprintf(
                'Config "hellowin.%s" (env %s) must be a positive integer, got %s.',
                $key,
                $env,
                \is_string($value) ? \sprintf('"%s"', $value) : \get_debug_type($value),
            ));
    }

    /**
     * @return 's'|'m'|'l'
     */
    private static function size(mixed $value): string
    {
        return \in_array($value, ['s', 'm', 'l'], true)
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.elements.*.size" must be one of "s", "m", "l".');
    }

    private static function float(mixed $value, string $key): float
    {
        return \is_int($value) || \is_float($value)
            ? (float) $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must be a number.', $key));
    }

    private function walk(): array
    {
        $walk = $this->config['walk'] ?? [];
        \is_array($walk) or throw new \InvalidArgumentException('Config "hellowin.walk" must be an array.');

        return $walk;
    }
}
