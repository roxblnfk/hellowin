<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Element\Element;
use App\Domain\Flyer\Bush;
use App\Domain\Flyer\Point;
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
        'centrifugo_url' => '/connection/websocket',
        'clicks_per_stage' => 5,
        'reset_rpc' => true,
        'scene_stage_max' => '4,6,8',
        'valkey_dsn' => 'tcp://valkey:6379',
        'elements' => [],
        'bushes' => [],
        'horizon' => 38,
        'items' => [],
        'walk' => [
            'area' => ['x1' => 3, 'y1' => 44, 'x2' => 97, 'y2' => 97],
            'patch' => ['cx' => 50, 'cy' => 63, 'rx' => 31, 'ry' => 25],
            'spawn' => ['y1' => 86, 'y2' => 90],
            'speed' => 12,
            'leave_after' => 5000,
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

    public function getCentrifugoUrl(): string
    {
        return self::nonEmptyString($this->config['centrifugo_url'] ?? null, 'centrifugo_url');
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
     * The last pumpkin stage of every scene where pumpkins move, cumulative: `[4, 6, 8]` takes pumpkins to stage 4
     * in scene 0, to 6 in scene 1, to 8 in scene 2; scene 3 is the final one. A comma-separated string is accepted
     * too: env variables arrive as strings.
     *
     * @return non-empty-list<positive-int> Strictly increasing.
     */
    public function getSceneStageMax(): array
    {
        $value = $this->config['scene_stage_max'] ?? null;
        \is_string($value) and $value = \array_map(\trim(...), \explode(',', $value));

        $error = static fn(): \InvalidArgumentException => new \InvalidArgumentException(\sprintf(
            'Config "hellowin.scene_stage_max" (env HELLOWIN_SCENE_STAGE_MAX) must be a strictly increasing list of '
            . 'positive integers, like "4,6,8"; got %s.',
            \is_array($value) ? (string) \json_encode($value, \JSON_UNESCAPED_UNICODE) : \get_debug_type($value),
        ));

        \is_array($value) && $value !== [] or throw $error();

        $list = [];
        foreach ($value as $stage) {
            \is_string($stage) && \preg_match('/^\d+$/', $stage) === 1 and $stage = (int) $stage;
            \is_int($stage) && $stage > ($list === [] ? 0 : $list[\count($list) - 1]) or throw $error();
            $list[] = $stage;
        }

        /** @var non-empty-list<positive-int> $list */
        return $list;
    }

    /**
     * @return non-empty-string
     */
    public function getValkeyDsn(): string
    {
        return self::nonEmptyString($this->config['valkey_dsn'] ?? null, 'valkey_dsn');
    }

    /**
     * Whether clients may restart the ritual with RPC `reset`.
     */
    public function isResetRpcEnabled(): bool
    {
        /** @var mixed $value */
        $value = $this->config['reset_rpc'] ?? null;
        \is_string($value) and $value = \filter_var(\trim($value), \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);

        return \is_bool($value)
            ? $value
            : throw new \InvalidArgumentException('Config "hellowin.reset_rpc" (env HELLOWIN_RESET_RPC) must be true or false.');
    }

    /**
     * @return list<Element>
     */
    public function getElements(): array
    {
        $elements = $this->config['elements'] ?? [];
        \is_array($elements) or throw new \InvalidArgumentException('Config "hellowin.elements" must be a list.');

        return \array_values(\array_map(self::element(...), $elements));
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

        return new Items(\array_values(\array_map(static function (mixed $item): ?string {
            $item === null || \is_string($item) && \preg_match('/^[a-z0-9-]{1,24}$/', $item) === 1 or throw new \InvalidArgumentException(
                'Config "hellowin.items" must list item ids (slugs `a-z 0-9 -`) or null by scene.',
            );

            /** @var non-empty-string|null $item */
            return $item;
        }, $items)));
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

    private static function element(mixed $spec): Element
    {
        \is_array($spec) or throw new \InvalidArgumentException('Each element of "hellowin.elements" must be an array.');

        $emojis = $spec['emojis'] ?? null;
        \is_array($emojis) && $emojis !== [] or throw new \InvalidArgumentException('Element emojis must be a non-empty list.');

        return new Element(
            id: self::nonEmptyString($spec['id'] ?? null, 'elements.*.id'),
            type: self::nonEmptyString($spec['type'] ?? null, 'elements.*.type'),
            x: self::float($spec['x'] ?? null, 'elements.*.x'),
            y: self::float($spec['y'] ?? null, 'elements.*.y'),
            size: self::size($spec['size'] ?? null),
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
