<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Console;

use Closure;
use VeyroPHP\Application\Application;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;

#[AsCommand('serve', 'Run the application with PHP\'s built-in development server')]
final class ServeCommand extends Command
{
    /** @var Closure(list<string>): int */
    private readonly Closure $runner;

    /**
     * @param (Closure(list<string>): int)|null $runner runs the command; replaced in tests
     */
    public function __construct(
        private readonly Application $app,
        ?Closure $runner = null,
    ) {
        $this->runner = $runner ?? self::process(...);
    }

    /**
     * @param list<string> $command
     */
    private static function process(array $command): int
    {
        // An array command is executed without a shell, so no argument is interpreted by one.
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

        return is_resource($process) ? proc_close($process) : self::FAILURE;
    }

    public function definition(): array
    {
        return [
            Option::value('host', 'Address to listen on', '127.0.0.1'),
            Option::value('port', 'Port to listen on', '8000'),
        ];
    }

    public function help(): string
    {
        return "For development only: the built-in server handles one request at a time.\n"
            . 'Use --host=0.0.0.0 only on a trusted network; it makes the app reachable from other computers.';
    }

    public function execute(Input $input, Output $output): int
    {
        $host = (string) $input->option('host');
        $port = $input->positiveInt('port') ?? 8000;

        if (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/iD', $host) !== 1) {
            throw new UsageException(sprintf('Invalid --host "%s".', $host));
        }
        if ($port > 65535) {
            throw new UsageException('The --port option must be between 1 and 65535.');
        }

        $public = $this->app->basePath('public');
        if (!is_file($public . DIRECTORY_SEPARATOR . 'index.php')) {
            $output->error('public/index.php was not found; run this command from the application directory.');

            return self::FAILURE;
        }

        $address = str_contains($host, ':') ? '[' . $host . ']:' . $port : $host . ':' . $port;

        if ($host === '0.0.0.0' || $host === '::') {
            $output->warning('Listening on all network interfaces: other computers can reach this development server.');
        }

        $output->info(sprintf('Development server: http://%s', $host === '0.0.0.0' ? '127.0.0.1:' . $port : $address));
        $output->line('Press Ctrl+C to stop.');

        return ($this->runner)([PHP_BINARY, '-S', $address, '-t', $public, self::routerScript()]);
    }

    public static function routerScript(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'server.php';
    }
}
