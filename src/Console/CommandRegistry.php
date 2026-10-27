<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

/**
 * Commands added by service providers (packages), merged into the Console
 * when it is first created. Order of registration is kept; duplicates are ignored.
 */
final class CommandRegistry
{
    /** @var list<class-string<Command>> */
    private array $commands = [];

    /**
     * @param class-string<Command> ...$commands
     */
    public function add(string ...$commands): void
    {
        foreach ($commands as $command) {
            if (!is_subclass_of($command, Command::class)) {
                throw new ConsoleException(sprintf('%s is not a command (it must extend %s).', $command, Command::class));
            }

            if (!in_array($command, $this->commands, true)) {
                $this->commands[] = $command;
            }
        }
    }

    /**
     * @return list<class-string<Command>>
     */
    public function all(): array
    {
        return $this->commands;
    }
}
