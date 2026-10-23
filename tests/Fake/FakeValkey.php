<?php

declare(strict_types=1);

namespace Tests\Fake;

use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * A Valkey of the commands the app sends itself — `incr`, `expire`, `get`, `del` — in an array; TTLs are recorded,
 * not enforced. {@see $down} makes every command fail as a lost connection does.
 */
final class FakeValkey implements ClientInterface
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, int> Seconds by key, as the last `expire` set them. */
    public array $ttls = [];

    public bool $down = false;

    #[\Override]
    public function getCommandFactory()
    {
        throw new \LogicException('Not faked.');
    }

    #[\Override]
    public function getOptions()
    {
        throw new \LogicException('Not faked.');
    }

    #[\Override]
    public function connect(): void {}

    #[\Override]
    public function disconnect(): void {}

    #[\Override]
    public function getConnection()
    {
        throw new \LogicException('Not faked.');
    }

    #[\Override]
    public function createCommand($method, $arguments = [])
    {
        throw new \LogicException('Not faked.');
    }

    #[\Override]
    public function executeCommand(CommandInterface $command)
    {
        throw new \LogicException('Not faked.');
    }

    #[\Override]
    public function __call($method, $arguments)
    {
        $this->down and throw new \RuntimeException('Connection refused.');
        /** @var list<string|int> $arguments */
        $key = (string) ($arguments[0] ?? '');

        switch (\strtolower((string) $method)) {
            case 'incr':
                $value = (int) ($this->values[$key] ?? 0) + 1;
                $this->values[$key] = (string) $value;
                return $value;
            case 'expire':
                $this->ttls[$key] = (int) ($arguments[1] ?? 0);
                return 1;
            case 'get':
                return $this->values[$key] ?? null;
            case 'del':
                $deleted = 0;
                foreach ($arguments as $name) {
                    $name = (string) $name;
                    $deleted += (int) isset($this->values[$name]);
                    unset($this->values[$name], $this->ttls[$name]);
                }
                return $deleted;
        }

        throw new \LogicException(\sprintf('The command "%s" is not faked.', (string) $method));
    }
}
