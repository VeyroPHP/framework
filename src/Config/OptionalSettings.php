<?php

declare(strict_types=1);

namespace VeyroPHP\Config;

use VeyroPHP\Config\Exception\ConfigException;

/**
 * @internal reads optional settings for service providers (HTTP-5): a missing key or null
 * gives the default, and any other value of the wrong type throws ConfigException naming
 * the key. Before, wrong types were silently replaced by defaults, so a string
 * hsts_seconds turned HSTS off without a word.
 */
final class OptionalSettings
{
    public static function string(Config $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return $value === null ? $default : (is_string($value) ? $value : throw ConfigException::wrongType($key, 'string', $value));
    }

    public static function nullableString(Config $config, string $key): ?string
    {
        $value = $config->get($key);

        return $value === null || is_string($value) ? $value : throw ConfigException::wrongType($key, 'string or null', $value);
    }

    public static function int(Config $config, string $key, int $default, int $min = PHP_INT_MIN): int
    {
        $value = $config->get($key);
        $value = $value === null ? $default : (is_int($value) ? $value : throw ConfigException::wrongType($key, 'int', $value));

        return $value >= $min ? $value : throw ConfigException::invalidValue($key, sprintf('at least %d', $min));
    }

    public static function bool(Config $config, string $key, bool $default): bool
    {
        $value = $config->get($key);

        return $value === null ? $default : (is_bool($value) ? $value : throw ConfigException::wrongType($key, 'bool', $value));
    }

    public static function nullableBool(Config $config, string $key): ?bool
    {
        $value = $config->get($key);

        return $value === null || is_bool($value) ? $value : throw ConfigException::wrongType($key, 'bool or null', $value);
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public static function stringList(Config $config, string $key, array $default = []): array
    {
        $value = $config->get($key);

        if ($value === null) {
            return $default;
        }

        if (!is_array($value)) {
            throw ConfigException::wrongType($key, 'list of strings', $value);
        }

        $list = [];

        foreach ($value as $index => $item) {
            $list[] = is_string($item) ? $item : throw ConfigException::wrongType($key . '.' . $index, 'string', $item);
        }

        return $list;
    }

    /**
     * Names to string-or-null values, such as header => value.
     *
     * @return array<string, string|null>
     */
    public static function nullableStringMap(Config $config, string $key): array
    {
        $value = $config->get($key);

        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw ConfigException::wrongType($key, 'array of name => string or null', $value);
        }

        $map = [];

        foreach ($value as $name => $item) {
            if (!is_string($name)) {
                throw ConfigException::invalidValue($key, 'an array with names as keys, not a list');
            }

            $map[$name] = $item === null || is_string($item) ? $item : throw ConfigException::wrongType($key . '.' . $name, 'string or null', $item);
        }

        return $map;
    }
}
