<?php

declare(strict_types=1);

namespace VeyroPHP\Config;

use VeyroPHP\Config\Exception\ConfigException;

/**
 * Configuration values with dot-notation access. Existing values never change;
 * packages may only add defaults for keys the application did not set
 * (mergeDefaults(), during provider registration).
 *
 * "database.connections.mysql.host" reads $items['database']['connections']['mysql']['host'].
 * Array keys that themselves contain a dot cannot be addressed.
 *
 * The typed getters are strict: they throw when the key is missing or the value
 * has a different type. No value is ever converted, except that float() accepts
 * integers.
 */
final class Config
{
    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(private array $items = []) {}

    public function has(string $key): bool
    {
        return $this->find($key)[0];
    }

    /**
     * Returns the value, or $default when the key is not defined.
     *
     * A key that is defined with the value null returns null, not $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : $default;
    }

    /**
     * @throws ConfigException when the key is missing or the value is not a string
     */
    public function string(string $key): string
    {
        $value = $this->require($key);

        return is_string($value) ? $value : throw ConfigException::wrongType($key, 'string', $value);
    }

    /**
     * @throws ConfigException when the key is missing or the value is not an int
     */
    public function int(string $key): int
    {
        $value = $this->require($key);

        return is_int($value) ? $value : throw ConfigException::wrongType($key, 'int', $value);
    }

    /**
     * @throws ConfigException when the key is missing or the value is not a float or int
     */
    public function float(string $key): float
    {
        $value = $this->require($key);

        return is_float($value) || is_int($value) ? (float) $value : throw ConfigException::wrongType($key, 'float', $value);
    }

    /**
     * @throws ConfigException when the key is missing or the value is not a bool
     */
    public function bool(string $key): bool
    {
        $value = $this->require($key);

        return is_bool($value) ? $value : throw ConfigException::wrongType($key, 'bool', $value);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConfigException when the key is missing or the value is not an array
     */
    public function array(string $key): array
    {
        $value = $this->require($key);

        return is_array($value) ? $value : throw ConfigException::wrongType($key, 'array', $value);
    }

    /**
     * Adds a package's default settings under $key. Values the application
     * already has always win: nested arrays are merged key by key, and lists
     * (and every other value) set by the application are kept as they are.
     *
     * @param array<array-key, mixed> $defaults
     */
    public function mergeDefaults(string $key, array $defaults): void
    {
        $segments = self::segments($key);

        if (count($segments) !== 1) {
            throw ConfigException::invalidKey($key);
        }

        $existing = $this->items[$key] ?? null;
        $this->items[$key] = is_array($existing) ? self::merge($defaults, $existing) : ($existing ?? $defaults);
    }

    /**
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $values): array
    {
        if (array_is_list($values) && $values !== []) {
            return $values;
        }

        foreach ($defaults as $name => $default) {
            if (!array_key_exists($name, $values)) {
                $values[$name] = $default;
            } elseif (is_array($default) && is_array($values[$name])) {
                $values[$name] = self::merge($default, $values[$name]);
            }
        }

        return $values;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    private function require(string $key): mixed
    {
        [$found, $value] = $this->find($key);

        return $found ? $value : throw ConfigException::missingKey($key);
    }

    /**
     * @return array{bool, mixed}
     */
    private function find(string $key): array
    {
        $current = $this->items;

        foreach (self::segments($key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * @return non-empty-list<string>
     */
    private static function segments(string $key): array
    {
        $segments = explode('.', $key);

        if (in_array('', $segments, true)) {
            throw ConfigException::invalidKey($key);
        }

        return $segments;
    }
}
