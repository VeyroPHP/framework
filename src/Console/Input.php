<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * Parsed command-line input for one command.
 *
 * Rules:
 * - Options are long only: --flag, --name=value or --name value.
 * - Everything after "--" is positional, even if it starts with "-".
 * - Unknown options, extra arguments, missing required arguments, a value for a
 *   flag or a missing value are usage errors.
 * - A repeated option keeps its last value.
 */
final class Input
{
    /** @var resource */
    private $stdin;

    /**
     * @param array<string, string|null> $arguments
     * @param array<string, string|bool|null> $options
     * @param resource|null $stdin
     */
    private function __construct(
        private readonly array $arguments,
        private readonly array $options,
        $stdin = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
    }

    /**
     * @param list<string> $tokens the words after the command name
     * @param list<Argument|Option> $definition
     * @param resource|null $stdin
     *
     * @throws UsageException
     */
    public static function parse(array $tokens, array $definition, $stdin = null): self
    {
        $argumentDefinitions = [];
        $optionDefinitions = [];

        foreach ($definition as $item) {
            if ($item instanceof Argument) {
                $argumentDefinitions[] = $item;
            } else {
                $optionDefinitions[$item->name] = $item;
            }
        }

        $positional = [];
        $options = [];
        $onlyPositional = false;

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($onlyPositional || $token === '-' || !str_starts_with($token, '-')) {
                $positional[] = $token;
                continue;
            }

            if ($token === '--') {
                $onlyPositional = true;
                continue;
            }

            if (!str_starts_with($token, '--')) {
                throw new UsageException(sprintf('Unknown option "%s". Options are written as --name.', $token));
            }

            $parts = explode('=', substr($token, 2), 2);
            $name = $parts[0];
            $value = $parts[1] ?? null;
            $option = $optionDefinitions[$name] ?? throw new UsageException(self::unknownOption($name, array_keys($optionDefinitions)));

            if (!$option->acceptsValue) {
                if ($value !== null) {
                    throw new UsageException(sprintf('The --%s option does not take a value.', $name));
                }
                $options[$name] = true;
                continue;
            }

            if ($value === null) {
                if (!isset($tokens[$i + 1]) || str_starts_with($tokens[$i + 1], '--')) {
                    throw new UsageException(sprintf('The --%s option needs a value.', $name));
                }
                $value = $tokens[++$i];
            }

            $options[$name] = $value;
        }

        if (count($positional) > count($argumentDefinitions)) {
            throw new UsageException(sprintf('Too many arguments: "%s" was not expected.', $positional[count($argumentDefinitions)]));
        }

        $arguments = [];
        foreach ($argumentDefinitions as $index => $argument) {
            if (!array_key_exists($index, $positional) && $argument->required) {
                throw new UsageException(sprintf('Missing required argument <%s>.', $argument->name));
            }
            $arguments[$argument->name] = $positional[$index] ?? $argument->default;
        }

        foreach ($optionDefinitions as $name => $option) {
            $options[$name] ??= $option->acceptsValue ? $option->default : false;
        }

        return new self($arguments, $options, $stdin);
    }

    public function argument(string $name): ?string
    {
        return array_key_exists($name, $this->arguments)
            ? $this->arguments[$name]
            : throw new ConsoleException(sprintf('The command has no argument "%s".', $name));
    }

    /**
     * The value of an option that accepts one (null when not given and without default).
     */
    public function option(string $name): ?string
    {
        $value = $this->defined($name);

        return is_bool($value) ? throw new ConsoleException(sprintf('--%s is a flag; use flag().', $name)) : $value;
    }

    public function flag(string $name): bool
    {
        $value = $this->defined($name);

        return is_bool($value) ? $value : throw new ConsoleException(sprintf('--%s takes a value; use option().', $name));
    }

    /**
     * A positive integer option, e.g. --step=2.
     *
     * @throws UsageException when the value is not a positive whole number
     */
    public function positiveInt(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        return ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : throw new UsageException(sprintf('The --%s option must be a positive whole number.', $name));
    }

    /**
     * One line from standard input without the line ending, or null at end of input.
     */
    public function readLine(): ?string
    {
        $line = fgets($this->stdin);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    private function defined(string $name): string|bool|null
    {
        return array_key_exists($name, $this->options)
            ? $this->options[$name]
            : throw new ConsoleException(sprintf('The command has no option "--%s".', $name));
    }

    /**
     * @param list<string> $known
     */
    private static function unknownOption(string $name, array $known): string
    {
        $message = sprintf('Unknown option "--%s".', $name);
        $suggestion = Suggest::closest($name, $known);

        return $suggestion === null ? $message : $message . sprintf(' Did you mean --%s?', $suggestion);
    }
}
