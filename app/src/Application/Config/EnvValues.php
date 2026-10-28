<?php

declare(strict_types=1);

namespace App\Application\Config;

/**
 * Parsing of config values that come from env variables, and so arrive as strings.
 */
trait EnvValues
{
    /**
     * @return positive-int
     */
    private static function positiveInt(mixed $value, string $key, string $env): int
    {
        \is_string($value) && \preg_match('/^\s*\d+\s*$/', $value) === 1 and $value = (int) $value;

        return \is_int($value) && $value > 0
            ? $value
            : throw self::invalid($key, $env, 'a positive integer', $value);
    }

    /**
     * @return int<0, max>
     */
    private static function nonNegativeInt(mixed $value, string $key, string $env): int
    {
        \is_string($value) && \preg_match('/^\s*\d+\s*$/', $value) === 1 and $value = (int) $value;

        return \is_int($value) && $value >= 0
            ? $value
            : throw self::invalid($key, $env, 'a non-negative integer', $value);
    }

    private static function invalid(string $key, string $env, string $expected, mixed $value): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            'Config "%s.%s" (env %s) must be %s, got %s.',
            self::CONFIG,
            $key,
            $env,
            $expected,
            \is_string($value) ? \sprintf('"%s"', $value) : \get_debug_type($value),
        ));
    }
}
