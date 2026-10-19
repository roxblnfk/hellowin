<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Element\Element;
use App\Domain\Game\Thresholds;
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
        'thresholds' => [5, 10, 15],
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
     * Accepts a list or a comma-separated string, so the value can come straight from an env variable.
     */
    public function getThresholds(): Thresholds
    {
        return self::thresholds($this->config['thresholds'] ?? null);
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

    private static function thresholds(mixed $value): Thresholds
    {
        $values = match (true) {
            \is_array($value) => $value,
            \is_int($value) => [$value],
            \is_string($value) => \explode(',', $value),
            default => throw new \InvalidArgumentException('Config "hellowin.thresholds" must be a list or a string.'),
        };

        return new Thresholds(\array_values(\array_map(
            static fn(mixed $v): int => \is_numeric($v) ? (int) $v : throw new \InvalidArgumentException(
                'Config "hellowin.thresholds" must contain integers.',
            ),
            $values,
        )));
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

    private static function float(mixed $value, string $key): float
    {
        return \is_int($value) || \is_float($value)
            ? (float) $value
            : throw new \InvalidArgumentException(\sprintf('Config "hellowin.%s" must be a number.', $key));
    }
}
