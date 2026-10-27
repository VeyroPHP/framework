<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * A positional command argument. Required arguments must come before optional ones.
 */
final class Argument
{
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $required,
        public readonly ?string $default,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
            throw new ConsoleException(sprintf('Invalid argument name "%s".', $name));
        }
    }

    public static function required(string $name, string $description = ''): self
    {
        return new self($name, $description, true, null);
    }

    public static function optional(string $name, string $description = '', ?string $default = null): self
    {
        return new self($name, $description, false, $default);
    }

    public function synopsis(): string
    {
        return $this->required ? '<' . $this->name . '>' : '[<' . $this->name . '>]';
    }
}
