<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Console;

use VeyroPHP\Application\Application;
use VeyroPHP\Console\AsCommand;
use VeyroPHP\Console\Command;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Output;
use VeyroPHP\Http\Maintenance\MaintenanceMode;

#[AsCommand('up', 'Bring the application out of maintenance mode')]
final class UpCommand extends Command
{
    public function __construct(private readonly Application $app) {}

    public function execute(Input $input, Output $output): int
    {
        if ((new MaintenanceMode($this->app->basePath(MaintenanceMode::FILE)))->up()) {
            $output->success('The application is live again.');
        } else {
            $output->info('The application was not down.');
        }

        return self::SUCCESS;
    }
}
