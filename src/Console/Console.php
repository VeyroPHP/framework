<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Throwable;
use VeyroPHP\Container\Container;
use VeyroPHP\Exception\HasErrorDetails;

/**
 * Runs console commands.
 *
 *     php veyro                       lists commands
 *     php veyro help migrate          help for one command (also: migrate --help)
 *     php veyro migrate --force       runs it
 *
 * Exit codes: 0 success, 1 failure (including uncaught exceptions), 2 usage error.
 * Commands are registered by class name and constructed only when they run.
 */
final class Console
{
    private const RESERVED = ['help', 'list'];

    /** @var array<string, array{class: class-string<Command>, description: string}> sorted by name */
    private array $commands = [];

    /**
     * @param list<class-string<Command>> $commands
     *
     * @throws ConsoleException for an invalid or duplicate command
     */
    public function __construct(
        private readonly Container $container,
        array $commands,
        private readonly string $binary = 'veyro',
        private readonly string $version = '',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $debug = false,
    ) {
        foreach ($commands as $class) {
            $this->add($class);
        }
        ksort($this->commands);
    }

    /**
     * @return array<string, string> command name => description
     */
    public function commands(): array
    {
        return array_map(static fn(array $c): string => $c['description'], $this->commands);
    }

    /**
     * @param list<string> $arguments the words after the script name ($argv without $argv[0])
     * @param resource|null $stdin
     */
    public function run(array $arguments, Output $output, $stdin = null): int
    {
        $name = $arguments[0] ?? 'list';
        $rest = array_slice($arguments, 1);

        if ($name === 'list' || $name === '--help' || $name === '-h' || ($name === 'help' && $rest === [])) {
            $this->renderList($output);

            return Command::SUCCESS;
        }

        if ($name === '--version' || $name === '-V') {
            $output->line(trim('VeyroPHP ' . $this->version));

            return Command::SUCCESS;
        }

        if ($name === 'help') {
            $name = $rest[0];
            $rest = ['--help'];
        }

        if (!isset($this->commands[$name])) {
            $suggestion = Suggest::closest($name, array_keys($this->commands));
            $output->error(sprintf('Command "%s" is not defined.', $name) . ($suggestion !== null ? sprintf(' Did you mean "%s"?', $suggestion) : ''));
            $output->error(sprintf('Run "php %s list" to see all commands.', $this->binary));

            return Command::USAGE;
        }

        $class = $this->commands[$name]['class'];

        try {
            $command = $this->container->make($class);

            if (self::wantsHelp($rest)) {
                $this->renderHelp($name, $command, $output);

                return Command::SUCCESS;
            }

            $input = Input::parse($rest, $command->definition(), $stdin);
        } catch (UsageException $e) {
            return $this->usageError($e, $name, $output);
        } catch (Throwable $e) {
            return $this->failure($e, $name, $output);
        }

        try {
            $code = $command->execute($input, $output);
        } catch (UsageException $e) {
            return $this->usageError($e, $name, $output);
        } catch (Throwable $e) {
            return $this->failure($e, $name, $output);
        }

        if ($code < 0 || $code > 255) {
            $output->error(sprintf('Command "%s" returned exit code %d; codes must be 0-255.', $name, $code));

            return Command::FAILURE;
        }

        return $code;
    }

    /**
     * @param class-string<Command> $class
     */
    private function add(string $class): void
    {
        if (!class_exists($class) || !is_subclass_of($class, Command::class)) {
            throw new ConsoleException(sprintf('Console command "%s" must be a class extending %s.', $class, Command::class));
        }

        $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);

        if ($attributes === []) {
            throw new ConsoleException(sprintf('Console command %s has no #[AsCommand] attribute.', $class));
        }

        $meta = $attributes[0]->newInstance();

        if (preg_match('/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)*$/D', $meta->name) !== 1) {
            throw new ConsoleException(sprintf('Invalid command name "%s" on %s; use lowercase words separated by ":" or "-".', $meta->name, $class));
        }

        if (in_array($meta->name, self::RESERVED, true)) {
            throw new ConsoleException(sprintf('The command name "%s" is reserved.', $meta->name));
        }

        if (isset($this->commands[$meta->name])) {
            throw new ConsoleException(sprintf('Command "%s" is registered twice (%s and %s).', $meta->name, $this->commands[$meta->name]['class'], $class));
        }

