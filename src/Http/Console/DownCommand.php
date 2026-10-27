<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;
use VeyroPHP\Console\UsageException;
use VeyroPHP\Http\Maintenance\BlockDuringMaintenance;
use VeyroPHP\Http\Maintenance\MaintenanceMode;

/**
 * Takes the application down: web requests get 503 (see BlockDuringMaintenance).
 * Queue workers and the scheduler keep running; stop them yourself if a migration needs it.
 */
#[AsCommand('down', 'Put the application into maintenance mode (503 for visitors)')]
final class DownCommand extends Command
{
    public function __construct(private readonly Application $app) {}

    public function definition(): array
    {
        return [
            Option::value('retry', 'Seconds for the Retry-After header', (string) MaintenanceMode::DEFAULT_RETRY),
            Option::value('message', 'Text shown on the maintenance page'),
            Option::value('allow', 'Comma-separated IP addresses or CIDR ranges that keep access'),
            Option::flag('secret', 'Print a one-time link that lets your browser in (stored only as a hash)'),
        ];
    }

    public function help(): string
    {
        return "Running \"down\" again replaces the settings and any earlier secret link.\n"
            . 'The health check path (/up) stays reachable. "php veyro up" brings the site back.';
    }

    public function execute(Input $input, Output $output): int
    {
        $retry = $input->positiveInt('retry') ?? MaintenanceMode::DEFAULT_RETRY;
        $allow = array_values(array_filter(array_map('trim', explode(',', $input->option('allow') ?? '')), static fn(string $v): bool => $v !== ''));
        $mode = new MaintenanceMode($this->app->basePath(MaintenanceMode::FILE));

        try {
            $secret = $mode->down($retry, $input->option('message') ?? '', $allow, $input->flag('secret'));
        } catch (\InvalidArgumentException $e) {
            throw new UsageException($e->getMessage(), 0, $e);
        }

        $output->success('The application is now down for maintenance.');

        if ($secret !== null) {
            $output->line('Open this link once to get in (it works until the next "down"; keep it private):');
            $output->line($this->baseUrl() . BlockDuringMaintenance::BYPASS_PREFIX . $secret);
        }

        return self::SUCCESS;
    }

    private function baseUrl(): string
    {
        $container = $this->app->container();
        $url = $container->has(Config::class) ? $container->make(Config::class)->get('app.url') : null;

        return is_string($url) && $url !== '' ? rtrim($url, '/') : '';
    }
}
