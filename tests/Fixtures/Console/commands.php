<?php

declare(strict_types=1);

// Commands used by the console tests.

namespace VeyroPHP\Tests\Fixtures\Console;

use RuntimeException;
use VeyroPHP\Console\Argument;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;
use VeyroPHP\Validation\Validator;

final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello, ' . $name . '!';
    }
}

#[AsCommand('greet', 'Greet someone')]
final class GreetCommand extends Command
{
    public static int $constructed = 0;

    public function __construct(private readonly Greeter $greeter)
    {
        self::$constructed++;
    }

    public function definition(): array
    {
        return [
            Argument::required('name', 'Who to greet'),
            Argument::optional('punctuation', 'Ending', '!'),
            Option::flag('shout', 'Use capitals'),
            Option::value('times', 'Repeat count', '1'),
        ];
    }

    public function help(): string
    {
        return 'Example: php veyro greet Asha --times=2';
    }

    public function execute(Input $input, Output $output): int
    {
        $text = $this->greeter->greet((string) $input->argument('name'));
        $text = $input->flag('shout') ? strtoupper($text) : $text;

        for ($i = 0; $i < ($input->positiveInt('times') ?? 1); $i++) {
            $output->line($text);
        }

        return self::SUCCESS;
    }
}

#[AsCommand('cache:clear', 'Clear the cache')]
final class CacheClearCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        $output->success('cleared');

        return self::SUCCESS;
    }
}

#[AsCommand('cache:warm', 'Warm the cache')]
final class CacheWarmCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        return 3;
    }
}

#[AsCommand('fail')]
final class FailingCommand extends Command
{
    public function definition(): array
    {
        return [Option::value('mode', 'crash, usage, invalid, code', 'crash')];
    }

    public function execute(Input $input, Output $output): int
    {
        return match ($input->option('mode')) {
            'usage' => throw new UsageException('The --mode value is not allowed here.'),
            'invalid' => (new Validator())->validate(['email' => 'nope'], ['email' => 'required|email']) === [] ? 0 : 0,
            'code' => 300,
            default => throw new RuntimeException('Disk is full'),
        };
    }
}

#[AsCommand('echo-line', 'Echo one line from standard input')]
final class EchoLineCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        $output->line('got: ' . ($input->readLine() ?? '(eof)'));
        $output->line('then: ' . ($input->readLine() ?? '(eof)'));

        return self::SUCCESS;
    }
}

final class NotACommand {}

final class UnnamedCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        return self::SUCCESS;
    }
}

#[AsCommand('Bad Name')]
final class BadNameCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        return self::SUCCESS;
    }
}

#[AsCommand('list')]
final class ReservedNameCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        return self::SUCCESS;
    }
}

#[AsCommand('greet')]
final class DuplicateGreetCommand extends Command
{
    public function execute(Input $input, Output $output): int
    {
        return self::SUCCESS;
    }
}
