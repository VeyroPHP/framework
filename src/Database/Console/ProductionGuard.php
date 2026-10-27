<?php

declare(strict_types=1);

namespace VeyroPHP\Database\Console;

use VeyroPHP\Config\Config;
use VeyroPHP\Console\Input;
use VeyroPHP\Console\Option;
use VeyroPHP\Console\Output;

/**
 * Commands that change the database refuse to run in production without --force.
 *
 * @internal
 */
final class ProductionGuard
{
    public static function option(): Option
    {
        return Option::flag('force', 'Allow running when app.env is "production"');
    }

    public static function blocks(Config $config, Input $input, Output $output): bool
    {
        if ($config->get('app.env') !== 'production' || $input->flag('force')) {
            return false;
        }

        $output->error('Refusing to change the database in production without --force.');

        return true;
    }
}
