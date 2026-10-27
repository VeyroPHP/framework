<?php

declare(strict_types=1);

namespace VeyroPHP\Config\Env;

use VeyroPHP\Config\Exception\EnvException;

/**
 * Immutable, typed access to environment variables.
 *
 * Environment values are always strings. The typed getters convert them using
 * strict rules and throw when the value does not match; they never guess.
 * A default is used only when the variable is not set at all.
 */
final class Env
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values = []) {}

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * @throws EnvException when the variable is not set and $default is null
     */
    public function string(string $name, ?string $default = null): string
    {
        return $this->values[$name] ?? $default ?? throw EnvException::missing($name);
    }

    /**
     * Accepts an optional minus sign followed by digits, within the int range.
     *
     * @throws EnvException when missing without default, or not an integer
     */
    public function int(string $name, ?int $default = null): int
    {
        if (!$this->has($name)) {
            return $default ?? throw EnvException::missing($name);
        }

        $value = $this->values[$name];

        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            throw EnvException::invalid($name, 'an integer');
        }

        // filter_var() rejects leading zeros, so strip them before the range check.
        $negative = $value[0] === '-';
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');

        if ($digits === '') {
            return 0;
        }

        $int = filter_var(($negative ? '-' : '') . $digits, FILTER_VALIDATE_INT);

        return is_int($int) ? $int : throw EnvException::invalid($name, 'an integer within the platform int range');
    }

    /**
     * Accepts decimal numbers such as "1", "-0.5" or "2.5e3".
     *
     * @throws EnvException when missing without default, or not a number
     */
    public function float(string $name, ?float $default = null): float
    {
        if (!$this->has($name)) {
            return $default ?? throw EnvException::missing($name);
        }

        $value = $this->values[$name];

        if (preg_match('/^-?(\d+(\.\d*)?|\.\d+)([eE][-+]?\d+)?$/D', $value) !== 1) {
            throw EnvException::invalid($name, 'a number');
        }

        return (float) $value;
    }

    /**
     * Accepts "true", "false", "1" and "0", case-insensitively. Nothing else.
     *
     * @throws EnvException when missing without default, or not a boolean
     */
    public function bool(string $name, ?bool $default = null): bool
    {
        if (!$this->has($name)) {
            return $default ?? throw EnvException::missing($name);
        }

        return match (strtolower($this->values[$name])) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw EnvException::invalid($name, 'one of true, false, 1, 0'),
        };
    }

    /**
     * Returns the raw value, or null when the variable is not set.
     */
    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }
}