        $this->commands[$meta->name] = ['class' => $class, 'description' => $meta->description];
    }

    /**
     * @param list<string> $tokens
     */
    private static function wantsHelp(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token === '--') {
                return false;
            }
            if ($token === '--help' || $token === '-h') {
                return true;
            }
        }

        return false;
    }

    private function renderList(Output $output): void
    {
        $output->line(trim('VeyroPHP ' . $this->version));
        $output->line();
        $output->line($output->style('Usage:', 'warning'));
        $output->line(sprintf('  php %s <command> [arguments] [options]', $this->binary));
        $output->line();
        $output->line($output->style('Available commands:', 'warning'));

        $all = ['help' => 'Show help for a command', 'list' => 'List all commands'] + $this->commands();
        ksort($all);
        $width = max(array_map(strlen(...), array_keys($all))) + 2;

        $prefixes = [];
        foreach (array_keys($all) as $name) {
            if (str_contains($name, ':')) {
                $prefixes[explode(':', $name, 2)[0]] = true;
            }
        }

        // Commands without a group first, then each group under its heading.
        // "migrate" belongs to the group of "migrate:status".
        $groups = ['' => []];
        foreach ($all as $name => $description) {
            $first = explode(':', $name, 2)[0];
            $groups[isset($prefixes[$first]) ? $first : ''][$name] = $description;
        }

        foreach ($groups as $prefix => $commands) {
            if ($prefix !== '') {
                $output->line(' ' . $output->style($prefix, 'muted'));
            }
            foreach ($commands as $name => $description) {
                $output->line('  ' . $output->style(str_pad($name, $width), 'success') . $description);
            }
        }
    }

    private function renderHelp(string $name, Command $command, Output $output): void
    {
        $description = $this->commands[$name]['description'];
        $definition = $command->definition();

        if ($description !== '') {
            $output->line($description);
            $output->line();
        }

        $synopsis = array_map(static fn(Argument|Option $item): string => $item->synopsis(), $definition);
        $output->line($output->style('Usage:', 'warning'));
        $output->line(rtrim(sprintf('  php %s %s %s', $this->binary, $name, implode(' ', $synopsis))));

        $arguments = array_filter($definition, static fn(Argument|Option $item): bool => $item instanceof Argument);
        $options = array_filter($definition, static fn(Argument|Option $item): bool => $item instanceof Option);

        $rows = [];
        foreach ($arguments as $argument) {
            $rows['Arguments:'][] = [$argument->name, $argument->description . ($argument->default !== null ? sprintf(' [default: %s]', $argument->default) : '')];
        }
        foreach ($options as $option) {
            $label = '--' . $option->name . ($option->acceptsValue ? '=' . strtoupper(str_replace('-', '_', $option->name)) : '');
            $rows['Options:'][] = [$label, $option->description . ($option->default !== null ? sprintf(' [default: %s]', $option->default) : '')];
        }
        $rows['Options:'][] = ['-h, --help', 'Show this help'];

        $width = 0;
        foreach ($rows as $section) {
            foreach ($section as [$label]) {
                $width = max($width, strlen($label));
            }
        }

        foreach ($rows as $title => $section) {
            $output->line();
            $output->line($output->style($title, 'warning'));
            foreach ($section as [$label, $text]) {
                $output->line(rtrim('  ' . $output->style(str_pad($label, $width + 2), 'success') . $text));
            }
        }

        if ($command->help() !== '') {
            $output->line();
            $output->line($command->help());
        }
    }

    private function usageError(UsageException $e, string $name, Output $output): int
    {
        $output->error($e->getMessage());
        $output->error(sprintf('Run "php %s help %s" for usage.', $this->binary, $name));

        return Command::USAGE;
    }

    private function failure(Throwable $e, string $name, Output $output): int
    {
        $this->logger->error('Console command {command} failed: {message}', ['command' => $name, 'message' => $e->getMessage(), 'exception' => $e]);

        $output->error('Error: ' . $e->getMessage());

        // Validation errors: ['errors' => [field => [message, ...]]].
        $errors = $e instanceof HasErrorDetails ? ($e->getDetails()['errors'] ?? null) : null;

        foreach (is_array($errors) ? $errors : [] as $messages) {
            foreach (is_array($messages) ? $messages : [] as $message) {
                if (is_string($message)) {
                    $output->error('  ' . $message);
                }
            }
        }

        // Validation errors are the user's input, not a bug: no trace even in debug.
        if ($this->debug && !$e instanceof HasErrorDetails) {
            $output->error(sprintf('%s at %s:%d', $e::class, $e->getFile(), $e->getLine()));
            $output->error($e->getTraceAsString());
        }

        return Command::FAILURE;
    }
}
