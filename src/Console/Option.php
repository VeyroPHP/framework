<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * A long option: a flag (--force) or an option with a value (--step=2 or --step 2).
 */
final class Option
{
    private function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $acceptsValue,
        public readonly ?string $default,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
            throw new ConsoleException(sprintf('Invalid option name "%s".', $name));
        }

        if ($name === 'help') {
            throw new ConsoleException('The option name "help" is reserved.');
        }
    }

    public static function flag(string $name, string $description = ''): self
    {
        return new self($name, $description, false, null);
    }

    public static function value(string $name, string $description = '', ?string $default = null): self
    {
        return new self($name, $description, true, $default);
    }

    public function synopsis(): string
    {
        return '[--' . $this->name . ($this->acceptsValue ? '=' . strtoupper(str_replace('-', '_', $this->name)) : '') . ']';
    }
}
