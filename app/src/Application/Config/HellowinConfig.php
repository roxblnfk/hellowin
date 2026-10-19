<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Element\Element;
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
        'scene_stage_max' => '4,6,8',
        'valkey_dsn' => 'tcp://valkey:6379',
        'elements' => [],
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
     * @return list<Element>
     */
    public function getElements(): array
    {
        $elements = $this->config['elements'] ?? [];
        \is_array($elements) or throw new \InvalidArgumentException('Config "hellowin.elements" must be a list.');

        return \array_values(\array_map(self::element(...), $elements));
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
}
