<?php

declare(strict_types=1);

namespace VeyroPHP\Package\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Package\PackageManifest;

/**
 * Rebuilds bootstrap/cache/packages.php from vendor/composer/installed.json
 * and lists the packages and providers that will be registered. Composer runs
 * it after every install or update (post-autoload-dump in the starter app).
 * Exits with 1 when the cache file cannot be written, and says why.
 */
#[AsCommand('package:discover', 'Find installed VeyroPHP packages and list their providers')]
final class DiscoverCommand extends Command
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    public function definition(): array
    {
        return [];
    }

    public function execute(Input $input, Output $output): int
    {
        $manifest = new PackageManifest($this->app->basePath());
        $packages = $manifest->build();
        $skipped = array_values(array_filter((array) $this->config->get('packages.dont_discover', []), 'is_string'));

        foreach ($manifest->warnings() as $warning) {
            $output->warning($warning);
        }

        // The point of this command is the cache file: without it, report failure (CORE-4).
        $status = $manifest->cacheWritten() ? self::SUCCESS : self::FAILURE;

        if ($packages === []) {
            $output->line('No VeyroPHP packages found.');

            return $status;
        }

        $rows = [];

        foreach ($packages as $name => $package) {
            $state = in_array('*', $skipped, true) || in_array($name, $skipped, true) ? 'skipped (dont_discover)' : 'registered';
            $rows[] = [$name, $package['version'], implode(', ', $package['providers']) ?: '-', $state];
        }

        $output->table(['Package', 'Version', 'Providers', 'State'], $rows);
        $output->line('Every registered provider runs on each request: only install packages you trust.');

        return $status;
    }
}
