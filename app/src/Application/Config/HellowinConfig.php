<?php

declare(strict_types=1);

namespace App\Application\Config;

use App\Domain\Element\Element;
use App\Domain\Game\Thresholds;
use Spiral\Core\InjectableConfig;

/**
 * @see app/config/hellowin.php
 *
 * @psalm-type ElementSpec = array{id: non-empty-string, type: non-empty-string, x: int|float, y: int|float, emojis: non-empty-list<non-empty-string>}
 */
final class HellowinConfig extends InjectableConfig
{
    public const CONFIG = 'hellowin';

    /**
     * @var array{
     *     channel: non-empty-string,
     *     storage: non-empty-string,
     *     centrifugo_url: string,
     *     thresholds: int|string|list<int>,
     *     elements: list<ElementSpec>,
     * }
     */
    protected array $config = [
        'channel' => 'hellowin',
        'storage' => 'hellowin',
        'centrifugo_url' => '/connection/websocket',
        'thresholds' => [100, 500, 2000],
        'elements' => [],
    ];

    /**
     * @return non-empty-string
     */
    public function getChannel(): string
    {
        return $this->config['channel'];
    }

    /**
     * Cache storage holding the game state.
     *
     * @return non-empty-string
     */
    public function getStorage(): string
    {
        return $this->config['storage'];
    }

    public function getCentrifugoUrl(): string
    {
        return $this->config['centrifugo_url'];
    }

    /**
     * Accepts a list or a comma-separated string, so the value can come straight from an env variable.
     */
    public function getThresholds(): Thresholds
    {
        $value = $this->config['thresholds'];
        $values = \is_array($value) ? $value : \explode(',', (string) $value);

        return new Thresholds(\array_map(static fn(int|string $v): int => (int) \trim((string) $v), $values));
    }

    /**
     * @return list<Element>
     */
    public function getElements(): array
    {
        return \array_map(
            static fn(array $spec): Element => new Element(
                id: $spec['id'],
                type: $spec['type'],
                x: (float) $spec['x'],
                y: (float) $spec['y'],
                emojis: $spec['emojis'],
            ),
            $this->config['elements'],
        );
    }
}
